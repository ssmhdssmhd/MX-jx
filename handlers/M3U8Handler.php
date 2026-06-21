<?php
/**
 * M3U8链接验证处理
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class M3U8Handler {
    /**
     * 检查是否为M3U8链接
     */
    public static function isM3U8Url($url) {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['path'])) {
            return false;
        }
        
        // 检查文件扩展名
        $path = strtolower($parsedUrl['path']);
        if (strpos($path, '.m3u8') !== false) {
            return true;
        }
        
        // 检查查询参数中是否包含m3u8
        if (isset($parsedUrl['query']) && strpos(strtolower($parsedUrl['query']), 'm3u8') !== false) {
            return true;
        }
        
        // 检查路径中是否包含m3u8关键字
        if (strpos($path, 'm3u8') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 生成M3U8直接输出响应
     */
    public static function generateM3U8Response($url, $enableUnifiedDisplay = true) {
        $phpSelf = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $proxyScript = rtrim(dirname($phpSelf), '/\\') . '/noad_proxy.php';
        $noadUrl = $proxyScript . '?mode=m3u8&src=' . urlencode($url);

        $response = [
            'code' => 200,
            'msg' => $enableUnifiedDisplay ? $url : '检测到M3U8，直接输出',
            'url' => $url,
            'noad_url' => $noadUrl,
            'MX' => '检测到M3U8，直接输出',
            'type' => 'm3u8',
            'developer' => 'MX-射手沫蝴蝶',
            'contact' => 'QQ: 2094332348',
            'timestamp' => time(),
            'strategy' => 'M3U8-Direct-Output'
        ];
        
        return $response;
    }
    
    /**
     * 处理M3U8链接
     */
    public static function handleM3U8($url, $enableM3U8Direct = true, $enableUnifiedDisplay = true) {
        if (!$enableM3U8Direct) {
            return null;
        }
        
        if (self::isM3U8Url($url)) {
            $response = self::generateM3U8Response($url, $enableUnifiedDisplay);
            return json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        
        return null;
    }
}