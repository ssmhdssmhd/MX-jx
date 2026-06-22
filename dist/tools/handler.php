<?php
/**
 * 本地工具 HTTP 接口 —— 供后台 admin.php 通过 AJAX 调用
 *
 * 调用方式：
 *   1) 列出所有工具：
 *      GET  /tools/handler.php?action=list
 *
 *   2) 运行指定工具（POST 表单参数）：
 *      POST /tools/handler.php
 *      Form: action=run
 *            tool_id=ad_cleaner_m3u8
 *            input=...M3U8内容或URL...
 *            <其他参数按工具 schema 传...>
 *
 *   3) 重新扫描工具目录（后台新增工具后使用）：
 *      GET  /tools/handler.php?action=reload
 *
 * 返回格式：统一 JSON，字段含义见 ToolManager/AbstractTool。
 */

// 若项目目录下存在 admin 权限控制，可在此处校验
// 例如：require_once __DIR__ . '/../admin.php'; 然后检查 session

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bootstrap.php';

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'list';
    $manager = ToolManager::getInstance();

    switch ($action) {
        case 'list':
            echo json_encode([
                'success' => true,
                'message' => '工具列表加载成功，共 ' . $manager->count() . ' 个工具',
                'data'    => $manager->listTools(),
                'total'   => $manager->count(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'run':
            $toolId = $_POST['tool_id'] ?? $_GET['tool_id'] ?? '';
            if (empty($toolId)) {
                throw new Exception('参数 tool_id 不能为空');
            }
            // 收集除 action/tool_id 以外的所有参数作为工具入参
            $params = [];
            foreach (array_merge($_GET, $_POST) as $k => $v) {
                if ($k === 'action' || $k === 'tool_id') continue;
                $params[$k] = $v;
            }
            $result = $manager->runTool($toolId, $params);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'reload':
            $count = $manager->reload();
            echo json_encode([
                'success' => true,
                'message' => "重新扫描完成，共 {$count} 个工具",
                'data'    => $manager->listTools(),
                'total'   => $count,
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => '未知 action 参数',
                'data'    => null,
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
}
