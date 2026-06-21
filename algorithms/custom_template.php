<?php
/**
 * 自定义算法模板：复制本文件并修改 class 名 + 逻辑即可生效
 *
 * 文件名建议: custom_<你的描述>.php，例如 custom_bilibili_cleaner.php
 *
 * 运行机制：
 *   - 系统启动时会扫描 algorithms/ 目录下所有 *.php 文件
 *   - 识别继承自 AbstractAlgorithm 的类并自动注册
 *   - 按 priority 属性排序后依次执行
 *
 * 常用钩子方法（按需 override）：
 *   - name():        string   返回算法展示名
 *   - description(): string   返回算法功能描述
 *   - shouldRun($in, $ctx):  bool  判断是否需要执行
 *   - apply($in, $ctx):      string 处理输入，返回处理结果
 *
 * 可调属性：
 *   - $this->id          唯一标识符，默认等于类名
 *   - $this->priority    优先级，数字越大越先执行（默认 10）
 *   - $this->enabled     是否启用（默认 true）
 *   - $this->scope       作用域 'url' | 'm3u8' | 'all'（默认 'all'）
 *   - $this->matchPatterns  匹配规则数组，空数组 = 总是执行
 *   - $this->config      任意键值配置，便于在线编辑
 *
 * 上下文 $context 可能包含的字段：
 *   - original_url       原始请求的视频 URL
 *   - site_name          命中的资源站名称（如："电影天堂"）
 *   - base_url           当前 M3U8 的基础 URL（补全相对路径时使用）
 *   - scope              'url' | 'm3u8'
 *   - parse_time         请求开始时间戳
 *
 * 调试提示：在开发时可用 error_log() 将日志写入 PHP error_log
 *
 * @author 请填写你的名字
 * @version 1.0.0
 */
class CustomAlgorithmTemplate extends AbstractAlgorithm {

    public $id       = 'custom_template';
    public $priority = 15;      // 数字越大越先执行
    public $enabled  = true;
    public $scope    = 'all';   // 'url' | 'm3u8' | 'all'
    public $matchPatterns = []; // 空数组 → 总是执行

    /** 自定义配置：可通过后台在线修改 */
    public $config = [
        'example_param' => 'hello',
    ];

    public function name() { return '自定义算法模板（请修改类名和 name()）'; }

    public function description() {
        return '这是一个用户自定义算法的模板文件，复制后修改类名与 apply() 即可新增一种去广告/去垃圾逻辑';
    }

    public function version() { return '1.0.0'; }

    /**
     * 核心处理：对输入字符串进行改造并返回
     * @param string $input 输入（URL 字符串 / M3U8 文本 / 任意字符串）
     * @param array  $context 上下文信息
     * @return string 处理后的字符串
     */
    public function apply($input, $context = []) {
        // --------------------------
        // 示例：在这里写你的处理逻辑
        // --------------------------
        // $result = str_replace('ad_', '', $input);

        // 这里的示范：原样返回（实际使用时请替换为你的业务逻辑）
        $result = $input;

        return $result;
    }

    /**
     * 可选：在此处判断是否需要执行本算法
     * 返回 false 时 apply() 将被跳过，节省性能
     */
    public function shouldRun($input, $context = []) {
        if (!parent::shouldRun($input, $context)) return false;
        // 示例：只对包含某些关键字的 URL 执行
        // if (strpos($input, 'bilibili') === false) return false;
        return true;
    }
}
