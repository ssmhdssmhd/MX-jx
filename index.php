<?php
/**
 * PHP 智能线路切换 - 并发版 + Noad 去广告解析
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v5.1.1
 *
 * 使用方法:
 *   /?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html
 *   /?url=...&type=movie   (指定7种资源类型之一)
 *   /?url=...&mode=noad    (强制使用 Noad 去广告版)
 *   /?url=...&mode=legacy  (强制使用旧版并发解析)
 */

// 错误报告设置（生产环境建议仅记录致命错误，关闭显示）
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('html_errors', 0);

// ========== Noad 系统判断（高优先级）==========
$noadConfigFile = __DIR__ . '/config/noad.php';
$useNoad = false;
$noadConfig = null;
if (file_exists($noadConfigFile)) {
    $noadConfig = require $noadConfigFile;
    if (!empty($noadConfig['noad_enabled'])) {
        $useNoad = true;
    }
}

// URL 参数
$mode = $_GET['mode'] ?? 'auto';   // auto / noad / legacy
$url  = $_GET['url'] ?? ($_POST['url'] ?? '');
$videoType = $_GET['type'] ?? '';

// 快速空值响应
if (empty($url)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(array(
        'code' => 400,
        'msg'  => 'URL 参数不能为空，用法：/?url=视频播放地址',
        'usage' => '/?url=https://v.qq.com/...&type=movie|tv|variety|anime|document|sports|short',
        'developer' => 'MX-射手沫蝴蝶',
        'contact' => 'QQ: 2094332348',
        'version' => 'v5.1.1',
        'timestamp' => time(),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// 校验 URL 格式
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => 400, 'msg' => 'URL 格式不正确'), JSON_UNESCAPED_UNICODE);
    exit;
}

// SSRF 防护：仅允许 http / https
$urlScheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array(strtolower($urlScheme), array('http', 'https'), true)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => 400, 'msg' => '仅支持 http / https 协议'),
                      JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== Noad 去广告解析（v4 新增路径）==========
if ($useNoad && ($mode === 'auto' || $mode === 'noad')) {
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/NoAdParser.php';

    try {
        $parser = new NoAdParser();
        $result = $parser->parse($url, $videoType);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        // Noad 异常时不中断整个服务，回退到 legacy 模式
        error_log('[Noad] 解析异常: ' . $e->getMessage());
        $mode = 'legacy';
    }
}

// ========== 原有并发解析引擎（legacy 模式，向后兼容）==========
require_once 'config/api.php';
require_once 'config/platform.php';
require_once 'config/switch.php';
require_once 'core/strategy.php';
require_once 'core/requester.php';
require_once 'handlers/M3U8Handler.php';

$apiConfig = getApiConfig();
$platformConfig = getPlatformConfig();
$switchConfig = getSwitchConfig();

$strategy = new Strategy($apiConfig, $platformConfig, $switchConfig);
$requester = new Requester($strategy);

// M3U8 直链快速通道
if (M3U8Handler::isM3U8Url($url)) {
    $m3u8Response = M3U8Handler::generateM3U8Response($url, true);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($m3u8Response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 执行并发解析
try {
    $data = $requester->concurrentRequest($url);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo $data;
} catch (Exception $e) {
    echo json_encode(array(
        'code' => 500,
        'msg'  => '服务器内部错误: ' . $e->getMessage(),
    ), JSON_UNESCAPED_UNICODE);
}

function getApiConfig() { return require 'config/api.php'; }
function getPlatformConfig() { return require 'config/platform.php'; }
function getSwitchConfig() { return require 'config/switch.php'; }
