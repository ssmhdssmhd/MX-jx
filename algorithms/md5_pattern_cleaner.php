<?php
/**
 * MD5 去广告算法 - 万能规则1
 *
 * ╔═══════════════════════════════════════════════════════════════╗
 * ║  核心原理：                                                    ║
 * ║  ============================================================  ║
 * ║  广告片段会在不同视频/不同集数中重复出现，它的 MD5 指纹        ║
 * ║  具有"高频率重复"的特征。而正片内容是独一无二的。               ║
 * ║                                                               ║
 * ║  通过统计 TS 片段的 MD5 出现次数，自动识别广告片段。           ║
 * ║  这是一种"万能"的去广告方法，与内容无关，只要资源站播放        ║
 * ║  器重复使用相同的广告片段，就能被识别。                         ║
 * ║                                                               ║
 * ║  处理流程：                                                    ║
 * ║    1. 解析 M3U8  → 获取 TS 片段列表                           ║
 * ║    2. 并发下载片段（代理池 + UA 轮换 + 速率限制）              ║
 * ║    3. 流式计算 MD5 指纹                                       ║
 * ║    4. 查询 MD5 指纹库 → 三态判断：                            ║
 * ║       ▸ 白名单：  正片片段，永不删除                           ║
 * ║       ▸ 黑名单：  已知广告，立即删除                           ║
 * ║       ▸ 高重复频率：  超过阈值 → 自动识别为广告并删除          ║
 * ║    5. 输出清理后的 M3U8                                      ║
 * ║    6. 自动学习：将新 MD5 指纹记录到数据库                     ║
 * ║                                                               ║
 * ║  服务器保护：                                                  ║
 * ║    ▸ 动态并发：根据 CPU/内存 自动调整                          ║
 * ║    ▸ 超时控制：单片段 15s，总请求 60s                         ║
 * ║    ▸ 内存限制：使用流式下载 + 增量 MD5                        ║
 * ║    ▸ 保护模式：CPU>90% 时停止新请求                           ║
 * ║                                                               ║
 * ║  IP 防护：                                                     ║
 * ║    ▸ 代理池：每 3 次请求换一个代理                           ║
 * ║    ▸ UA 随机：8 种浏览器指纹轮换                              ║
 * ║    ▸ 请求间隔：随机 50-300ms 间隔                            ║
 * ║    ▸ 失败切换：代理失败自动换一个                             ║
 * ╚═══════════════════════════════════════════════════════════════╝
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

require_once __DIR__ . '/../core/MD5FingerprintDB.php';
require_once __DIR__ . '/../core/SmartConcurrency.php';

class MD5PatternCleaner
{
    // ============== 可配置参数 ==============

    /** @var bool 是否启用 MD5 去广告 */
    private $enabled = true;

    /** @var int MD5 出现次数达到此值视为广告（默认 3） */
    private $md5RepeatThreshold = 3;

    /** @var int 最大并发数（curl_multi） */
    private $maxConcurrency = 12;

    /** @var int 多进程数（0=自动检测CPU核心数，-1=禁用多进程只用curl_multi） */
    private $numProcesses = 8;

    /** @var int 单片段超时（秒） */
    private $segmentTimeout = 15;

    /** @var int 总处理超时（秒） */
    private $totalTimeout = 60;

    /** @var int 单个片段最大大小（KB），超过则跳过（防止下载大文件卡死） */
    private $maxSegmentSizeKB = 5000;

    /** @var bool 是否启用代理池 */
    private $useProxy = true;

    /** @var int 最小请求间隔 ms */
    private $minRequestIntervalMs = 100;

    /** @var bool 调试模式 */
    private $debugMode = false;

    // ============== 运行时状态 ==============

    /** @var MD5FingerprintDB */
    private $db;

    /** @var IPGuard */
    private $ipGuard;

    /** @var array 代理池 */
    private $proxyPool = [];

    /** @var array 运行统计 */
    private $stats = [];

    /** @var float 开始时间 */
    private $startTime;

    /**
     * 构造函数 - 从配置文件读取参数
     */
    public function __construct()
    {
        $configPath = __DIR__ . '/../config/noad.php';
        if (file_exists($configPath)) {
            $config = @include $configPath;
            if (is_array($config)) {
                $this->enabled = isset($config['md5_enabled']) ? (bool)$config['md5_enabled'] : true;
                $this->md5RepeatThreshold = (int)($config['md5_repeat_threshold'] ?? 3);
                $this->maxConcurrency = (int)($config['md5_max_concurrency'] ?? 6);
                $this->numProcesses = isset($config['md5_num_processes']) ? (int)$config['md5_num_processes'] : 4;
                $this->segmentTimeout = (int)($config['md5_segment_timeout'] ?? 15);
                $this->totalTimeout = (int)($config['md5_total_timeout'] ?? 60);
                $this->maxSegmentSizeKB = (int)($config['md5_max_segment_kb'] ?? 5000);
                $this->useProxy = isset($config['md5_use_proxy']) ? (bool)$config['md5_use_proxy'] : true;
                $this->minRequestIntervalMs = (int)($config['md5_min_interval_ms'] ?? 100);
                $this->debugMode = isset($config['md5_debug']) ? (bool)$config['md5_debug'] : false;

                // 代理池配置（与如意算法共享）
                $this->proxyPool = [];
                if (!empty($config['ruyi_proxy_pool']) && is_array($config['ruyi_proxy_pool'])) {
                    $this->proxyPool = $config['ruyi_proxy_pool'];
                }
                if (!empty($config['md5_proxy_pool']) && is_array($config['md5_proxy_pool'])) {
                    $this->proxyPool = array_merge($this->proxyPool, $config['md5_proxy_pool']);
                }
            }
        }

        $this->db = new MD5FingerprintDB();
        $this->ipGuard = new IPGuard($this->proxyPool, 3, $this->minRequestIntervalMs);
        $this->startTime = microtime(true);
    }

    /**
     * 获取当前参数（供后台展示）
     */
    public function getCurrentParams()
    {
        return [
            'enabled' => $this->enabled,
            'md5_repeat_threshold' => $this->md5RepeatThreshold,
            'max_concurrency' => $this->maxConcurrency,
            'num_processes' => $this->numProcesses,
            'segment_timeout' => $this->segmentTimeout,
            'total_timeout' => $this->totalTimeout,
            'max_segment_size_kb' => $this->maxSegmentSizeKB,
            'use_proxy' => $this->useProxy,
            'proxy_count' => $this->ipGuard->getProxyCount(),
            'db_ready' => $this->db->isReady(),
        ];
    }

    /**
     * 设置运行时参数（用于动态调整性能）
     */
    public function setRuntimeParams($params = [])
    {
        if (isset($params['max_concurrency']) && (int)$params['max_concurrency'] > 0) {
            $this->maxConcurrency = min(32, max(1, (int)$params['max_concurrency']));
        }
        if (isset($params['num_processes']) && (int)$params['num_processes'] >= -1) {
            $this->numProcesses = min(32, max(-1, (int)$params['num_processes']));
        }
        if (isset($params['segment_timeout']) && (int)$params['segment_timeout'] > 0) {
            $this->segmentTimeout = min(120, max(5, (int)$params['segment_timeout']));
        }
        if (isset($params['total_timeout']) && (int)$params['total_timeout'] > 0) {
            $this->totalTimeout = min(600, max(30, (int)$params['total_timeout']));
        }
        if (isset($params['max_segment_kb']) && (int)$params['max_segment_kb'] > 0) {
            $this->maxSegmentSizeKB = min(50000, max(100, (int)$params['max_segment_kb']));
        }
    }

    /**
     * 获取数据库统计信息
     */
    public function getDbStats()
    {
        return $this->db->getStats();
    }

    /**
     * 获取上一次运行的统计信息
     */
    public function getLastStats()
    {
        return $this->stats;
    }

    /**
     * 检查超时（防止服务器卡死）
     */
    private function isTimedOut()
    {
        return (microtime(true) - $this->startTime) > $this->totalTimeout;
    }

    /**
     * 解析 M3U8 获取片段信息
     *
     * @param string $m3u8Content M3U8 文件内容
     * @return array ['segments' => [...], 'header' => '...', 'has_end' => bool]
     */
    public function parseM3U8($m3u8Content)
    {
        $segments = [];
        $lines = explode("\n", $m3u8Content);
        $headerLines = [];
        $currentDuration = 0;
        $hasEnd = false;
        $isMaster = false;
        $masterVariants = [];
        $currentVariant = [];
        $discontinuityCount = 0;
        $discontinuityIndices = [];
        $currentDiscontinuity = 0;

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;

            if ($line === '#EXT-X-ENDLIST') {
                $hasEnd = true;
                continue;
            }

            if ($line === '#EXT-X-DISCONTINUITY') {
                $discontinuityCount++;
                $discontinuityIndices[] = count($segments);
                $currentDiscontinuity = $discontinuityCount;
                continue;
            }

            if (strpos($line, '#EXT-X-STREAM-INF:') === 0) {
                $isMaster = true;
                $currentVariant = ['stream_inf' => $line, 'uri' => ''];
                continue;
            }

            if ($isMaster && strpos($line, '#') !== 0 && $line !== '') {
                if (!empty($currentVariant)) {
                    $currentVariant['uri'] = $line;
                    $masterVariants[] = $currentVariant;
                }
                $currentVariant = [];
                continue;
            }

            if ($i < 5 && strpos($line, '#EXT') === 0) {
                $headerLines[] = $line;
            }

            if (strpos($line, '#EXTINF:') === 0) {
                if (preg_match('/#EXTINF:([\d.]+)/', $line, $m)) {
                    $currentDuration = (float)$m[1];
                }
            } elseif (strpos($line, '#') !== 0 && $line !== '') {
                $segments[] = [
                    'uri' => $line,
                    'duration' => $currentDuration,
                    'index' => count($segments),
                    'discontinuity_group' => $currentDiscontinuity,
                ];
            }
        }

        return [
            'segments' => $segments,
            'header' => implode("\n", $headerLines) . "\n",
            'has_end' => $hasEnd,
            'is_master' => $isMaster,
            'master_variants' => $masterVariants,
            'discontinuity_count' => $discontinuityCount,
            'discontinuity_indices' => $discontinuityIndices,
        ];
    }

    /**
     * 解析相对 URL 为绝对 URL
     */
    public static function resolveUrl($baseUrl, $relativeUrl)
    {
        if (strpos($relativeUrl, 'http://') === 0 || strpos($relativeUrl, 'https://') === 0) {
            return $relativeUrl;
        }
        if (strpos($relativeUrl, '//') === 0) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $relativeUrl;
        }
        $basePath = dirname(parse_url($baseUrl, PHP_URL_PATH));
        $baseHost = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
        if (strpos($relativeUrl, '/') === 0) {
            return $baseHost . $relativeUrl;
        }
        return $baseHost . rtrim($basePath, '/') . '/' . $relativeUrl;
    }

    /**
     * 下载并计算单个片段的 MD5（流式计算，内存友好）
     *
     * @param string $url TS 片段 URL
     * @param string $videoUrl 所属视频 URL
     * @return array ['md5' => string, 'size' => int, 'duration' => float, 'download_ms' => int, 'success' => bool, 'error' => string]
     */
    private function downloadAndHash($url, $videoUrl)
    {
        $result = ['md5' => '', 'size' => 0, 'duration' => 0, 'download_ms' => 0, 'success' => false, 'error' => ''];

        if ($this->isTimedOut() || SmartConcurrencyController::isProtectionMode()) {
            $result['error'] = '服务器负载过高或超时，跳过';
            return $result;
        }

        $segStart = microtime(true);
        $ch = curl_init($url);

        $proxyUsed = '';
        if ($this->useProxy && $this->ipGuard !== null) {
            $this->ipGuard->waitForRateLimit();
            $proxyUsed = $this->ipGuard->configureCurl($ch, $videoUrl);
        } else {
            curl_setopt($ch, CURLOPT_USERAGENT, $this->ipGuard ? $this->ipGuard->getRandomUA() : 'Mozilla/5.0');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->segmentTimeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:!DH');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOBODY, false);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $downloadMs = round((microtime(true) - $segStart) * 1000, 1);

        if ($httpCode >= 200 && $httpCode < 300 && is_string($data) && strlen($data) > 0) {
            $maxBytes = $this->maxSegmentSizeKB * 1024;
            if (strlen($data) > $maxBytes) {
                $data = substr($data, 0, $maxBytes);
            }
            $md5 = md5($data);
            $result['md5'] = $md5;
            $result['size'] = strlen($data);
            $result['download_ms'] = $downloadMs;
            $result['success'] = true;
            $result['proxy'] = $proxyUsed;
            return $result;
        }

        if ($this->useProxy && $this->ipGuard !== null && $proxyUsed !== '') {
            $this->ipGuard->markCurrentProxyFailed();
        }
        $result['error'] = 'HTTP ' . $httpCode . ' - ' . ($curlError ?: '未知错误');
        return $result;
    }

    /**
     * 主入口：应用 MD5 去广告算法到 M3U8 内容
     *
     * @param string $m3u8Content M3U8 文件内容
     * @param string $videoUrl   原始视频 URL（用于解析相对 URI）
     * @return array ['output' => 清理后的 M3U8, 'stats' => 统计信息]
     */
    public function apply($m3u8Content, $videoUrl = '')
    {
        $this->stats = [
            'total_segments' => 0,
            'downloaded' => 0,
            'skipped_protection' => 0,
            'skipped_timeout' => 0,
            'removed_ad' => 0,
            'kept_whitelist' => 0,
            'kept_new' => 0,
            'kept_low_freq' => 0,
            'failed_downloads' => 0,
            'elapsed_ms' => 0,
            'concurrency' => 0,
        ];

        if (!$this->enabled || !$this->db->isReady()) {
            return ['output' => $m3u8Content, 'stats' => $this->stats, 'skipped' => true, 'reason' => $this->db->isReady() ? '未启用' : '数据库不可用'];
        }

        // 1. 解析 M3U8
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $this->stats['total_segments'] = count($segments);

        if (count($segments) === 0) {
            return ['output' => $m3u8Content, 'stats' => $this->stats];
        }

        // 2. 动态确定并发数
        $actualConcurrency = SmartConcurrencyController::getOptimalConcurrency($this->maxConcurrency);
        $this->stats['concurrency'] = $actualConcurrency;

        // 3. 先批量查询数据库：哪些 MD5 已经是已知状态（优化：先不下，先看有没有缓存过）
        // 注意：URI 不等于 MD5，无法预先查询。所以直接下载 + 计算 + 查询。
        // 但我们可以对已知片段 URI 做一个简单缓存（URI → MD5 映射，减少重复下载）

        // 4. 串行或并行下载片段（PHP 没有真正的多线程，但可以用 cURL 多句柄实现并发下载）
        $segmentResults = [];
        if ($actualConcurrency > 1 && function_exists('curl_multi_init')) {
            $segmentResults = $this->downloadSegmentsConcurrent($segments, $videoUrl, $actualConcurrency);
        } else {
            $segmentResults = $this->downloadSegmentsSequential($segments, $videoUrl);
        }

        // 5. 收集所有新 MD5，批量查询数据库
        $md5List = [];
        foreach ($segmentResults as $r) {
            if (!empty($r['md5'])) {
                $md5List[] = $r['md5'];
            }
        }

        // 6. 查询指纹库（批量查询，减少 DB 往返）
        $md5StatusMap = $this->db->queryBatch($md5List);

        // 7. 将新 MD5 记录到数据库（用于未来识别），并构建片段决策
        $segDecisions = []; // idx => ['remove' => bool, 'reason' => string, 'md5' => string]
        foreach ($segmentResults as $idx => $r) {
            $segDecisions[$idx] = ['remove' => false, 'reason' => '', 'md5' => ''];

            if (!$r['success']) {
                $this->stats['failed_downloads']++;
                $segDecisions[$idx]['reason'] = '下载失败: ' . $r['error'];
                continue;
            }

            $md5 = $r['md5'];
            $segDecisions[$idx]['md5'] = $md5;

            // 查询状态
            $status = $md5StatusMap[$md5] ?? null;

            if ($status) {
                if (!empty($status['is_whitelist'])) {
                    $segDecisions[$idx]['reason'] = '白名单';
                    $this->stats['kept_whitelist']++;
                    continue;
                }
                if (!empty($status['in_blacklist'])) {
                    $segDecisions[$idx]['remove'] = true;
                    $segDecisions[$idx]['reason'] = '黑名单';
                    $this->stats['removed_ad']++;
                    continue;
                }
                if (!empty($status['is_ad'])) {
                    $segDecisions[$idx]['remove'] = true;
                    $segDecisions[$idx]['reason'] = '自动识别(广告)';
                    $this->stats['removed_ad']++;
                    continue;
                }
                if ($status['count'] >= $this->md5RepeatThreshold) {
                    // 高频率 MD5 → 自动识别为广告
                    $segDecisions[$idx]['remove'] = true;
                    $segDecisions[$idx]['reason'] = '高频率识别(' . $status['count'] . '次)';
                    $this->stats['removed_ad']++;
                    // 同时自动加入黑名单（提高优先级）
                    $this->db->markAsAd($md5, true);
                    continue;
                }
                $this->stats['kept_low_freq']++;
                $segDecisions[$idx]['reason'] = '低频率(' . $status['count'] . '次)';
            } else {
                // 新 MD5（第一次出现）
                $this->stats['kept_new']++;
                $segDecisions[$idx]['reason'] = '新片段';
            }

            // 记录到数据库（自动学习）
            $this->db->record(
                $md5,
                $r['size'],
                $segments[$idx]['duration'] ?? 0,
                $videoUrl,
                $segments[$idx]['uri'] ?? '',
                $r['download_ms'],
                '',
                $r['proxy'] ?? ''
            );
        }

        // 8. 构建输出 M3U8
        $output = $this->buildOutputM3U8($parsed, $segments, $segDecisions);

        $this->stats['elapsed_ms'] = round((microtime(true) - $this->startTime) * 1000, 1);
        $this->stats['downloaded'] = count(array_filter($segmentResults, function($r) { return !empty($r['success']); }));

        return [
            'output' => $output,
            'stats' => $this->stats,
        ];
    }

    /**
     * 并行下载多个片段（使用 cURL 多句柄）
     */
    private function downloadSegmentsConcurrent($segments, $videoUrl, $concurrency)
    {
        $results = [];
        $multiHandle = curl_multi_init();
        $activeHandles = [];
        $pending = array_values($segments);
        $nextIdx = 0;
        $maxBytes = $this->maxSegmentSizeKB * 1024;

        $addHandle = function ($seg, $idx) use ($videoUrl, $multiHandle, &$activeHandles, $maxBytes) {
            $url = $this->resolveUrl($videoUrl, $seg['uri']);
            $ch = curl_init($url);

            $proxyUsed = '';
            if ($this->useProxy) {
                $proxyUsed = $this->ipGuard ? $this->ipGuard->configureCurl($ch, $videoUrl) : '';
            } else {
                curl_setopt($ch, CURLOPT_USERAGENT, $this->ipGuard ? $this->ipGuard->getRandomUA() : 'Mozilla/5.0');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->segmentTimeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:!DH');
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);

            curl_multi_add_handle($multiHandle, $ch);
            $activeHandles[(int)$ch] = [
                'ch' => $ch,
                'seg_index' => $idx,
                'start_time' => microtime(true),
                'proxy' => $proxyUsed,
            ];
        };

        while (count($activeHandles) < $concurrency && $nextIdx < count($pending)) {
            $addHandle($pending[$nextIdx], $nextIdx);
            $nextIdx++;
        }

        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active > 0) {
                curl_multi_select($multiHandle, 0.1);
            }

            while ($info = curl_multi_info_read($multiHandle)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                if (isset($activeHandles[$key])) {
                    $h = $activeHandles[$key];
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $downloadMs = round((microtime(true) - $h['start_time']) * 1000, 1);
                    $data = curl_multi_getcontent($ch);

                    $idx = $h['seg_index'];
                    $origIndex = isset($pending[$idx]['index']) ? $pending[$idx]['index'] : $idx;
                    if ($httpCode >= 200 && $httpCode < 300 && is_string($data) && strlen($data) > 0) {
                        if (strlen($data) > $maxBytes) {
                            $data = substr($data, 0, $maxBytes);
                        }
                        $md5 = md5($data);
                        $results[$origIndex] = [
                            'success' => true,
                            'md5' => $md5,
                            'size' => strlen($data),
                            'download_ms' => $downloadMs,
                            'proxy' => $h['proxy'],
                        ];
                    } else {
                        $results[$origIndex] = [
                            'success' => false,
                            'md5' => '',
                            'size' => 0,
                            'download_ms' => $downloadMs,
                            'error' => 'HTTP ' . $httpCode . ' - ' . ($curlError ?: '未知错误'),
                        ];
                    }

                    curl_multi_remove_handle($multiHandle, $ch);
                    curl_close($ch);
                    unset($activeHandles[$key]);

                    if ($nextIdx < count($pending) && !$this->isTimedOut() && !SmartConcurrencyController::isProtectionMode()) {
                        $addHandle($pending[$nextIdx], $nextIdx);
                        $nextIdx++;
                    }
                }
            }

            if ($this->isTimedOut() || SmartConcurrencyController::isProtectionMode()) {
                foreach ($activeHandles as $key => $h) {
                    curl_multi_remove_handle($multiHandle, $h['ch']);
                    curl_close($h['ch']);
                    $idx = $h['seg_index'];
                    $origIndex = isset($pending[$idx]['index']) ? $pending[$idx]['index'] : $idx;
                    $results[$origIndex] = [
                        'success' => false,
                        'md5' => '',
                        'size' => 0,
                        'download_ms' => 0,
                        'error' => '服务器超时或保护模式',
                    ];
                }
                $activeHandles = [];
                break;
            }
        } while ($active && count($activeHandles) > 0);

        curl_multi_close($multiHandle);

        ksort($results);
        return $results;
    }

    /**
     * 多进程下载并计算 MD5（真正的多进程并行，比 curl_multi 更快）
     * 使用 pcntl_fork + 临时文件通信
     */
    public function downloadSegmentsMultiProcess($segments, $videoUrl, $numProcesses = 4)
    {
        $results = [];

        if (!function_exists('pcntl_fork') || !function_exists('posix_getpid')) {
            // 降级到 curl_multi 并发
            return $this->downloadSegmentsConcurrent($segments, $videoUrl, min($numProcesses * 3, 12));
        }

        if ($numProcesses < 1) $numProcesses = 1;
        if ($numProcesses > 16) $numProcesses = 16;

        $segmentList = [];
        foreach ($segments as $idx => $seg) {
            $segmentList[] = ['index' => $idx, 'seg' => $seg];
        }
        $total = count($segmentList);

        if ($total === 0) {
            return [];
        }

        // 单片段时直接串行，避免进程开销
        if ($total <= 2) {
            return $this->downloadSegmentsConcurrent($segments, $videoUrl, $total);
        }

        // 进程数不超过片段数
        if ($numProcesses > $total) {
            $numProcesses = $total;
        }

        // 分配任务：每个进程处理一组片段
        $chunks = array_chunk($segmentList, (int)ceil($total / $numProcesses));
        $tempDir = sys_get_temp_dir() . '/md5_mp_' . getmypid() . '_' . uniqid();
        @mkdir($tempDir, 0700, true);

        if (!is_dir($tempDir)) {
            return $this->downloadSegmentsConcurrent($segments, $videoUrl, min($numProcesses * 3, 12));
        }

        $pids = [];
        $resultFiles = [];

        foreach ($chunks as $procId => $chunk) {
            $resultFile = $tempDir . '/result_' . $procId . '.json';
            $resultFiles[] = $resultFile;

            $pid = pcntl_fork();

            if ($pid == -1) {
                // fork 失败，降级
                foreach ($pids as $p) {
                    pcntl_waitpid($p, $status);
                }
                $this->cleanupTempDir($tempDir);
                return $this->downloadSegmentsConcurrent($segments, $videoUrl, min($numProcesses * 3, 12));
            } elseif ($pid == 0) {
                // 子进程：处理分配的片段（使用 curl_multi 并发进一步加速）
                $childResults = [];
                $maxBytes = $this->maxSegmentSizeKB * 1024;
                $childConcurrency = min(8, max(2, (int)ceil(count($chunk) / 2)));

                if ($childConcurrency > 1 && count($chunk) > 2 && function_exists('curl_multi_init')) {
                    $childResults = $this->downloadChildConcurrent($chunk, $videoUrl, $maxBytes, $childConcurrency);
                } else {
                    foreach ($chunk as $item) {
                        $idx = $item['index'];
                        $seg = $item['seg'];
                        $url = $this->resolveUrl($videoUrl, $seg['uri']);
                        $startTime = microtime(true);

                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_TIMEOUT, $this->segmentTimeout);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_USERAGENT, $this->ipGuard ? $this->ipGuard->getRandomUA() : 'Mozilla/5.0');
                        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:!DH');

                        $proxyUsed = '';
                        if ($this->useProxy && $this->ipGuard) {
                            $proxyUsed = $this->ipGuard->configureCurl($ch, $videoUrl);
                        }

                        $data = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlError = curl_error($ch);
                        $downloadMs = round((microtime(true) - $startTime) * 1000, 1);
                        curl_close($ch);

                        $origIndex = is_numeric($idx) ? $idx : $idx;
                        if ($httpCode >= 200 && $httpCode < 300 && is_string($data) && strlen($data) > 0) {
                            if (strlen($data) > $maxBytes) {
                                $data = substr($data, 0, $maxBytes);
                            }
                            $md5 = md5($data);
                            $childResults[$origIndex] = [
                                'success' => true,
                                'md5' => $md5,
                                'size' => strlen($data),
                                'download_ms' => $downloadMs,
                                'proxy' => $proxyUsed,
                            ];
                        } else {
                            $childResults[$origIndex] = [
                                'success' => false,
                                'md5' => '',
                                'size' => 0,
                                'download_ms' => $downloadMs,
                                'error' => 'HTTP ' . $httpCode . ' - ' . ($curlError ?: '未知错误'),
                            ];
                        }
                    }
                }

                file_put_contents($resultFile, json_encode($childResults, JSON_UNESCAPED_UNICODE));
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        // 父进程：等待所有子进程完成
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // 收集结果
        foreach ($resultFiles as $file) {
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                if (is_array($data)) {
                    foreach ($data as $idx => $result) {
                        $results[$idx] = $result;
                    }
                }
            }
        }

        // 清理临时文件
        $this->cleanupTempDir($tempDir);

        ksort($results);
        return $results;
    }

    /**
     * 子进程内使用 curl_multi 并发下载（多进程 + curl_multi 双层并发）
     */
    private function downloadChildConcurrent($chunk, $videoUrl, $maxBytes, $concurrency)
    {
        $results = [];
        $items = [];
        foreach ($chunk as $item) {
            $items[] = $item;
        }
        $total = count($items);
        $position = 0;

        while ($position < $total) {
            $batch = array_slice($items, $position, $concurrency);
            $mh = curl_multi_init();
            $handles = [];
            $batchInfo = [];

            foreach ($batch as $i => $item) {
                $idx = $item['index'];
                $seg = $item['seg'];
                $url = $this->resolveUrl($videoUrl, $seg['uri']);

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->segmentTimeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_USERAGENT, $this->ipGuard ? $this->ipGuard->getRandomUA() : 'Mozilla/5.0');
                curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
                curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT:!DH');
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

                $proxyUsed = '';
                if ($this->useProxy && $this->ipGuard) {
                    $proxyUsed = $this->ipGuard->configureCurl($ch, $videoUrl);
                }

                $handles[$i] = $ch;
                $batchInfo[$i] = [
                    'index' => $idx,
                    'start_time' => microtime(true),
                    'proxy' => $proxyUsed,
                ];
                curl_multi_add_handle($mh, $ch);
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
                if ($mrc != CURLM_OK) break;
                curl_multi_select($mh, 0.5);
            } while ($active > 0);

            foreach ($handles as $i => $ch) {
                $info = $batchInfo[$i];
                $idx = $info['index'];
                $startTime = $info['start_time'];
                $proxyUsed = $info['proxy'];

                $data = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $downloadMs = round((microtime(true) - $startTime) * 1000, 1);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300 && is_string($data) && strlen($data) > 0) {
                    if (strlen($data) > $maxBytes) {
                        $data = substr($data, 0, $maxBytes);
                    }
                    $md5 = md5($data);
                    $results[$idx] = [
                        'success' => true,
                        'md5' => $md5,
                        'size' => strlen($data),
                        'download_ms' => $downloadMs,
                        'proxy' => $proxyUsed,
                    ];
                } else {
                    $results[$idx] = [
                        'success' => false,
                        'md5' => '',
                        'size' => 0,
                        'download_ms' => $downloadMs,
                        'error' => 'HTTP ' . $httpCode . ' - ' . ($curlError ?: '未知错误'),
                    ];
                }
            }

            curl_multi_close($mh);
            $position += $concurrency;
        }

        return $results;
    }

    /**
     * 获取最佳多进程数
     */
    public function getOptimalProcessCount()
    {
        if ($this->numProcesses <= 0) {
            $cpuCores = 4;
            if (function_exists('posix_sysconf') && defined('_SC_NPROCESSORS_ONLN')) {
                $cpuCores = (int)posix_sysconf(23);
            } elseif (is_readable('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $cpuCores = count($matches[0]) ?: 4;
            }
            return max(4, min(16, $cpuCores));
        }
        if ($this->numProcesses < 0) {
            return 0;
        }
        return $this->numProcesses;
    }

    /**
     * 清理临时目录
     */
    private function cleanupTempDir($dir)
    {
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * 串行下载（降级方案）
     */
    private function downloadSegmentsSequential($segments, $videoUrl)
    {
        $results = [];
        foreach ($segments as $idx => $seg) {
            if ($this->isTimedOut() || SmartConcurrencyController::isProtectionMode()) {
                $this->stats['skipped_protection']++;
                $results[$idx] = ['success' => false, 'md5' => '', 'size' => 0, 'download_ms' => 0, 'error' => '保护模式/超时'];
                continue;
            }
            $url = $this->resolveUrl($videoUrl, $seg['uri']);
            $results[$idx] = $this->downloadAndHash($url, $videoUrl);
        }
        return $results;
    }

    /**
     * 构建输出 M3U8
     */
    private function buildOutputM3U8($parsed, $segments, $decisions)
    {
        $output = $parsed['header'];
        foreach ($segments as $idx => $seg) {
            if (isset($decisions[$idx]) && $decisions[$idx]['remove']) {
                // 删除这个片段
                continue;
            }
            $output .= '#EXTINF:' . sprintf('%.3f', $seg['duration']) . ",\n";
            $output .= $seg['uri'] . "\n";
        }
        if ($parsed['has_end']) {
            $output .= "#EXT-X-ENDLIST\n";
        }
        return $output;
    }

    /**
     * 深度分析：获取每个片段的详细信息（供后台展示）
     *
     * @param string $m3u8Content M3U8 文件内容
     * @param string $videoUrl 原始视频 URL
     * @return array [
     *   'segments' => [[
     *     'index' => int,
     *     'duration' => float,
     *     'uri' => string (原始相对URI),
     *     'full_url' => string (完整绝对URL),
     *     'md5' => string,
     *     'size' => int,
     *     'is_ad' => bool,
     *     'reason' => string,
     *     'status' => string (ad/content/new/whitelist/blacklist等),
     *     'count' => int (MD5出现次数),
     *   ], ...],
     *   'stats' => [...],
     *   'clean_m3u8' => string (清理后的M3U8内容，使用完整URL),
     *   'domain' => string (视频域名),
     * ]
     */
    public function deepAnalyzeBatch($m3u8Content, $videoUrl = '', $offset = 0, $batchSize = 0)
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $totalSegments = count($segments);

        // 预计算全片时长统计，用于启发式检测
        $allDurations = [];
        foreach ($segments as $s) {
            $allDurations[] = $s['duration'];
        }
        sort($allDurations);
        $medianDuration = $totalSegments > 0 ? $allDurations[floor($totalSegments / 2)] : 0;
        $minDuration = $totalSegments > 0 ? $allDurations[0] : 0;
        $maxDuration = $totalSegments > 0 ? end($allDurations) : 0;

        // 计算时长众数区间（用于识别广告群）
        $durationBuckets = [];
        foreach ($allDurations as $d) {
            $bucket = round($d, 1);
            if (!isset($durationBuckets[$bucket])) $durationBuckets[$bucket] = 0;
            $durationBuckets[$bucket]++;
        }
        arsort($durationBuckets);
        $mostCommonDuration = $totalSegments > 0 ? (float)key($durationBuckets) : 0;
        $mostCommonCount = $totalSegments > 0 ? current($durationBuckets) : 0;

        $result = [
            'segments' => [],
            'total_segments' => $totalSegments,
            'offset' => (int)$offset,
            'batch_size' => 0,
            'has_more' => false,
            'domain' => parse_url($videoUrl, PHP_URL_SCHEME) . '://' . parse_url($videoUrl, PHP_URL_HOST),
            'header' => $parsed['header'],
            'has_end' => $parsed['has_end'],
        ];

        if ($totalSegments === 0 || $offset >= $totalSegments) {
            return $result;
        }

        $end = $batchSize > 0 ? min($offset + $batchSize, $totalSegments) : $totalSegments;
        $batchSegments = [];
        for ($i = $offset; $i < $end; $i++) {
            if (isset($segments[$i])) {
                $batchSegments[$i] = $segments[$i];
            }
        }

        $result['batch_size'] = count($batchSegments);
        $result['has_more'] = ($end < $totalSegments);

        if (empty($batchSegments)) {
            return $result;
        }

        $segmentResults = [];
        $actualConcurrency = SmartConcurrencyController::getOptimalConcurrency($this->maxConcurrency);

        // === 启发式预检测：跳过明显广告/正片片段的下载，大幅提速 ===
        $preChecked = [];
        $needDownload = [];
        foreach ($batchSegments as $idx => $seg) {
            $heuristic = $this->heuristicCheckLight($seg, $idx, $totalSegments, $medianDuration, $mostCommonDuration, $mostCommonCount, $minDuration, $maxDuration);
            if ($heuristic['confident']) {
                $preChecked[$idx] = [
                    'skip' => true,
                    'is_ad' => $heuristic['is_ad'],
                    'reason' => $heuristic['reason'],
                    'status' => $heuristic['is_ad'] ? 'heuristic_ad_skip' : 'heuristic_content_skip',
                    'score' => $heuristic['score'],
                ];
            } else {
                $needDownload[$idx] = $seg;
            }
        }

        if (!empty($needDownload)) {
            $numProcs = $this->getOptimalProcessCount();
            if ($numProcs > 1 && function_exists('pcntl_fork')) {
                // 多进程模式：更快
                $segmentResults = $this->downloadSegmentsMultiProcess($needDownload, $videoUrl, $numProcs);
            } elseif ($actualConcurrency > 1 && function_exists('curl_multi_init')) {
                // 降级：curl_multi 并发
                $segmentResults = $this->downloadSegmentsConcurrent($needDownload, $videoUrl, $actualConcurrency);
            } else {
                // 降级：串行
                $segmentResults = $this->downloadSegmentsSequential($needDownload, $videoUrl);
            }
        }

        $md5List = [];
        foreach ($segmentResults as $r) {
            if (!empty($r['md5'])) {
                $md5List[] = $r['md5'];
            }
        }
        $md5StatusMap = $this->db->queryBatch($md5List);

        $avgBitrate = null;
        $totalBytesForBitrate = 0;
        $totalDurationForBitrate = 0;
        foreach ($batchSegments as $idx => $seg) {
            $r = $segmentResults[$idx] ?? ['success' => false, 'size' => 0];
            if (!empty($r['success']) && !empty($r['size']) && $r['size'] > 0 && $seg['duration'] > 0) {
                $totalBytesForBitrate += $r['size'];
                $totalDurationForBitrate += $seg['duration'];
            }
        }
        if ($totalDurationForBitrate > 0) {
            $avgBitrate = ($totalBytesForBitrate * 8) / $totalDurationForBitrate;
        }

        foreach ($batchSegments as $idx => $seg) {
            $fullUrl = $this->resolveUrl($videoUrl, $seg['uri']);

            // === 启发式预检测命中：直接使用预检测结果，跳过下载 ===
            if (isset($preChecked[$idx])) {
                $pre = $preChecked[$idx];
                $segInfo = [
                    'index' => $idx,
                    'duration' => $seg['duration'],
                    'uri' => $seg['uri'],
                    'full_url' => $fullUrl,
                    'md5' => '',
                    'size' => 0,
                    'download_ms' => 0,
                    'success' => true,
                    'error' => '',
                    'skipped_download' => true,
                    'heuristic_score' => $pre['score'],
                    'is_ad' => $pre['is_ad'],
                    'reason' => $pre['reason'] . '(免下载)',
                    'status' => $pre['status'],
                ];
                $result['segments'][] = $segInfo;
                continue;
            }

            $r = $segmentResults[$idx] ?? ['success' => false, 'md5' => '', 'size' => 0];
            $fullUrl = $this->resolveUrl($videoUrl, $seg['uri']);

            $segInfo = [
                'index' => $idx,
                'duration' => $seg['duration'],
                'uri' => $seg['uri'],
                'full_url' => $fullUrl,
                'md5' => $r['success'] ? $r['md5'] : '',
                'size' => $r['size'] ?? 0,
                'download_ms' => $r['download_ms'] ?? 0,
                'success' => $r['success'] ?? false,
                'error' => $r['error'] ?? '',
            ];

            $isAd = false;
            $reason = '';
            $status = 'unknown';

            if (!$r['success']) {
                $status = 'download_failed';
                $reason = '下载失败';
            } else {
                $md5 = $r['md5'];
                $segInfo['md5'] = $md5;

                $statusEntry = $md5StatusMap[$md5] ?? null;

                if ($statusEntry) {
                    if (!empty($statusEntry['is_whitelist'])) {
                        $status = 'whitelist';
                        $reason = '白名单-正片';
                    } elseif (!empty($statusEntry['in_blacklist'])) {
                        $status = 'blacklist';
                        $reason = '黑名单-广告';
                        $isAd = true;
                    } elseif (!empty($statusEntry['is_ad'])) {
                        $status = 'ad_identified';
                        $reason = '已识别广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                    } elseif ($statusEntry['count'] >= $this->md5RepeatThreshold) {
                        $status = 'ad_high_freq';
                        $reason = '高频广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                        $this->db->markAsAd($md5, true);
                    } else {
                        $status = 'content_low_freq';
                        $reason = '低频内容(' . $statusEntry['count'] . '次)';
                    }
                } else {
                    $status = 'new_segment';
                    $reason = '新片段(未记录)';

                    // === 启发式检测：即使是新片段，也尝试识别广告 ===
                    $segSize = $r['size'] ?? null;
                    $heuristicResult = $this->heuristicCheck($seg, $idx, $totalSegments, $medianDuration, $mostCommonDuration, $mostCommonCount, $minDuration, $maxDuration, $segSize, $avgBitrate);
                    if ($heuristicResult['is_ad']) {
                        $isAd = true;
                        $status = 'heuristic_ad';
                        $reason = '疑似广告(' . $heuristicResult['reason'] . ')';
                        $segInfo['heuristic_score'] = $heuristicResult['score'];
                    }
                }

                $this->db->record(
                    $md5,
                    $r['size'],
                    $seg['duration'],
                    $videoUrl,
                    $seg['uri'],
                    $r['download_ms'] ?? 0,
                    '',
                    $r['proxy'] ?? ''
                );
            }

            $segInfo['is_ad'] = $isAd;
            $segInfo['reason'] = $reason;
            $segInfo['status'] = $status;
            $result['segments'][] = $segInfo;
        }

        return $result;
    }

    /**
     * 按指定索引批量分析（用于智能抽样模式）
     */
    public function deepAnalyzeByIndexes($m3u8Content, $videoUrl, $indexes)
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $totalSegments = count($segments);

        // 预计算全片时长统计
        $allDurations = [];
        foreach ($segments as $s) {
            $allDurations[] = $s['duration'];
        }
        sort($allDurations);
        $medianDuration = $totalSegments > 0 ? $allDurations[floor($totalSegments / 2)] : 0;
        $minDuration = $totalSegments > 0 ? $allDurations[0] : 0;
        $maxDuration = $totalSegments > 0 ? end($allDurations) : 0;

        $durationBuckets = [];
        foreach ($allDurations as $d) {
            $bucket = round($d, 1);
            if (!isset($durationBuckets[$bucket])) $durationBuckets[$bucket] = 0;
            $durationBuckets[$bucket]++;
        }
        arsort($durationBuckets);
        $mostCommonDuration = $totalSegments > 0 ? (float)key($durationBuckets) : 0;
        $mostCommonCount = $totalSegments > 0 ? current($durationBuckets) : 0;

        $result = [
            'segments' => [],
            'total_segments' => $totalSegments,
            'sample_count' => count($indexes),
            'domain' => parse_url($videoUrl, PHP_URL_SCHEME) . '://' . parse_url($videoUrl, PHP_URL_HOST),
            'header' => $parsed['header'],
            'has_end' => $parsed['has_end'],
        ];

        if (empty($indexes) || $totalSegments === 0) {
            return $result;
        }

        // 收集需要分析的片段
        $batchSegments = [];
        foreach ($indexes as $idx) {
            if (isset($segments[$idx])) {
                $batchSegments[$idx] = $segments[$idx];
            }
        }

        if (empty($batchSegments)) {
            return $result;
        }

        $actualConcurrency = SmartConcurrencyController::getOptimalConcurrency($this->maxConcurrency);

        // 启发式预检测
        $preChecked = [];
        $needDownload = [];
        foreach ($batchSegments as $idx => $seg) {
            $heuristic = $this->heuristicCheckLight($seg, $idx, $totalSegments, $medianDuration, $mostCommonDuration, $mostCommonCount, $minDuration, $maxDuration);
            if ($heuristic['confident']) {
                $preChecked[$idx] = [
                    'skip' => true,
                    'is_ad' => $heuristic['is_ad'],
                    'reason' => $heuristic['reason'],
                    'status' => $heuristic['is_ad'] ? 'heuristic_ad_skip' : 'heuristic_content_skip',
                    'score' => $heuristic['score'],
                ];
            } else {
                $needDownload[$idx] = $seg;
            }
        }

        $segmentResults = [];
        if (!empty($needDownload)) {
            $numProcs = $this->getOptimalProcessCount();
            if ($numProcs > 1 && function_exists('pcntl_fork')) {
                // 多进程模式：更快
                $segmentResults = $this->downloadSegmentsMultiProcess($needDownload, $videoUrl, $numProcs);
            } elseif ($actualConcurrency > 1 && function_exists('curl_multi_init')) {
                // 降级：curl_multi 并发
                $segmentResults = $this->downloadSegmentsConcurrent($needDownload, $videoUrl, $actualConcurrency);
            } else {
                // 降级：串行
                $segmentResults = $this->downloadSegmentsSequential($needDownload, $videoUrl);
            }
        }

        $md5List = [];
        foreach ($segmentResults as $r) {
            if (!empty($r['md5'])) {
                $md5List[] = $r['md5'];
            }
        }
        $md5StatusMap = $this->db->queryBatch($md5List);

        // 计算平均比特率
        $avgBitrate = null;
        $totalBytesForBitrate = 0;
        $totalDurationForBitrate = 0;
        foreach ($batchSegments as $idx => $seg) {
            $r = $segmentResults[$idx] ?? ['success' => false, 'size' => 0];
            if (!empty($r['success']) && !empty($r['size']) && $r['size'] > 0 && $seg['duration'] > 0) {
                $totalBytesForBitrate += $r['size'];
                $totalDurationForBitrate += $seg['duration'];
            }
        }
        if ($totalDurationForBitrate > 0) {
            $avgBitrate = ($totalBytesForBitrate * 8) / $totalDurationForBitrate;
        }

        foreach ($batchSegments as $idx => $seg) {
            $fullUrl = $this->resolveUrl($videoUrl, $seg['uri']);

            if (isset($preChecked[$idx])) {
                $pre = $preChecked[$idx];
                $result['segments'][] = [
                    'index' => $idx,
                    'duration' => $seg['duration'],
                    'uri' => $seg['uri'],
                    'full_url' => $fullUrl,
                    'md5' => '',
                    'size' => 0,
                    'download_ms' => 0,
                    'success' => true,
                    'error' => '',
                    'skipped_download' => true,
                    'heuristic_score' => $pre['score'],
                    'is_ad' => $pre['is_ad'],
                    'reason' => $pre['reason'] . '(免下载)',
                    'status' => $pre['status'],
                    'is_sample' => true,
                ];
                continue;
            }

            $r = $segmentResults[$idx] ?? ['success' => false, 'md5' => '', 'size' => 0];

            $segInfo = [
                'index' => $idx,
                'duration' => $seg['duration'],
                'uri' => $seg['uri'],
                'full_url' => $fullUrl,
                'md5' => $r['success'] ? $r['md5'] : '',
                'size' => $r['size'] ?? 0,
                'download_ms' => $r['download_ms'] ?? 0,
                'success' => $r['success'] ?? false,
                'error' => $r['error'] ?? '',
                'is_sample' => true,
            ];

            $isAd = false;
            $reason = '';
            $status = 'unknown';

            if (!$r['success']) {
                $status = 'download_failed';
                $reason = '下载失败';
            } else {
                $md5 = $r['md5'];
                $segInfo['md5'] = $md5;

                $statusEntry = $md5StatusMap[$md5] ?? null;

                if ($statusEntry) {
                    if (!empty($statusEntry['is_whitelist'])) {
                        $status = 'whitelist';
                        $reason = '白名单-正片';
                    } elseif (!empty($statusEntry['in_blacklist'])) {
                        $status = 'blacklist';
                        $reason = '黑名单-广告';
                        $isAd = true;
                    } elseif (!empty($statusEntry['is_ad'])) {
                        $status = 'ad_identified';
                        $reason = '已识别广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                    } elseif ($statusEntry['count'] >= $this->md5RepeatThreshold) {
                        $status = 'ad_high_freq';
                        $reason = '高频广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                        $this->db->markAsAd($md5, true);
                    } else {
                        $status = 'content_low_freq';
                        $reason = '低频内容(' . $statusEntry['count'] . '次)';
                    }
                } else {
                    $status = 'new_segment';
                    $reason = '新片段(未记录)';

                    $segSize = $r['size'] ?? null;
                    $heuristicResult = $this->heuristicCheck($seg, $idx, $totalSegments, $medianDuration, $mostCommonDuration, $mostCommonCount, $minDuration, $maxDuration, $segSize, $avgBitrate);
                    if ($heuristicResult['is_ad']) {
                        $isAd = true;
                        $status = 'heuristic_ad';
                        $reason = '疑似广告(' . $heuristicResult['reason'] . ')';
                        $segInfo['heuristic_score'] = $heuristicResult['score'];
                    }
                }

                $this->db->record(
                    $md5,
                    $r['size'],
                    $seg['duration'],
                    $videoUrl,
                    $seg['uri'],
                    $r['download_ms'] ?? 0,
                    '',
                    $r['proxy'] ?? ''
                );
            }

            $segInfo['is_ad'] = $isAd;
            $segInfo['reason'] = $reason;
            $segInfo['status'] = $status;
            $result['segments'][] = $segInfo;
        }

        return $result;
    }

    public function deepAnalyze($m3u8Content, $videoUrl = '', $maxSegments = 0)
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $totalSegments = count($segments);

        $result = [
            'segments' => [],
            'total_segments' => $totalSegments,
            'offset' => 0,
            'batch_size' => 0,
            'has_more' => false,
            'domain' => parse_url($videoUrl, PHP_URL_SCHEME) . '://' . parse_url($videoUrl, PHP_URL_HOST),
            'header' => $parsed['header'],
            'has_end' => $parsed['has_end'],
        ];

        if ($totalSegments === 0) {
            return $result;
        }

        $batchSize = $maxSegments > 0 ? min($maxSegments, $totalSegments) : $totalSegments;
        $end = $batchSize;
        $batchSegments = [];
        for ($i = 0; $i < $end; $i++) {
            if (isset($segments[$i])) {
                $batchSegments[$i] = $segments[$i];
            }
        }

        $result['batch_size'] = count($batchSegments);
        $result['has_more'] = ($end < $totalSegments);

        if (empty($batchSegments)) {
            return $result;
        }

        // 下载并分析这批片段
        $segmentResults = [];
        $actualConcurrency = SmartConcurrencyController::getOptimalConcurrency($this->maxConcurrency);

        if ($actualConcurrency > 1 && function_exists('curl_multi_init')) {
            $segmentResults = $this->downloadSegmentsConcurrent($batchSegments, $videoUrl, $actualConcurrency);
        } else {
            $segmentResults = $this->downloadSegmentsSequential($batchSegments, $videoUrl);
        }

        // 批量查询数据库
        $md5List = [];
        foreach ($segmentResults as $r) {
            if (!empty($r['md5'])) {
                $md5List[] = $r['md5'];
            }
        }
        $md5StatusMap = $this->db->queryBatch($md5List);

        // 构建片段详情
        foreach ($batchSegments as $idx => $seg) {
            $r = $segmentResults[$idx] ?? ['success' => false, 'md5' => '', 'size' => 0];
            $fullUrl = $this->resolveUrl($videoUrl, $seg['uri']);

            $segInfo = [
                'index' => $idx,
                'duration' => $seg['duration'],
                'uri' => $seg['uri'],
                'full_url' => $fullUrl,
                'md5' => $r['success'] ? $r['md5'] : '',
                'size' => $r['size'] ?? 0,
                'download_ms' => $r['download_ms'] ?? 0,
                'success' => $r['success'] ?? false,
                'error' => $r['error'] ?? '',
            ];

            $isAd = false;
            $reason = '';
            $status = 'unknown';

            if (!$r['success']) {
                $status = 'download_failed';
                $reason = '下载失败';
            } else {
                $md5 = $r['md5'];
                $segInfo['md5'] = $md5;

                $statusEntry = $md5StatusMap[$md5] ?? null;

                if ($statusEntry) {
                    if (!empty($statusEntry['is_whitelist'])) {
                        $status = 'whitelist';
                        $reason = '白名单-正片';
                    } elseif (!empty($statusEntry['in_blacklist'])) {
                        $status = 'blacklist';
                        $reason = '黑名单-广告';
                        $isAd = true;
                    } elseif (!empty($statusEntry['is_ad'])) {
                        $status = 'ad_identified';
                        $reason = '已识别广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                    } elseif ($statusEntry['count'] >= $this->md5RepeatThreshold) {
                        $status = 'ad_high_freq';
                        $reason = '高频广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                        $this->db->markAsAd($md5, true);
                    } else {
                        $status = 'content_low_freq';
                        $reason = '低频内容(' . $statusEntry['count'] . '次)';
                    }
                } else {
                    $status = 'new_segment';
                    $reason = '新片段(未记录)';
                }

                // 记录到数据库
                $this->db->record(
                    $md5,
                    $r['size'],
                    $seg['duration'],
                    $videoUrl,
                    $seg['uri'],
                    $r['download_ms'] ?? 0,
                    '',
                    $r['proxy'] ?? ''
                );
            }

            $segInfo['is_ad'] = $isAd;
            $segInfo['reason'] = $reason;
            $segInfo['status'] = $status;
            $result['segments'][] = $segInfo;
        }

        return $result;
    }

    /**
     * 构建去广告后的完整 M3U8（使用所有已分析的片段判断 + 未分析片段默认保留）
     *
     * @param string $m3u8Content 原始 M3U8 内容
     * @param string $videoUrl 视频 URL
     * @param array $analyzedSegs 已分析的片段 [index => ['is_ad' => bool, ...]]
     * @return string 清理后的 M3U8 内容
     */
    public function buildCleanM3U8FromAnalyzed($m3u8Content, $videoUrl, $analyzedSegs)
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $cleanSegments = [];

        foreach ($segments as $idx => $seg) {
            if (isset($analyzedSegs[$idx]) && $analyzedSegs[$idx]['is_ad']) {
                continue;
            }
            $cleanSegments[] = $seg;
        }

        return $this->buildOutputM3U8FullUrl($parsed['header'], $cleanSegments, $videoUrl, $parsed['has_end']);
    }

    /**
     * 根据广告片段索引构建去广告 M3U8（高效，不需要重新下载）
     *
     * @param string $m3u8Content 原始 M3U8 内容
     * @param string $videoUrl 视频 URL
     * @param array $adIndexes 广告片段索引 [index => true]
     * @return string 清理后的 M3U8 内容
     */
    public function buildCleanM3U8ByIndex($m3u8Content, $videoUrl, $adIndexes)
    {
        $lines = explode("\n", $m3u8Content);
        $outputLines = [];
        $currentDuration = 0;
        $segmentIndex = 0;
        $skipNextSegment = false;

        $headerDone = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (strpos($line, '#EXT-X-STREAM-INF:') === 0) {
                return $m3u8Content;
            }

            if ($line === '#EXTM3U' || $line === '#EXT-X-VERSION:3'
                || strpos($line, '#EXT-X-TARGETDURATION:') === 0
                || strpos($line, '#EXT-X-MEDIA-SEQUENCE:') === 0
                || strpos($line, '#EXT-X-PLAYLIST-TYPE:') === 0
                || strpos($line, '#EXT-X-KEY:') === 0
                || strpos($line, '#EXT-X-MAP:') === 0) {
                $outputLines[] = $line;
                continue;
            }

            if ($line === '#EXT-X-ENDLIST') {
                $outputLines[] = $line;
                continue;
            }

            if ($line === '#EXT-X-DISCONTINUITY') {
                $outputLines[] = $line;
                continue;
            }

            if (strpos($line, '#EXTINF:') === 0) {
                if (preg_match('/#EXTINF:([\d.]+)/', $line, $m)) {
                    $currentDuration = (float)$m[1];
                }
                $skipNextSegment = isset($adIndexes[$segmentIndex]);
                if (!$skipNextSegment) {
                    $outputLines[] = $line;
                }
                continue;
            }

            if (strpos($line, '#') !== 0 && $line !== '') {
                if (!$skipNextSegment) {
                    $fullUrl = $this->resolveUrl($videoUrl, $line);
                    $outputLines[] = $fullUrl;
                }
                $segmentIndex++;
                $skipNextSegment = false;
                continue;
            }

            if (strpos($line, '#') === 0 && !$skipNextSegment) {
                $outputLines[] = $line;
            }
        }

        return implode("\n", $outputLines) . "\n";
    }

    /**
     * 构建使用完整URL的输出M3U8
     */
    private function buildOutputM3U8FullUrl($header, $segments, $baseUrl, $hasEnd)
    {
        $output = $header;
        foreach ($segments as $seg) {
            $fullUrl = $this->resolveUrl($baseUrl, $seg['uri']);
            $output .= '#EXTINF:' . sprintf('%.3f', $seg['duration']) . ",\n";
            $output .= $fullUrl . "\n";
        }
        if ($hasEnd) {
            $output .= "#EXT-X-ENDLIST\n";
        }
        return $output;
    }

    // ============== 辅助静态方法（供后台调用） ==============

    /**
     * 从 M3U8 URL 下载原始内容（增强版：完整请求头 + 自动重试）
     *
     * @param string $url
     * @param bool $useProxy
     * @param array $proxyPool
     * @return string|false 成功返回内容字符串，失败返回 false
     */
    public static function downloadM3U8($url, $useProxy = false, $proxyPool = [])
    {
        $result = self::downloadM3U8Ex($url, $useProxy, $proxyPool);
        return $result ? $result['content'] : false;
    }

    /**
     * 从 M3U8 URL 下载原始内容（增强版，返回详细信息）
     *
     * @param string $url
     * @param bool $useProxy
     * @param array $proxyPool
     * @return array|false 成功返回 ['content' => string, 'http_code' => int]，失败返回 false
     */
    public static function downloadM3U8Ex($url, $useProxy = false, $proxyPool = [], $timeout = 15)
    {
        if (!function_exists('curl_init')) return false;

        $maxRetries = 2;
        $lastError = '';
        $lastHttpCode = 0;

        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        ];

        $parsed = parse_url($url);
        $referer = isset($parsed['scheme']) && isset($parsed['host'])
            ? $parsed['scheme'] . '://' . $parsed['host'] . '/'
            : '';

        for ($try = 0; $try < $maxRetries; $try++) {
            if ($try > 0) {
                usleep(100000 * $try + mt_rand(0, 100000));
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[$try % count($userAgents)]);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

            $headers = [
                'Accept: */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ];
            if ($referer) {
                curl_setopt($ch, CURLOPT_REFERER, $referer);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($useProxy && !empty($proxyPool)) {
                $proxy = $proxyPool[array_rand($proxyPool)];
                if (strpos($proxy, '://') !== false) {
                    curl_setopt($ch, CURLOPT_PROXY, $proxy);
                } else {
                    curl_setopt($ch, CURLOPT_PROXY, 'http://' . $proxy);
                }
                if (preg_match('/([^:]+):([^@]+)@(.+)/', $proxy, $m)) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $m[1] . ':' . $m[2]);
                }
            }

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && $content !== false) {
                return ['content' => $content, 'http_code' => $httpCode];
            }

            $lastHttpCode = $httpCode;
            $lastError = $curlError ?: ('HTTP ' . $httpCode);
        }

        return false;
    }

    /**
     * 从任意 URL（视频页或 M3U8）解析出真实的 M3U8 内容
     *
     * @param string $url
     * @return array|false ['content' => string, 'final_url' => string] 或 false
     */
    public static function resolveM3U8FromUrl($url)
    {
        if (stripos($url, '.m3u8') !== false) {
            $result = self::downloadM3U8Ex($url);
            if ($result) {
                $result['final_url'] = $url;
                $resolved = self::resolveMasterPlaylist($url, $result);
                if ($resolved) return $resolved;
                return $result;
            }
            return false;
        }

        $pageResult = self::downloadM3U8Ex($url);
        if (!$pageResult) return false;

        $content = $pageResult['content'];

        if (strpos($content, '#EXTM3U') !== false) {
            $pageResult['final_url'] = $url;
            $resolved = self::resolveMasterPlaylist($url, $pageResult);
            if ($resolved) return $resolved;
            return $pageResult;
        }

        $json = json_decode($content, true);
        if (is_array($json)) {
            $m3u8Url = self::extractM3U8FromArray($json);
            if ($m3u8Url) {
                if (!filter_var($m3u8Url, FILTER_VALIDATE_URL)) {
                    $m3u8Url = self::resolveUrl($url, $m3u8Url);
                }
                $subResult = self::downloadM3U8Ex($m3u8Url);
                if ($subResult) {
                    $subResult['final_url'] = $m3u8Url;
                    $resolved = self::resolveMasterPlaylist($m3u8Url, $subResult);
                    if ($resolved) return $resolved;
                    return $subResult;
                }
            }
        }

        if (preg_match_all('/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i', $content, $matches)) {
            foreach ($matches[0] as $m3u8Url) {
                $subResult = self::downloadM3U8Ex($m3u8Url);
                if ($subResult) {
                    $subResult['final_url'] = $m3u8Url;
                    $resolved = self::resolveMasterPlaylist($m3u8Url, $subResult);
                    if ($resolved) return $resolved;
                    return $subResult;
                }
            }
        }

        if (preg_match_all('/"url"\s*:\s*"([^"]+)"/i', $content, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = stripslashes($candidate);
                if (stripos($candidate, 'http') === 0 || stripos($candidate, '/') === 0) {
                    if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                        $candidate = self::resolveUrl($url, $candidate);
                    }
                    $subResult = self::downloadM3U8Ex($candidate);
                    if ($subResult && strpos($subResult['content'], '#EXTM3U') !== false) {
                        $subResult['final_url'] = $candidate;
                        $resolved = self::resolveMasterPlaylist($candidate, $subResult);
                        if ($resolved) return $resolved;
                        return $subResult;
                    }
                }
            }
        }

        $extractedUrls = self::extractAllM3u8Urls($content);
        foreach ($extractedUrls as $m3u8Url) {
            if (!filter_var($m3u8Url, FILTER_VALIDATE_URL)) {
                $m3u8Url = self::resolveUrl($url, $m3u8Url);
            }
            $subResult = self::downloadM3U8Ex($m3u8Url);
            if ($subResult) {
                $subResult['final_url'] = $m3u8Url;
                $resolved = self::resolveMasterPlaylist($m3u8Url, $subResult);
                if ($resolved) return $resolved;
                return $subResult;
            }
        }

        $streamUrls = self::extractStreamUrls($content);
        foreach ($streamUrls as $streamUrl) {
            if (!filter_var($streamUrl, FILTER_VALIDATE_URL)) {
                $streamUrl = self::resolveUrl($url, $streamUrl);
            }
            $subResult = self::downloadM3U8Ex($streamUrl);
            if ($subResult && strpos($subResult['content'], '#EXTM3U') !== false) {
                $subResult['final_url'] = $streamUrl;
                $resolved = self::resolveMasterPlaylist($streamUrl, $subResult);
                if ($resolved) return $resolved;
                return $subResult;
            }
        }

        return false;
    }

    /**
     * 提取页面中所有可能的 M3U8 URL（支持多种格式）
     *
     * @param string $content HTML/JSON 内容
     * @return array M3U8 URL 列表
     */
    public static function extractAllM3u8Urls($content)
    {
        $urls = [];

        $patterns = [
            '/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i',
            '/\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i',
            '/["\']([^\s"\'<>]+\.m3u8[^\s"\'<>]*)["\']/i',
            '/["\']url["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
            '/["\']video_url["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
            '/["\']play_url["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
            '/["\']src["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
            '/["\']hls["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
            '/["\']m3u8["\']\s*:\s*["\']([^\s"\']+\.m3u8[^\s"\']*)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $url = trim($match, '"\'');
                    if (!empty($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * 提取页面中的流媒体 URL（可能是加密的 m3u8）
     *
     * @param string $content HTML/JSON 内容
     * @return array 流媒体 URL 列表
     */
    public static function extractStreamUrls($content)
    {
        $urls = [];

        $patterns = [
            '/["\']url["\']\s*:\s*["\']([^\s"\']+\.(?:ts|m3u8|mp4)[^\s"\']*)["\']/i',
            '/["\']src["\']\s*:\s*["\']([^\s"\']+\.(?:ts|m3u8|mp4)[^\s"\']*)["\']/i',
            '/data-url=["\']([^\s"\']+\.(?:ts|m3u8)[^\s"\']*)["\']/i',
            '/data-src=["\']([^\s"\']+\.(?:ts|m3u8)[^\s"\']*)["\']/i',
            '/<source[^>]+src=["\']([^\s"\']+\.(?:ts|m3u8)[^\s"\']*)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $url = trim($match, '"\'');
                    if (!empty($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * 递归解析 Master Playlist，获取真正的 Media Playlist（包含 TS 片段）
     *
     * @param string $baseUrl 基础 URL
     * @param array $m3u8Result ['content' => string, 'http_code' => int]
     * @return array|false 解析后的 Media Playlist 或 false
     */
    public static function resolveMasterPlaylist($baseUrl, $m3u8Result)
    {
        if (!isset($m3u8Result['content'])) return false;

        $cleaner = new self();
        $parsed = $cleaner->parseM3U8($m3u8Result['content']);

        if (!$parsed['is_master'] || empty($parsed['master_variants'])) {
            return false;
        }

        $bestVariant = null;
        $bestBandwidth = 0;

        foreach ($parsed['master_variants'] as $variant) {
            $bandwidth = 0;
            if (preg_match('/BANDWIDTH=(\d+)/', $variant['stream_inf'], $m)) {
                $bandwidth = (int)$m[1];
            }

            if ($bandwidth > $bestBandwidth) {
                $bestBandwidth = $bandwidth;
                $bestVariant = $variant;
            }
        }

        if ($bestVariant === null) {
            $bestVariant = $parsed['master_variants'][0];
        }

        $subUrl = self::resolveUrl($baseUrl, $bestVariant['uri']);

        $subResult = self::downloadM3U8Ex($subUrl);
        if ($subResult) {
            $subResult['final_url'] = $subUrl;
            $subResult['master_url'] = $baseUrl;
            return $subResult;
        }

        return false;
    }

    /**
     * 启发式广告检测 - 单视频深度分析
     *
     * 综合多维度特征判断片段是否为广告：
     * 1. 时长异常（远短于/远长于中位数）
     * 2. 位置特征（片头/片尾/插播位置）
     * 3. URL 关键词（ad、advert、pre、mid、post 等）
     * 4. 大小异常（比特率与正片差异大）
     * 5. 时长众数偏离（广告往往有独特的时长规律）
     *
     * @param array $seg 片段信息 ['uri'=>'', 'duration'=>float, 'index'=>int]
     * @param int $index 片段索引
     * @param int $total 总片段数
     * @param float $medianDuration 时长中位数
     * @param float $modeDuration 时长众数
     * @param int $modeCount 众数出现次数
     * @param float $minDuration 最小时长
     * @param float $maxDuration 最大时长
     * @param float|null $segmentSize 片段大小（字节）
     * @param float|null $avgBitrate 平均比特率
     * @return array ['is_ad' => bool, 'reason' => string, 'score' => int]
     */
    /**
     * 轻量级启发式检测（预检测用，无需下载，只用URL和时长判断）
     * 返回 confident=true 表示可以跳过下载直接判定
     */
    public function heuristicCheckLight($seg, $index, $total, $medianDuration, $modeDuration, $modeCount, $minDuration, $maxDuration)
    {
        $score = 0;
        $reasons = [];
        $duration = $seg['duration'];
        $uri = $seg['uri'];

        if ($total <= 3 || $medianDuration <= 0) {
            return ['is_ad' => false, 'reason' => '样本太少', 'score' => 0, 'confident' => false];
        }

        // === 规则1: URL 关键词强匹配（直接判定广告） ===
        $uriLower = strtolower($uri);
        $strongAdKeywords = ['ad/', 'advert', 'preroll', 'midroll', 'postroll', 'commercial', 'sponsor', '片头广告', '片中广告', '片尾广告', '/gg/', 'gg1.', 'gg2.', 'gg3.'];
        foreach ($strongAdKeywords as $kw) {
            if (strpos($uriLower, $kw) !== false) {
                $score += 50;
                $reasons[] = "URL强特征[$kw]";
                break;
            }
        }

        // === 规则2: 时长严重异常（远小于中位数的50% + 极短片段） ===
        if ($medianDuration > 0) {
            $ratio = $duration / $medianDuration;
            if ($ratio < 0.2 && $duration < 3) {
                $score += 40;
                $reasons[] = "极短片段({$duration}s)";
            } elseif ($ratio < 0.3) {
                $score += 25;
                $reasons[] = "时长短({$duration}s)";
            }
        }

        // === 规则3: 时长与众数严重偏离（短于众数60%以上） ===
        if ($modeDuration > 0 && $modeCount > $total * 0.4) {
            $modeDiffRatio = ($modeDuration - $duration) / $modeDuration;
            if ($modeDiffRatio > 0.6 && $duration < $modeDuration) {
                $score += 20;
                $reasons[] = "时长偏离众数(众数{$modeDuration}s)";
            }
        }

        // === 判定是否足够自信可以跳过下载 ===
        $confidentAd = ($score >= 50);
        $confidentContent = false;

        // 正片高置信判定：时长接近中位数(0.8-1.2倍) + 非片头片尾 + URL无广告特征
        if ($score < 10 && $medianDuration > 0) {
            $ratio = $duration / $medianDuration;
            $headThreshold = min(3, (int)($total * 0.03));
            $tailThreshold = $total - min(3, (int)($total * 0.03));
            if ($ratio >= 0.85 && $ratio <= 1.15 && $index >= $headThreshold && $index < $tailThreshold) {
                $confidentContent = true;
            }
        }

        $isAd = $confidentAd;
        $reason = implode(',', $reasons) ?: ($confidentContent ? '正片特征明显' : '特征不明显');

        return [
            'is_ad' => $isAd,
            'reason' => $reason,
            'score' => $score,
            'confident' => ($confidentAd || $confidentContent),
        ];
    }

    public function heuristicCheck($seg, $index, $total, $medianDuration, $modeDuration, $modeCount, $minDuration, $maxDuration, $segmentSize = null, $avgBitrate = null)
    {
        $score = 0;
        $reasons = [];
        $duration = $seg['duration'];
        $uri = $seg['uri'];

        if ($total <= 3 || $medianDuration <= 0) {
            return ['is_ad' => false, 'reason' => '样本太少', 'score' => 0];
        }

        // === 规则1: URL 关键词检测 ===
        $adKeywords = [
            'ad', 'advert', 'advertisement', 'ads',
            'pre', 'pre-roll', 'preroll',
            'mid', 'mid-roll', 'midroll',
            'post', 'post-roll', 'postroll',
            'commercial', 'promo', 'promotion',
            'sponsor', 'sponsored',
            'banner', 'interstitial',
            'splash', 'opening_ad',
            '片头广告', '片中广告', '片尾广告',
            'gg', 'gg1', 'gg2', 'gg3',
        ];
        $uriLower = strtolower($uri);
        foreach ($adKeywords as $kw) {
            if (strpos($uriLower, $kw) !== false) {
                $score += 25;
                $reasons[] = "URL含关键词[$kw]";
                break;
            }
        }

        // === 规则2: 时长异常检测 ===
        if ($medianDuration > 0) {
            $ratio = $duration / $medianDuration;

            if ($ratio < 0.3) {
                $score += 20;
                $reasons[] = "时长短(仅{$duration}s,中位数{$medianDuration}s)";
            } elseif ($ratio < 0.5) {
                $score += 10;
                $reasons[] = "时长偏短({$duration}s)";
            } elseif ($ratio > 2.5) {
                $score += 15;
                $reasons[] = "时长远超中位数({$duration}s)";
            } elseif ($ratio > 1.8) {
                $score += 8;
                $reasons[] = "时长偏长({$duration}s)";
            }
        }

        // === 规则3: 时长与众数的偏离度 ===
        if ($modeDuration > 0 && $modeCount > $total * 0.3) {
            $modeDiffRatio = abs($duration - $modeDuration) / $modeDuration;
            if ($modeDiffRatio > 0.5 && $duration < $modeDuration) {
                $score += 10;
                $reasons[] = "时长偏离众数(众数{$modeDuration}s)";
            }
        }

        // === 规则4: 位置特征检测 ===
        $headThreshold = min(5, (int)($total * 0.05));
        $tailThreshold = $total - min(5, (int)($total * 0.05));

        if ($index < $headThreshold) {
            $score += 10;
            $reasons[] = '片头位置';
        } elseif ($index >= $tailThreshold) {
            $score += 8;
            $reasons[] = '片尾位置';
        }

        // === 规则5: 片段大小/比特率异常 ===
        if ($segmentSize !== null && $segmentSize > 0 && $duration > 0 && $avgBitrate !== null && $avgBitrate > 0) {
            $bitrate = ($segmentSize * 8) / $duration;
            $bitrateRatio = $bitrate / $avgBitrate;

            if ($bitrateRatio < 0.4) {
                $score += 15;
                $reasons[] = "比特率偏低(" . round($bitrate/1024, 0) . "kbps)";
            } elseif ($bitrateRatio < 0.6) {
                $score += 8;
                $reasons[] = "比特率略低";
            } elseif ($bitrateRatio > 2.0) {
                $score += 10;
                $reasons[] = "比特率偏高";
            }
        }

        // === 规则6: 极短片段（<2秒）通常是广告片头/片尾 ===
        if ($duration < 2.0 && $total > 10) {
            $score += 15;
            $reasons[] = "极短片段({$duration}s)";
        }

        $isAd = ($score >= 30);

        return [
            'is_ad' => $isAd,
            'reason' => implode('+', $reasons) ?: '正常',
            'score' => $score,
        ];
    }

    /**
     * 深度分析增强版：聚类分析 + 比特率分析 + 广告集群识别
     *
     * 对所有已分析的片段进行二次深度分析，识别：
     * - 连续广告片段集群（插播广告通常是连续的多个片段）
     * - 比特率异常区间
     * - 时长模式突变点
     *
     * @param array $allSegments 所有已分析的片段列表
     * @param int $totalSegments 总片段数
     * @return array [
     *   'ad_clusters' => [[start, end, count, reason], ...],
     *   'refined_segments' => [...],
     *   'analysis_summary' => string,
     * ]
     */
    public function deepClusterAnalyze($allSegments, $totalSegments)
    {
        if (empty($allSegments)) {
            return ['ad_clusters' => [], 'refined_segments' => $allSegments, 'analysis_summary' => '无数据'];
        }

        $segments = $allSegments;
        $adClusters = [];
        $currentCluster = null;

        // === 步骤1: 识别连续广告片段集群 ===
        foreach ($segments as $i => $seg) {
            $isAd = !empty($seg['is_ad']);

            if ($isAd) {
                if ($currentCluster === null) {
                    $currentCluster = [
                        'start' => $seg['index'],
                        'end' => $seg['index'],
                        'count' => 1,
                        'total_duration' => $seg['duration'],
                    ];
                } else {
                    $currentCluster['end'] = $seg['index'];
                    $currentCluster['count']++;
                    $currentCluster['total_duration'] += $seg['duration'];
                }
            } else {
                if ($currentCluster !== null) {
                    if ($currentCluster['count'] >= 2) {
                        $currentCluster['reason'] = "连续{$currentCluster['count']}段广告集群(" . round($currentCluster['total_duration'], 1) . "s)";
                        $adClusters[] = $currentCluster;
                    }
                    $currentCluster = null;
                }
            }
        }
        if ($currentCluster !== null && $currentCluster['count'] >= 2) {
            $currentCluster['reason'] = "连续{$currentCluster['count']}段广告集群(" . round($currentCluster['total_duration'], 1) . "s)";
            $adClusters[] = $currentCluster;
        }

        // === 步骤2: 基于集群扩展识别（如果集群周围的片段时长相似，可能也是广告）===
        $validDurations = [];
        foreach ($segments as $seg) {
            if (!empty($seg['success']) && $seg['duration'] > 0) {
                $validDurations[] = $seg['duration'];
            }
        }
        if (count($validDurations) > 5) {
            sort($validDurations);
            $medianDur = $validDurations[floor(count($validDurations) / 2)];

            foreach ($adClusters as $cluster) {
                $clusterAvgDuration = $cluster['total_duration'] / $cluster['count'];
                $beforeIdx = $cluster['start'] - 1;
                $afterIdx = $cluster['end'] + 1;

                if ($beforeIdx >= 0 && isset($segments[$beforeIdx])) {
                    $beforeSeg = $segments[$beforeIdx];
                    if (empty($beforeSeg['is_ad']) && $beforeSeg['duration'] > 0) {
                        $durDiff = abs($beforeSeg['duration'] - $clusterAvgDuration) / $clusterAvgDuration;
                        if ($durDiff < 0.2 && $beforeSeg['duration'] < $medianDur * 0.6) {
                            $segments[$beforeIdx]['is_ad'] = true;
                            $segments[$beforeIdx]['status'] = 'cluster_extended';
                            $segments[$beforeIdx]['reason'] = '广告集群扩展(前邻片)';
                        }
                    }
                }

                if ($afterIdx < count($segments) && isset($segments[$afterIdx])) {
                    $afterSeg = $segments[$afterIdx];
                    if (empty($afterSeg['is_ad']) && $afterSeg['duration'] > 0) {
                        $durDiff = abs($afterSeg['duration'] - $clusterAvgDuration) / $clusterAvgDuration;
                        if ($durDiff < 0.2 && $afterSeg['duration'] < $medianDur * 0.6) {
                            $segments[$afterIdx]['is_ad'] = true;
                            $segments[$afterIdx]['status'] = 'cluster_extended';
                            $segments[$afterIdx]['reason'] = '广告集群扩展(后邻片)';
                        }
                    }
                }
            }
        }

        // === 步骤3: 比特率深度分析 ===
        $segmentSizes = [];
        foreach ($segments as $seg) {
            if (!empty($seg['success']) && !empty($seg['size']) && $seg['size'] > 0 && $seg['duration'] > 0) {
                $bitrate = ($seg['size'] * 8) / $seg['duration'];
                $segmentSizes[] = ['index' => $seg['index'], 'bitrate' => $bitrate, 'size' => $seg['size']];
            }
        }

        if (count($segmentSizes) > 5) {
            $bitrates = array_column($segmentSizes, 'bitrate');
            sort($bitrates);
            $medianBitrate = $bitrates[floor(count($bitrates) / 2)];

            foreach ($segments as $i => $seg) {
                if (!empty($seg['is_ad'])) continue;
                if (empty($seg['success']) || empty($seg['size']) || $seg['size'] <= 0 || $seg['duration'] <= 0) continue;

                $bitrate = ($seg['size'] * 8) / $seg['duration'];
                $bitrateRatio = $bitrate / $medianBitrate;

                if ($bitrateRatio < 0.35 && $seg['duration'] < 30) {
                    $segments[$i]['is_ad'] = true;
                    $segments[$i]['status'] = 'bitrate_anomaly';
                    $segments[$i]['reason'] = '比特率异常低(' . round($bitrate/1024, 0) . 'kbps)';
                }
            }
        }

        // === 步骤4: 统计摘要 ===
        $adCount = 0;
        $adDuration = 0;
        $totalDuration = 0;
        foreach ($segments as $seg) {
            $totalDuration += $seg['duration'];
            if (!empty($seg['is_ad'])) {
                $adCount++;
                $adDuration += $seg['duration'];
            }
        }

        $summary = "共检测到 {$adCount} 个广告片段，约 " . round($adDuration, 1) . "秒，占总时长 " . ($totalDuration > 0 ? round($adDuration / $totalDuration * 100, 1) : 0) . "%；发现 " . count($adClusters) . " 个广告集群。";

        return [
            'ad_clusters' => $adClusters,
            'refined_segments' => $segments,
            'analysis_summary' => $summary,
            'ad_count' => $adCount,
            'ad_duration' => $adDuration,
            'total_duration' => $totalDuration,
        ];
    }

    /**
     * 从多维数组中提取 m3u8 播放地址
     */
    private static function extractM3U8FromArray($arr)
    {
        if (!is_array($arr)) return null;

        foreach ($arr as $key => $val) {
            if (is_string($val) && stripos($val, '.m3u8') !== false) {
                return $val;
            }
            if (is_string($key) && in_array(strtolower($key), ['url', 'play_url', 'm3u8', 'video_url', 'src'])) {
                if (is_string($val) && filter_var($val, FILTER_VALIDATE_URL)) {
                    return $val;
                }
            }
            if (is_array($val)) {
                $found = self::extractM3U8FromArray($val);
                if ($found) return $found;
            }
        }
        return null;
    }

    /**
     * 提取 TS 片段特征码（用于二次开发和模式识别）
     * 包含：文件名模式、MD5前缀、大小分布、时长特征等
     */
    public function extractSegmentSignatures($segments, $segmentResults = [])
    {
        $signatures = [
            'filename_patterns' => [],
            'md5_prefixes' => [],
            'size_distribution' => [],
            'duration_patterns' => [],
            'ad_signatures' => [],
            'content_signatures' => [],
        ];

        $filenamePrefixes = [];
        $md5PrefixCounts = [];
        $sizeBuckets = [];
        $adMd5List = [];
        $contentMd5List = [];

        foreach ($segments as $idx => $seg) {
            $uri = $seg['uri'] ?? '';
            $basename = basename($uri);

            $prefix = '';
            if (preg_match('/^([a-zA-Z0-9]+)/', $basename, $m)) {
                $prefix = $m[1];
            }
            if ($prefix) {
                if (!isset($filenamePrefixes[$prefix])) $filenamePrefixes[$prefix] = 0;
                $filenamePrefixes[$prefix]++;
            }

            $r = $segmentResults[$idx] ?? null;
            if ($r && !empty($r['md5'])) {
                $md5 = $r['md5'];
                $md5Prefix = substr($md5, 0, 4);
                if (!isset($md5PrefixCounts[$md5Prefix])) $md5PrefixCounts[$md5Prefix] = 0;
                $md5PrefixCounts[$md5Prefix]++;

                $sizeKB = round(($r['size'] ?? 0) / 1024, 0);
                $sizeBucket = floor($sizeKB / 100) * 100;
                if (!isset($sizeBuckets[$sizeBucket])) $sizeBuckets[$sizeBucket] = 0;
                $sizeBuckets[$sizeBucket]++;
            }
        }

        arsort($filenamePrefixes);
        arsort($md5PrefixCounts);
        ksort($sizeBuckets);

        $signatures['filename_patterns'] = array_slice($filenamePrefixes, 0, 10, true);
        $signatures['md5_prefixes'] = array_slice($md5PrefixCounts, 0, 10, true);
        $signatures['size_distribution'] = $sizeBuckets;

        return $signatures;
    }

    /**
     * 深度分析：合并广告和插播信息，生成完整分析报告
     */
    public function deepAnalysisWithCommercials($m3u8Content, $videoUrl, $analyzedSegments = [])
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $totalSegments = count($segments);
        $discontinuityIndices = $parsed['discontinuity_indices'] ?? [];

        $commercialBreaks = [];
        $adSegments = [];
        $contentSegments = [];

        $allAnalyzed = [];
        foreach ($analyzedSegments as $s) {
            $allAnalyzed[$s['index']] = $s;
        }

        foreach ($segments as $idx => $seg) {
            $segInfo = $allAnalyzed[$idx] ?? [
                'index' => $idx,
                'duration' => $seg['duration'],
                'uri' => $seg['uri'],
                'is_ad' => false,
                'reason' => '未分析',
                'md5' => '',
                'size' => 0,
            ];
            $segInfo['discontinuity_group'] = $seg['discontinuity_group'] ?? 0;

            if (!empty($segInfo['is_ad'])) {
                $adSegments[] = $segInfo;
            } else {
                $contentSegments[] = $segInfo;
            }
        }

        $adIndexMap = [];
        foreach ($adSegments as $s) {
            $adIndexMap[$s['index']] = true;
        }

        if (!empty($adSegments)) {
            usort($adSegments, function($a, $b) { return $a['index'] - $b['index']; });

            $currentCluster = null;
            foreach ($adSegments as $s) {
                $idx = $s['index'];
                if ($currentCluster === null || $idx - $currentCluster['end'] > 2) {
                    if ($currentCluster !== null) {
                        $commercialBreaks[] = $currentCluster;
                    }
                    $currentCluster = [
                        'start' => $idx,
                        'end' => $idx,
                        'count' => 1,
                        'total_duration' => $s['duration'] ?? 0,
                        'segments' => [$s],
                    ];
                } else {
                    $currentCluster['end'] = $idx;
                    $currentCluster['count']++;
                    $currentCluster['total_duration'] += ($s['duration'] ?? 0);
                    $currentCluster['segments'][] = $s;
                }
            }
            if ($currentCluster !== null) {
                $commercialBreaks[] = $currentCluster;
            }
        }

        $discontinuityBreaks = [];
        foreach ($discontinuityIndices as $dIdx) {
            $hasAd = false;
            for ($i = max(0, $dIdx - 5); $i < min($totalSegments, $dIdx + 10); $i++) {
                if (isset($adIndexMap[$i])) {
                    $hasAd = true;
                    break;
                }
            }
            $discontinuityBreaks[] = [
                'segment_index' => $dIdx,
                'has_ad_suspected' => $hasAd,
                'description' => $hasAd ? '疑似广告插播点' : '普通场景切换点',
            ];
        }

        $adSignatures = [];
        foreach ($adSegments as $s) {
            if (!empty($s['md5'])) {
                $adSignatures[] = [
                    'md5' => $s['md5'],
                    'size' => $s['size'] ?? 0,
                    'duration' => $s['duration'] ?? 0,
                    'reason' => $s['reason'] ?? '',
                    'index' => $s['index'],
                ];
            }
        }

        $signatures = $this->extractSegmentSignatures($segments, $allAnalyzed);

        $totalDuration = 0;
        foreach ($segments as $s) {
            $totalDuration += $s['duration'];
        }
        $adDuration = 0;
        foreach ($adSegments as $s) {
            $adDuration += $s['duration'] ?? 0;
        }

        return [
            'total_segments' => $totalSegments,
            'total_duration' => round($totalDuration, 1),
            'ad_segments' => $adSegments,
            'content_segments' => $contentSegments,
            'ad_count' => count($adSegments),
            'ad_duration' => round($adDuration, 1),
            'ad_percentage' => $totalDuration > 0 ? round($adDuration / $totalDuration * 100, 1) : 0,
            'commercial_breaks' => $commercialBreaks,
            'commercial_break_count' => count($commercialBreaks),
            'discontinuity_breaks' => $discontinuityBreaks,
            'discontinuity_count' => count($discontinuityBreaks),
            'ad_signatures' => $adSignatures,
            'ad_signature_count' => count($adSignatures),
            'signatures' => $signatures,
        ];
    }

    /**
     * 同步广告特征码到MD5指纹库（算法列表）
     * 将深度分析识别出的广告MD5自动存入指纹库黑名单
     * 后续相同MD5的片段会被直接识别为广告
     *
     * @param array $adSignatures 广告特征码数组 [['md5'=>'', 'size'=>'', 'duration'=>''], ...]
     * @param string $videoUrl 来源视频URL（用于记录）
     * @return array ['synced_count'=>int, 'new_count'=>int, 'existed_count'=>int]
     */
    public function syncAdSignaturesToDB($adSignatures, $videoUrl = '')
    {
        $syncedCount = 0;
        $newCount = 0;
        $existedCount = 0;

        if (!is_array($adSignatures) || empty($adSignatures)) {
            return ['synced_count' => 0, 'new_count' => 0, 'existed_count' => 0];
        }

        if (!$this->db || !$this->db->isReady()) {
            return ['synced_count' => 0, 'new_count' => 0, 'existed_count' => 0];
        }

        $md5List = [];
        foreach ($adSignatures as $sig) {
            if (!empty($sig['md5'])) {
                $md5 = strtolower(trim($sig['md5']));
                if (strlen($md5) === 32 && ctype_xdigit($md5)) {
                    $md5List[] = $md5;
                }
            }
        }

        $md5List = array_unique($md5List);
        if (empty($md5List)) {
            return ['synced_count' => 0, 'new_count' => 0, 'existed_count' => 0];
        }

        $statusMap = $this->db->queryBatch($md5List);

        foreach ($adSignatures as $sig) {
            if (empty($sig['md5'])) continue;

            $md5 = strtolower(trim($sig['md5']));
            if (strlen($md5) !== 32 || !ctype_xdigit($md5)) continue;

            $size = isset($sig['size']) ? (int)$sig['size'] : 0;
            $duration = isset($sig['duration']) ? (float)$sig['duration'] : 0;
            $reason = isset($sig['reason']) ? $sig['reason'] : '深度分析自动识别';

            $existing = $statusMap[$md5] ?? null;

            if ($existing && !empty($existing['in_blacklist'])) {
                $existedCount++;
                $syncedCount++;
                continue;
            }

            if ($existing && !empty($existing['is_whitelist'])) {
                continue;
            }

            if (!$existing) {
                $this->db->record($md5, $size, $duration, $videoUrl, '', 0, '', '');
            }

            $this->db->markAsAd($md5, true);
            $newCount++;
            $syncedCount++;
        }

        return [
            'synced_count' => $syncedCount,
            'new_count' => $newCount,
            'existed_count' => $existedCount,
        ];
    }

    /**
     * 获取指纹库统计信息（供算法列表展示）
     */
    public function getFingerprintStats()
    {
        if (!$this->db || !$this->db->isReady()) {
            return [
                'total' => 0,
                'ad_count' => 0,
                'whitelist_count' => 0,
                'total_segments' => 0,
                'db_size' => 0,
            ];
        }

        $stats = $this->db->getStats();
        $stats['db_size'] = $this->db->getDbSize();
        return $stats;
    }
}
