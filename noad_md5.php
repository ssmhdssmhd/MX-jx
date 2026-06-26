<?php
/**
 * 沫兮万能解析 - NoAd MD5 去插播专用接口
 * ============================================================
 * 功能：自动运行 MD5 深度分析去广告，返回去广告后的 M3U8 链接
 *
 * 使用方式：
 *   ?url=<M3U8地址>                        // 自动分析并返回去广告后的 M3U8 链接
 *   ?url=<M3U8地址>&mode=json             // 返回 JSON 格式结果
 *   ?url=<M3U8地址>&fast=1                // 快速模式（智能抽样，约25%片段）
 *   ?url=<M3U8地址>&force=1               // 强制重新分析（忽略缓存）
 *   ?url=<M3U8地址>&procs=8               // 多进程数（1-32，默认自动）
 *   ?url=<M3U8地址>&concurrency=12        // curl并发数（1-32，默认12）
 *   ?url=<M3U8地址>&sync=1                // 同步广告特征码到指纹库（默认开启）
 *   ?url=<M3U8地址>&async=1               // 异步模式：快速返回缓存，后台深度分析
 *
 * 核心机制：
 *   1. 用户访问接口 → 自动运行多线程MD5深度分析
 *   2. 识别广告和插播片段 → 自动过滤
 *   3. 广告特征码自动同步到MD5指纹库（算法列表）
 *   4. 生成缓存 → 后续相同请求直接返回，秒开播放
 *   5. 指纹库累积越多，识别越准，速度越快
 *
 * 性能优化：
 *   - 多进程并行下载：自动检测CPU核心数，最高16进程
 *   - 子进程内curl_multi并发：每进程可同时下载多个TS片段
 *   - 智能抽样快速模式：仅分析约25%片段，速度提升4倍
 *   - 智能缓存：30分钟内相同请求秒开
 *   - 特征码自学习：越用越准，广告识别率持续提升
 *
 * 输出：
 *   默认：302 跳转到去广告后的 M3U8 链接
 *   mode=json：JSON 格式结果（包含广告信息、特征码等）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v5.1.0
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
$sync = isset($_GET['sync']) ? (int)$_GET['sync'] : 1;
$async = isset($_GET['async']) ? (int)$_GET['async'] : 0;
$callback = $_GET['callback'] ?? '';

if (empty($url)) {
    outputJson([
        'code' => 400,
        'msg'  => '缺少 url 参数',
        'usage' => '?url=<M3U8地址>',
        'version' => 'v5.1.0',
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
$lockFile = $cacheDir . '/' . $urlHash . '.lock';

$cacheTtl = isset($noadCfg['cache_ttl_seconds']) ? (int)$noadCfg['cache_ttl_seconds'] : 1800;
$autoSync = isset($noadCfg['md5_auto_sync_signatures']) ? (bool)$noadCfg['md5_auto_sync_signatures'] : true;

$currentProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentPath = dirname($_SERVER['PHP_SELF'] ?? '/noad_md5.php');
$cleanUrl = $currentProto . '://' . $currentHost . rtrim($currentPath, '/') . '/cache/noad_md5/' . $urlHash . '.m3u8';

if (!$force && file_exists($cacheFile) && file_exists($cacheM3u8) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cacheData) && !empty($cacheData['data']['clean_url'])) {
        if ($async) {
            triggerBackgroundAnalysis($url, $urlHash, $procs, $concurrency, $fast, $sync);
        }
        if ($mode === 'json') {
            $cacheData['from_cache'] = true;
            $cacheData['cached_at'] = filemtime($cacheFile);
            $cacheData['cache_expires_in'] = $cacheTtl - (time() - filemtime($cacheFile));
            outputJson($cacheData, $callback);
        } else {
            header('Location: ' . $cacheData['data']['clean_url'], true, 302);
        }
        exit;
    }
}

if ($async && file_exists($lockFile) && (time() - filemtime($lockFile) < 300)) {
    if ($mode === 'json') {
        outputJson([
            'code' => 202,
            'msg'  => '分析中，请稍后重试',
            'data' => [
                'original_url' => $url,
                'status' => 'analyzing',
                'retry_after' => 5,
            ],
        ], $callback);
    } else {
        header('Retry-After: 5');
        http_response_code(202);
        echo 'Analyzing... Please retry after 5 seconds.';
    }
    exit;
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
        @unlink($lockFile);
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
            @unlink($lockFile);
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

    $deepAnalysis = null;
    $signatures = null;
    $adSignatures = null;
    $commercialBreaks = null;
    $syncResult = null;

    if (is_array($analyzed) && method_exists($md5, 'deepAnalysisWithCommercials')) {
        $analyzedSegments = $analyzed['segments'] ?? [];
        $deepResult = $md5->deepAnalysisWithCommercials($m3u8Content, $finalUrl, $analyzedSegments);
        $commercialBreaks = $deepResult['commercial_breaks'] ?? null;
        $adSignatures = $deepResult['ad_signatures'] ?? null;
        $signatures = $deepResult['signatures'] ?? null;
        $deepAnalysis = $deepResult;

        if ($sync && $autoSync && !empty($adSignatures) && is_array($adSignatures)) {
            $syncResult = $md5->syncAdSignaturesToDB($adSignatures, $url);
        }
    }

    $fingerprintStats = null;
    if (method_exists($md5, 'getFingerprintStats')) {
        $fingerprintStats = $md5->getFingerprintStats();
    }

    $result = [
        'code' => 200,
        'msg'  => 'ok',
        'version' => 'v5.1.0',
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
            'ad_indexes'         => array_keys($adIndexes),
            'commercial_breaks'  => $commercialBreaks,
            'ad_signatures'      => $adSignatures,
            'signatures'         => $signatures,
            'deep_analysis'      => $deepAnalysis,
            'cached_at'          => time(),
            'cache_expires_in'   => $cacheTtl,
        ],
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    @unlink($lockFile);

    if ($mode === 'json') {
        outputJson($result, $callback);
    } else {
        header('Location: ' . $cleanUrl, true, 302);
    }

} catch (Exception $e) {
    @unlink($lockFile);
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

function triggerBackgroundAnalysis($url, $urlHash, $procs, $concurrency, $fast, $sync)
{
    $cacheDir = __DIR__ . '/cache/noad_md5';
    $lockFile = $cacheDir . '/' . $urlHash . '.lock';

    if (file_exists($lockFile) && (time() - filemtime($lockFile) < 300)) {
        return;
    }

    @file_put_contents($lockFile, time());

    $phpBinary = PHP_BINARY;
    if (empty($phpBinary) || !is_executable($phpBinary)) {
        $phpBinary = 'php';
    }

    $scriptPath = __FILE__;
    $query = http_build_query([
        'url' => $url,
        'mode' => 'json',
        'fast' => $fast,
        'force' => 1,
        'procs' => $procs,
        'concurrency' => $concurrency,
        'sync' => $sync,
        'bg' => 1,
    ]);

    $cmd = sprintf(
        '%s %s %s > /dev/null 2>&1 &',
        escapeshellarg($phpBinary),
        escapeshellarg($scriptPath),
        escapeshellarg($query)
    );

    @shell_exec($cmd);
}
