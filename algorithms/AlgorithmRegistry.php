<?php
/**
 * 算法注册表：自动发现 + 加载 + 应用
 *
 * 使用方式:
 *   require_once __DIR__ . '/AlgorithmRegistry.php';
 *   $registry = AlgorithmRegistry::getInstance();
 *   $registry->loadFromDir(__DIR__);        // 加载目录下所有算法
 *   $clean = $registry->applyAll($url, $data, $ctx);  // 按优先级应用所有启用的算法
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */
class AlgorithmRegistry {

    /** @var AlgorithmRegistry|null 单例 */
    private static $instance = null;

    /** @var AbstractAlgorithm[] 已加载的算法列表 */
    private $algorithms = [];

    /** @var string[] 已加载的类名（防重复） */
    private $loadedClasses = [];

    /** @var bool 是否已完成至少一次扫描 */
    private $scanned = false;

    /**
     * 单例入口
     * @return AlgorithmRegistry
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * 扫描目录下所有 *.php 文件，识别继承 AbstractAlgorithm 的类
     * @param string $dir   目录绝对路径
     * @param bool   $force 是否强制重新扫描
     * @return int 成功加载的算法数量
     */
    public function loadFromDir($dir, $force = false) {
        if ($this->scanned && !$force) return count($this->algorithms);

        if (!is_dir($dir)) return 0;

        // 确保 AbstractAlgorithm 已加载
        if (!class_exists('AbstractAlgorithm', false)) {
            require_once $dir . '/AbstractAlgorithm.php';
        }

        $files = glob(rtrim($dir, '/') . '/*.php');
        if (!is_array($files)) return 0;

        foreach ($files as $file) {
            $basename = basename($file);
            // 跳过基类 / 注册表 / 模板文件本身
            if (in_array($basename, [
                'AbstractAlgorithm.php',
                'AlgorithmRegistry.php',
                'registry.php',
                'README.md',
            ], true)) {
                continue;
            }

            // 解析文件，尝试找类名
            $className = $this->guessClassName($file);
            if ($className === null) {
                // 尝试 require 一次，然后看是否出现未加载的 AbstractAlgorithm 子类
                require_once $file;
                $classes = array_filter(
                    get_declared_classes(),
                    function ($cls) {
                        return is_subclass_of($cls, 'AbstractAlgorithm')
                            && !in_array($cls, $this->loadedClasses, true);
                    }
                );
                foreach ($classes as $cls) {
                    $this->register(new $cls());
                }
                continue;
            }

            // 按解析出的类名加载
            if (!in_array($className, $this->loadedClasses, true)) {
                require_once $file;
                if (class_exists($className, false)
                    && is_subclass_of($className, 'AbstractAlgorithm')) {
                    $this->register(new $className());
                }
            }
        }

        $this->scanned = true;
        $this->sort();
        return count($this->algorithms);
    }

    /**
     * 手工注册一个算法实例
     */
    public function register(AbstractAlgorithm $algo) {
        if ($algo->id === '') $algo->id = get_class($algo);
        $this->algorithms[$algo->id] = $algo;
        $this->loadedClasses[] = get_class($algo);
        $this->sort();
    }

    /**
     * 按算法 id 移除
     */
    public function unregister($id) {
        if (isset($this->algorithms[$id])) unset($this->algorithms[$id]);
    }

    /**
     * 按优先级 + 名称排序
     */
    public function sort() {
        uasort($this->algorithms, function ($a, $b) {
            if ($a->priority !== $b->priority) {
                return $b->priority - $a->priority; // 高优先级在前
            }
            return strcmp($a->name(), $b->name());
        });
    }

    /**
     * 返回所有已加载的算法（复制）
     * @return AbstractAlgorithm[]
     */
    public function getAll() {
        return array_values($this->algorithms);
    }

    /**
     * 返回启用中算法
     * @return AbstractAlgorithm[]
     */
    public function getEnabled() {
        return array_values(array_filter($this->algorithms, function ($a) {
            return !empty($a->enabled);
        }));
    }

    /**
     * 按 id 获取
     */
    public function get($id) {
        return $this->algorithms[$id] ?? null;
    }

    /**
     * 启用/禁用指定算法
     */
    public function setEnabled($id, $enabled) {
        if (isset($this->algorithms[$id])) {
            $this->algorithms[$id]->enabled = (bool)$enabled;
        }
    }

    /**
     * 对输入 $data 应用所有启用且满足匹配规则的算法
     *
     * @param string $data     要处理的字符串（URL / M3U8）
     * @param string $scope    当前作用域：'url' | 'm3u8' | 'all'
     * @param array  $context  上下文（含 original_url 等）
     * @return array  { 'data' => string, 'applied' => [ {id,name,hits,elapsed}, ... ] }
     */
    public function applyAll($data, $scope = 'all', $context = []) {
        $applied = [];
        $current = $data;

        foreach ($this->algorithms as $algo) {
            if (empty($algo->enabled)) continue;
            if ($scope !== 'all' && $algo->scope !== 'all' && $algo->scope !== $scope) continue;
            if (!$algo->shouldRun($current, $context)) continue;

            $before = $current;
            $current = $algo->run($current, $context);
            $after = $current;

            $applied[] = [
                'id'          => $algo->id,
                'name'        => $algo->name(),
                'priority'    => $algo->priority,
                'hits'        => $algo->hitCount,
                'elapsed_ms'  => $algo->elapsedMs,
                'changed'     => $before !== $after,
            ];
        }

        return [
            'data'     => $current,
            'applied'  => $applied,
            'original' => $data,
        ];
    }

    /**
     * 返回所有算法的摘要数组（供 JSON 接口返回）
     */
    public function summary() {
        return array_map(function ($a) { return $a->toArray(); }, $this->algorithms);
    }

    /**
     * 粗略解析 PHP 文件：定位第一个 class Xxx ... 的类名
     * 仅做简单扫描，避免 require_once 带来的副作用（如全局代码）
     * @param string $file
     * @return string|null
     */
    private function guessClassName($file) {
        $content = @file_get_contents($file);
        if ($content === false) return null;
        if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+AbstractAlgorithm/', $content, $m)) {
            return $m[1];
        }
        return null;
    }
}

// 便捷入口：require_once 本文件后直接返回 $registry 变量
if (!isset($GLOBALS['__algo_registry__'])) {
    $GLOBALS['__algo_registry__'] = AlgorithmRegistry::getInstance();
}
