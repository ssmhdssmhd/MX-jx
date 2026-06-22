<?php
/**
 * 智能并发控制器 - 防止服务器卡死 + 动态资源分配
 *
 * 核心功能：
 *   1. 服务器健康监控（CPU/内存/磁盘负载）
 *   2. 动态并发数调整（负载高时自动降速
 *   3. 内存使用量控制
 *   4. 超时控制（单任务/总任务双重保护
 *   5. 代理池管理 + UA 轮换（防止 IP 被禁）
 *   6. 速率限制（防止高频请求被识别）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

class SmartConcurrencyController
{
    // ============== 服务器资源监控 ==============

    /**
     * 获取当前服务器 CPU 使用率（百分比 0-100）
     * Linux: 读取 /proc/stat 两次计算
     * Windows: 使用 COM 不可用时返回估算值
     */
    public static function getCpuUsage()
    {
        if (!function_exists('shell_exec')) return 50;

        if (PHP_OS === 'Linux') {
            // Linux: 读取 /proc/stat 两次
            $stat1 = @file_get_contents('/proc/stat');
            usleep(100000); // 100ms
            $stat2 = @file_get_contents('/proc/stat');

            if ($stat1 === false || $stat2 === false) return 50;

            $line1 = explode(' ', preg_replace('/\s+/', ' ', trim(explode("\n", $stat1)[0])));
            $line2 = explode(' ', preg_replace('/\s+/', ' ', trim(explode("\n", $stat2)[0])));

            // cpu 行格式: cpu user nice system idle iowait irq softirq steal
            $total1 = array_sum(array_slice($line1, 1));
            $idle1 = (int)$line1[4];
            $total2 = array_sum(array_slice($line2, 1));
            $idle2 = (int)$line2[4];

            if ($total2 === $total1) return 50;
            $totalDiff = $total2 - $total1;
            $idleDiff = $idle2 - $idle1;
            $cpuUsage = 100 - round(($idleDiff / $totalDiff) * 100, 1);
            return max(0, min(100, $cpuUsage));
        }
        return 50; // 默认保守值
    }

    /**
     * 获取内存使用率（百分比 0-100）
     */
    public static function getMemoryUsage()
    {
        if (PHP_OS === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);
                if (isset($total[1]) && isset($avail[1])) {
                    $used = (int)$total[1] - (int)$avail[1];
                    return round(($used / (int)$total[1]) * 100, 1);
                }
            }
        }
        // PHP memory_limit 兜底
        $limit = ini_get('memory_limit');
        if ($limit && $limit !== '-1') {
            $usageBytes = memory_get_usage(true);
            $limitBytes = self::parseSizeToBytes($limit);
            if ($limitBytes > 0) {
                return round(($usageBytes / $limitBytes) * 100, 1);
            }
        }
        return 50;
    }

    /**
     * 根据服务器状态计算最佳并发数
     *
     * @param int $maxConcurrency 最大期望并发数
     * @return int 实际使用的并发数（被系统自动降速保护）
     */
    public static function getOptimalConcurrency($maxConcurrency = 8)
    {
        $cpu = self::getCpuUsage();
        $mem = self::getMemoryUsage();

        // 健康度 = 100 - max($cpu, $mem) / 2
        $healthScore = 100 - ($cpu + $mem) / 2;

        // 根据健康度动态调整
        if ($healthScore >= 80) {
            $concurrency = $maxConcurrency;
        } elseif ($healthScore >= 60) {
            $concurrency = max(2, (int)ceil($maxConcurrency * 0.7));
        } elseif ($healthScore >= 40) {
            $concurrency = max(1, (int)ceil($maxConcurrency * 0.5));
        } elseif ($healthScore >= 20) {
            $concurrency = 2; // 保守
        } else {
            $concurrency = 1; // 极重负载 -> 串行处理
        }
        return $concurrency;
    }

    /**
     * 检查是否需要"保护模式（CPU/内存过高时，拒绝新请求）
     * @return bool true=进入保护模式，停止新请求
     */
    public static function isProtectionMode()
    {
        $cpu = self::getCpuUsage();
        $mem = self::getMemoryUsage();
        // 超过 90% 且两者任一条件触发保护
        return ($cpu >= 90 || $mem >= 90);
    }

    /**
     * 解析内存大小字符串为字节数（如 "128M" -> 134217728）
     */
    public static function parseSizeToBytes($sizeStr)
    {
        $sizeStr = trim($sizeStr);
        $lastChar = strtoupper($sizeStr[strlen($sizeStr) - 1]);
        $num = (int)$sizeStr;
        switch ($lastChar) {
            case 'G': return $num * 1073741824;
            case 'M': return $num * 1048576;
            case 'K': return $num * 1024;
            default: return $num;
        }
    }
}

/**
 * IP 防护与代理池管理
 *
 * 防止 IP 被禁的核心策略：
 *   1. 代理轮换（HTTP/HTTPS/SOCKS 混用）
 *   2. User-Agent 随机化（模拟真实浏览器）
 *   3. 请求间隔随机化（50-300ms）
 *   4. 请求头模拟（Accept、Accept-Language、Referer）
 *   5. 失败自动切换代理
 */
class IPGuard
{
    /** @var array 代理池列表（从 config/noad.php 的 proxy_pool） */
    private $proxies = [];

    /** @var int 当前使用的代理索引 */
    private $currentProxyIdx = 0;

    /** @var int 同一个代理最多连续使用次数 */
    private $maxConsecutiveUse = 3;

    /** @var int 连续使用当前代理的次数 */
    private $consecutiveUseCount = 0;

    /** @var array 失败代理标记 */
    private $failedProxies = [];

    /** @var array 常用浏览器 User-Agent 池 */
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Android 13; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    /** @var array Accept-Language 池 */
    private $languages = [
        'zh-CN,zh;q=0.9,en;q=0.8',
        'zh-CN,zh;q=0.8,en-US;q=0.5,en;q=0.3',
        'zh,en-US;q=0.7,en;q=0.3',
        'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
    ];

    /** @var float 上次请求时间戳（用于限制速率） */
    private $lastRequestTime = 0;

    /** @var int 最小请求间隔（毫秒） */
    private $minRequestIntervalMs = 100;

    /**
     * @param array $proxies 代理池数组 [ 'host:port' | 'user:pass@host:port' ]
     * @param int $maxConsecutiveUse 同一代理最多连续使用次数
     * @param int $minIntervalMs 最小请求间隔毫秒
     */
    public function __construct($proxies = [], $maxConsecutiveUse = 3, $minIntervalMs = 100)
    {
        $this->proxies = $proxies;
        $this->maxConsecutiveUse = $maxConsecutiveUse;
        $this->minRequestIntervalMs = $minIntervalMs;
        $this->currentProxyIdx = mt_rand(0, max(0, count($proxies) - 1));
    }

    /**
     * 获取下一个代理（轮换策略）
     * @return string|null 代理字符串（如 "127.0.0.1:8080"），无代理则 null
     */
    public function getNextProxy()
    {
        if (empty($this->proxies) || count($this->proxies) === 0) {
            return null;
        }

        // 连续使用次数判断
        if ($this->consecutiveUseCount >= $this->maxConsecutiveUse) {
            $this->consecutiveUseCount = 0;
            // 跳过失败代理
            $this->currentProxyIdx = ($this->currentProxyIdx + 1) % count($this->proxies);
            // 寻找一个非失败代理
            $tries = 0;
            while (isset($this->failedProxies[$this->proxies[$this->currentProxyIdx]])
                && $this->failedProxies[$this->currentProxyIdx] > time() - 300
                && $tries < count($this->proxies)) {
                $this->currentProxyIdx = ($this->currentProxyIdx + 1) % count($this->proxies);
                $tries++;
            }
        }
        $this->consecutiveUseCount++;
        return $this->proxies[$this->currentProxyIdx];
    }

    /**
     * 标记当前代理失败（切换到下一个）
     */
    public function markCurrentProxyFailed()
    {
        if (!empty($this->proxies)) return;
        $current = $this->proxies[$this->currentProxyIdx] ?? '';
        if ($current !== '') {
            $this->failedProxies[$current] = time();
        }
        $this->consecutiveUseCount = $this->maxConsecutiveUse; // 强制切换
    }

    /**
     * 获取随机 User-Agent
     */
    public function getRandomUA()
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    /**
     * 获取随机 Accept-Language
     */
    public function getRandomLanguage()
    {
        return $this->languages[array_rand($this->languages)];
    }

    /**
     * 生成 cURL 请求头（模拟浏览器）
     * @param string $referer 可选来源页
     * @return array
     */
    public function buildHeaders($referer = '')
    {
        $headers = [
            'Accept: */*',
            'Accept-Language: ' . $this->getRandomLanguage(),
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        if ($referer !== '') {
            $headers[] = 'Referer: ' . $referer;
        }
        return $headers;
    }

    /**
     * 速率限制：等待确保最小间隔
     */
    public function waitForRateLimit()
    {
        if ($this->lastRequestTime > 0) {
            $elapsedMs = (microtime(true) - $this->lastRequestTime) * 1000;
            if ($elapsedMs < $this->minRequestIntervalMs) {
                usleep((int)(($this->minRequestIntervalMs - $elapsedMs) * 1000));
            }
        }
        // 额外增加 20-80ms 随机抖动
        usleep(mt_rand(20000, 80000));
        $this->lastRequestTime = microtime(true);
    }

    /**
     * 使用代理 + 随机UA 配置 cURL
     *
     * @param resource $ch cURL句柄
     * @param string $url 请求URL
     * @param string|null $proxy 使用的代理
     * @return string 使用的代理（空=无代理）
     */
    public function configureCurl($ch, $url, $proxy = null)
    {
        if ($proxy === null) {
            $proxy = $this->getNextProxy();
        }

        curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeaders($this->extractHost($url)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');

        if ($proxy !== null && $proxy !== '' && $proxy !== 'off') {
            // 解析格式: [scheme://]user:pass@host:port
            if (strpos($proxy, '://') !== false) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            } else {
                curl_setopt($ch, CURLOPT_PROXY, 'http://' . $proxy);
            }
            // 处理认证
            if (preg_match('/([^:]+):([^@]+)@(.+)/', $proxy, $m)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $m[1] . ':' . $m[2]);
            }
        }
        return $proxy;
    }

    /**
     * 从 URL 提取 host（用于 Referer）
     */
    private function extractHost($url)
    {
        $parts = parse_url($url);
        if (isset($parts['scheme'], $parts['host'])) {
            return $parts['scheme'] . '://' . $parts['host'] . '/';
        }
        return '';
    }

    /**
     * 获取代理池大小
     */
    public function getProxyCount()
    {
        return count($this->proxies);
    }
}
