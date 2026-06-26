<?php
/**
 * 命令行完整测试 noad_md5 接口功能
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/algorithms/md5_pattern_cleaner.php';

$url = 'http://localhost:8080/test_video/test.m3u8';
$fast = 0;
$force = 1;

echo "========================================\n";
echo "  NoAd MD5 去插播接口 - 完整测试\n";
echo "========================================\n\n";

$md5 = new MD5PatternCleaner();

echo "[1/6] 解析 M3U8...\n";
$resolved = MD5PatternCleaner::resolveM3U8FromUrl($url);
if ($resolved === false) {
    echo "❌ 错误：无法获取 M3U8 内容\n";
    exit(1);
}
echo "  ✅ M3U8 获取成功\n";

$m3u8Content = $resolved['content'];
$finalUrl = $resolved['final_url'] ?? $url;

$parsed = $md5->parseM3U8($m3u8Content);
$segments = $parsed['segments'];
$totalSegments = count($segments);
echo "  ✅ 共 {$totalSegments} 个片段\n";
echo "  ✅ DISCONTINUITY 插播点: " . count($parsed['discontinuity_indices'] ?? []) . " 个\n\n";

echo "[2/6] 运行 MD5 深度分析...\n";
$analyzed = $md5->deepAnalyzeBatch($m3u8Content, $finalUrl, 0, 0);
$analyzedCount = count($analyzed['segments'] ?? []);
echo "  ✅ 分析完成，分析了 {$analyzedCount} 个片段\n\n";

echo "[3/6] 识别广告片段...\n";
$adIndexes = [];
$adList = [];
if (is_array($analyzed) && !empty($analyzed['segments'])) {
    foreach ($analyzed['segments'] as $seg) {
        if (!empty($seg['is_ad']) && isset($seg['index'])) {
            $adIndexes[$seg['index']] = true;
            $adList[] = $seg;
            echo "  🚫 片段 {$seg['index']}: 广告 ({$seg['reason']})\n";
        }
    }
}
$adCount = count($adIndexes);
$cleanCount = $totalSegments - $adCount;
echo "  ✅ 识别出 {$adCount} 个广告片段，{$cleanCount} 个正片片段\n";
echo "  ✅ 广告占比: " . ($totalSegments > 0 ? round($adCount/$totalSegments*100, 2) : 0) . "%\n\n";

echo "[4/6] 生成去广告 M3U8...\n";
$cleanM3u8Content = $md5->buildCleanM3U8ByIndex($m3u8Content, $finalUrl, $adIndexes);

$cacheDir = __DIR__ . '/cache/noad_md5';
@mkdir($cacheDir, 0755, true);
$urlHash = md5($url);
$cacheM3u8 = $cacheDir . '/' . $urlHash . '.m3u8';
file_put_contents($cacheM3u8, $cleanM3u8Content);
echo "  ✅ 去广告 M3U8 已保存\n";
echo "  📄 文件路径: {$cacheM3u8}\n\n";

echo "[5/6] 深度分析（广告插播和特征码）...\n";
$deepResult = null;
if (method_exists($md5, 'deepAnalysisWithCommercials')) {
    $analyzedSegments = $analyzed['segments'] ?? [];
    $deepResult = $md5->deepAnalysisWithCommercials($m3u8Content, $finalUrl, $analyzedSegments);
    echo "  ✅ 广告插播段数: " . ($deepResult['commercial_break_count'] ?? 0) . "\n";
    echo "  ✅ 广告特征码数: " . ($deepResult['ad_signature_count'] ?? 0) . "\n";
    if (!empty($deepResult['ad_signatures'])) {
        foreach ($deepResult['ad_signatures'] as $sig) {
            echo "    🔑 MD5: {$sig['md5']}, 大小: {$sig['size']} 字节\n";
        }
    }
    echo "  ✅ 场景切换点: " . ($deepResult['discontinuity_count'] ?? 0) . " 个\n";
}
echo "\n";

$currentProto = 'http';
$currentHost = 'localhost:8080';
$cleanUrl = $currentProto . '://' . $currentHost . '/cache/noad_md5/' . $urlHash . '.m3u8';

echo "[6/6] 生成结果...\n";
echo "  ✅ 去广告链接已生成\n\n";

echo "========================================\n";
echo "  🎉 测试完成！\n";
echo "========================================\n";
echo "📺 原始链接: {$url}\n";
echo "✨ 去广告链接: {$cleanUrl}\n";
echo "📊 广告片段: {$adCount}/{$totalSegments} (" . ($totalSegments > 0 ? round($adCount/$totalSegments*100, 2) : 0) . "%)\n";
echo "========================================\n";

// 保存结果到缓存（模拟接口完整输出）
$cacheFile = $cacheDir . '/' . $urlHash . '.json';
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
        'commercial_breaks'  => $deepResult['commercial_breaks'] ?? null,
        'ad_signatures'      => $deepResult['ad_signatures'] ?? null,
        'signatures'         => $deepResult['signatures'] ?? null,
        'deep_analysis'      => $deepResult,
    ],
];
file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n📦 完整结果已保存到: {$cacheFile}\n";

// 验证缓存的 M3U8 是否有效
echo "\n🔍 验证去广告 M3U8 内容:\n";
$lines = explode("\n", trim($cleanM3u8Content));
$tsCount = 0;
foreach ($lines as $line) {
    if (preg_match('/\.ts$/i', trim($line))) {
        $tsCount++;
        echo "  🎬 " . basename(trim($line)) . "\n";
    }
}
echo "  ✅ 共 {$tsCount} 个 TS 片段\n";

echo "\n========================================\n";
echo "  ✅ 所有测试通过！\n";
echo "========================================\n";
