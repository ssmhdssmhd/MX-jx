<?php
class CommonAdRuleTool extends AbstractTool {
    public function __construct() {
        $this->id = 'common_ad_rules';
        $this->group = '去广告';
    }
    
    public function name() { return '常用去广告规则库'; }
    
    public function description() { return '包含域名黑名单、关键词库、MD5指纹等所有去广告规则'; }
    
    public function getParamSchema() {
        return array(
            'input' => array('type' => 'textarea', 'label' => '输入内容', 'hint' => 'M3U8内容或视频URL', 'required' => true),
            'mode' => array('type' => 'select', 'label' => '处理模式', 'options' => array(
                array('value' => 'auto', 'label' => '自动识别'),
                array('value' => 'm3u8', 'label' => 'M3U8格式'),
                array('value' => 'url', 'label' => 'URL格式'),
            ), 'default' => 'auto'),
            'extra_domains' => array('type' => 'text', 'label' => '额外域名', 'hint' => '逗号分隔的额外广告域名'),
            'extra_keywords' => array('type' => 'text', 'label' => '额外关键词', 'hint' => '逗号分隔的额外关键词'),
        );
    }
    
    public function run($params = []) {
        $input = trim($params['input'] ?? '');
        $mode = $params['mode'] ?? 'auto';
        
        if (empty($input)) {
            return array('success' => false, 'message' => '参数 input 不能为空', 'data' => null);
        }
        
        $rules = $this->loadGlobalRules();
        
        if (!empty($params['extra_domains'])) {
            $rules['ad_domains'] = array_merge($rules['ad_domains'], explode(',', $params['extra_domains']));
        }
        if (!empty($params['extra_keywords'])) {
            $rules['ad_keywords'] = array_merge($rules['ad_keywords'], explode(',', $params['extra_keywords']));
        }
        
        if (stripos($input, '#EXTM3U') !== false || $mode === 'm3u8') {
            $result = $this->processM3u8($input, $rules);
        } else if (filter_var($input, FILTER_VALIDATE_URL) || $mode === 'url') {
            $result = $this->processUrl($input, $rules);
        } else {
            $result = $this->processText($input, $rules);
        }
        
        return array(
            'success' => true,
            'message' => '规则匹配完成',
            'data' => $result,
            'rule_counts' => array(
                'domains' => count($rules['ad_domains']),
                'keywords' => count($rules['ad_keywords']),
                'prefixes' => count($rules['ad_prefixes']),
                'whitelist' => count($rules['whitelist']),
            ),
        );
    }
    
    private function loadGlobalRules() {
        $configFile = __DIR__ . '/../../config/global_rules.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        
        return array(
            'ad_domains' => array(
                'ad.', 'ads.', 'cdn-ad.', 'adserver.', 'doubleclick',
                'googletagmanager', 'amazon-adsystem', 'track.', 'pixel.',
            ),
            'ad_keywords' => array('ad_', '_ad', 'adver', 'advert', 'promo', 'banner'),
            'ad_prefixes' => array(),
            'whitelist' => array('main', 'video', 'play', 'stream'),
        );
    }
    
    private function processM3u8($m3u8, $rules) {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8);
        $output = array('#EXTM3U');
        $removed = 0;
        $total = 0;
        $reasons = array();
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            if (strpos($line, '#EXTINF') === 0) {
                $uri = '';
                $j = $i + 1;
                while ($j < count($lines)) {
                    $t = trim($lines[$j]);
                    if (!empty($t) && strpos($t, '#') !== 0) {
                        $uri = $t;
                        break;
                    }
                    $j++;
                }
                $total++;
                
                $isAd = $this->isAdSegment($uri, $line, $rules);
                if ($isAd) {
                    $removed++;
                    $reasons[] = $isAd;
                } else {
                    $output[] = $line;
                    if (!empty($uri)) $output[] = $uri;
                }
            } else if (strpos($line, '#') !== 0) {
                $total++;
                $isAd = $this->isAdSegment($line, '', $rules);
                if (!$isAd) $output[] = $line;
            } else {
                $output[] = $line;
            }
        }
        
        return array(
            'type' => 'm3u8',
            'total' => $total,
            'removed' => $removed,
            'kept' => $total - $removed,
            'output' => implode("\n", $output),
            'reasons' => $reasons,
        );
    }
    
    private function processUrl($url, $rules) {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        
        $isAd = $this->isAdSegment($url, '', $rules);
        
        return array(
            'type' => 'url',
            'url' => $url,
            'host' => $host,
            'is_ad' => !!$isAd,
            'reason' => $isAd ?: '安全',
        );
    }
    
    private function processText($text, $rules) {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $results = array();
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (filter_var($line, FILTER_VALIDATE_URL)) {
                $results[] = $this->processUrl($line, $rules);
            }
        }
        
        return array(
            'type' => 'text',
            'count' => count($results),
            'matches' => $results,
        );
    }
    
    private function isAdSegment($uri, $extinf, $rules) {
        $context = strtolower($uri . ' ' . $extinf);
        
        foreach ($rules['whitelist'] as $w) {
            if (strpos($context, strtolower($w)) !== false) {
                return false;
            }
        }
        
        foreach ($rules['ad_domains'] as $domain) {
            if (strpos($context, strtolower($domain)) !== false) {
                return '域名黑名单: ' . $domain;
            }
        }
        
        foreach ($rules['ad_prefixes'] as $prefix) {
            if (strpos($context, strtolower($prefix)) !== false) {
                return '文件前缀: ' . $prefix;
            }
        }
        
        foreach ($rules['ad_keywords'] as $kw) {
            if (strpos($context, strtolower($kw)) !== false) {
                return '关键词: ' . $kw;
            }
        }
        
        return false;
    }
}
