<?php
/**
 * 算法注册表 - 统一管理所有去广告算法
 *
 * 使用方式：
 * 1. 算法文件放入 algorithms/ 目录
 * 2. 继承 AbstractAlgorithm 基类
 * 3. 实现 apply() 方法
 * 4. 注册到 AlgorithmRegistry
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

class AlgorithmRegistry {
    private static $instance = null;
    private $algorithms = [];
    private $loaded = false;

    private function __construct() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 加载所有算法
     */
    public function load($config = []) {
        if ($this->loaded) return;

        $algDir = __DIR__;
        $files = glob($algDir . '/*.php');

        foreach ($files as $file) {
            $basename = basename($file);
            // 跳过基础类和注册表本身
            if (in_array($basename, ['AbstractAlgorithm.php', 'AlgorithmRegistry.php', 'custom_template.php'])) {
                continue;
            }

            try {
                require_once $file;
                // 根据文件名推断类名
                $className = $this->filenameToClassName($basename);
                if (class_exists($className) && is_subclass_of($className, 'AbstractAlgorithm')) {
                    $instance = new $className($config);
                    if ($instance->enabled) {
                        $this->algorithms[$instance->id] = $instance;
                    }
                }
            } catch (Exception $e) {
                error_log("[AlgorithmRegistry] 加载算法失败 {$basename}: " . $e->getMessage());
            }
        }

        // 按优先级排序
        uasort($this->algorithms, function($a, $b) {
            return ($b->priority ?? 50) - ($a->priority ?? 50);
        });

        $this->loaded = true;
    }

    /**
     * 获取所有算法
     */
    public function getAll() {
        return $this->algorithms;
    }

    /**
     * 获取指定作用域的算法
     */
    public function getByScope($scope) {
        $result = [];
        foreach ($this->algorithms as $id => $alg) {
            if ($alg->scope === 'all' || $alg->scope === $scope) {
                $result[$id] = $alg;
            }
        }
        return $result;
    }

    /**
     * 获取指定算法
     */
    public function get($id) {
        return $this->algorithms[$id] ?? null;
    }

    /**
     * 执行所有适用算法
     */
    public function applyAll($input, $context = [], $scope = 'all') {
        $output = $input;
        foreach ($this->getByScope($scope) as $alg) {
            try {
                $output = $alg->apply($output, $context);
            } catch (Exception $e) {
                error_log("[AlgorithmRegistry] 算法执行失败 {$alg->id}: " . $e->getMessage());
            }
        }
        return $output;
    }

    /**
     * 文件名转类名
     */
    private function filenameToClassName($filename) {
        $name = str_replace('.php', '', $filename);
        $parts = explode('_', $name);
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        return $className;
    }

    /**
     * 获取统计信息
     */
    public function getStats() {
        $stats = [
            'total' => count($this->algorithms),
            'by_scope' => [],
            'by_priority' => [],
        ];

        foreach ($this->algorithms as $alg) {
            $scope = $alg->scope;
            $stats['by_scope'][$scope] = ($stats['by_scope'][$scope] ?? 0) + 1;
            $priority = $alg->priority ?? 50;
            $stats['by_priority'][$priority] = ($stats['by_priority'][$priority] ?? 0) + 1;
        }

        return $stats;
    }
}
