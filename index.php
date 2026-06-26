<?php
/**
 * PHP 智能线路切换 - 并发版 + Noad 去广告解析
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v5.2.0
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

// ========== 加载系统开关配置 ==========
$switchConfigFile = __DIR__ . '/config/switch.php';
$switchConfig = file_exists($switchConfigFile) ? require $switchConfigFile : [];

// ========== 视频解析接口开关检查 ==========
if (empty($switchConfig['video_parse_enabled'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(array(
        'code' => 503,
        'msg'  => '视频解析功能未启用，请在后台系统设置中开启',
        'developer' => 'MX-射手沫蝴蝶',
        'contact' => 'QQ: 2094332348',
        'version' => 'v5.2.0',
        'timestamp' => time(),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

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

        // 解析结果集成去广告链接
        if (!empty($switchConfig['parse_integrate_noad']) &&
            !empty($switchConfig['ad_remove_enabled']) &&
            !empty($switchConfig['ad_remove_url_enabled']) &&
            !empty($result['code']) && $result['code'] == 200) {
            $result['noad_urls'] = buildNoadUrlArray($url, $result);
        }

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

    // 解析结果集成去广告链接
    if (!empty($switchConfig['parse_integrate_noad']) &&
        !empty($switchConfig['ad_remove_enabled']) &&
        !empty($switchConfig['ad_remove_url_enabled']) &&
        !empty($m3u8Response['code']) && $m3u8Response['code'] == 200) {
        $m3u8Response['noad_urls'] = buildNoadUrlArray($url, $m3u8Response);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($m3u8Response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 执行并发解析
try {
    $data = $requester->concurrentRequest($url);

    // 解析结果集成去广告链接
    if (!empty($switchConfig['parse_integrate_noad']) &&
        !empty($switchConfig['ad_remove_enabled']) &&
        !empty($switchConfig['ad_remove_url_enabled'])) {
        $decoded = json_decode($data, true);
        if (is_array($decoded) && !empty($decoded['code']) && $decoded['code'] == 200) {
            $decoded['noad_urls'] = buildNoadUrlArray($url, $decoded);
            $data = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo $data;
} catch (Exception $e) {
    echo json_encode(array(
        'code' => 500,
        'msg'  => '服务器内部错误: ' . $e->getMessage(),
    ), JSON_UNESCAPED_UNICODE);
}

/**
 * 构建去广告链接数组
 * @param string $originalUrl 原始视频URL
 * @param array $parseResult 解析结果
 * @return array 去广告链接数组
 */
function buildNoadUrlArray($originalUrl, $parseResult) {
    $noadUrls = array();

    // 获取当前服务器协议和域名
    $currentProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $currentProto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
    $currentPath = rtrim(dirname($scriptName), '/');
    if ($currentPath === '.' || $currentPath === '\\') {
        $currentPath = '';
    }
    $baseUrl = $currentProto . '://' . $currentHost . $currentPath;

    // 方式1: 通过 q.php 去广告接口（传入解析后的播放地址）
    $playUrl = !empty($parseResult['url']) ? $parseResult['url'] : '';
    if (!empty($playUrl) && filter_var($playUrl, FILTER_VALIDATE_URL)) {
        $noadUrls[] = array(
            'type' => 'q_php',
            'name' => '去广告播放链接（q.php接口）',
            'url'  => $baseUrl . '/q.php?url=' . urlencode($playUrl),
            'desc' => '通过 q.php 接口去除 M3U8 中的广告和插播片段'
        );
    }

    // 方式2: 通过 q 短路径去广告接口
    if (!empty($playUrl) && filter_var($playUrl, FILTER_VALIDATE_URL)) {
        $noadUrls[] = array(
            'type' => 'q_short',
            'name' => '去广告播放链接（短路径）',
            'url'  => $baseUrl . '/q?url=' . urlencode($playUrl),
            'desc' => '通过短路径接口去除 M3U8 中的广告和插播片段'
        );
    }

    // 方式3: noad_url（如果解析结果中已包含）
    if (!empty($parseResult['noad_url']) && filter_var($parseResult['noad_url'], FILTER_VALIDATE_URL)) {
        $noadUrls[] = array(
            'type' => 'noad_parser',
            'name' => 'NoAd 去广告版链接',
            'url'  => $parseResult['noad_url'],
            'desc' => 'NoAdParser 内置去广告处理后的播放链接'
        );
    }

    // 方式4: 直接对原始视频URL去广告
    if (!empty($originalUrl) && filter_var($originalUrl, FILTER_VALIDATE_URL)) {
        $noadUrls[] = array(
            'type' => 'q_php_original',
            'name' => '原始链接去广告（q.php接口）',
            'url'  => $baseUrl . '/q.php?url=' . urlencode($originalUrl),
            'desc' => '直接对原始视频页面URL进行去广告处理'
        );
    }

    return $noadUrls;
}

function getApiConfig() { return require 'config/api.php'; }
function getPlatformConfig() { return require 'config/platform.php'; }
function getSwitchConfig() { return require 'config/switch.php'; }
