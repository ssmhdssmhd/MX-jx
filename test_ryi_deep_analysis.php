<?php
/**
 * 深度分析工具：分析真实 M3U8 中的广告特征
 * 包括: URI 模式分析 / 时长分布 / DISCONTINUITY 标记 / 广告关键词
 */

$testUrl = 'https://svip.ryiplay18.com/20260621/7402_adcfbfd7/2000k/hls/index.m3u8';

function fetchM3u8($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_REFERER, parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $content = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return trim($content);
}

echo "=== 步骤1: 下载 M3U8 ===\n";
$content = fetchM3u8($testUrl);
$lines = preg_split('/\r\n|\r|\n/', $content);
$n = count($lines);

// --- 解析片段 ---
$segments = [];
$discontinuityCount = 0;
$keywordsCount = 0;
$adKeywords = ['ad', 'advert', '广告', 'vod_ad', 'ad_slide', 'pre_ad', 'ad_', 'pre_roll', 'mid_roll',
                'p2pcdn', 's3', 'cdn'];  // 扩展广告关键词

for ($i = 0; $i < $n; $i++) {
    $line = trim($lines[$i]);
    if (strpos($line, '#EXT-X-DISCONTINUITY') === 0) {
        $discontinuityCount++;
        end($segments);
        $segIdx = key($segments);
        if ($segIdx !== null) {
            $segments[$segIdx]['after_discontinuity'] = true;
        }
        continue;
    }
    if (strpos($line, '#EXTINF') === 0) {
        $duration = 0;
        if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $duration = (float)$m[1];
        $uri = '';
        for ($j = $i + 1; $j < $n; $j++) {
            $next = trim($lines[$j]);
            if ($next !== '' && strpos($next, '#') !== 0) {
                $uri = $next;
                break;
            }
        }
        $hasAdKw = false;
        foreach ($adKeywords as $kw) {
            if (stripos($uri, $kw) !== false) {
                $hasAdKw = true;
                $keywordsCount++;
                break;
            }
        }
        $segments[] = [
            'index' => count($segments) + 1,
            'duration' => $duration,
            'uri' => $uri,
            'has_ad_kw' => $hasAdKw,
            'after_discontinuity' => false,
            'extinf' => $line,
        ];
    }
}

$totalSegs = count($segments);
$totalDur = 0;
foreach ($segments as $s) $totalDur += $s['duration'];

echo "✅ 解析到 {$totalSegs} 段，总时长: " . number_format($totalDur/60, 2) . " 分钟\n\n";

// --- 分析1: 时长分布 TOP10 ---
echo "=== 分析1: 时长分布 TOP10（出现次数）===\n";
$durDist = [];
foreach ($segments as $s) {
    $key = number_format($s['duration'], 2);
    if (!isset($durDist[$key])) $durDist[$key] = 0;
    $durDist[$key]++;
}
arsort($durDist);
$count = 0;
foreach ($durDist as $dur => $num) {
    if ($count++ >= 10) break;
    $pct = round($num * 100 / $totalSegs, 1);
    echo "   {$dur}s: {$num} 段 ({$pct}%)\n";
}
echo "\n";

// --- 分析2: URI 文件名模式（找广告特征）---
echo "=== 分析2: URI 文件名模式分析 ===\n";
$namePatterns = [];
foreach ($segments as $s) {
    $fn = basename($s['uri']);
    // 提取无扩展名的文件名
    $nameWithoutExt = preg_replace('/\.[a-z0-9]+$/i', '', $fn);
    // 提取开头特征（如 000, ad_001 等）
    if (preg_match('/^([a-z]+)[_\-]?\d+$/i', $nameWithoutExt, $m)) {
        $prefix = strtolower($m[1]);
    } else {
        // 提取非数字前缀
        $prefix = preg_replace('/\d.*$/', '', $nameWithoutExt);
        $prefix = strtolower(trim($prefix, '-_ '));
        if ($prefix === '') $prefix = 'numeric';
    }
    if (!isset($namePatterns[$prefix])) $namePatterns[$prefix] = 0;
    $namePatterns[$prefix]++;
}
arsort($namePatterns);
echo "   URI 前缀统计:\n";
foreach ($namePatterns as $prefix => $num) {
    $pct = round($num * 100 / $totalSegs, 1);
    echo "     '{$prefix}': {$num} 段 ({$pct}%)\n";
}
echo "\n";

// --- 分析3: DISCONTINUITY 边界 ---
echo "=== 分析3: EXT-X-DISCONTINUITY 标记（广告插入特征）===\n";
echo "   总DISCONTINUITY数: {$discontinuityCount}\n";
echo "   含广告关键词URI: {$keywordsCount}\n";
if ($discontinuityCount > 0) {
    echo "   检查 DISCONTINUITY 前后的片段时长突变:\n";
    for ($i = 1; $i < $totalSegs; $i++) {
        if (!empty($segments[$i]['after_discontinuity'])) {
            $prev = $segments[$i - 1]['duration'];
            $curr = $segments[$i]['duration'];
            $delta = abs($prev - $curr);
            echo "     片段#" . ($i + 1) . ": 前={$prev}s, 后={$curr}s (突变 " . number_format($delta, 2) . "s)\n";
        }
    }
}
echo "\n";

// --- 分析4: 非4s片段的分布 ---
echo "=== 分析4: 非4s 片段的位置与特征（广告候选）===\n";
$nonFourClusters = [];
$cluster = [];
for ($i = 0; $i < $totalSegs; $i++) {
    $isFour = abs($segments[$i]['duration'] - 4.00) <= 0.05;
    if (!$isFour) {
        $cluster[] = $i;
    } else {
        if (count($cluster) > 0) {
            $startSeg = $cluster[0] + 1;
            $endSeg = end($cluster) + 1;
            $len = count($cluster);
            $sum = 0;
            $durs = [];
            foreach ($cluster as $idx) {
                $sum += $segments[$idx]['duration'];
                $durs[] = $segments[$idx]['duration'];
            }
            $nonFourClusters[] = [
                'start' => $startSeg,
                'end' => $endSeg,
                'length' => $len,
                'sum' => $sum,
                'durations' => $durs,
            ];
            $cluster = [];
        }
    }
}
if (count($cluster) > 0) {
    // 末尾的簇
    $startSeg = $cluster[0] + 1;
    $endSeg = end($cluster) + 1;
    $len = count($cluster);
    $sum = 0;
    $durs = [];
    foreach ($cluster as $idx) {
        $sum += $segments[$idx]['duration'];
        $durs[] = $segments[$idx]['duration'];
    }
    $nonFourClusters[] = [
        'start' => $startSeg, 'end' => $endSeg,
        'length' => $len, 'sum' => $sum, 'durations' => $durs,
    ];
}

// 按簇长度排序，优先显示短簇（广告通常是5~10段的短插入）
usort($nonFourClusters, function($a, $b) { return $a['length'] <=> $b['length']; });

echo "   非4s片段簇总数: " . count($nonFourClusters) . "\n";
echo "   短簇候选（1~15段）: \n";
$adCandidateList = [];
foreach ($nonFourClusters as $c) {
    if ($c['length'] <= 15) {
        $adCandidateList[] = $c;
        echo "     位置{$c['start']}-{$c['end']} ({$c['length']}段, sum=" . number_format($c['sum'], 2) . "s): ["
           . implode(', ', $c['durations']) . "]\n";
    }
}
echo "   较长簇（>15段）可能是正常场景切换:\n";
foreach ($nonFourClusters as $c) {
    if ($c['length'] > 15) {
        echo "     位置{$c['start']}-{$c['end']} ({$c['length']}段, sum=" . number_format($c['sum']/60, 2) . "分钟)\n";
    }
}
echo "\n";

// --- 分析5: 广告特征时长的检测（小于4s的片段）---
echo "=== 分析5: 时长 <3s 或 >10s 的异常片段 ===\n";
$shortCount = 0;
$longCount = 0;
foreach ($segments as $s) {
    if ($s['duration'] <= 3.0) $shortCount++;
    if ($s['duration'] > 7.0) $longCount++;
}
echo "   ≤3s 片段: {$shortCount}\n";
echo "   >7s 片段: {$longCount}\n";

// --- 分析6: 检测 URI 中含广告关键词的片段，并检查这些片段是否聚集 ---
echo "\n=== 分析6: URI 含广告关键词的片段 ===\n";
if ($keywordsCount > 0) {
    echo "   找到 {$keywordsCount} 个含关键词的URI\n";
    $foundKwUris = [];
    foreach ($segments as $s) {
        if ($s['has_ad_kw']) $foundKwUris[] = $s['uri'];
    }
    if (count($foundKwUris) > 0) {
        echo "   前5个示例:\n";
        foreach (array_slice($foundKwUris, 0, 5) as $u) {
            echo "     " . basename($u) . "\n";
        }
    }
} else {
    echo "   未检测到明显的广告关键词 URI\n";
}

// --- 分析7: 关键结论与新规则建议 ---
echo "\n=== 分析7: 关键结论与新规则建议 ===\n\n";

// 基于以上数据重新判断什么是真正的广告特征
echo "   ✅ 新发现: 4s 是资源站正常片段时长，不能用作广告特征\n";
echo "   ✅ 新发现: 广告可能藏在 '非4s簇'（时长突变、长度5~15段）中\n";
echo "   ✅ 新发现: 需要检测 DISCONTINUITY 前后的片段突变\n";
echo "   ✅ 建议: 检查簇的 sum 是否在特定范围（如 15~35 秒的短片）\n\n";

// 针对本资源站的特定建议：打印所有短簇的详细数据
echo "   候选广告片段数（来自短簇）: ";
$adTotal = 0;
foreach ($adCandidateList as $c) $adTotal += $c['length'];
echo "{$adTotal}/{$totalSegs}\n";

// 打印前5个短簇的完整信息
echo "\n   前5个短簇的详细时长（人工核对）:\n";
$idx = 0;
foreach ($adCandidateList as $c) {
    if ($idx++ >= 5) break;
    echo "     簇#{$idx} 位置{$c['start']}-{$c['end']}: [";
    echo implode(',', array_slice($c['durations'], 0, min(10, count($c['durations']))));
    if (count($c['durations']) > 10) echo "...";
    echo "] sum=" . number_format($c['sum'], 2) . "s\n";
}

// --- 分析8: 检测 URI 域名变化（广告常来自不同 CDN）---
echo "\n=== 分析8: URI 域名变化检测 ===\n";
$uriDomains = [];
foreach ($segments as $s) {
    if (preg_match('/^https?:\/\/([^\/]+)/', $s['uri'], $m)) {
        $host = $m[1];
    } else {
        $host = 'relative'; // 相对路径
    }
    if (!isset($uriDomains[$host])) $uriDomains[$host] = 0;
    $uriDomains[$host]++;
}
echo "   URI 域名统计:\n";
foreach ($uriDomains as $host => $num) {
    echo "     {$host}: {$num} 段\n";
}

echo "\n=== 分析完成! ===\n";
exit(0);
