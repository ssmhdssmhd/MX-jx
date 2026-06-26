<?php
/**
 * 沫兮万能解析 - NoAd MD5 去插播专用接口
 * ============================================================
 * 功能：自动运行 MD5 深度分析去广告，返回去广告后的 M3U8 链接
 *
 * 使用方式：
 *   ?url=<M3U8地址>           // 自动分析并返回去广告后的 M3U8 链接
 *   ?url=<M3U8地址>&mode=json // 返回 JSON 格式结果
 *   ?url=<M3U8地址>&fast=1    // 快速模式（智能抽样，约25%片段）
 *   ?url=<M3U8地址>&force=1   // 强制重新分析（忽略缓存）
 *
 * 输出：
 *   默认：302 跳转到去广告后的 M3U8 链接
 *   mode=json：JSON 格式结果（包含广告信息、特征码等）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v4.5.6
 */

error_reporting(0);
ini_set('display_errors', 0);
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/algorithms/md5_pattern_cleaner.php';

$noadCfg = file_exists(__DIR__ . '/config/noad.php')
    ? require __DIR__ . '/config/noad.php'
    : [];

$url = $_GET['url'] ?? $_POST['url'] ?? '';
$mode = $_GET['mode'] ?? 'redirect';
$fast = isset($_GET['fast']) ? (int)$_GET['fast'] : 1;
$force = isset($_GET['force']) ? (int)$_GET['force'] : 0;
$procs = isset($_GET['procs']) ? (int)$_GET['procs'] : 0;
$concurrency = isset($_GET['concurrency']) ? (int)$_GET['concurrency'] : 0;
$callback = $_GET['callback'] ?? '';

if (empty($url)) {
    outputJson([
        'code' => 400,
        'msg'  => '缺少 url 参数',
        'usage' => '?url=<M3U8地址>',
    ], $callback);
    exit;
}

$url = trim($url);

if (!preg_match('#^https?://#i', $url)) {
    outputJson([
        'code' => 400,
        'msg'  => 'URL 格式错误，必须以 http:// 或 https:// 开头',
    ], $callback);
    exit;
}

$cacheDir = __DIR__ . '/cache/noad_md5';
@mkdir($cacheDir, 0755, true);

$urlHash = md5($url);
$cacheFile = $cacheDir . '/' . $urlHash . '.json';
$cacheM3u8 = $cacheDir . '/' . $urlHash . '.m3u8';

$cacheTtl = isset($noadCfg['cache_ttl_seconds']) ? (int)$noadCfg['cache_ttl_seconds'] : 1800;

if (!$force && file_exists($cacheFile) && file_exists($cacheM3u8) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cacheData) && !empty($cacheData['clean_url'])) {
        if ($mode === 'json') {
            $cacheData['from_cache'] = true;
            outputJson($cacheData, $callback);
        } else {
            header('Location: ' . $cacheData['clean_url'], true, 302);
        }
        exit;
    }
}

try {
    $md5 = new MD5PatternCleaner();

    $resolved = MD5PatternCleaner::resolveM3U8FromUrl($url);
    if ($resolved === false) {
        outputJson([
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
        outputJson([
            'code' => 500,
            'msg'  => 'M3U8 中没有找到可解析的片段',
        ], $callback);
        exit;
    }

    if (!empty($parsed['is_master'])) {
        if (!empty($parsed['master_variants'])) {
            $firstVariant = $parsed['master_variants'][0];
            $variantUrl = $md5->resolveUrl($finalUrl, $firstVariant['uri']);
            outputJson([
                'code' => 300,
                'msg'  => 'Master Playlist，请指定具体码率',
                'variants' => array_slice($parsed['master_variants'], 0, 5),
                'hint' => '请使用具体的 M3U8 地址，而非 master playlist',
            ], $callback);
            exit;
        }
    }

    $analyzed = null;
    $adIndexes = [];

    if ($fast && $totalSegments > 60 && method_exists($md5, 'deepAnalyzeByIndexes')) {
        $sampleCount = (int)ceil($totalSegments * 0.25);
        $sampleIdx = [];
        for ($i = 0; $i < 10; $i++) $sampleIdx[] = $i;
        $endStart = $totalSegments - 10;
        for ($i = $endStart; $i < $totalSegments; $i++) $sampleIdx[] = max(0, $i);
        $midStep = (int)floor(($totalSegments - 20) / max(1, $sampleCount - 20));
        for ($i = 10; $i < $endStart; $i += $midStep) {
            $sampleIdx[] = $i;
        }
        $sampleIdx = array_unique($sampleIdx);
        sort($sampleIdx);
        $sampleIdx = array_values($sampleIdx);

        $analyzed = $md5->deepAnalyzeByIndexes($m3u8Content, $finalUrl, $sampleIdx);
    } else {
        $analyzed = $md5->deepAnalyzeBatch($m3u8Content, $finalUrl, 0, 0);
    }

    if (is_array($analyzed) && !empty($analyzed['segments'])) {
        foreach ($analyzed['segments'] as $seg) {
            if (!empty($seg['is_ad']) && isset($seg['index'])) {
                $adIndexes[$seg['index']] = true;
            }
        }
    }

    $adCount = count($adIndexes);
    $cleanCount = $totalSegments - $adCount;

    $cleanM3u8Content = $md5->buildCleanM3U8ByIndex($m3u8Content, $finalUrl, $adIndexes);

    @file_put_contents($cacheM3u8, $cleanM3u8Content);

    $currentProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $currentPath = dirname($_SERVER['PHP_SELF'] ?? '/noad_md5.php');
    $cleanUrl = $currentProto . '://' . $currentHost . rtrim($currentPath, '/') . '/cache/noad_md5/' . $urlHash . '.m3u8';

    $deepAnalysis = null;
    $signatures = null;
    $commercials = null;
    $adSignatures = null;
    $commercialBreaks = null;

    if (is_array($analyzed) && method_exists($md5, 'deepAnalysisWithCommercials')) {
        $analyzedSegments = $analyzed['segments'] ?? [];
        $deepResult = $md5->deepAnalysisWithCommercials($m3u8Content, $finalUrl, $analyzedSegments);
        $commercialBreaks = $deepResult['commercial_breaks'] ?? null;
        $adSignatures = $deepResult['ad_signatures'] ?? null;
        $signatures = $deepResult['signatures'] ?? null;
        $deepAnalysis = $deepResult;
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
            'ad_indexes'         => array_keys($adIndexes),
            'commercial_breaks'  => $commercialBreaks,
            'ad_signatures'      => $adSignatures,
            'signatures'         => $signatures,
            'deep_analysis'      => $deepAnalysis,
        ],
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));

    if ($mode === 'json') {
        outputJson($result, $callback);
    } else {
        header('Location: ' . $cleanUrl, true, 302);
    }

} catch (Exception $e) {
    outputJson([
        'code' => 500,
        'msg'  => '分析失败: ' . $e->getMessage(),
    ], $callback);
}

function outputJson($data, $callback = '')
{
    if (!empty($callback) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $callback)) {
        header('Content-Type: application/javascript; charset=utf-8');
        echo $callback . '(' . json_encode($data, JSON_UNESCAPED_UNICODE) . ')';
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
