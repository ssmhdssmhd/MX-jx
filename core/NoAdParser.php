<?php
/**
 * Noad - M3U8 去广告解析核心
 * 全方位解决 M3U8 视频插播广告难题
 *
 * 核心能力:
 *   1. 解析 M3U8 播放列表，智能识别广告片段（关键词 + 时长判定）
 *   2. 多源自动匹配：尝试多个解析源，选最快有效响应
 *   3. 缓存加速：30 分钟内相同请求直接返回缓存
 *   4. TS 片段代理转发（解决跨域问题）
 *   5. 自动识别不同站点的流媒体规则（无需复杂配置）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 4.0.0
 */

class NoAdParser {
    private $config;
    private $db;
    private $adRulesCache;       // 广告关键词本地缓存
    private $whitelistCache;     // 白名单关键词本地缓存
    private $debug;

    public function __construct() {
        $this->config = require __DIR__ . '/../config/noad.php';
        $this->debug  = !empty($this->config['debug_mode']);

        // 初始化 SQLite 数据库（统计 & 资源源管理）
        if (!empty($this->config['stats_enabled']) &&
            extension_loaded('pdo_sqlite')) {
            try {
                $this->db = Database::getInstance($this->config['sqlite_path']);
            } catch (Exception $e) {
                $this->db = null;
                error_log('[NoAdParser] SQLite 初始化失败: ' . $e->getMessage());
            }
        } else {
            $this->db = null;
        }

        $this->loadRules();
    }

    // ========== 广告规则加载 ==========

    private function loadRules() {
        // 基础规则来自配置
        $this->adRulesCache = $this->config['ad_keywords'] ?? array();
        $this->whitelistCache = $this->config['whitelist_keywords'] ?? array();

        // 追加数据库中自定义规则（如果可用）
        if ($this->db) {
            try {
                $rules = $this->db->getAdRules(true);
                foreach ($rules as $r) {
                    if (!empty($r['keyword']) && !in_array($r['keyword'], $this->adRulesCache, true)) {
                        $this->adRulesCache[] = $r['keyword'];
                    }
                }
            } catch (Exception $e) { /* 静默 */ }
        }
    }

    // ========== 对外主入口：解析视频 URL ==========

    /**
     * 解析视频播放页 URL，返回去广告后的 M3U8 或直链
     *
     * 返回结构:
     *   [ 'code'        => 200,
     *     'msg'         => '解析成功',
     *     'url'         => '最终播放地址（可直接是/raw 代理 m3u8）',
     *     'noad_url'    => '去广告版 m3u8 代理入口',
     *     'type'        => 'm3u8'|'mp4'|'json',
     *     'from_source' => '源名称',
     *     'ad_removed'  => 移除的片段数,
     *     'total_seg'   => 总片段数,
     *     'from_cache'  => bool,
     *     'response_time'=> float ]
     */
    public function parse($originalUrl, $videoType = '') {
        $startTime = microtime(true);
        $respData  = null;
        $fromCache = false;
        $sourceId  = 0;
        $sourceName = '';
        $adRemoved = 0;
        $totalSegs = 0;

        // 1. 缓存命中检查
        if (!empty($this->config['cache_enabled'])) {
            $cacheKey = $this->cacheKey($originalUrl);
            $cached   = $this->readCache($cacheKey);
            if ($cached !== null) {
                $fromCache     = true;
                $respData      = $cached;
                $sourceId      = (int)($respData['source_id'] ?? 0);
                $sourceName    = $respData['from_source'] ?? 'cache';
                $adRemoved     = (int)($respData['ad_removed'] ?? 0);
                $totalSegs     = (int)($respData['total_seg'] ?? 0);
                $respData['from_cache'] = true;
            }
        }

        // 2. 若未命中缓存，走多源解析
        if (!$fromCache) {
            $sources = $this->pickSourcesFor($originalUrl, $videoType);
            if (empty($sources)) {
                // 数据库没有源，则用配置文件中的默认源
                $sources = $this->config['default_sources'] ?? array();
            }

            $result = $this->tryMultiSource($sources, $originalUrl);
            if ($result === null) {
                $respTime = round((microtime(true) - $startTime) * 1000, 2);
                $this->recordStat(0, 'all_failed', $originalUrl, $originalUrl,
                                  $videoType, 0, 0, $respTime, false, 'failed');
                return array(
                    'code'    => 500,
                    'msg'     => '所有解析源均返回无效内容',
                    'url'     => '',
                    'type'    => 'error',
                    'developer' => 'MX-射手沫蝴蝶',
                    'contact' => 'QQ: 2094332348',
                );
            }

            $sourceId   = (int)($result['source_id'] ?? 0);
            $sourceName = $result['source_name'];
            $respData   = $result['payload'];

            // 3. 如果拿到的是 M3U8，则做广告片段过滤
            if (!empty($this->config['enable_ad_filter']) &&
                $respData['type'] === 'm3u8' &&
                !empty($respData['raw_m3u8'])) {

                $cleanResult = $this->filterM3u8($respData['raw_m3u8']);
                $adRemoved   = $cleanResult['removed'];
                $totalSegs   = $cleanResult['total'];

                // 如果确实清理了任何内容，就生成代理版播放列表
                if ($adRemoved > 0) {
                    $proxyUrl = $this->buildProxyUrl($originalUrl, $respData['final_url']);
                    $respData['noad_url']   = $proxyUrl;
                    $respData['url']        = $proxyUrl;  // 默认返回去广告版
                    $respData['clean_m3u8'] = $cleanResult['content'];
                } else {
                    $respData['noad_url'] = $respData['final_url'];
                }
            }

            // 写入缓存
            if (!empty($this->config['cache_enabled'])) {
                $respData['source_id']   = $sourceId;
                $respData['from_source'] = $sourceName;
                $respData['ad_removed']  = $adRemoved;
                $respData['total_seg']   = $totalSegs;
                $this->writeCache($cacheKey, $respData);
            }
        }

        $respTime = round((microtime(true) - $startTime) * 1000, 2);

        // 4. 写入统计
        $this->recordStat($sourceId, $sourceName, $respData['final_url'] ?? $originalUrl,
                          $originalUrl, $videoType, $adRemoved, $totalSegs,
                          $respTime, $fromCache, 'ok');

        // 5. 构造最终响应（前端可直接用 url 字段播放）
        return array(
            'code'          => 200,
            'msg'           => $respData['msg'] ?? '解析成功',
            'url'           => $respData['url'] ?? $respData['final_url'] ?? '',
            'noad_url'      => $respData['noad_url'] ?? ($respData['url'] ?? ''),
            'type'          => $respData['type'] ?? 'm3u8',
            'from_source'   => $sourceName,
            'ad_removed'    => $adRemoved,
            'total_segments'=> $totalSegs,
            'from_cache'    => $fromCache,
            'response_time' => $respTime,
            'developer'     => 'MX-射手沫蝴蝶',
            'contact'       => 'QQ: 2094332348',
            'strategy'      => 'Noad-M3U8-Ad-Cleaner-v4',
            'timestamp'     => time(),
        );
    }

    // ========== 对外：代理获取去广告版 M3U8 ==========

    public function serveCleanM3u8($originalUrl, $remoteM3u8Url) {
        $content = $this->fetchUrl($remoteM3u8Url, 15);
        if ($content === false) {
            header('HTTP/1.1 502 Bad Gateway');
            echo "# M3U8 fetch failed\n";
            return;
        }
        $result = $this->filterM3u8($content);
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        // 若有相对路径 TS，补全为绝对路径
        $base = $this->getBaseUrl($remoteM3u8Url);
        $clean = $this->resolveRelativePaths($result['content'], $base);
        echo $clean;
    }

    // ========== 对外：代理 TS 片段（解决跨域） ==========

    public function serveTs($tsUrl) {
        if (empty($tsUrl)) { http_response_code(400); echo 'empty url'; return; }
        $ch = curl_init($tsUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER         => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 NoAdParser/4.0',
            CURLOPT_REFERER        => $this->getBaseUrl($tsUrl),
        ));
        $data = curl_exec($ch);
        if ($data === false) { http_response_code(502); return; }
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'video/mp2t';
        curl_close($ch);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=86400');
        header('Access-Control-Allow-Origin: *');
        echo $data;
    }

    // ========== 多源并发匹配（选最优）==========

    private function tryMultiSource($sources, $originalUrl) {
        if (empty($sources)) return null;

        // 1. 限制并发数
        $maxTry = (int)($this->config['max_source_try'] ?? 3);
        $candidates = array_slice($sources, 0, max(1, $maxTry));

        // 2. 并发请求所有源
        $handles = array();
        $mh = curl_multi_init();
        foreach ($candidates as $idx => $src) {
            $reqUrl = str_replace('{url}', urlencode($originalUrl), $src['url']);
            $ch = curl_init($reqUrl);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)($src['timeout'] ?? 8),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 NoAdParser/4.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING       => 'gzip,deflate',
            ));
            $handles[$idx] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        // 3. 执行并发
        $active = null;
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh, 0.2);
        } while ($active > 0);

        // 4. 收集结果
        $results = array();
        foreach ($handles as $idx => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($code === 200 && $body !== false && !empty($body)) {
                $results[] = array('source' => $candidates[$idx], 'body' => $body, 'time' => $time);
            }
        }
        curl_multi_close($mh);

        if (empty($results)) return null;

        // 5. 选最佳：能解析出 m3u8 直链的优先（响应快者）
        usort($results, function($a, $b) { return $a['time'] <=> $b['time']; });

        foreach ($results as $r) {
            $parsed = $this->parseApiResponse($r['body']);
            if ($parsed !== null) {
                $parsed['source_id']   = $r['source']['id'] ?? 0;
                $parsed['source_name'] = $r['source']['name'];
                return $parsed;
            }
        }
        return null;
    }

    // ========== 解析第三方接口响应 ==========

    private function parseApiResponse($body) {
        if ($body === false || $body === '') return null;

        // 情况A：直接就是 M3U8 内容
        if (strpos($body, '#EXTM3U') !== false) {
            return array(
                'type'      => 'm3u8',
                'msg'       => 'M3U8 direct',
                'final_url' => '',  // 无 URL，由调用方处理
                'raw_m3u8'  => $body,
            );
        }

        // 情况B：JSON 响应（最常见）
        $json = json_decode($body, true);
        if (is_array($json)) {
            $playUrl = $this->pickPlayUrlFromJson($json);
            if ($playUrl !== null) {
                // 进一步，如果返回的是 m3u8 链接，则拉取 m3u8 原始内容
                // （只有 30% 概率的源直接返回 m3u8 内容，大部分是 url。）
                $type = (stripos($playUrl, '.m3u8') !== false) ? 'm3u8' : 'mp4';
                $rawM3u8 = '';
                if ($type === 'm3u8') {
                    $raw = $this->fetchUrl($playUrl, 10);
                    if ($raw !== false && strpos($raw, '#EXTM3U') !== false) {
                        $rawM3u8 = $raw;
                    }
                }
                return array(
                    'type'      => $type,
                    'msg'       => $json['msg'] ?? ($json['info'] ?? '解析成功'),
                    'final_url' => $playUrl,
                    'raw_m3u8'  => $rawM3u8,
                    'url'       => $playUrl,
                );
            }
        }

        // 情况C：响应体是纯 URL 字符串
        $trimmed = trim($body);
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $type = (stripos($trimmed, '.m3u8') !== false) ? 'm3u8' : 'mp4';
            return array(
                'type'      => $type,
                'msg'       => 'raw url',
                'final_url' => $trimmed,
                'raw_m3u8'  => '',
                'url'       => $trimmed,
            );
        }
        return null;
    }

    private function pickPlayUrlFromJson($json) {
        // 遍历常见字段名
        $fields = array('url', 'playurl', 'play_url', 'video_url',
                        'jx', 'durl', 'm3u8', 'hls', 'src',
                        'data.url', 'data.playurl', 'result.url');
        foreach ($fields as $f) {
            if (strpos($f, '.') !== false) {
                $parts = explode('.', $f);
                $node = $json;
                $ok = true;
                foreach ($parts as $p) {
                    if (!is_array($node) || !isset($node[$p])) { $ok = false; break; }
                    $node = $node[$p];
                }
                if ($ok && is_string($node) && filter_var($node, FILTER_VALIDATE_URL)) {
                    return $node;
                }
            } else {
                if (isset($json[$f]) && is_string($json[$f]) &&
                    filter_var($json[$f], FILTER_VALIDATE_URL)) {
                    return $json[$f];
                }
            }
        }
        return null;
    }

    // ========== M3U8 广告过滤核心 ==========

    /**
     * 过滤 M3U8 中的广告片段
     * 返回: array('content' => 过滤后的 m3u8 文本,
     *             'removed' => 移除片段数,
     *             'total'   => 总片段数)
     */
    public function filterM3u8($m3u8Content) {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8Content);
        $output = array();
        $removed = 0;
        $total = 0;
        $hitRuleIds = array();

        // 保持头部
        $headPassed = false;

        // 辅助：当前片段的 EXTINF 行（待决定是否保留）
        $pendingExtinf = null;
        $pendingDuration = 0;

        $output[] = '#EXTM3U';
        $output[] = '# Generated-By: Noad M3U8 Ad-Cleaner v4';
        $output[] = '# Ad-Segments-Removed: '; // 占位，稍后补数

        $idx = 0;
        while ($idx < count($lines)) {
            $line = trim($lines[$idx]);
            if ($line === '') { $idx++; continue; }

            // 直通全局头
            if (strpos($line, '#EXT-X-') === 0 || strpos($line, '#EXTM3U') === 0) {
                if (strpos($line, '#EXTM3U') !== 0) $output[] = $line;
                $idx++; continue;
            }

            // 注释但非标签 -> 忽略
            if (strpos($line, '#') === 0 && strpos($line, '#EXT') !== 0) {
                $idx++; continue;
            }

            // 片段信息: 读取 #EXTINF 及其后一行的 URI
            if (strpos($line, '#EXTINF') === 0) {
                $extinfLine = $line;
                // 提取 #EXTINF 后的时长
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                    $duration = (float)$m[1];
                }
                $uriLine = '';
                $extLines = array($line);
                $idx++;
                // 继续读取紧跟的属性行（#EXT-X-KEY, #EXT-X-BYTERANGE, #EXT-X-DISCONTINUITY 等）
                while ($idx < count($lines) &&
                       (trim($lines[$idx]) === '' ||
                        (strpos(trim($lines[$idx]), '#EXT-X-') === 0 &&
                         strpos(trim($lines[$idx]), '#EXT-X-ENDLIST') !== 0) ||
                        strpos(trim($lines[$idx]), '#EXT-X-DISCONTINUITY') === 0 ||
                        strpos(trim($lines[$idx]), '#EXT-X-KEY') === 0 ||
                        strpos(trim($lines[$idx]), '#EXT-X-BYTERANGE') === 0)) {
                    if (trim($lines[$idx]) !== '') $extLines[] = trim($lines[$idx]);
                    $idx++;
                }
                if ($idx < count($lines)) {
                    $uriLine = trim($lines[$idx]);
                    $idx++;
                }

                $total++;
                // 判断是否是广告片段
                if ($this->isAdSegment($extinfLine . ' ' . implode(' ', $extLines) . ' ' . $uriLine, $duration)) {
                    $removed++;
                    continue; // 丢弃
                }
                // 保留
                foreach ($extLines as $el) $output[] = $el;
                $output[] = $uriLine;
                continue;
            }

            // 普通 URI 行（没有 EXTINF 的情况，罕见但兼容处理）
            if (strpos($line, '#') !== 0 && $line !== '') {
                $total++;
                if ($this->isAdSegment($line, 0)) {
                    $removed++;
                    $idx++;
                    continue;
                }
                $output[] = $line;
                $idx++;
                continue;
            }

            $idx++;
        }
        // 若输出末尾尚未含 ENDLIST，则补充一条（防止重复）
        $hasEndList = false;
        foreach (array_reverse($output) as $checkLine) {
            if (strpos(trim($checkLine), '#EXT-X-ENDLIST') === 0) { $hasEndList = true; break; }
        }
        if (!$hasEndList) $output[] = '#EXT-X-ENDLIST';

        // 回填移除数
        foreach ($output as $k => $l) {
            if (strpos($l, '# Ad-Segments-Removed:') === 0) {
                $output[$k] = '# Ad-Segments-Removed: ' . $removed . ' / ' . $total;
                break;
            }
        }

        return array(
            'content' => implode("\n", $output),
            'removed' => $removed,
            'total'   => $total,
        );
    }

    /**
     * 单片段广告判定
     */
    private function isAdSegment($context, $duration) {
        $contextLower = mb_strtolower($context);

        // 1. 白名单优先
        foreach ($this->whitelistCache as $w) {
            if (stripos($contextLower, mb_strtolower($w)) !== false) return false;
        }

        // 2. 极短视频（< 1 秒）+ 命中任意广告关键词，判定为广告
        $hitCount = 0;
        foreach ($this->adRulesCache as $kw) {
            if (stripos($contextLower, mb_strtolower($kw)) !== false) $hitCount++;
        }

        $threshold = (int)($this->config['ad_keyword_threshold'] ?? 2);

        if ($hitCount >= $threshold) return true;
        if ($hitCount >= 1 && $duration > 0 && $duration < 2.0) return true;
        if ($hitCount >= 1 && $duration > 60.0) return true; // 超长 + 命中关键词也是广告（部分站点做法）

        return false;
    }

    // ========== 缓存系统 ==========

    private function cacheKey($url) {
        return 'noad_' . md5($url);
    }

    private function cacheFile($key) {
        $dir = $this->config['cache_dir'] ?? (__DIR__ . '/../cache');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return rtrim($dir, '/') . '/' . $key . '.cache';
    }

    public function writeCache($key, $data) {
        $ttl = (int)($this->config['cache_ttl'] ?? 1800);
        $file = $this->cacheFile($key);
        $payload = array('expires' => time() + $ttl, 'data' => $data);
        @file_put_contents($file, serialize($payload), LOCK_EX);
    }

    public function readCache($key) {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $payload = @unserialize($raw);
        if (!is_array($payload) || !isset($payload['expires']) || !isset($payload['data'])) {
            @unlink($file);
            return null;
        }
        if ((int)$payload['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $payload['data'];
    }

    public function clearCache() {
        $dir = $this->config['cache_dir'] ?? (__DIR__ . '/../cache');
        $count = 0;
        $files = glob(rtrim($dir, '/') . '/noad_*.cache');
        if (is_array($files)) {
            foreach ($files as $f) { if (@unlink($f)) $count++; }
        }
        return $count;
    }

    // ========== 工具方法 ==========

    private function fetchUrl($url, $timeout = 10) {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 NoAdParser/4.0',
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_REFERER        => $this->getBaseUrl($url),
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || $body === false) return false;
        return $body;
    }

    private function getBaseUrl($url) {
        $parts = parse_url($url);
        if ($parts === false) return '';
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';
        $dirPath = substr($path, 0, strrpos($path, '/') + 1);
        return $scheme . '://' . $host . $port . $dirPath;
    }

    private function resolveRelativePaths($m3u8, $base) {
        if ($base === '') return $m3u8;
        $lines = preg_split('/\r\n|\r|\n/', $m3u8);
        $out = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || strpos($t, '#') === 0) { $out[] = $line; continue; }
            // 如果是相对路径 / 相对 http(s) 之外的协议
            if (filter_var($t, FILTER_VALIDATE_URL)) {
                $out[] = $line;
            } else {
                if (substr($t, 0, 1) === '/') {
                    // 绝对路径
                    $parts = parse_url($base);
                    $out[] = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') .
                             (isset($parts['port']) ? ':' . $parts['port'] : '') . $t;
                } else {
                    $out[] = rtrim($base, '/') . '/' . ltrim($t, '/');
                }
            }
        }
        return implode("\n", $out);
    }

    private function buildProxyUrl($originalUrl, $remoteM3u8) {
        $phpSelf = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $proxy = dirname($phpSelf) . '/noad_proxy.php';
        return $proxy . '?mode=m3u8&src=' . urlencode($remoteM3u8) .
               '&ref=' . urlencode($originalUrl);
    }

    private function pickSourcesFor($originalUrl, $videoType = '') {
        if ($this->db === null) return array();
        try {
            $sources = $this->db->getSources(0, true);
            // 如果有 type_id 匹配，则优先
            $result = array();
            foreach ($sources as $s) {
                if ($videoType !== '' && !empty($s['match_rules']) &&
                    stripos($videoType, $s['match_rules']) !== false) {
                    array_unshift($result, $s);
                } else {
                    $result[] = $s;
                }
            }
            return $result;
        } catch (Exception $e) { return array(); }
    }

    private function recordStat($sourceId, $sourceName, $requestUrl, $originalUrl,
                                $videoType, $adRemoved, $totalSegs, $respTime,
                                $fromCache, $status) {
        if ($this->db === null || empty($this->config['stats_enabled'])) return;
        try {
            $this->db->recordRequest($sourceId, $sourceName, $requestUrl, $originalUrl,
                                     $videoType, $adRemoved, $totalSegs, $respTime,
                                     $fromCache, $status);
        } catch (Exception $e) { /* 静默 */ }
    }
}
