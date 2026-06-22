<?php
/**
 * 自定义算法基类 AbstractAlgorithm
 * 
 * 所有用户自定义算法必须继承此类，并实现：
 *   - name(): 返回算法显示名
 *   - description(): 返回功能描述
 *   - apply($input, $context = []): 处理输入并返回结果
 *
 * <code>
 *   class MyAlgorithm extends AbstractAlgorithm {
 *     public function name() { return '我的自定义算法'; }
 *     public function description() { return '示例：去除 xxx 广告'; }
 *     public function apply($input, $context = []) {
 *         return str_replace('ad_', '', $input);
 *     }
 *   }
 * </code>
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */
abstract class AbstractAlgorithm {

    /** 算法唯一标识符（留空则自动用类名） */
    public $id = '';

    /** 优先级：数值越大越先执行（默认 10） */
    public $priority = 10;

    /** 是否启用（默认 true，可通过后台关闭） */
    public $enabled = true;

    /** 作用域: 'url' | 'm3u8' | 'all'（默认 'all'，即对两者都生效） */
    public $scope = 'all';

    /** 匹配规则：仅当输入 URL 含以下关键词时才执行（空数组 = 总是执行） */
    public $matchPatterns = [];

    /** 配置参数：由配置文件或数据库注入 */
    public $config = [];

    /** 执行耗时统计（毫秒） */
    public $elapsedMs = 0;

    /** 命中计数（便于评估效果） */
    public $hitCount = 0;

    /**
     * 返回算法显示名（供后台展示）
     * @return string
     */
    abstract public function name();

    /**
     * 返回算法功能描述（供后台展示）
     * @return string
     */
    public function description() { return ''; }

    /**
     * 返回作者信息
     * @return string
     */
    public function author() { return 'MX-射手沫蝴蝶'; }

    /**
     * 返回版本号
     * @return string
     */
    public function version() { return '1.0.0'; }

    /**
     * 核心处理方法：子类覆盖此方法
     *
     * @param string $input  输入内容（URL 字符串 / M3U8 文本 / 其他字符串）
     * @param array  $context 上下文（含 'original_url', 'site_name', 'parse_time' 等）
     * @return string        处理后的字符串
     */
    public function apply($input, $context = []) {
        return $input; // 基类直接返回
    }

    /**
     * 判断当前输入是否需要执行本算法
     * @param string $input
     * @param array  $context
     * @return bool
     */
    public function shouldRun($input, $context = []) {
        if (!$this->enabled) return false;
        // 空匹配规则 → 总是执行
        if (empty($this->matchPatterns)) return true;
        // 有匹配规则 → 命中任一即执行
        foreach ($this->matchPatterns as $p) {
            if (stripos($input, $p) !== false) return true;
        }
        // 也检查 context 中的 URL
        if (!empty($context['original_url'])) {
            foreach ($this->matchPatterns as $p) {
                if (stripos($context['original_url'], $p) !== false) return true;
            }
        }
        return false;
    }

    /**
     * 便捷方法：测量 apply() 执行时间
     */
    final public function run($input, $context = []) {
        $start = microtime(true);
        $result = $this->apply($input, $context);
        $this->elapsedMs = round((microtime(true) - $start) * 1000, 3);
        if ($result !== $input) $this->hitCount++;
        return $result;
    }

    /**
     * 便捷：获取算法的数组摘要（供接口返回）
     */
    final public function toArray() {
        return [
            'id'          => $this->id ?: get_class($this),
            'name'        => $this->name(),
            'description' => $this->description(),
            'author'      => $this->author(),
            'version'     => $this->version(),
            'priority'    => $this->priority,
            'enabled'     => $this->enabled,
            'scope'       => $this->scope,
            'match_count' => count($this->matchPatterns),
            'elapsed_ms'  => $this->elapsedMs,
            'hits'        => $this->hitCount,
        ];
    }
}
