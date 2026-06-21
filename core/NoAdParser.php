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
     * 单片段广告判定（增强版：关键词 + 时长 + DISCONTINUITY）
     */
    private function isAdSegment($context, $duration) {
        $contextLower = mb_strtolower($context);

        // 1. 白名单优先：命中白名单则永远不是广告
        foreach ($this->whitelistCache as $w) {
            if (stripos($contextLower, mb_strtolower($w)) !== false) return false;
        }

        // 2. 关键词扫描
        $hitCount = 0;
        $hitRules = array();
        foreach ($this->adRulesCache as $kw) {
            if (stripos($contextLower, mb_strtolower($kw)) !== false) {
                $hitCount++;
                $hitRules[] = $kw;
            }
        }

        $threshold = (int)($this->config['ad_keyword_threshold'] ?? 2);

        // 3. 规则命中：超阈值直接判为广告
        if ($hitCount >= $threshold) return true;

        // 4. 时长判定：<= 1.5 秒的极短片段可能是广告片头/片尾
        if ($duration > 0 && $duration <= 1.5 && $hitCount >= 1) return true;

        // 5. 1.5 ~ 5 秒 + 命中关键词 = 可能是贴片广告
        if ($duration > 1.5 && $duration <= 5.0 && $hitCount >= 1) return true;

        // 6. 超长片段 > 60 秒 + 命中关键词 = 可能是插播广告视频（部分站点做法）
        if ($hitCount >= 1 && $duration > 60.0) return true;

        return false;
    }

    /**
     * 分析 M3U8：返回每个片段的详细信息（含时间戳、广告标记）
     * 供后台 M3U8 解析页面做双栏对比展示
     *
     * 返回: array(
     *   'raw_content'   => 原始 M3U8 文本
     *   'total'         => 片段总数
     *   'ad_count'      => 广告片段数
     *   'keep_count'    => 保留片段数
     *   'total_duration'=> 总时长(秒)
     *   'ad_duration'   => 广告总时长(秒)
     *   'keep_duration' => 保留总时长(秒)
     *   'clean_m3u8'    => 过滤后纯净 m3u8 文本
     *   'segments'      => array( array('idx','duration','uri','is_ad','reason',
     *                                   'time_start','time_end','extinf_lines') ...)
     *   'rules'         => 命中的广告关键词总数
     * )
     */
    public function analyzeM3u8($m3u8Content) {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8Content);
        $segments = array();
        $totalSegments = 0;
        $totalAdSegments = 0;
        $totalDuration = 0.0;
        $adDuration = 0.0;
        $runningTimestamp = 0.0;
        $totalRulesHit = 0;

        $idx = 0;
        $lineCount = count($lines);
        $segIndex = 0;
        $inDiscontinuityBlock = false;

        while ($idx < $lineCount) {
            $line = trim($lines[$idx]);
            if ($line === '') { $idx++; continue; }

            // 头部标签：直接跳过（保留到 raw，但不做片段解析）
            if (strpos($line, '#EXTM3U') === 0) { $idx++; continue; }
            if (strpos($line, '#EXT-X-DISCONTINUITY') === 0) {
                // 可能开始/结束一个广告块，作为标记记录
                $inDiscontinuityBlock = !$inDiscontinuityBlock;
                $idx++; continue;
            }
            if (strpos($line, '#EXT-X-ENDLIST') === 0) { $idx++; break; }

            // 其他全局头 (#EXT-X-VERSION, #EXT-X-TARGETDURATION, #EXT-X-MEDIA-SEQUENCE 等)
            if (strpos($line, '#EXT-X-') === 0 && strpos($line, '#EXTINF') !== 0) {
                $idx++; continue;
            }
            // 非 #EXT 注释
            if (strpos($line, '#') === 0 && strpos($line, '#EXT') !== 0) {
                $idx++; continue;
            }

            // ===== 片段解析：#EXTINF:duration,[title] =====
            if (strpos($line, '#EXTINF') === 0) {
                $extinfLines = array($line);
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $duration = (float)$m[1];
                $idx++;

                // 收集附属标签（#EXT-X-KEY, #EXT-X-BYTERANGE, #EXT-X-DISCONTINUITY 等）
                while ($idx < $lineCount) {
                    $t = trim($lines[$idx]);
                    if ($t === '') { $idx++; continue; }
                    // 继续收集的条件：
                    //   #EXT-X-KEY, #EXT-X-BYTERANGE, #EXT-X-DISCONTINUITY
                    if (strpos($t, '#EXT-X-KEY') === 0 ||
                        strpos($t, '#EXT-X-BYTERANGE') === 0 ||
                        strpos($t, '#EXT-X-DISCONTINUITY') === 0) {
                        $extinfLines[] = $t;
                        $idx++;
                        continue;
                    }
                    break;
                }
                // 下一行应该是 URI
                $uri = '';
                if ($idx < $lineCount) {
                    $uri = trim($lines[$idx]);
                    $idx++;
                }

                $totalSegments++;
                $segIndex++;

                $context = implode(' ', $extinfLines) . ' ' . $uri;
                $isAd = $this->isAdSegment($context, $duration);

                $timeStart = $runningTimestamp;
                $timeEnd = $runningTimestamp + $duration;
                $runningTimestamp = $timeEnd;
                $totalDuration += $duration;

                $reason = '';
                if ($isAd) {
                    $totalAdSegments++;
                    $adDuration += $duration;
                    $totalRulesHit++;
                    if ($duration <= 1.5) $reason = '极短视频(≤1.5s)+ 命中规则';
                    elseif ($duration <= 5.0) $reason = '短片段(≤5s)+ 命中规则';
                    elseif ($duration > 60) $reason = '超长片段(>60s)+ 命中规则';
                    else $reason = '命中广告关键词';
                }

                $segments[] = array(
                    'idx'      => $segIndex,
                    'duration' => $duration,
                    'uri'      => $uri,
                    'is_ad'    => $isAd,
                    'reason'   => $reason,
                    'time_start' => $timeStart,
                    'time_end'   => $timeEnd,
                    'extinf_lines' => $extinfLines,
                );
                continue;
            }

            // 裸 URI 行（无 EXTINF），兼容处理
            if (strpos($line, '#') !== 0 && $line !== '') {
                $totalSegments++;
                $segIndex++;
                $isAd = $this->isAdSegment($line, 0);
                $segments[] = array(
                    'idx' => $segIndex, 'duration' => 0, 'uri' => $line,
                    'is_ad' => $isAd, 'reason' => $isAd ? '命中关键词' : '',
                    'time_start' => $runningTimestamp, 'time_end' => $runningTimestamp,
                    'extinf_lines' => array(),
                );
                if ($isAd) { $totalAdSegments++; $totalRulesHit++; }
                $idx++;
                continue;
            }

            $idx++;
        }

        // 过滤后内容（纯视频部分）
        $cleanLines = array();
        $cleanLines[] = '#EXTM3U';
        $cleanLines[] = '# Generated-By: Noad M3U8 Ad-Cleaner v4.1';
        $cleanLines[] = '# Ad-Segments-Removed: ' . $totalAdSegments . ' / ' . $totalSegments;
        foreach ($segments as $s) {
            if (!$s['is_ad']) {
                foreach ($s['extinf_lines'] as $el) $cleanLines[] = $el;
                $cleanLines[] = $s['uri'];
            }
        }
        $cleanLines[] = '#EXT-X-ENDLIST';
        $cleanM3u8 = implode("\n", $cleanLines);

        return array(
            'raw_content'   => $m3u8Content,
            'total'         => $totalSegments,
            'ad_count'      => $totalAdSegments,
            'keep_count'    => $totalSegments - $totalAdSegments,
            'total_duration'=> round($totalDuration, 3),
            'ad_duration'   => round($adDuration, 3),
            'keep_duration' => round($totalDuration - $adDuration, 3),
            'clean_m3u8'    => $cleanM3u8,
            'segments'      => $segments,
            'rules'         => $totalRulesHit,
        );
    }

    /**
     * 获取远程 M3U8 并分析（直接 URL 解析）
     */
    public function fetchAndAnalyze($url, $timeout = 15) {
        $content = $this->fetchUrl($url, $timeout);
        if ($content === false) return null;
        // 如果内容不是 m3u8（可能是 JSON/HTML），尝试从 JSON 中提取
        if (strpos($content, '#EXTM3U') === false) {
            $json = json_decode($content, true);
            if (is_array($json)) {
                $nestedUrl = $this->pickPlayUrlFromJson($json);
                if ($nestedUrl !== null && $nestedUrl !== '') {
                    $content2 = $this->fetchUrl($nestedUrl, $timeout);
                    if ($content2 !== false) $content = $content2;
                }
            }
        }
        if (strpos($content, '#EXTM3U') === false) return null;
        return $this->analyzeM3u8($content);
    }

    /**
     * 辅助：格式化秒数 -> HH:MM:SS
     */
    public function formatTime($seconds) {
        $seconds = max(0, (float)$seconds);
        $h = floor($seconds / 3600);
        $m = floor(($seconds - $h * 3600) / 60);
        $s = $seconds - $h * 3600 - $m * 60;
        return sprintf('%02d:%02d:%04.1f', $h, $m, $s);
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

    // ============================================================
    // ====== 资源站去广告：域名识别 + 算法（suanfa1~9 等）======
    // ============================================================

    /**
     * 对外入口：识别资源站并应用对应去广告算法
     *
     * @param string $url  原始视频 URL（识别资源站用）
     * @param string $data 待处理的字符串（视频 URL / M3U8 内容 / JSON）
     * @return array { 'data': string 处理后数据, 'matched_site': string 匹配的站点名,
     *                  'algorithms_applied': array 应用的算法列表,
     *                  'ad_tokens_removed': int 去除的广告标记总数 }
     */
    public function cleanByResourceSite($url, $data) {
        $result = array(
            'data'                => $data,
            'matched_site'        => '',
            'algorithms_applied'  => array(),
            'ad_tokens_removed'   => 0,
        );

        if ($data === '' || $data === null) return $result;

        $turerCount = 0;
        $ruyi = false;
        $xgzy = true;

        // ========= 电影天堂特殊处理（dytt/电影天堂域名）=========
        if ($this->checkDomainContainsDytt($url)) {
            $result['matched_site'] = '电影天堂 (dytt)';
            $turer = $this->suanfasmall($data, 0);
            $data  = $this->suanfa3($data, $turer - 1);
            $result['algorithms_applied'][] = 'suanfasmall';
            $result['algorithms_applied'][] = 'suanfa3';
            $result['ad_tokens_removed'] += $turer;
        }

        // ========= 西瓜资源站（xiguang / xigua / 360kan 等）=========
        if ($this->checkDomainContainsXg($url)) {
            if ($result['matched_site'] === '') $result['matched_site'] = '西瓜 (xiguang/xigua)';
            $data = $this->suanfaxiguang($data, 0, 0);
            $xgzy = false;
            $result['algorithms_applied'][] = 'suanfaxiguang';
            $result['ad_tokens_removed']++;
        }

        // ========= 如意资源站（ruyi 相关）=========
        if ($this->checkDomainContainsRuyi($url)) {
            if ($result['matched_site'] === '') $result['matched_site'] = '如意 (ruyi)';
            $ruyi = (bool)$this->suanfasmall($data);
            $result['algorithms_applied'][] = 'ruyi-detect';
        }

        // ========= 组合分支：根据 ruyi + xgzy 组合决策 =========
        if ($ruyi && $xgzy) {
            $data = $this->suanfa5($data, 0, 0);
            $data = $this->suanfa4($data, 0, 4);
            $result['algorithms_applied'][] = 'suanfa5';
            $result['algorithms_applied'][] = 'suanfa4';
            $result['ad_tokens_removed'] += 2;
        }

        if (!$ruyi && $xgzy) {
            $true = $this->suanfa2($data);
            if ($true) {
                $data = $this->suanfa9($data, 0, 0);
                $data = $this->suanfa5($data, 1, 2);
                $data = $this->suanfa3($data, 1, 2);
                $result['algorithms_applied'][] = 'suanfa9';
                $result['algorithms_applied'][] = 'suanfa5';
                $result['algorithms_applied'][] = 'suanfa3';
                $result['ad_tokens_removed'] += 3;
            } else {
                $data = $this->suanfa8($data);
                $data = $this->suanfa4($data, 0, 4);
                $result['algorithms_applied'][] = 'suanfa8';
                $result['algorithms_applied'][] = 'suanfa4';
                $result['ad_tokens_removed'] += 2;
            }
        }

        // ========= 兜底：对任意字符串走一遍轻量算法 =========
        if (empty($result['matched_site'])) {
            $before = $this->countAdTokens($data);
            $data = $this->suanfa4($data, 0, 0);
            $data = $this->suanfa8($data);
            $after = $this->countAdTokens($data);
            $result['matched_site'] = '通用清理';
            $result['algorithms_applied'][] = 'suanfa4';
            $result['algorithms_applied'][] = 'suanfa8';
            $result['ad_tokens_removed'] += max(0, $before - $after);
        }

        $result['data'] = $data;
        return $result;
    }

    // --------- 资源站域名识别函数 ---------

    private function checkDomainContainsXg($url) {
        $host = strtolower($this->getHostFromUrl($url));
        $patterns = array(
            'xigua', 'xiguang', '360kan', 'jx.xg', 'xg.jx',
            '西瓜', 'xigua-video', 'xiguavideo', 'xg-video',
            'xiguayingshi', 'ixigua', 'ixg', 'xgplayer',
        );
        foreach ($patterns as $p) {
            if (strpos($host, $p) !== false || strpos(strtolower($url), $p) !== false) return true;
        }
        return false;
    }

    private function checkDomainContainsRuyi($url) {
        $host = strtolower($this->getHostFromUrl($url));
        $patterns = array(
            'ruyi', 'ry.jx', 'jx.ry', '如意', 'ryplayer',
            'ruyi-video', 'ruyivideo', 'ry-vod', 'vod-ry',
            'ryvideo', 'ruyi.tv', 'ry.tv',
        );
        foreach ($patterns as $p) {
            if (strpos($host, $p) !== false || strpos(strtolower($url), $p) !== false) return true;
        }
        return false;
    }

    private function checkDomainContainsDytt($url) {
        $host = strtolower($this->getHostFromUrl($url));
        $patterns = array(
            'dytt', '电影天堂', 'dianyingtiantang', 'dytv',
            'dy8', 'dy2018', 'dy1234', 'dyvod', 'dy-api',
            'yttv', 'dianying', 'dy.cc', 'dytt8',
        );
        foreach ($patterns as $p) {
            if (strpos($host, $p) !== false || strpos(strtolower($url), $p) !== false) return true;
        }
        return false;
    }

    private function getHostFromUrl($url) {
        if ($url === '' || $url === null) return '';
        $parts = parse_url($url);
        if ($parts === false) return '';
        $host = $parts['host'] ?? '';
        if ($host === '' && preg_match('/https?:\/\/([^\/\s]+)/i', $url, $m)) {
            $host = $m[1];
        }
        return $host;
    }

    // --------- suanfa 算法系列（URL/数据清理）---------

    /**
     * suanfa1: 去除 URL 中的分析/跟踪参数
     */
    public function suanfa1($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $blacklist = array(
            'from=', 'from_uid=', 'spm=', 'utm_source', 'utm_medium',
            'utm_campaign', 'utm_content', 'utm_term', 'track', 'trace',
            'refer', 'referer', 'ref=', 'aff=', 'affiliate', 'adid=',
            'ad=', 'clickid=', 'click_id=', 'clk=', 'log_id=', 'logid=',
            'sign=', 'nonce=', 'token=', 'tk=', 'skey=',
        );
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (filter_var($line, FILTER_VALIDATE_URL)) {
                foreach ($blacklist as $bad) {
                    $line = preg_replace('/(&|\?)' . preg_quote($bad, '/') . '[^&]*/i', '$1', $line);
                }
                // 清理多余的 & 或空查询
                $line = preg_replace('/\?&+/', '?', $line);
                $line = rtrim($line, '?&');
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * suanfa2: 检测是否含广告标记，返回 bool
     */
    public function suanfa2($data) {
        if (!is_string($data)) return false;
        $needles = array('ad_', '_ad', 'adver', 'advert', 'promo', 'banner',
                          '贴片', '片头', '广告', 'pre-roll', 'mid-roll',
                          'adbreak', 'ad_url', 'adlink', 'ad-domain', '广告域名');
        foreach ($needles as $n) {
            if (stripos($data, $n) !== false) return true;
        }
        return false;
    }

    /**
     * suanfa3: 以第 p2 个 "/" 为界截断路径（去除尾部广告路径段）
     */
    public function suanfa3($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        $limit = (int)$p2;
        if ($limit <= 0) $limit = 3;
        foreach ($lines as $line) {
            $t = trim($line);
            if (filter_var($t, FILTER_VALIDATE_URL)) {
                $parts = parse_url($t);
                if ($parts !== false && isset($parts['path'])) {
                    $segs = array_values(array_filter(explode('/', $parts['path']),
                                                       function($x) { return $x !== ''; }));
                    if (count($segs) > $limit) {
                        $segs = array_slice($segs, 0, $limit);
                        $newPath = '/' . implode('/', $segs);
                        $scheme = $parts['scheme'] ?? 'https';
                        $host   = $parts['host'] ?? '';
                        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
                        $query  = !empty($parts['query']) ? '?' . $parts['query'] : '';
                        $line = $scheme . '://' . $host . $port . $newPath . $query;
                    }
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * suanfa4: 规范化 URL 协议（http→https，补全缺失协议）
     */
    public function suanfa4($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        // 1) 替换已知广告域为普通 CDN
        $adDomains = array(
            'ad.qq.com' => 'v.qq.com',
            'adservice.google.com' => 'video.google.com',
            'v.ad.video.qq.com' => 'v.qq.com',
            'ad.video.iqiyi.com' => 'www.iqiyi.com',
            'x.ad.youku.com' => 'v.youku.com',
        );
        foreach ($adDomains as $bad => $good) {
            $data = str_ireplace($bad, $good, $data);
        }
        // 2) http:// 升级为 https://（减少混合内容警告）
        $data = preg_replace('/http:\/\/(?!127\.0\.0\.1|localhost)/i', 'https://', $data);
        // 3) 去除 "//" 以外的双斜杠（保持协议双斜杠）
        $data = preg_replace_callback('/(https?:\/\/[^\s]+)/i', function($m) {
            $u = $m[1];
            $u = preg_replace('/([^:])(\/{2,})/', '$1/', $u);
            return $u;
        }, $data);
        return $data;
    }

    /**
     * suanfa5: 标准化 m3u8 URL 结构（去除多余路径段）
     */
    public function suanfa5($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if (filter_var($t, FILTER_VALIDATE_URL)) {
                // 去除 URL 中常见的广告路径段：如 /ads/, /ad/, /promo/, /banner/, /track/
                $t = preg_replace('/\/(ads|ad|promo|promotion|banner|track|tracker|tongji|analytics|stat)(\/|\?|$)/i',
                                   '/', $t);
                // 修复多余斜杠
                $t = preg_replace_callback('/(https?:\/\/[^\s]+)/i', function($m) {
                    return preg_replace('/([^:])(\/{2,})/', '$1/', $m[1]);
                }, $t);
                $t = rtrim($t, '/');
                $line = $t;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * suanfa6: 去除 URL 中防缓存参数（如 _=timestamp, cb=, t=时间戳）
     */
    public function suanfa6($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        foreach ($lines as $line) {
            $t = trim($line);
            if (filter_var($t, FILTER_VALIDATE_URL)) {
                $t = preg_replace('/(&|\?)_=\d+/i', '$1', $t);
                $t = preg_replace('/(&|\?)t=\d+/i', '$1', $t);
                $t = preg_replace('/(&|\?)timestamp=\d+/i', '$1', $t);
                $t = preg_replace('/(&|\?)time=\d+/i', '$1', $t);
                $t = preg_replace('/(&|\?)cb=\d+/i', '$1', $t);
                $t = preg_replace('/&+/', '&', $t);
                $t = rtrim($t, '?&');
                $line = $t;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * suanfa7: 替换视频源中的广告域名
     */
    public function suanfa7($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $map = array(
            'adcdn.'       => 'cdn.',
            'ad-push.'     => 'push.',
            'adplayer.'    => 'player.',
            'ad-play.'     => 'play.',
            'ads-video.'   => 'video.',
            'ad-video.'    => 'video.',
            'advertising.' => 'video.',
            'tracker.'     => 'api.',
            'adapi.'       => 'api.',
            'clicktrack.'  => 'api.',
        );
        foreach ($map as $bad => $good) {
            $data = str_ireplace($bad, $good, $data);
        }
        return $data;
    }

    /**
     * suanfa8: 过滤广告相关的 302/重定向，保留真实播放地址
     */
    public function suanfa8($data) {
        if (!is_string($data)) return $data;
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        $redirectPatterns = array(
            '/\/redirect\?url=/i',
            '/\/jump\?/i',
            '/\/track\?/i',
            '/\/ad\?/i',
            '/\/goto\?/i',
        );
        foreach ($lines as $line) {
            $t = trim($line);
            $isRedirect = false;
            foreach ($redirectPatterns as $p) {
                if (preg_match($p, $t)) { $isRedirect = true; break; }
            }
            if ($isRedirect) {
                // 尝试提取其中嵌套的 URL
                if (preg_match('/[?&](?:url|u|target|go)=([^&\s]+)/i', $t, $m)) {
                    $decoded = urldecode($m[1]);
                    if (filter_var($decoded, FILTER_VALIDATE_URL)) {
                        $line = $decoded;
                    }
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * suanfa9: 深度清理（广告域替换 + URL 规范化）
     */
    public function suanfa9($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        $data = $this->suanfa7($data);
        $data = $this->suanfa4($data);
        $data = $this->suanfa5($data);
        $data = $this->suanfa1($data);
        return $data;
    }

    /**
     * suanfasmall: 轻量检测字符串中的广告特征数
     */
    public function suanfasmall($data, $flag = 0) {
        if (!is_string($data)) return 0;
        $needles = array('ad_', 'ads/', 'ad/', '广告', 'promo', 'banner',
                          'pre-roll', 'mid-roll', 'post-roll', '贴片', '推广',
                          'adbreak', 'advert', 'adurl', 'ad_domain');
        $count = 0;
        foreach ($needles as $n) {
            if (stripos($data, $n) !== false) $count++;
        }
        return $count;
    }

    /**
     * suanfadyt: 电影天堂专有清洗（去除第三方广告接口）
     */
    public function suanfadyt($data, $flag = 0) {
        if (!is_string($data)) return $data;
        $blacklist = array(
            'api.dytt-ad.com', 'dytt8.net/ad', 'v.dytt.biz', 'dytt-api.cn',
            'dytt.ads.com', 'dytt-ad.com', 'dytt-jump.com',
        );
        foreach ($blacklist as $bad) {
            $data = str_ireplace($bad, 'v.qq.com', $data);
        }
        $data = $this->suanfa6($data);
        return $data;
    }

    /**
     * suanfaxiguang: 西瓜资源站专有清洗（去除 xigua 自己的广告轨道）
     */
    public function suanfaxiguang($data, $p1 = 0, $p2 = 0) {
        if (!is_string($data)) return $data;
        // 西瓜常见广告片段前缀
        $adPrefixes = array(
            'xg-ad-', 'xiguang-ad-', 'ixg-ad-', 'xigua-ad-',
            'xg_promo_', 'xg_premiere_', 'ixigua_ad', 'xgplayer_ad'
        );
        $lines = preg_split('/\r\n|\r|\n/', $data);
        $out = array();
        foreach ($lines as $line) {
            $skip = false;
            $t = trim($line);
            foreach ($adPrefixes as $p) {
                if (stripos($t, $p) !== false) { $skip = true; break; }
            }
            if (!$skip) $out[] = $line;
        }
        $data = implode("\n", $out);
        // 进一步用 suanfa5 做 URL 标准化
        $data = $this->suanfa5($data);
        return $data;
    }

    /**
     * 辅助：统计字符串中广告特征总数（用于评估去除效果）
     */
    public function countAdTokens($data) {
        if (!is_string($data)) return 0;
        $tokens = array('ad_', 'ads/', 'ad/', '广告', 'banner', 'promo',
                         'tracker', 'analytics', 'tongji', 'utm_', 'spm=',
                         'pre-roll', 'mid-roll', 'post-roll', 'advert',
                         'clickid', 'ad_domain', 'adapi', 'adcdn', '推广');
        $c = 0;
        foreach ($tokens as $t) {
            $c += substr_count(strtolower($data), strtolower($t));
        }
        return $c;
    }
}
