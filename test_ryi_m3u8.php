<?php
/**
 * 现场测试：如意算法清洗指定 M3U8 链接
 * 步骤：① 下载原始 M3U8 → ② 解析片段时长 → ③ 用如意算法测试识别广告 → ④ 对比清洗结果
 */

require_once __DIR__ . '/algorithms/AbstractAlgorithm.php';
require_once __DIR__ . '/algorithms/AlgorithmRegistry.php';
require_once __DIR__ . '/algorithms/ruyi_pattern_cleaner.php';

$testUrl = 'https://svip.ryiplay18.com/20260621/7402_adcfbfd7/index.m3u8';

echo "=== 步骤1: 下载 M3U8 内容...\n";
$ch = curl_init($testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($content === false || $httpCode !== 200) {
    echo "❌ 下载失败 HTTP: {$httpCode}\n";
    exit(1);
}
echo "✅ 下载成功 HTTP: {$httpCode}, 大小: " . strlen($content) . " bytes\n\n";

echo "=== 步骤2: 解析片段结构...\n";
$lines = preg_split('/\r\n|\r|\n/', $content);
$segments = [];
$totalLines = [];
$n = count($lines);
for ($i = 0; $i < $n; $i++) {
    $line = trim($lines[$i]);
    if (strpos($line, '#EXTINF') === 0) {
        $duration = 0;
        if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $duration = (float)$m[1];
        $uri = '';
        for ($j = $i + 1; $j < $n; $j++) {
            if (strpos(trim($lines[$j]), '#') !== 0 && trim($lines[$j]) !== '') {
                $uri = trim($lines[$j]);
                break;
            }
        }
        $segments[] = ['extinf' => $line, 'duration' => $duration, 'uri' => $uri];
        $totalLines[] = $line;
        if ($uri !== '') $totalLines[] = $uri;
    }
}

$segCount = count($segments);
$totalDuration = 0;
foreach ($segments as $s) $totalDuration += $s['duration'];
echo "✅ 总片段数: {$segCount}, 总时长: " . number_format($totalDuration, 2) . " 秒 (" . number_format($totalDuration / 60, 2) . " 分钟)\n\n";

echo "=== 步骤3: 前40片段时长序列（用于人工检查如意规则）...\n";
$durations = array_map(function($s) { return $s['duration']; }, $segments);

// 打印前40个时长序列，便于检查序列模式
$chunkSize = 10;
for ($i = 0; $i < min(40, $segCount); $i += $chunkSize) {
    $chunk = array_slice($durations, $i, $chunkSize);
    echo '  片段 ' . ($i + 1) . '-' . min($i + $chunkSize, $segCount) . ': [' . implode(', ', $chunk) . "]\n";
}

// 如果片段数超过40，打印后半部分也显示最后20个片段时长序列和模式是否变化
if ($segCount > 40) {
    echo "\n  最后20个片段:\n";
    $start = max(0, $segCount - 20);
    for ($i = $start; $i < $segCount; $i += $chunkSize) {
        $chunk = array_slice($durations, $i, $chunkSize);
        echo '    片段 ' . ($i + 1) . '-' . min($i + $chunkSize, $segCount) . ': [' . implode(', ', $chunk) . "]\n";
    }
}

echo "\n=== 步骤4: 识别到广告特征时长统计...\n";
$adPatterns = ['4.00', '5.48', '3.24', '3.28', '2.00', '1.28', '0.28'];
$adCount = 0;
$adPatternsFound = [];
foreach ($durations as $d) {
    $ds = number_format($d, 2);
    foreach ($adPatterns as $ap) {
        if (abs($d - (float)$ap) <= 0.05) {
            if (!isset($adPatternsFound[$ap])) $adPatternsFound[$ap] = 0;
            $adPatternsFound[$ap]++;
            $adCount++;
        }
    }
}
echo "  可能为广告的特征时长片段数: {$adCount}/{$segCount}\n";
foreach ($adPatternsFound as $p => $c) echo "    特征时长 {$p}s: {$c} 段\n";

echo "\n=== 步骤5: 用如意算法清洗 M3U8 并对比...\n";
$algo = new RuyiPatternCleaner();
$cleaned = $algo->apply($content);

$origLines = preg_split('/\r\n|\r|\n/', $content);
$cleanLines = preg_split('/\r\n|\r|\n/', $cleaned);
$origSegCount = preg_match_all('/#EXTINF:/', $content, $_);
$cleanSegCount = preg_match_all('/#EXTINF:/', $cleaned, $_);
$removed = $origSegCount - $cleanSegCount;
$removedPercent = $origSegCount > 0 ? round($removed * 100 / $origSegCount, 1) : 0;

echo "  清洗前片段数: {$origSegCount}\n";
echo "  清洗后片段数: {$cleanSegCount}\n";
echo "  删除片段数: {$removed} (占 {$removedPercent}%)\n\n";

if ($removed > 0) {
    echo "  对比显示：\n    前5个保留片段时长: [";
    $count = 0;
    foreach (explode("\n", $cleaned) as $line) {
        if (strpos(trim($line), '#EXTINF:') === 0 && $count < 5) {
            if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                if ($count > 0) echo ', ';
                echo (float)$m[1];
                $count++;
            }
        }
    }
    echo "]\n";
} else {
    echo "  ⚠️ 本如意算法没有检测到广告片段。资源站可能已经改变规则\n";
}

// 计算总时长对比
$origTotal = 0;
foreach ($segments as $s) $origTotal += $s['duration'];
$cleanedSegs = [];
foreach (explode("\n", $cleaned) as $line) {
    if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $cleanedSegs[] = (float)$m[1];
}
$cleanedTotal = array_sum($cleanedSegs);

echo "\n  总时长对比: " . number_format($origTotal/60, 2) . " 分钟 → " . number_format($cleanedTotal/60, 2) . " 分钟\n";
echo "  节省时长: " . number_format(($origTotal - $cleanedTotal)/60, 2) . " 分钟 (" . round(($origTotal - $cleanedTotal)/$origTotal*100, 1) . "%)\n";
echo "\n=== 测试完成! ===\n";
exit(0);
