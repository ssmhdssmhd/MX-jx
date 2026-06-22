<?php
/**
 * 万能规则2 - 批量解析特征学习引擎
 *
 * 核心原理：
 *   - 利用多个专业去广告解析接口（如 jx.playerjy.com、yemu.xyz 等）批量解析同一视频
 *   - 多个解析源共同出现的片段/域名 = 正片特征（高可信度）
 *   - 某个解析源独有的片段/域名 = 广告特征
 *   - 通过统计多次解析结果建立稳定的特征库
 *
 * 低资源模式设计：
 *   - 默认并发 = 2（并发请求上限）
 *   - 最多调用 3 个解析源（不是全部）
 *   - 采样分析：对视频前 10 个片段
 *   - 优先使用缓存（避免重复请求）
 *   - 单任务/总任务双重超时保护
 *
 * 工作流程：
 *   1. 对输入视频 URL，向 N 个专业解析接口发起请求
 *   2. 从每个响应中提取 M3U8，解析出所有片段 URL、域名、路径
 *   3. 对比所有解析源，提取"共识内容"（>= min_votes 个解析源共享）
 *   4. 将学习到的特征写入 PatternFeatureDB
 *   5. 用学到的特征去清理当前 M3U8
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

require_once __DIR__ . '/AbstractAlgorithm.php';

class PatternFeatureCleaner extends AbstractAlgorithm
{
    public $id = 'pattern_feature_cleaner';
    public $priority = 20;  // 较高优先级，在 md5 之后执行
    public $enabled = true;
    public $scope = 'all';
    public $matchPatterns = [];

    // 配置参数（由 config/noad.php 中的 feat_* 配置注入
    private $maxSources = 3;             // 最多调用多少个解析源
    private $sourceTimeout = 15;               // 单个解析源超时（秒）
    private $totalTimeout = 60;              // 总处理超时（秒）
    private $minVotes = 2;                    // 最少投票数（至少 N 个解析源一致才信任
    private $lowResourceMode = true;         // 低资源模式（默认开启，差服务器也能跑
    private $maxConcurrency = 2;               // 并发请求上限（差服务器最多并发
    private $sampleSegmentCount = 15;     // 采样片段数（对每视频采样分析）
    private $enableLearn = true;               // 是否学习：每次解析自动学习新特征
    private $useProxy = true;             // 是否启用代理池
    private $debug = false;

    // 内部状态
    private $featureDB = null;
    private $parseSources = [];                 // 已配置的解析源列表
    private $stats = ['sources_called' => 0, 'sources_ok' => 0, 'segments_content' => 0, 'segments_removed' => 0, 'learned' => false];

    public function __construct($config = [])
    {
        $this->config = $config;

        // 从配置中读取参数
        $this->maxSources = $config['feat_max_sources'] ?? $this->maxSources;
        $this->sourceTimeout = $config['feat_source_timeout'] ?? $this->sourceTimeout;
        $this->totalTimeout = $config['feat_total_timeout'] ?? $this->totalTimeout;
        $this->minVotes = $config['feat_min_votes'] ?? $this->minVotes;
        $this->lowResourceMode = $config['feat_low_resource_mode'] ?? $this->lowResourceMode;
        $this->maxConcurrency = $config['feat_max_concurrency'] ?? $this->maxConcurrency;
        $this->sampleSegmentCount = $config['feat_sample_count'] ?? $this->sampleSegmentCount;
        $this->enableLearn = $config['feat_learn_enabled'] ?? $this->enableLearn;
        $this->useProxy = $config['feat_use_proxy'] ?? $this->useProxy;
        $this->debug = $config['feat_debug'] ?? false;

        // 加载数据库
        if (file_exists(__DIR__ . '/../core/PatternFeatureDB.php')) {
            require_once __DIR__ . '/../core/PatternFeatureDB.php';
        }
        if (class_exists('PatternFeatureDB')) {
            try {
                $this->featureDB = new PatternFeatureDB(null, $this->debug);
            } catch (Exception $e) {
                error_log('[PatternFeatureCleaner] 数据库初始化失败: ' . $e->getMessage());
            }
        }

        // 默认解析源（可被 config/noad.php 中 default_sources 覆盖
        $this->parseSources = [
            ['name' => 'Noad-主源',   'url' => 'https://jx.playerjy.com/?url={url}', 'timeout' => 8],
            ['name' => 'Noad-备源1',  'url' => 'https://www.yemu.xyz/?url={url}',     'timeout' => 8],
            ['name' => 'Noad-备源2',  'url' => 'https://jx.xmflv.com/?url={url}',    'timeout' => 8],
            ['name' => 'Noad-备源3',  'url' => 'https://jx.aidouer.net/?url={url}',  'timeout' => 10],
        ];

        // 若配置中有自定义解析源，覆盖
        if (!empty($config['default_sources']) && is_array($config['default_sources'])) {
            $this->parseSources = $config['default_sources'];
        }
    }

    public function name() { return '万能规则2 - 批量解析特征学习'; }
    public function description() { return '利用多个专业去广告解析接口批量解析同一视频，自动学习正片/广告特征，建立特征库去广告'; }
    public function version() { return '1.0.0'; }

    // ============== 核心处理 ==============

    public function apply($input, $context = [])
    {
        $videoUrl = $context['original_url'] ?? '';

        if (empty($videoUrl) || empty($this->featureDB)) {
            return $input;
        }

        // 检查是否需要处理（根据 enabled 开关）
        if (isset($this->config['feat_enabled']) && $this->config['feat_enabled'] === false) {
            return $input;
        }

        // 低资源模式：检查服务器健康度，超负载时跳过本规则
        if ($this->lowResourceMode && !$this->checkServerHealth()) {
            return $input;
        }

        // 步骤1: 批量解析 → 学习特征
        $sourceResults = $this->batchParse($videoUrl);

        // 步骤2: 学习特征（如启用）
        if ($this->enableLearn && count($sourceResults) >= 2) {
            $learnResult = $this->featureDB->learn($videoUrl, $sourceResults, $this->minVotes);
            $this->stats['learned'] = true;
            $this->stats['sources_ok'] = count($sourceResults);
            $this->stats['segments_content'] = $learnResult['content_segments'] ?? 0;
        }

        // 步骤3: 用学到的特征清理当前 M3U8（如果输入是 M3U8）
        if ($this->looksLikeM3U8($input)) {
            $output = $this->cleanM3U8ByFeatures($input);
            return $output;
        }

        // 不是 M3U8，原样返回
        return $input;
    }

    // ============== 批量解析 ==============

    /**
     * 批量调用多个解析源解析同一个视频
     *
     * @param string $videoUrl 原始视频 URL
     * @return array 每个解析源的结果: [[sourceName, [segUrls...], [domains...], [paths...]]
     */
    public function batchParse($videoUrl)
    {
        if (empty($videoUrl)) return [];

        // 1. 选择要使用的解析源（最多 maxSources 个）
        $sources = array_slice($this->parseSources, 0, $this->maxSources);
        if (count($sources) < 2) return [];  // 至少 2 个才有意义

        // 2. 低资源模式：根据服务器健康度动态调整
        $concurrency = $this->getOptimalConcurrency();

        // 3. 准备请求
        $requests = [];
        foreach ($sources as $s) {
            $url = str_replace('{url}', urlencode($videoUrl), $s['url']);
            $requests[] = [
                'name' => $s['name'],
                'url' => $url,
                'timeout' => min($s['timeout'], $this->sourceTimeout),
            ];
        }

        // 4. 并发请求（多 curl_multi（低资源模式：使用顺序请求（
        $results = [];
        $startTime = microtime(true);

        if ($concurrency >= 2 && function_exists('curl_multi_init')) {
            $results = $this->curlMultiRequest($requests, $concurrency);
        } else {
            $results = $this->sequentialRequest($requests);
        }

        // 5. 超时检查
        $elapsed = microtime(true) - $startTime;
        if ($elapsed > $this->totalTimeout) {
            error_log('[PatternFeatureCleaner] 总处理超时 (' . round($elapsed, 1) . 's)');
        }

        // 6. 解析每个响应 → 提取片段
        $sourceResults = [];
        foreach ($results as $r) {
            if (empty($r['content'])) continue;
            $parsed = $this->parseM3U8Content($r['content'], $r['url']);
            if (empty($parsed['segments'])) continue;
            $sourceResults[] = [
                $r['name'],
                $parsed['segments'],
                $parsed['domains'],
                $parsed['paths'],
            ];
        }

        $this->stats['sources_called'] = count($requests);
        return $sourceResults;
    }

    /**
     * 并发请求（curl_multi，并发上限）
     */
    private function curlMultiRequest($requests, $concurrency)
    {
        $results = [];
        $mh = curl_multi_init();
        $handles = [];

        // 分批处理（每批 concurrency 个
        $chunks = array_chunk($requests, $concurrency);

        foreach ($chunks as $chunk) {
            $handles = [];
            foreach ($chunk as $req) {
                $ch = curl_init($req['url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $req['timeout']);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_REFERER, $this->extractHost($req['url']));
                $handles[] = [$ch, $req];
                curl_multi_add_handle($mh, $ch);
            }

            // 执行并发请求
            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
                if ($active > 0) {
                    curl_multi_select($mh, 1.0); // 最多等 1s
                }
            } while ($mrc == CURLM_CALL_MULTI_PERFORM || $active);

            // 收集结果
            foreach ($handles as $h) {
                list($ch, $req) = $h;
                $content = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (!empty($content) && $httpCode >= 200 && $httpCode < 400) {
                    $results[] = ['name' => $req['name'], 'url' => $req['url'], 'content' => $content];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * 顺序请求（低资源/不可用 curl_multi 时使用）
     */
    private function sequentialRequest($requests)
    {
        $results = [];
        foreach ($requests as $req) {
            $ch = curl_init($req['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $req['timeout']);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!empty($content) && $httpCode >= 200 && $httpCode < 400) {
                $results[] = ['name' => $req['name'], 'url' => $req['url'], 'content' => $content];
            }
            // 低资源模式：请求之间加短延迟避免瞬间爆发
            if ($this->lowResourceMode) {
                usleep(100000); // 100ms
            }
        }
        return $results;
    }

    // ============== M3U8 解析 ==============

    /**
     * 解析 M3U8 内容 → 提取片段 URL / 域名 / 路径模式
     */
    private function parseM3U8Content($content, $baseUrl = '')
    {
        if (empty($content)) return ['segments' => [], 'domains' => [], 'paths' => []];

        // 过滤无效内容：检查是否为有效的 M3U8 或 JSON 响应
        if (!$this->isValidM3U8OrJSON($content)) {
            return ['segments' => [], 'domains' => [], 'paths' => []];
        }

        // 如果是 JSON，尝试解析
        if ($this->looksLikeJSON($content)) {
            return $this->parseJSONResponse($content, $baseUrl);
        }

        $lines = explode("\n", $content);
        $segUrls = [];
        $domains = [];
        $paths = [];

        $baseDomain = $this->extractHost($baseUrl);
        $segCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '.ts') === false && strpos($line, '.m3u8') === false && strpos($line, 'vod') === false) continue;

            // 解析片段 URL
            if (preg_match('/^https?:\/\//i', $line)) {
                $fullUrl = $line;
            } else {
                // 相对路径 → 补全
                if (!empty($baseUrl)) {
                    $fullUrl = rtrim($baseDomain, '/') . '/' . ltrim($line, '/');
                } else {
                    continue;
                }
            }

            $segUrls[] = $fullUrl;

            $domain = $this->extractHost($fullUrl);
            if (!in_array($domain, $domains)) $domains[] = $domain;

            $pathPattern = $this->extractPathPatternFromUrl($fullUrl);
            if ($pathPattern !== '' && !in_array($pathPattern, $paths)) $paths[] = $pathPattern;

            $segCount++;

            // 低资源模式：只采样前 N 个片段做学习（减少数据库写入）
            if ($this->lowResourceMode && $segCount >= $this->sampleSegmentCount) {
                break;
            }
        }

        return [
            'segments' => $segUrls,
            'domains' => $domains,
            'paths' => $paths,
        ];
    }

    /**
     * 检查内容是否为有效的 M3U8 或 JSON
     */
    private function isValidM3U8OrJSON($content)
    {
        $content = trim($content);
        if (empty($content)) return false;

        // 检查是否为有效的 M3U8（以 #EXTM3U 开头）
        if (strpos($content, '#EXTM3U') === 0) return true;

        // 检查是否为有效的 JSON（以 { 或 [ 开头）
        if (strpos($content, '{') === 0 || strpos($content, '[') === 0) return true;

        // 检查是否包含有效的片段 URL
        if (preg_match('/https?:\/\/[\w\-\.]+\.[a-z]{2,}\/.+\.(ts|m3u8)/i', $content)) return true;

        // 过滤错误消息
        $errorPatterns = [
            '/error|failed|timeout|denied|forbidden|not found|unavailable|service unavailable/i',
            '/upstream|gateway|proxy|502|503|504/i',
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                error_log('[PatternFeatureCleaner] 过滤错误响应: ' . substr($content, 0, 100));
                return false;
            }
        }

        return false;
    }

    /**
     * 检查内容是否看起来像 JSON
     */
    private function looksLikeJSON($content)
    {
        $content = trim($content);
        return strpos($content, '{') === 0 || strpos($content, '[') === 0;
    }

    /**
     * 解析 JSON 响应
     */
    private function parseJSONResponse($jsonContent, $baseUrl)
    {
        try {
            $data = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['segments' => [], 'domains' => [], 'paths' => []];
            }

            // 尝试从多种 JSON 结构中提取 M3U8 URL
            $m3u8Url = '';
            
            // 常见的解析接口响应格式
            if (!empty($data['url'])) $m3u8Url = $data['url'];
            elseif (!empty($data['data']['url'])) $m3u8Url = $data['data']['url'];
            elseif (!empty($data['video']['url'])) $m3u8Url = $data['video']['url'];
            elseif (!empty($data['result'])) $m3u8Url = $data['result'];
            elseif (!empty($data['m3u8'])) $m3u8Url = $data['m3u8'];

            if (!empty($m3u8Url) && filter_var($m3u8Url, FILTER_VALIDATE_URL)) {
                // 如果是 M3U8 URL，下载并解析
                return $this->downloadAndParseM3U8($m3u8Url);
            }
        } catch (Exception $e) {
            error_log('[PatternFeatureCleaner] JSON 解析失败: ' . $e->getMessage());
        }

        return ['segments' => [], 'domains' => [], 'paths' => []];
    }

    /**
     * 下载并解析 M3U8
     */
    private function downloadAndParseM3U8($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!empty($content) && $httpCode >= 200 && $httpCode < 400) {
            return $this->parseM3U8Content($content, $url);
        }

        return ['segments' => [], 'domains' => [], 'paths' => []];
    }

    // ============== M3U8 清理 ==============

    /**
     * 用学到的特征清理 M3U8
     *
     * 策略：
     *   - 若片段 URL 的域名在正片特征库中 → 保留
     *   - 若片段 URL 的域名在广告特征库中 → 删除
     *   - 未知域名的片段 → 保守策略：保留（避免误删正片
     */
    private function cleanM3U8ByFeatures($m3u8Content)
    {
        if (empty($m3u8Content)) return '';

        // 先收集所有片段 URL
        $lines = explode("\n", $m3u8Content);
        $segUrls = [];
        $lineMap = []; // 行号 → URL 的映射

        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) continue;
            if (strpos($trimmed, '.ts') === false && strpos($trimmed, '.m3u8') === false && strpos($trimmed, 'vod') === false) continue;
            if (preg_match('/^https?:\/\//i', $trimmed) || preg_match('/^\//', $trimmed)) {
                $segUrls[] = $trimmed;
                $lineMap[] = $idx;
            }
        }

        if (empty($segUrls)) return $m3u8Content;

        // 批量判断每个片段 URL 的类型
        $classifications = $this->featureDB->classifyUrls($segUrls);

        // 构建输出：删除标记为 'ad' 的片段
        $outputLines = [];
        $removeCount = 0;
        $removeNextLine = false;

        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);

            // 如果上一行是 #EXTINF 标记为广告，则删除这两行
            if ($removeNextLine) {
                $removeNextLine = false;
                // 跳过这行（片段 URL 行）
                continue;
            }

            // 如果这行是片段 URL，检查是否为广告
            if (in_array($trimmed, $segUrls, true)) {
                $verdict = $classifications[$trimmed] ?? 'unknown';
                if ($verdict === 'ad') {
                    $removeCount++;
                    // 删除前一行的 #EXTINF
                    if (count($outputLines) > 0 && strpos(end($outputLines) ?? '', '#EXTINF') === 0) {
                        array_pop($outputLines);  // 移除前一行 EXTINF
                    }
                    continue;  // 跳过当前片段行
                }
            }

            $outputLines[] = $line;
        }

        $this->stats['segments_removed'] = $removeCount;
        return implode("\n", $outputLines);
    }

    // ============== 辅助方法 ==============

    /**
     * 检查服务器健康度（低资源模式核心逻辑）
     *
     * @return bool true=健康可继续, false=过载跳过本规则
     */
    private function checkServerHealth()
    {
        // 1. CPU 使用率
        $cpuUsage = $this->getCpuUsage();
        if ($cpuUsage > 80) return false;

        // 2. 内存使用率
        $memUsage = $this->getMemoryUsage();
        if ($memUsage > 85) return false;

        // 3. 执行时间检查
        if (function_exists('ini_get')) {
            $maxExecTime = (int)ini_get('max_execution_time');
            if ($maxExecTime > 0 && $maxExecTime < 30) {
                // 执行时间非常短，降低并发
                $this->maxConcurrency = 1;
            }
        }

        return true;
    }

    /**
     * 获取最优并发数（根据服务器负载动态调整）
     *
     * @return int 并发数 1-4
     */
    private function getOptimalConcurrency()
    {
        if (!$this->lowResourceMode) return $this->maxConcurrency;

        $cpu = $this->getCpuUsage();
        $mem = $this->getMemoryUsage();

        // 简单规则：负载越高并发越低
        if ($cpu >= 70 || $mem >= 80) return 1;          // 高负载：单线程
        if ($cpu >= 50 || $mem >= 70) return 2;          // 中负载：2 并发
        return min(2, $this->maxConcurrency);            // 健康：默认 2
    }

    /**
     * 获取 CPU 使用率（兼容各种环境）
     */
    private function getCpuUsage()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (!empty($load) && $load[0] > 0) {
                return min(100, round($load[0] * 100, 0));
            }
        }
        if (PHP_OS === 'Linux' && is_readable('/proc/stat')) {
            $stat1 = @file_get_contents('/proc/stat');
            usleep(100000);
            $stat2 = @file_get_contents('/proc/stat');
            if ($stat1 !== false && $stat2 !== false) {
                preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1);
                preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2);
                if (isset($m1) && isset($m2)) {
                    $total1 = array_sum(array_slice($m1, 1));
                    $idle1 = (int)$m1[4];
                    $total2 = array_sum(array_slice($m2, 1));
                    $idle2 = (int)$m2[4];
                    if ($total2 > $total1) {
                        return (int)round(100 - (($idle2 - $idle1) / ($total2 - $total1) * 100), 0);
                    }
                }
            }
        }
        return 30; // 默认保守值
    }

    /**
     * 获取内存使用率
     */
    private function getMemoryUsage()
    {
        if (function_exists('memory_get_usage') && function_exists('ini_get')) {
            $used = memory_get_usage(true);
            $limit = ini_get('memory_limit');
            $limitBytes = $this->returnBytes($limit);
            if ($limitBytes > 0) {
                return min(100, round($used / $limitBytes * 100, 0));
            }
        }
        if (PHP_OS === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
                preg_match('/MemFree:\s+(\d+)/', $meminfo, $free);
                if (isset($total[1]) && isset($free[1])) {
                    $used = $total[1] - $free[1];
                    return min(100, round($used / $total[1] * 100, 0));
                }
            }
        }
        return 30;
    }

    private function returnBytes($val)
    {
        if (empty($val)) return 0;
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    private function looksLikeM3U8($content)
    {
        return strpos($content, '#EXTM3U') !== false || strpos($content, '.ts') !== false || strpos($content, 'EXTINF') !== false;
    }

    private function getRandomUA()
    {
        $uas = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Android 14; Mobile; rv:124.0) Gecko/124.0 Firefox/124.0',
        ];
        return $uas[array_rand($uas)];
    }

    private function extractHost($url)
    {
        $parts = parse_url($url);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $scheme = $parts['scheme'];
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $scheme . '://' . $host . $port . '/';
        }
        return '';
    }

    private function extractPathPatternFromUrl($url)
    {
        $parts = parse_url($url);
        if (empty($parts['path'] ?? '')) return '';
        $path = $parts['path'];
        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) >= 2) {
            return '/' . $segments[0] . '/' . $segments[1] . '/';
        } elseif (count($segments) >= 1) {
            return '/' . $segments[0] . '/';
        }
        return $path;
    }

    // ============== 对外接口 ==============

    public function getStats()
    {
        return $this->stats;
    }

    public function getFeatureDB()
    {
        return $this->featureDB;
    }

    public function getContentDomains()
    {
        return $this->featureDB ? $this->featureDB->getContentDomains() : [];
    }

    public function getAdDomains()
    {
        return $this->featureDB ? $this->featureDB->getAdDomains() : [];
    }

    public function getFeatureStats()
    {
        return $this->featureDB ? $this->featureDB->getStats() : ['enabled' => false];
    }

    public function markDomain($domain, $type)
    {
        return $this->featureDB ? $this->featureDB->markDomain($domain, $type) : false;
    }

    public function cleanupOld($days)
    {
        return $this->featureDB ? $this->featureDB->cleanupOldData($days) : 0;
    }
}
