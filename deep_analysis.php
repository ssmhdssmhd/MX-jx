<?php
/**
 * 完整深度分析：使用 deepAnalyzeBatch 逐批处理
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/algorithms/md5_pattern_cleaner.php';

$url = 'https://v.lfthirtytwo.com/20260623/7885_1d9dba16/index.m3u8';
$batchSize = 15;
$outputDir = __DIR__ . '/cache/deep_analysis';
@mkdir($outputDir, 0755, true);

echo "=" . str_repeat("=", 70) . "\n";
echo "  🔬 MD5 深度分析 - 完整视频分析\n";
echo "=" . str_repeat("=", 70) . "\n\n";

echo "📺 视频链接: {$url}\n\n";

$md5 = new MD5PatternCleaner();

// 步骤1: 解析M3U8
echo "🔍 [1/5] 解析 M3U8...\n";
$resolved = MD5PatternCleaner::resolveM3U8FromUrl($url);
if ($resolved === false) {
    echo "❌ 失败：无法获取 M3U8 内容\n";
    exit(1);
}

$m3u8Content = $resolved['content'];
$finalUrl = $resolved['final_url'] ?? $url;
$parsed = $md5->parseM3U8($m3u8Content);
$segCount = count($parsed['segments']);
$isMaster = !empty($parsed['is_master']);

echo "  ✅ 最终 URL: {$finalUrl}\n";
echo "  ✅ Master Playlist: " . ($isMaster ? '是' : '否') . "\n";
echo "  ✅ 片段总数: {$segCount}\n";

if ($isMaster && !empty($parsed['master_variants'])) {
    echo "  📋 分辨率变体:\n";
    foreach ($parsed['master_variants'] as $v) {
        $bw = '';
        if (preg_match('/BANDWIDTH=(\d+)/', $v['stream_inf'], $m)) {
            $bw = round($m[1] / 1000) . ' kbps';
        }
        $res = '';
        if (preg_match('/RESOLUTION=([\dx]+)/', $v['stream_inf'], $m)) {
            $res = $m[1];
        }
        echo "    - {$v['uri']} ({$res} / {$bw})\n";
    }
}

// 步骤2: 时长统计
echo "\n⏱️  [2/5] 时长统计...\n";
$totalDuration = 0;
$durations = [];
foreach ($parsed['segments'] as $seg) {
    $totalDuration += $seg['duration'];
    $durations[] = $seg['duration'];
}
sort($durations);
$medianDur = $segCount > 0 ? $durations[floor($segCount / 2)] : 0;
$minDur = $segCount > 0 ? $durations[0] : 0;
$maxDur = $segCount > 0 ? end($durations) : 0;
$avgDur = $segCount > 0 ? $totalDuration / $segCount : 0;

echo "  ✅ 总时长: " . round($totalDuration, 1) . " 秒 (" . round($totalDuration / 60, 1) . " 分钟)\n";
echo "  ✅ 时长范围: {$minDur}s - {$maxDur}s\n";
echo "  ✅ 时长中位数: {$medianDur}s\n";
echo "  ✅ 时长平均值: " . round($avgDur, 2) . "s\n";

// 步骤3: 逐批深度分析
echo "\n⬇️  [3/5] 逐批深度分析 (每批 {$batchSize} 段)...\n";
$allSegments = [];
$offset = 0;
$batchNum = 0;

while (true) {
    $batchNum++;
    $result = $md5->deepAnalyzeBatch($m3u8Content, $finalUrl, $offset, $batchSize);

    if (empty($result['segments'])) {
        break;
    }

    $newSegs = $result['segments'];
    $batchStart = $offset + 1;
    $batchEnd = $offset + count($newSegs);
    $percent = round(($batchEnd / $segCount) * 100, 1);

    $successCount = 0;
    $adCount = 0;
    foreach ($newSegs as $s) {
        if (!empty($s['success'])) $successCount++;
        if (!empty($s['is_ad'])) $adCount++;
    }

    echo "  📦 第 {$batchNum} 批: 段 {$batchStart}-{$batchEnd} ({$percent}%) - 成功 {$successCount}, 广告 {$adCount}\n";
    flush();

    $allSegments = array_merge($allSegments, $newSegs);

    if (empty($result['has_more'])) {
        break;
    }

    $offset = $offset + count($newSegs);
    usleep(100000);
}

$successTotal = 0;
$adTotal = 0;
foreach ($allSegments as $s) {
    if (!empty($s['success'])) $successTotal++;
    if (!empty($s['is_ad'])) $adTotal++;
}
echo "  ✅ 分析完成: 共 " . count($allSegments) . " 段, 成功 {$successTotal} 段, 初步广告 {$adTotal} 段\n";

// 步骤4: 深度聚类分析
echo "\n🧩 [4/5] 深度聚类分析...\n";
$clusterResult = $md5->deepClusterAnalyze($allSegments, $segCount);

$finalAdCount = $clusterResult['ad_count'];
$finalAdDuration = $clusterResult['ad_duration'];
$adPercent = $totalDuration > 0 ? round($finalAdDuration / $totalDuration * 100, 1) : 0;

echo "  🎯 广告集群: " . count($clusterResult['ad_clusters']) . " 个\n";
echo "  📊 精炼后广告数: {$finalAdCount} 段\n";
echo "  📊 精炼后广告时长: " . round($finalAdDuration, 1) . " 秒 ({$adPercent}%)\n";
echo "  📝 分析摘要: " . $clusterResult['analysis_summary'] . "\n";

if (!empty($clusterResult['ad_clusters'])) {
    echo "\n  📋 广告集群详情:\n";
    foreach ($clusterResult['ad_clusters'] as $c) {
        echo "    - 第 {$c['start']} - {$c['end']} 段 (共 {$c['count']} 段, " . round($c['total_duration'], 1) . "秒)\n";
    }
}

// 步骤5: 生成去广告M3U8
echo "\n🔗 [5/5] 生成去广告后的 M3U8...\n";
$adIndexes = [];
foreach ($clusterResult['refined_segments'] as $seg) {
    if (!empty($seg['is_ad'])) {
        $adIndexes[$seg['index']] = true;
    }
}

$cleanM3u8 = $md5->buildCleanM3U8ByIndex($m3u8Content, $finalUrl, $adIndexes);
$cleanSegmentCount = substr_count($cleanM3u8, '#EXTINF:');

echo "  ✅ 原始片段数: {$segCount}\n";
echo "  ✅ 去广告后片段数: {$cleanSegmentCount}\n";
echo "  ✅ 移除广告片段: " . count($adIndexes) . " 段\n";

$cleanFile = $outputDir . '/clean_output.m3u8';
file_put_contents($cleanFile, $cleanM3u8);
echo "  💾 去广告 M3U8: {$cleanFile}\n";

// 保存完整结果
$resultFile = $outputDir . '/full_analysis_result.json';
file_put_contents($resultFile, json_encode([
    'video_url' => $url,
    'final_m3u8_url' => $finalUrl,
    'total_segments' => $segCount,
    'total_duration' => round($totalDuration, 1),
    'ad_segments' => count($adIndexes),
    'ad_duration' => round($finalAdDuration, 1),
    'ad_percent' => $adPercent,
    'ad_clusters' => count($clusterResult['ad_clusters']),
    'clusters' => $clusterResult['ad_clusters'],
    'ad_list' => array_values(array_filter($clusterResult['refined_segments'], function($s) { return !empty($s['is_ad']); })),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  💾 完整分析结果: {$resultFile}\n";

// 广告详情
if (count($adIndexes) > 0) {
    echo "\n🚫 广告片段详情:\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-6s %-10s %-10s %-12s %-30s\n", "#", "时长", "大小", "检测方式", "原因");
    echo str_repeat("-", 80) . "\n";
    foreach ($clusterResult['refined_segments'] as $seg) {
        if (!empty($seg['is_ad'])) {
            $sizeStr = !empty($seg['size']) ? round($seg['size']/1024, 1) . 'KB' : '-';
            $statusMap = ['md5' => 'MD5指纹', 'heuristic' => '启发式', 'cluster' => '聚类扩展'];
            $statusStr = $statusMap[$seg['status'] ?? ''] ?? ($seg['status'] ?? 'unknown');
            printf("%-6d %-10s %-10s %-12s %-30s\n",
                $seg['index'],
                round($seg['duration'], 2) . 's',
                $sizeStr,
                $statusStr,
                mb_substr($seg['reason'] ?? '', 0, 28)
            );
        }
    }
    echo str_repeat("-", 80) . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "  ✅ 深度分析完成！\n";
echo str_repeat("=", 70) . "\n";
