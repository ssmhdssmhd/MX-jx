<?php
/**
 * 智能选择策略核心
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class Strategy {
    private $apiConfig;
    private $platformConfig;
    private $switchConfig;
    
    public function __construct($apiConfig, $platformConfig, $switchConfig = null) {
        $this->apiConfig = $apiConfig;
        $this->platformConfig = $platformConfig;
        $this->switchConfig = $switchConfig ?: $this->getDefaultSwitchConfig();
    }
    
    /**
     * 获取默认开关配置
     */
    private function getDefaultSwitchConfig() {
        return [
            'enable_global_api' => true,
            'zjk_file_path' => 'ZJK.txt',
            'global_api_timeout' => 8,
            'global_api_count' => 6,
            'enable_zjk_apis' => true,
            'enable_unified_display' => true,
            'enable_m3u8_direct' => true
        ];
    }
    
    /**
     * 获取平台优先API
     */
    public function getPriorityApi($url) {
        foreach ($this->platformConfig as $platform => $rule) {
            $data = explode('|', $rule);
            if (count($data) < 2) {
                continue; // 跳过格式错误的配置
            }
            if (strpos($url, $data[0]) !== false) {
                return [
                    'api_name' => $data[1],
                    'platform' => $platform,
                    'rule' => $data[0]
                ];
            }
        }
        return null;
    }
    
    /**
     * 获取所有可用API配置（包含总接口）
     */
    public function getAllApiConfigs() {
        $configs = [];
        $apiCount = 0;
        
        foreach ($this->apiConfig as $name => $config) {
            $apiCount++;
            $parts = explode('|', $config);
            if (count($parts) < 1 || empty($parts[0])) {
                continue; // 跳过格式错误的配置
            }
            $url = str_replace('?url=', '?url={url}', $parts[0]);
            
            // 如果是前N条API且开启总接口模式，使用全局超时时间
            $timeout = isset($parts[1]) ? intval($parts[1]) : 5;
            $isGlobal = $this->switchConfig['enable_global_api'] && $apiCount <= $this->switchConfig['global_api_count'];
            if ($isGlobal) {
                $timeout = intval($this->switchConfig['global_api_timeout']);
            }
            
            $configs[$name] = [
                'url' => $url,
                'timeout' => $timeout,
                'is_global' => $isGlobal
            ];
        }
        
        // 添加ZJK.txt中的接口
        if ($this->switchConfig['enable_zjk_apis'] && file_exists($this->switchConfig['zjk_file_path'])) {
            $zjkConfigs = $this->loadZjkApis();
            $configs = array_merge($configs, $zjkConfigs);
        }
        
        return $configs;
    }
    
    /**
     * 加载ZJK.txt中的接口配置
     */
    private function loadZjkApis() {
        $zjkConfigs = [];
        $lines = file($this->switchConfig['zjk_file_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        $index = 1;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue; // 跳过空行和注释
            }
            
            $parts = explode('|', $line);
            if (count($parts) >= 2) {
                $url = str_replace('?url=', '?url={url}', $parts[0]);
                $timeout = intval($parts[1]);
                
                $zjkConfigs['ZJK-' . $index] = [
                    'url' => $url,
                    'timeout' => $timeout,
                    'is_global' => true,
                    'is_zjk' => true
                ];
                $index++;
            }
        }
        
        return $zjkConfigs;
    }
    
    /**
     * 选择最佳响应
     */
    public function selectBestResponse($responses, $priorityApi = null) {
        if (empty($responses)) {
            return null;
        }
        
        // 优先使用平台指定的API
        if ($priorityApi && isset($responses[$priorityApi['api_name']])) {
            $bestResponse = $responses[$priorityApi['api_name']];
            $bestResponse['data']['from'] = $priorityApi['api_name'] . '-Priority[' . $priorityApi['platform'] . ']';
            return $bestResponse;
        }
        
        // 选择响应最快的有效响应
        $validResponses = array_filter($responses, function($response) {
            return $response['http_code'] == 200 && !empty($response['data']);
        });
        
        if (empty($validResponses)) {
            return null;
        }
        
        // 优先选择总接口的响应
        $globalResponses = array_filter($validResponses, function($response) {
            return isset($response['is_global']) && $response['is_global'];
        });
        
        if (!empty($globalResponses)) {
            uasort($globalResponses, function($a, $b) {
                return $a['response_time'] <=> $b['response_time'];
            });
            $bestResponse = reset($globalResponses);
            $bestApiName = key($globalResponses);
            $bestResponse['data']['from'] = $bestApiName . '-Global-Fastest';
            return $bestResponse;
        }
        
        // 如果没有总接口响应，选择普通的最快响应
        uasort($validResponses, function($a, $b) {
            return $a['response_time'] <=> $b['response_time'];
        });
        
        $bestResponse = reset($validResponses);
        $bestApiName = key($validResponses);
        $bestResponse['data']['from'] = $bestApiName . '-Fastest';
        
        return $bestResponse;
    }
    
    /**
     * 生成错误响应
     */
    public function generateErrorResponse($code = 404, $message = '解析失败') {
        return [
            'code' => $code,
            'msg' => $message,
            'developer' => 'MX-射手沫蝴蝶',
            'contact' => 'QQ: 2094332348',
            'timestamp' => time()
        ];
    }
    
    /**
     * 获取开关状态信息
     */
    public function getSwitchStatus() {
        return [
            'global_api_enabled' => $this->switchConfig['enable_global_api'],
            'global_api_count' => $this->switchConfig['global_api_count'],
            'zjk_apis_enabled' => $this->switchConfig['enable_zjk_apis'],
            'zjk_file_exists' => file_exists($this->switchConfig['zjk_file_path']),
            'unified_display_enabled' => isset($this->switchConfig['enable_unified_display']) ? $this->switchConfig['enable_unified_display'] : true,
            'm3u8_direct_enabled' => isset($this->switchConfig['enable_m3u8_direct']) ? $this->switchConfig['enable_m3u8_direct'] : true
        ];
    }
}