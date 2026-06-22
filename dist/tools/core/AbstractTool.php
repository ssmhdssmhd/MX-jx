<?php
/**
 * 本地工具基类 AbstractTool
 *
 * 所有 tools/ 下的工具都要继承此类。
 * 核心能力：
 *   - name(): 工具名称（后台展示用）
 *   - description(): 工具描述
 *   - version(): 版本号
 *   - run(array $params): 执行工具核心逻辑，返回数组结果
 *   - getParamSchema(): 参数定义（供后台生成表单 / 校验入参）
 *
 * 低资源服务器友好：所有工具均为纯 PHP，无外部依赖。
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

abstract class AbstractTool
{
    /** 工具唯一 ID（建议与类名一致） */
    public $id = '';

    /** 工具分组：ad_cleaner / feature_extractor / generic */
    public $group = 'generic';

    /** 执行耗时（毫秒，由基类统计） */
    public $elapsedMs = 0;

    /** 运行时错误信息 */
    protected $lastError = '';

    /**
     * 返回工具名称（后台展示）
     * @return string
     */
    abstract public function name();

    /**
     * 返回工具描述
     * @return string
     */
    abstract public function description();

    /**
     * 返回版本号
     * @return string
     */
    public function version() { return '1.0.0'; }

    /**
     * 参数定义 schema：
     *   [
     *     'param_name' => ['type' => 'string|int|bool|url', 'required' => true|false, 'default' => 'xxx', 'label' => '显示名', 'hint' => '提示']
     *   ]
     * @return array
     */
    public function getParamSchema() { return []; }

    /**
     * 核心执行方法：子类覆盖
     *
     * @param array $params  按 schema 传进来的参数
     * @return array  执行结果，必须包含：
     *   - success: bool
     *   - message: string（摘要信息）
     *   - data:    mixed（结构化结果，供 JSON 输出）
     */
    abstract public function run($params = []);

    /**
     * 包装后的对外入口：计时、参数校验、错误捕获
     */
    final public function execute($params = [])
    {
        $start = microtime(true);
        try {
            // 参数校验 & 默认值填充
            $schema = $this->getParamSchema();
            foreach ($schema as $name => $rule) {
                if (!isset($params[$name]) || $params[$name] === '' || $params[$name] === null) {
                    if (!empty($rule['required']) && !array_key_exists('default', $rule)) {
                        throw new Exception("参数 '{$name}' 不能为空");
                    }
                    if (array_key_exists('default', $rule)) {
                        $params[$name] = $rule['default'];
                    }
                }
                // 简单类型转换
                if (isset($params[$name])) {
                    if (!empty($rule['type']) && $rule['type'] === 'int') {
                        $params[$name] = (int)$params[$name];
                    } elseif (!empty($rule['type']) && $rule['type'] === 'bool') {
                        $params[$name] = (bool)$params[$name];
                    }
                }
            }

            $result = $this->run($params);
            if (!is_array($result)) $result = ['success' => false, 'message' => '工具返回格式异常', 'data' => null];
            $result['success'] = $result['success'] ?? false;
            $result['message'] = $result['message'] ?? '';
            $result['data']    = $result['data']    ?? null;
            $this->elapsedMs  = round((microtime(true) - $start) * 1000, 2);
            $result['elapsed_ms'] = $this->elapsedMs;
            $result['tool_id']    = $this->id;
            $result['tool_name']  = $this->name();
            return $result;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->elapsedMs = round((microtime(true) - $start) * 1000, 2);
            return [
                'success'    => false,
                'message'    => $e->getMessage(),
                'data'       => null,
                'elapsed_ms' => $this->elapsedMs,
                'tool_id'    => $this->id,
                'tool_name'  => $this->name(),
            ];
        }
    }

    /** 获取摘要信息（供 ToolManager 列表展示） */
    final public function toArray()
    {
        return [
            'id'          => $this->id ?: get_class($this),
            'name'        => $this->name(),
            'description' => $this->description(),
            'version'     => $this->version(),
            'group'       => $this->group,
            'schema'      => $this->getParamSchema(),
        ];
    }

    /** 获取最后一次错误信息 */
    public function getLastError() { return $this->lastError; }
}
