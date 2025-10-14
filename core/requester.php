<?php
/**
 * 请求处理核心
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class Requester {
    private $strategy;
    
    public function __construct($strategy) {
        $this->strategy = $strategy;
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
            
            // 短暂延迟，避免请求过快
            usleep(100000); // 100ms
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
     * 创建CURL句柄
     */
    private function createCurlHandle($url, $timeout) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Content-type: application/json', 
                'Accept: application/json',
                'Referer: ' . parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST)
            ]
        ]);
        return $ch;
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
     * 单次请求
     */
    private function singleRequest($url, $timeout) {
        $ch = $this->createCurlHandle($url, $timeout);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode == 200) ? $result : false;
    }
    
    /**
     * 验证响应是否有效
     */
    private function isValidResponse($json) {
        if (!$json || json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // 多种成功条件判断
        return isset($json['code']) && $json['code'] == 200 ||
               isset($json['url']) && !empty($json['url']) ||
               isset($json['data']) && !empty($json['data']) ||
               isset($json['title']) || isset($json['vurl']);
    }
}