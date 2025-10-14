<?php
/**
 * 智能缓存系统
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class SmartCache {
    private $cacheDir;
    private $cacheTime;
    
    public function __construct($cacheDir = 'cache', $cacheTime = 300) {
        $this->cacheDir = $cacheDir;
        $this->cacheTime = $cacheTime;
        $this->initCacheDir();
    }
    
    /**
     * 初始化缓存目录
     */
    private function initCacheDir() {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * 生成缓存键
     */
    private function generateKey($url) {
        return md5($url);
    }
    
    /**
     * 获取缓存文件路径
     */
    private function getCacheFile($key) {
        return $this->cacheDir . '/' . $key . '.cache';
    }
    
    /**
     * 获取缓存
     */
    public function get($url) {
        $key = $this->generateKey($url);
        $cacheFile = $this->getCacheFile($key);
        
        if (file_exists($cacheFile) {
            $data = unserialize(file_get_contents($cacheFile));
            if (time() - $data['timestamp'] < $this->cacheTime) {
                return $data['content'];
            }
            // 缓存过期，删除文件
            unlink($cacheFile);
        }
        return null;
    }
    
    /**
     * 设置缓存
     */
    public function set($url, $content) {
        $key = $this->generateKey($url);
        $cacheFile = $this->getCacheFile($key);
        
        $data = [
            'timestamp' => time(),
            'content' => $content,
            'url' => $url
        ];
        
        file_put_contents($cacheFile, serialize($data), LOCK_EX);
    }
    
    /**
     * 清理过期缓存
     */
    public function cleanup() {
        $files = glob($this->cacheDir . '/*.cache');
        $now = time();
        $cleaned = 0;
        
        foreach ($files as $file) {
            $data = unserialize(file_get_contents($file));
            if ($now - $data['timestamp'] > $this->cacheTime) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}