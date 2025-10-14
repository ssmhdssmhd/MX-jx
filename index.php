<?php
/**
 * PHP智能线路切换 - 并发版
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 3.0.0 2024-01-01
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 引入配置文件
require_once 'config/api.php';
require_once 'config/platform.php';
require_once 'config/switch.php';
require_once 'core/strategy.php';
require_once 'core/requester.php';
require_once 'disclaimer.php';
require_once 'handlers/M3U8Handler.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 初始化配置
$apiConfig = getApiConfig();
$platformConfig = getPlatformConfig();
$switchConfig = getSwitchConfig();

// 初始化核心组件
$strategy = new Strategy($apiConfig, $platformConfig, $switchConfig);
$requester = new Requester($strategy);

// 处理请求
$url = $_GET['url'] ?? ($_POST['url'] ?? '');
if (empty($url)) {
    $response = $strategy->generateErrorResponse(400, 'URL参数不能为空');
    echo json_encode($response);
    exit;
}

// 验证URL格式
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    $response = $strategy->generateErrorResponse(400, 'URL格式不正确');
    echo json_encode($response);
    exit;
}

// 检查M3U8链接并直接输出
$m3u8Response = M3U8Handler::handleM3U8($url, $switchConfig['enable_m3u8_direct'], $switchConfig['enable_unified_display']);
if ($m3u8Response) {
    echo $m3u8Response;
    exit;
}

// 执行解析
try {
    $data = $requester->concurrentRequest($url);
    echo $data;
} catch (Exception $e) {
    $response = $strategy->generateErrorResponse(500, '服务器内部错误: ' . $e->getMessage());
    echo json_encode($response);
}

/**
 * 获取API配置
 */
function getApiConfig() {
    return require 'config/api.php';
}

/**
 * 获取平台配置
 */
function getPlatformConfig() {
    return require 'config/platform.php';
}

/**
 * 获取开关配置
 */
function getSwitchConfig() {
    return require 'config/switch.php';
}