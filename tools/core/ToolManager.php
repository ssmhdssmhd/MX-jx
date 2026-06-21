<?php
/**
 * 本地工具管理器 ToolManager
 *
 * 职责：
 *   1. 扫描 tools/ 目录下所有 *Tool.php 文件，自动发现
 *   2. 按 group 分组列出已注册工具
 *   3. 通过 ID 调用具体工具（execute）
 *
 * 使用方式：
 *   require_once __DIR__ . '/tools/core/ToolManager.php';
 *   $manager = ToolManager::getInstance();
 *   $list = $manager->listTools();
 *   $result = $manager->runTool('ad_cleaner', ['input' => '...']);
 *
 * 后台 (admin.php) 可通过 AJAX 调用：action=tools_list / tool_run
 *
 * 低资源服务器友好：
 *   - 目录扫描仅做一次并在进程内缓存
 *   - 懒加载工具类（按需 require）
 *   - 工具本身都为纯 PHP 计算，无外部请求
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

class ToolManager
{
    /** @var ToolManager|null */
    private static $instance = null;

    /** 已加载工具实例 */
    private $tools = [];

    /** 是否已扫描 */
    private $scanned = false;

    /** 根目录 */
    private $rootDir;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->ensureBaseClass();
        $this->scan();
    }

    /** 加载基类（所有工具依赖 AbstractTool） */
    private function ensureBaseClass()
    {
        $file = __DIR__ . '/AbstractTool.php';
        if (file_exists($file) && !class_exists('AbstractTool', false)) {
            require_once $file;
        }
    }

    /**
     * 扫描 tools/ 目录下所有 *Tool.php 文件
     * 目录约定：
     *   tools/<group>/<Name>Tool.php   例如 tools/ad_cleaner/AdCleanerTool.php
     *   文件名必须与类名一致
     */
    public function scan()
    {
        if ($this->scanned) return;

        $dirs = glob($this->rootDir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) $dirs = [];

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*Tool.php');
            if (empty($files)) continue;
            $groupName = basename($dir);
            if ($groupName === 'core') continue; // 跳过 core 目录

            foreach ($files as $file) {
                $className = basename($file, '.php');
                // 防止与外部类名冲突，检查是否已加载
                if (!class_exists($className, false)) {
                    require_once $file;
                }
                if (class_exists($className, false) && is_subclass_of($className, 'AbstractTool')) {
                    /** @var AbstractTool $tool */
                    $tool = new $className();
                    if (empty($tool->group)) $tool->group = $groupName;
                    if (empty($tool->id))    $tool->id = $groupName . '_' . strtolower(str_replace('Tool', '', $className));
                    $this->tools[$tool->id] = $tool;
                }
            }
        }

        $this->scanned = true;
    }

    /** 强制重新扫描（后台新增工具后可调用） */
    public function reload()
    {
        $this->tools = [];
        $this->scanned = false;
        $this->scan();
        return count($this->tools);
    }

    /** 按分组列出所有工具 */
    public function listTools()
    {
        $byGroup = [];
        foreach ($this->tools as $id => $tool) {
            $byGroup[$tool->group][] = $tool->toArray();
        }
        return $byGroup;
    }

    /** 扁平化工具列表 */
    public function listToolsFlat()
    {
        $flat = [];
        foreach ($this->tools as $id => $tool) {
            $flat[] = $tool->toArray();
        }
        return $flat;
    }

    /** 通过 ID 获取工具 */
    public function getTool($id)
    {
        return $this->tools[$id] ?? null;
    }

    /** 通过 ID 运行工具 */
    public function runTool($id, $params = [])
    {
        if (empty($this->tools[$id])) {
            return [
                'success'   => false,
                'message'   => "工具 '{$id}' 未找到",
                'data'      => null,
                'elapsed_ms'=> 0,
                'tool_id'   => $id,
                'tool_name' => $id,
            ];
        }
        return $this->tools[$id]->execute($params);
    }

    /** 返回工具总数 */
    public function count() { return count($this->tools); }
}
