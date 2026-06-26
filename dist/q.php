<?php
/**
 * 沫兮万能解析 - 去广告专用接口 (/q?url=)
 * ============================================================
 * 功能：调用去广告算法去除 M3U8 中的广告和插播，返回纯净播放链接
 *
 * 使用方式：
 *   /q?url=<M3U8地址>                    // 去广告并返回JSON结果
 *   /q?url=<M3U8地址>&fast=1             // 快速模式（智能抽样）
 *   /q?url=<M3U8地址>&force=1            // 强制重新分析
 *   /q?url=<M3U8地址>&procs=8            // 指定多进程数
 *   /q?url=<M3U8地址>&sync=1             // 同步特征码到指纹库（默认开启）
 *
 * 返回格式（JSON）：
 *   {
 *     "code": 200,
 *     "msg": "ok",
 *     "data": {
 *       "original_url": "原始地址",
 *       "clean_url": "去广告后的M3U8地址",
 *       "total_segments": 总片段数,
 *       "ad_segments": 广告片段数,
 *       "clean_segments": 纯净片段数,
 *       "ad_ratio": 广告占比%,
 *       "from_cache": 是否来自缓存
 *     }
 *   }
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v5.2.0
 */

error_reporting(0);
ini_set('display_errors', 0);
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$switchConfig = file_exists(__DIR__ . '/config/switch.php')
    ? require __DIR__ . '/config/switch.php'
    : [];

if (empty($switchConfig['ad_remove_url_enabled'])) {
    echo json_encode([
        'code' => 503,
        'msg'  => '去广告链接接口未启用，请在后台系统设置中开启',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($switchConfig['ad_remove_enabled'])) {
    echo json_encode([
        'code' => 503,
        'msg'  => '去广告功能未启用，请在后台系统设置中开启',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($switchConfig['noad_enabled'])) {
    echo json_encode([
        'code' => 503,
        'msg'  => 'NoAd 去广告系统未启用，请在后台系统设置中开启',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/algorithms/md5_pattern_cleaner.php';

$noadCfg = file_exists(__DIR__ . '/config/noad.php')
    ? require __DIR__ . '/config/noad.php'
    : [];

$url = $_GET['url'] ?? $_POST['url'] ?? '';
$fast = isset($_GET['fast']) ? (int)$_GET['fast'] : 1;
$force = isset($_GET['force']) ? (int)$_GET['force'] : 0;
$procs = isset($_GET['procs']) ? (int)$_GET['procs'] : 0;
$concurrency = isset($_GET['concurrency']) ? (int)$_GET['concurrency'] : 0;
$sync = isset($_GET['sync']) ? (int)$_GET['sync'] : 1;
$callback = $_GET['callback'] ?? '';

if (empty($url)) {
    outputQJson([
        'code' => 400,
        'msg'  => '缺少 url 参数',
        'usage' => '/q?url=<M3U8地址>',
        'version' => 'v5.2.0',
    ], $callback);
    exit;
}

$url = trim($url);

if (!preg_match('#^https?://#i', $url)) {
    outputQJson([
        'code' => 400,
        'msg'  => 'URL 格式错误，必须以 http:// 或 https:// 开头',
    ], $callback);
    exit;
}

$cacheDir = __DIR__ . '/cache/q_ad_remove';
@mkdir($cacheDir, 0755, true);

$urlHash = md5($url);
$cacheFile = $cacheDir . '/' . $urlHash . '.json';
$cacheM3u8 = $cacheDir . '/' . $urlHash . '.m3u8';
$lockFile = $cacheDir . '/' . $urlHash . '.lock';

$cacheTtl = isset($noadCfg['cache_ttl_seconds']) ? (int)$noadCfg['cache_ttl_seconds'] : 1800;
$autoSync = isset($noadCfg['md5_auto_sync_signatures']) ? (bool)$noadCfg['md5_auto_sync_signatures'] : true;

$currentProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $currentProto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
}
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/q.php';
$currentPath = rtrim(dirname($scriptName), '/');
if ($currentPath === '.' || $currentPath === '\\') {
    $currentPath = '';
}
$cleanUrl = $currentProto . '://' . $currentHost . $currentPath . '/cache/q_ad_remove/' . $urlHash . '.m3u8';

if (!$force && file_exists($cacheFile) && file_exists($cacheM3u8) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cacheData) && !empty($cacheData['data']['clean_url'])) {
        $cacheData['data']['from_cache'] = true;
        $cacheData['data']['cached_at'] = filemtime($cacheFile);
        $cacheData['data']['cache_expires_in'] = $cacheTtl - (time() - filemtime($cacheFile));
        outputQJson($cacheData, $callback);
        exit;
    }
}

@file_put_contents($lockFile, time());

try {
    $md5 = new MD5PatternCleaner();

    if ($procs > 0 || $concurrency > 0) {
        $md5->setRuntimeParams([
            'num_processes' => $procs,
            'max_concurrency' => $concurrency,
        ]);
    }

    $resolved = MD5PatternCleaner::resolveM3U8FromUrl($url);
    if ($resolved === false) {
        @unlink($lockFile);
        outputQJson([
            'code' => 502,
            'msg'  => '无法获取 M3U8 内容，请检查 URL 是否有效',
        ], $callback);
        exit;
    }

    $m3u8Content = $resolved['content'];
    $finalUrl = $resolved['final_url'] ?? $url;

    $parsed = $md5->parseM3U8($m3u8Content);
    $segments = $parsed['segments'];
    $totalSegments = count($segments);

    if ($totalSegments === 0) {
        @unlink($lockFile);
        outputQJson([
            'code' => 500,
            'msg'  => 'M3U8 中没有找到可解析的片段',
        ], $callback);
        exit;
    }

    if (!empty($parsed['is_master'])) {
        if (!empty($parsed['master_variants'])) {
            $firstVariant = $parsed['master_variants'][0];
            $variantUrl = $md5->resolveUrl($finalUrl, $firstVariant['uri']);
            @unlink($lockFile);
            outputQJson([
                'code' => 300,
                'msg'  => 'Master Playlist，请使用具体码率的 M3U8 地址',
                'variants' => array_slice($parsed['master_variants'], 0, 5),
            ], $callback);
            exit;
        }
    }

    $deepResult = $md5->deepAnalyzeBatch($segments, $finalUrl, $fast ? 'sample' : 'full');
    $adIndices = $deepResult['ad_segments'] ?? [];
    $adCount = count($adIndices);
    $cleanCount = $totalSegments - $adCount;

    $cleanM3U8 = $md5->buildCleanM3U8ByIndex($m3u8Content, $adIndices);
    @file_put_contents($cacheM3u8, $cleanM3U8);

    $syncResult = null;
    $fingerprintStats = null;
    if ($sync && $autoSync && !empty($deepResult['ad_signatures'])) {
        $syncResult = $md5->syncAdSignaturesToDB($deepResult['ad_signatures'], $url);
        $fingerprintStats = $md5->getFingerprintStats();
    }

    $result = [
        'code' => 200,
        'msg'  => 'ok',
        'data' => [
            'original_url'       => $url,
            'final_url'          => $finalUrl,
            'clean_url'          => $cleanUrl,
            'total_segments'     => $totalSegments,
            'ad_segments'        => $adCount,
            'clean_segments'     => $cleanCount,
            'ad_ratio'           => $totalSegments > 0 ? round($adCount / $totalSegments * 100, 2) : 0,
            'fast_mode'          => (bool)$fast,
            'analysis_mode'      => $fast ? 'smart_sample' : 'full',
            'auto_sync'          => (bool)($sync && $autoSync),
            'sync_result'        => $syncResult,
            'fingerprint_stats'  => $fingerprintStats,
            'from_cache'         => false,
        ],
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    @unlink($lockFile);

    outputQJson($result, $callback);

} catch (Throwable $e) {
    @unlink($lockFile);
    outputQJson([
        'code' => 500,
        'msg'  => '分析失败: ' . $e->getMessage(),
    ], $callback);
}

function outputQJson($data, $callback = '') {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (!empty($callback) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $callback)) {
        echo $callback . '(' . $json . ');';
    } else {
        echo $json;
    }
}
