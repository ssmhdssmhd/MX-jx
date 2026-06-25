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

    /** @var int 最大并发下载数（会被动态调整） */
    private $maxConcurrency = 6;

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
            'segment_timeout' => $this->segmentTimeout,
            'total_timeout' => $this->totalTimeout,
            'max_segment_size_kb' => $this->maxSegmentSizeKB,
            'use_proxy' => $this->useProxy,
            'proxy_count' => $this->ipGuard->getProxyCount(),
            'db_ready' => $this->db->isReady(),
        ];
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
    private function parseM3U8($m3u8Content)
    {
        $segments = [];
        $lines = explode("\n", $m3u8Content);
        $headerLines = [];
        $currentDuration = 0;
        $hasEnd = false;

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;

            if ($i < 5 && strpos($line, '#EXT') === 0) {
                $headerLines[] = $line;
            }

            if ($line === '#EXT-X-ENDLIST') {
                $hasEnd = true;
                continue;
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
                ];
            }
        }

        return [
            'segments' => $segments,
            'header' => implode("\n", $headerLines) . "\n",
            'has_end' => $hasEnd,
        ];
    }

    /**
     * 解析相对 URL 为绝对 URL
     */
    private function resolveUrl($baseUrl, $relativeUrl)
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

        // 配置 cURL（代理 + UA + 速率限制）
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
        }

        // 流式处理：使用 WRITEFUNCTION 边下载边计算 MD5，不保存整个文件
        $hashCtx = hash_init('md5');
        $totalBytes = 0;
        $maxBytes = $this->maxSegmentSizeKB * 1024;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$hashCtx, &$totalBytes, $maxBytes) {
            $len = strlen($data);
            if ($totalBytes + $len > $maxBytes) {
                // 超过大小限制，停止下载，但保留已计算的数据
                $partial = substr($data, 0, $maxBytes - $totalBytes);
                hash_update($hashCtx, $partial);
                $totalBytes = $maxBytes;
                return 0; // 返回 0 停止下载（curl 会报错，但我们忽略）
            }
            hash_update($hashCtx, $data);
            $totalBytes += $len;
            return $len;
        });

        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $downloadMs = round((microtime(true) - $segStart) * 1000, 1);

        if ($httpCode >= 200 && $httpCode < 300 && $totalBytes > 0) {
            // 如果因大小限制而停止，也视为成功（部分哈希就够用）
            $md5 = hash_final($hashCtx);
            $result['md5'] = $md5;
            $result['size'] = $totalBytes;
            $result['download_ms'] = $downloadMs;
            $result['success'] = true;
            $result['proxy'] = $proxyUsed;
            return $result;
        }

        // 失败：标记代理失败
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
        $pending = $segments;
        $nextIdx = 0;

        // 分批填充初始连接
        while (count($activeHandles) < $concurrency && $nextIdx < count($pending)) {
            $seg = $pending[$nextIdx];
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
            }

            $hashCtx = hash_init('md5');
            $totalBytesRef = 0;
            $maxBytes = $this->maxSegmentSizeKB * 1024;
            $proxyRef = $proxyUsed;

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$hashCtx, &$totalBytesRef, $maxBytes) {
                $len = strlen($data);
                if ($totalBytesRef + $len > $maxBytes) {
                    $partial = substr($data, 0, $maxBytes - $totalBytesRef);
                    hash_update($hashCtx, $partial);
                    $totalBytesRef = $maxBytes;
                    return 0;
                }
                hash_update($hashCtx, $data);
                $totalBytesRef += $len;
                return $len;
            });
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);

            curl_multi_add_handle($multiHandle, $ch);
            $activeHandles[(int)$ch] = [
                'ch' => $ch,
                'seg_index' => $nextIdx,
                'hash_ctx' => $hashCtx,
                'total_bytes' => &$totalBytesRef,
                'start_time' => microtime(true),
                'proxy' => $proxyRef,
            ];
            $nextIdx++;
        }

        // 循环处理
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active > 0) {
                curl_multi_select($multiHandle, 0.1); // 等待 100ms，允许其他操作
            }

            // 处理已完成的连接
            while ($info = curl_multi_info_read($multiHandle)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                if (isset($activeHandles[$key])) {
                    $h = $activeHandles[$key];
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $downloadMs = round((microtime(true) - $h['start_time']) * 1000, 1);

                    $idx = $h['seg_index'];
                    if ($httpCode >= 200 && $httpCode < 300 && $h['total_bytes'] > 0) {
                        $md5 = hash_final($h['hash_ctx']);
                        $results[$idx] = [
                            'success' => true,
                            'md5' => $md5,
                            'size' => $h['total_bytes'],
                            'download_ms' => $downloadMs,
                            'proxy' => $h['proxy'],
                        ];
                    } else {
                        $results[$idx] = [
                            'success' => false,
                            'md5' => '',
                            'size' => 0,
                            'download_ms' => $downloadMs,
                            'error' => 'HTTP ' . $httpCode . ' - ' . ($curlError ?: '未知错误'),
                        ];
                        if ($this->useProxy && !empty($h['proxy'])) {
                            // 标记失败
                        }
                    }

                    curl_multi_remove_handle($multiHandle, $ch);
                    curl_close($ch);
                    unset($activeHandles[$key]);

                    // 填充下一个
                    if ($nextIdx < count($pending) && !$this->isTimedOut() && !SmartConcurrencyController::isProtectionMode()) {
                        $seg = $pending[$nextIdx];
                        $url = $this->resolveUrl($videoUrl, $seg['uri']);
                        $newCh = curl_init($url);

                        $proxyUsed = '';
                        if ($this->useProxy && $this->ipGuard) {
                            $proxyUsed = $this->ipGuard->configureCurl($newCh, $videoUrl);
                        } else {
                            curl_setopt($newCh, CURLOPT_USERAGENT, $this->ipGuard ? $this->ipGuard->getRandomUA() : 'Mozilla/5.0');
                            curl_setopt($newCh, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($newCh, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($newCh, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($newCh, CURLOPT_TIMEOUT, $this->segmentTimeout);
                        }

                        $newHashCtx = hash_init('md5');
                        $newBytesRef = 0;
                        $maxBytes2 = $this->maxSegmentSizeKB * 1024;
                        $newProxyRef = $proxyUsed;

                        curl_setopt($newCh, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$newHashCtx, &$newBytesRef, $maxBytes2) {
                            $len = strlen($data);
                            if ($newBytesRef + $len > $maxBytes2) {
                                $partial = substr($data, 0, $maxBytes2 - $newBytesRef);
                                hash_update($newHashCtx, $partial);
                                $newBytesRef = $maxBytes2;
                                return 0;
                            }
                            hash_update($newHashCtx, $data);
                            $newBytesRef += $len;
                            return $len;
                        });
                        curl_setopt($newCh, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($newCh, CURLOPT_HEADER, false);

                        curl_multi_add_handle($multiHandle, $newCh);
                        $activeHandles[(int)$newCh] = [
                            'ch' => $newCh,
                            'seg_index' => $nextIdx,
                            'hash_ctx' => $newHashCtx,
                            'total_bytes' => &$newBytesRef,
                            'start_time' => microtime(true),
                            'proxy' => $newProxyRef,
                        ];
                        $nextIdx++;
                    }
                }
            }

            if ($this->isTimedOut() || SmartConcurrencyController::isProtectionMode()) {
                // 超时或保护模式，取消剩余
                foreach ($activeHandles as $key => $h) {
                    curl_multi_remove_handle($multiHandle, $h['ch']);
                    curl_close($h['ch']);
                    $results[$h['seg_index']] = [
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

        // 按索引排序
        ksort($results);
        return $results;
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
    public function deepAnalyze($m3u8Content, $videoUrl = '')
    {
        $parsed = $this->parseM3U8($m3u8Content);
        $segments = $parsed['segments'];
        $totalSegments = count($segments);

        $result = [
            'segments' => [],
            'stats' => [
                'total_segments' => $totalSegments,
                'ad_segments' => 0,
                'content_segments' => 0,
            ],
            'clean_m3u8' => '',
            'domain' => parse_url($videoUrl, PHP_URL_SCHEME) . '://' . parse_url($videoUrl, PHP_URL_HOST),
            'header' => $parsed['header'],
            'has_end' => $parsed['has_end'],
        ];

        if ($totalSegments === 0) {
            return $result;
        }

        // 下载并分析所有片段
        $segmentResults = [];
        $actualConcurrency = SmartConcurrencyController::getOptimalConcurrency($this->maxConcurrency);

        if ($actualConcurrency > 1 && function_exists('curl_multi_init')) {
            $segmentResults = $this->downloadSegmentsConcurrent($segments, $videoUrl, $actualConcurrency);
        } else {
            $segmentResults = $this->downloadSegmentsSequential($segments, $videoUrl);
        }

        // 批量查询数据库获取MD5状态
        $md5List = [];
        foreach ($segmentResults as $r) {
            if (!empty($r['md5'])) {
                $md5List[] = $r['md5'];
            }
        }
        $md5StatusMap = $this->db->queryBatch($md5List);

        // 构建片段详情和决策
        $decisions = [];
        $cleanSegments = [];

        foreach ($segments as $idx => $seg) {
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
                        $cleanSegments[] = $seg;
                    } elseif (!empty($statusEntry['in_blacklist'])) {
                        $status = 'blacklist';
                        $reason = '黑名单-广告';
                        $isAd = true;
                        $result['stats']['ad_segments']++;
                    } elseif (!empty($statusEntry['is_ad'])) {
                        $status = 'ad_identified';
                        $reason = '已识别广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                        $result['stats']['ad_segments']++;
                    } elseif ($statusEntry['count'] >= $this->md5RepeatThreshold) {
                        $status = 'ad_high_freq';
                        $reason = '高频广告(' . $statusEntry['count'] . '次)';
                        $isAd = true;
                        $result['stats']['ad_segments']++;
                        // 自动加入黑名单
                        $this->db->markAsAd($md5, true);
                    } else {
                        $status = 'content_low_freq';
                        $reason = '低频内容(' . $statusEntry['count'] . '次)';
                        $result['stats']['content_segments']++;
                        $cleanSegments[] = $seg;
                    }
                } else {
                    $status = 'new_segment';
                    $reason = '新片段(未记录)';
                    $result['stats']['content_segments']++;
                    $cleanSegments[] = $seg;
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

        // 构建清理后的M3U8（使用完整URL）
        $result['clean_m3u8'] = $this->buildOutputM3U8FullUrl($parsed['header'], $cleanSegments, $videoUrl, $parsed['has_end']);

        return $result;
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
     * 从 M3U8 URL 下载原始内容
     */
    public static function downloadM3U8($url, $useProxy = false, $proxyPool = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

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
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) return false;
        return $content;
    }
}
