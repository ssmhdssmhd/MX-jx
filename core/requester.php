<?php
/**
 * 请求处理核心
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class Requester {
    private $strategy;
    private $noadConfig;       // v4.3: 同一份 noad 配置（代理 / 速率限制 / UA 等）
    private static $lastRequestTimeByHost = []; // 同域名最小间隔
    private static $sharedUserAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Android 14; Mobile; rv:124.0) Gecko/124.0 Firefox/124.0',
        'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
    ];
    
    public function __construct($strategy) {
        $this->strategy = $strategy;
        // 加载 noad 配置（若文件存在）—— 用于代理 / 速率限制 / 随机 UA / 重试
        $noadFile = __DIR__ . '/../config/noad.php';
        if (file_exists($noadFile)) {
            $this->noadConfig = require $noadFile;
        } else {
            $this->noadConfig = [
                'enable_proxy' => false,
                'proxies' => [],
                'proxy_failover' => true,
                'proxy_random_user_agent' => true,
                'enable_rate_limit' => true,
                'rate_limit_min_interval_ms' => 300,
                'rate_limit_burst_jitter_ms' => 200,
                'request_random_delay' => true,
                'request_retry_on_failure' => 1,
                'request_custom_headers' => [],
            ];
        }
    }
    
    /**
     * 并发请求处理
     */
    public function concurrentRequest($url) {
        // 获取所有API配置（包含总接口）
        $apiConfigs = $this->strategy->getAllApiConfigs();
        
        // 获取平台优先API
        $priorityApi = $this->strategy->getPriorityApi($url);
        
        // 准备并发请求
        $multiHandle = curl_multi_init();
        $handles = [];
        $responses = [];
        
        foreach ($apiConfigs as $apiName => $config) {
            $requestUrl = str_replace('{url}', urlencode($url), $config['url']);
            $timeout = $config['timeout'];
            
            $ch = $this->createCurlHandle($requestUrl, $timeout);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$apiName] = [
                'ch' => $ch,
                'config' => $config
            ];
        }

        // 执行并发请求
        $this->executeMultiRequest($multiHandle);
        
        // 处理响应
        foreach ($handles as $apiName => $handle) {
            $ch = $handle['ch'];
            $config = $handle['config'];
            
            $content = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);
            
            if ($content && $httpCode == 200) {
                $json = json_decode($content, true);
                if ($this->isValidResponse($json)) {
                    $responseData = [
                        'data' => $json,
                        'response_time' => $responseTime,
                        'http_code' => $httpCode
                    ];
                    
                    // 添加接口类型信息
                    if (isset($config['is_global'])) {
                        $responseData['is_global'] = $config['is_global'];
                    }
                    if (isset($config['is_zjk'])) {
                        $responseData['is_zjk'] = $config['is_zjk'];
                    }
                    
                    $responses[$apiName] = $responseData;
                }
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        // 选择最佳响应
        $bestResponse = $this->strategy->selectBestResponse($responses, $priorityApi);
        
        if ($bestResponse) {
            // 应用统一显示处理
            $bestResponse['data'] = $this->applyUnifiedDisplay($bestResponse['data']);
            
            // 添加开发者信息和策略信息
            $bestResponse['data']['developer'] = 'MX-射手沫蝴蝶';
            $bestResponse['data']['contact'] = 'QQ: 2094332348';
            $bestResponse['data']['strategy'] = $bestResponse['data']['from'];
            unset($bestResponse['data']['from']);
            
            // 添加开关状态信息
            $switchStatus = $this->strategy->getSwitchStatus();
            $bestResponse['data']['switch_status'] = $switchStatus;
            
            return json_encode($bestResponse['data'], JSON_UNESCAPED_UNICODE);
        }
        
        // 所有并发请求都失败，尝试顺序请求
        return $this->sequentialRequest($url, $apiConfigs);
    }
    
    /**
     * 顺序请求备用
     */
    private function sequentialRequest($url, $apiConfigs) {
        foreach ($apiConfigs as $apiName => $config) {
            $requestUrl = str_replace('{url}', urlencode($url), $config['url']);
            $content = $this->singleRequest($requestUrl, $config['timeout']);
            
            if ($content) {
                $json = json_decode($content, true);
                if ($this->isValidResponse($json)) {
                    $json['from'] = $apiName . '-Sequential';
                    $json['developer'] = 'MX-射手沫蝴蝶';
                    $json['contact'] = 'QQ: 2094332348';
                    
                    // 应用统一显示处理
                    $json = $this->applyUnifiedDisplay($json);
                    
                    // 添加开关状态信息
                    $switchStatus = $this->strategy->getSwitchStatus();
                    $json['switch_status'] = $switchStatus;
                    
                    return json_encode($json, JSON_UNESCAPED_UNICODE);
                }
            }
            
            // 短暂延迟（v4.3 随机抖动，避免请求频率稳定被识别为爬虫）
            if (!empty($this->noadConfig['request_random_delay'])) {
                usleep(mt_rand(50000, 300000));
            } else {
                usleep(100000);
            }
        }
        
        // 所有请求都失败
        $errorResponse = $this->strategy->generateErrorResponse(404, '所有解析接口均失败');
        return json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 应用统一显示处理
     */
    private function applyUnifiedDisplay($data) {
        $switchConfig = $this->strategy->getSwitchStatus();
        
        if (!$switchConfig['unified_display_enabled']) {
            return $data;
        }
        
        // 如果已经是统一格式，直接返回
        if (isset($data['msg']) && isset($data['url']) && $data['msg'] === $data['url']) {
            return $data;
        }
        
        // 处理不同类型的响应
        if (isset($data['type']) && $data['type'] === 'm3u8') {
            // M3U8类型响应
            if (isset($data['msg']) && $data['msg'] !== $data['url']) {
                $data['msg'] = $data['url']; // 统一显示URL
            }
        } else if (isset($data['code']) && $data['code'] == 200) {
            // 成功响应
            if (isset($data['url']) && (!isset($data['msg']) || $data['msg'] !== $data['url'])) {
                $data['msg'] = $data['url']; // 统一显示URL
            }
        } else if (isset($data['url']) && !isset($data['msg'])) {
            // 只有URL没有msg的情况
            $data['msg'] = $data['url'];
        }
        
        return $data;
    }
    
    /**
     * 创建CURL句柄（v4.3 支持代理 / 随机 UA / 自定义 header / 速率限制）
     */
    private function createCurlHandle($url, $timeout) {
        $host = parse_url($url, PHP_URL_HOST) ?: 'default';

        // --- 速率限制：同域名保持最小间隔 ---
        if (!empty($this->noadConfig['enable_rate_limit'])) {
            $minMs  = (int)($this->noadConfig['rate_limit_min_interval_ms'] ?? 300);
            $jitter = (int)($this->noadConfig['rate_limit_burst_jitter_ms'] ?? 200);
            $nowMs  = (int)(microtime(true) * 1000);
            if (isset(self::$lastRequestTimeByHost[$host])) {
                $elapsed = $nowMs - self::$lastRequestTimeByHost[$host];
                $need = $minMs + mt_rand(0, $jitter);
                if ($elapsed < $need) {
                    $sleep = (int)(($need - $elapsed) * 1000);
                    if ($sleep > 0) usleep($sleep);
                    $nowMs = (int)(microtime(true) * 1000);
                }
            }
            self::$lastRequestTimeByHost[$host] = $nowMs;
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_USERAGENT => $this->pickUserAgent(),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Referer: ' . (parse_url($url, PHP_URL_SCHEME) ?? 'https') . '://' . $host . '/',
            ],
        ];

        // --- HTTP 代理 ---
        if (!empty($this->noadConfig['enable_proxy']) && !empty($this->noadConfig['proxies'])) {
            $pool = array_values($this->noadConfig['proxies']);
            $candidate = $pool[mt_rand(0, count($pool) - 1)];
            if (!empty($candidate)) {
                $opts[CURLOPT_PROXY] = $candidate;
                $opts[CURLOPT_PROXYTYPE] = (strpos($candidate, 'https://') === 0) ? CURLPROXY_HTTPS : CURLPROXY_HTTP;
            }
        }

        // --- 自定义 header ---
        if (!empty($this->noadConfig['request_custom_headers']) && is_array($this->noadConfig['request_custom_headers'])) {
            foreach ($this->noadConfig['request_custom_headers'] as $k => $v) {
                $opts[CURLOPT_HTTPHEADER][] = $k . ': ' . $v;
            }
        }

        curl_setopt_array($ch, $opts);
        return $ch;
    }

    /** 随机 UA */
    private function pickUserAgent() {
        if (!empty($this->noadConfig['proxy_random_user_agent'])) {
            $list = self::$sharedUserAgents;
            return $list[mt_rand(0, count($list) - 1)];
        }
        return self::$sharedUserAgents[0];
    }

    /**
     * 执行并发请求
     */
    private function executeMultiRequest($multiHandle) {
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle, 0.1);
            }
        } while ($running > 0 && $status == CURLM_OK);
    }

    /**
     * 单次请求（v4.3 支持失败自动重试 + 代理回退直连）
     */
    private function singleRequest($url, $timeout) {
        $maxRetry = (int)($this->noadConfig['request_retry_on_failure'] ?? 0);
        if ($maxRetry < 0) $maxRetry = 0;
        $attempts = $maxRetry + 1;
        $proxyWasOn = !empty($this->noadConfig['enable_proxy']) && !empty($this->noadConfig['proxies']);

        for ($try = 0; $try < $attempts; $try++) {
            if ($try > 0) usleep(100000 * $try + mt_rand(0, 100000));

            $ch = $this->createCurlHandle($url, $timeout);
            $result = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $result !== false && $result !== '') {
                return $result;
            }

            // 代理失败 → 临时禁用代理，下次重试走直连
            if ($proxyWasOn && !empty($this->noadConfig['proxy_failover'])) {
                $this->noadConfig['enable_proxy'] = false;
                $proxyWasOn = false;
                continue;
            }

            if ($httpCode === 429 || $httpCode >= 500) {
                usleep(300000 + mt_rand(0, 300000));
                continue;
            }
        }
        return false;
    }
    
    /**
     * 验证响应是否有效
     */
    private function isValidResponse($json) {
        if (!$json || json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // 多种成功条件判断（显式括号，避免运算符优先级歧义）
        return (isset($json['code']) && $json['code'] == 200) ||
               (isset($json['url']) && !empty($json['url'])) ||
               (isset($json['data']) && !empty($json['data'])) ||
               isset($json['title']) ||
               isset($json['vurl']);
    }
}