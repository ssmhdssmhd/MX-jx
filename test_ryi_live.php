<?php
/**
 * 现场测试：抓取真实 M3U8 内容并分析
 * 支持：master playlist 跟随 / 多种解析方式
 */

$testUrl = 'https://svip.ryiplay18.com/20260621/7402_adcfbfd7/index.m3u8';

function fetchM3u8($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 12; MX Player) AppleWebKit/537.36 Chrome/120 Mobile');
    curl_setopt($ch, CURLOPT_REFERER, parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['content' => $content, 'http' => $httpCode, 'final_url' => $finalUrl];
}

function parseSegments($content) {
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $segs = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim($lines[$i]);
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
            $segs[] = ['duration' => $duration, 'uri' => $uri, 'extinf' => $line];
        }
    }
    return $segs;
}

// --- 步骤1: 抓取主 M3U8 ---
echo "=== 步骤1: 抓取 M3U8 内容 ===\n";
echo "URL: {$testUrl}\n";
$res = fetchM3u8($testUrl);
if ($res['http'] !== 200 || $res['content'] === false) {
    echo "❌ HTTP {$res['http']}, 无法访问 M3U8\n";
    echo "   响应内容前200字节: " . substr($res['content'], 0, 200) . "\n";
    exit(1);
}
echo "✅ HTTP 200, 最终 URL: {$res['final_url']}\n";
echo "   文件大小: " . strlen($res['content']) . " bytes\n";
echo "   前500字节内容:\n";
$rawPreview = substr($res['content'], 0, 500);
foreach (explode("\n", $rawPreview) as $line) {
    echo "     {$line}\n";
}
echo "\n";

// --- 步骤2: 检测是否为 master playlist ---
$segments = parseSegments($res['content']);
if (count($segments) === 0 && strpos($res['content'], '#EXT-X-STREAM-INF') !== false) {
    echo "=== 步骤2: 检测到 Master Playlist，跟随子 M3U8 ===\n";
    // 找到所有子 M3U8 引用
    $lines = preg_split('/\r\n|\r|\n/', $res['content']);
    $subUrls = [];
    $currentBase = dirname($res['final_url']) . '/';
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos(trim($lines[$i]), '#EXT-X-STREAM-INF') === 0) {
            for ($j = $i + 1; $j < count($lines); $j++) {
                $next = trim($lines[$j]);
                if ($next !== '' && strpos($next, '#') !== 0) {
                    $subUrl = (parse_url($next, PHP_URL_SCHEME) ? $next : $currentBase . $next);
                    $subUrls[] = $subUrl;
                    break;
                }
            }
        }
    }
    echo "   发现 " . count($subUrls) . " 个子 M3U8，使用第一个进行测试\n";
    echo "   子URL: {$subUrls[0]}\n\n";
    $res2 = fetchM3u8($subUrls[0]);
    if ($res2['http'] === 200) {
        $res = $res2;
        echo "   子 M3U8 抓取成功 (大小: " . strlen($res['content']) . " bytes)\n\n";
        $segments = parseSegments($res['content']);
    } else {
        echo "   ⚠️ 子 M3U8 抓取失败 HTTP: {$res2['http']}\n\n";
    }
}

// --- 步骤3: 再次尝试解析片段（若仍为0可能是短片段或空内容）---
if (count($segments) === 0) {
    echo "=== 警告: 无法解析到任何 #EXTINF 片段 ===\n";
    echo "   可能原因:\n";
    echo "   ① 该 URL 返回的是重定向或短内容（如 index.m3u8 只是个入口）\n";
    echo "   ② 该 URL 需要特殊 header/cookie 才能访问\n";
    echo "   ③ 内容不是标准 M3U8 格式\n\n";
    echo "   完整原始内容:\n";
    foreach (explode("\n", $res['content']) as $line) {
        echo "     {$line}\n";
    }
    exit(2);
}

// --- 步骤4: 解析成功，输出片段信息 ---
$totalSegs = count($segments);
$totalDur = 0;
foreach ($segments as $s) $totalDur += $s['duration'];
echo "=== 步骤3: 解析到 {$totalSegs} 个片段，总时长 " . number_format($totalDur/60, 2) . " 分钟 ===\n\n";

echo "=== 步骤4: 前50个片段的时长序列 ===\n";
$durations = array_map(function($s) { return $s['duration']; }, $segments);

// 检查是否存在 4s/5.48s/3.24s/3.28s/2s/1.28s/0.28s 特征时长
$adPatterns = [4.00, 5.48, 3.24, 3.28, 2.00, 1.28, 0.28];
$adDurationsFound = [];
$adCount = 0;
foreach ($durations as $d) {
    foreach ($adPatterns as $ap) {
        if (abs($d - $ap) <= 0.05) {
            $adDurationsFound[$ap] = ($adDurationsFound[$ap] ?? 0) + 1;
            $adCount++;
            break;
        }
    }
}
echo "   可能特征广告时长片段: {$adCount}/{$totalSegs}\n";
foreach ($adDurationsFound as $k => $v) {
    echo "     特征时长 {$k}s: {$v} 段\n";
}
echo "\n";

// 输出前50个时长序列
$chunkSize = 10;
$showCount = min(50, $totalSegs);
for ($i = 0; $i < $showCount; $i += $chunkSize) {
    $chunk = array_slice($durations, $i, $chunkSize);
    $markers = [];
    foreach ($chunk as $d) {
        $mk = ' ';
        foreach ($adPatterns as $ap) {
            if (abs($d - $ap) <= 0.05) {
                $mk = '⚡'; // 标记为特征时长
                break;
            }
        }
        $markers[] = $mk;
    }
    $segNums = [];
    for ($k = 1; $k <= $chunkSize; $k++) {
        if ($i + $k <= $totalSegs) $segNums[] = str_pad($i + $k, 2, ' ', STR_PAD_LEFT);
    }
    echo "   编号: [" . implode(', ', $segNums) . "]\n";
    echo "   时长: [" . implode(', ', $chunk) . "]\n";
    echo "   标记: [" . implode(', ', $markers) . "]\n\n";
}

if ($totalSegs > 50) {
    echo "   ...中间省略 " . ($totalSegs - 70) . " 段...\n\n";
    // 打印最后20个片段
    $start = max(50, $totalSegs - 20);
    echo "   最后20个片段 (编号 " . ($start + 1) . "-" . $totalSegs . "):\n";
    for ($i = $start; $i < $totalSegs; $i += $chunkSize) {
        $chunk = array_slice($durations, $i, $chunkSize);
        $markers = [];
        foreach ($chunk as $d) {
            $mk = ' ';
            foreach ($adPatterns as $ap) {
                if (abs($d - $ap) <= 0.05) {
                    $mk = '⚡';
                    break;
                }
            }
            $markers[] = $mk;
        }
        echo "   时长: [" . implode(', ', $chunk) . "]\n";
        echo "   标记: [" . implode(', ', $markers) . "]\n\n";
    }
}

// --- 步骤5: 手动检测是否存在族A/族B/族C 模式 ---
echo "=== 步骤5: 手动检测 3 族模式 ===\n\n";

// 族A: 检测含 5.48s 且 sum 接近 20/21/22 的5~6片段窗口
echo "   ▶ 族A (含5.48s + sum=20/21/22) 检测:\n";
$foundA = 0;
for ($i = 0; $i <= count($durations) - 5; $i++) {
    // 检测窗口内是否含 5.48s
    $hasFlag = false;
    for ($k = $i; $k < $i + 5; $k++) {
        if (abs($durations[$k] - 5.48) <= 0.05) { $hasFlag = true; break; }
    }
    if (!$hasFlag) continue;
    // 测试 5 和 6 片段窗口
    foreach ([5, 6] as $winSize) {
        if ($i + $winSize > count($durations)) continue;
        $sum = 0;
        for ($k = $i; $k < $i + $winSize; $k++) $sum += $durations[$k];
        foreach ([20.0, 21.0, 22.0] as $target) {
            if (abs($sum - $target) <= 0.2) {
                echo "     ✅ 位置[$i-" . ($i + $winSize - 1) . "] " . $winSize . "段 sum=" . number_format($sum, 2) . "s → 命中族A\n";
                echo "       时长序列: [" . implode(', ', array_slice($durations, $i, $winSize)) . "]\n";
                $foundA++;
            }
        }
    }
}
if ($foundA === 0) echo "     ❌ 未发现族A模式\n";
echo "\n";

// 族B: 前5个=4s, 第6个≠4s, sum=22
echo "   ▶ 族B (前5=4s + 第6≠4s + sum=22) 检测:\n";
$foundB = 0;
for ($i = 0; $i <= count($durations) - 6; $i++) {
    $allFour = true;
    for ($k = 0; $k < 5; $k++) {
        if (abs($durations[$i + $k] - 4.00) > 0.05) { $allFour = false; break; }
    }
    if (!$allFour) continue;
    if (abs($durations[$i + 5] - 4.00) <= 0.05) continue;
    $sum = 0;
    for ($k = 0; $k < 6; $k++) $sum += $durations[$i + $k];
    if (abs($sum - 22.0) > 0.2) continue;
    echo "     ✅ 位置[$i-" . ($i + 5) . "] 6段 sum=" . number_format($sum, 2) . "s → 命中族B\n";
    echo "       时长序列: [" . implode(', ', array_slice($durations, $i, 6)) . "]\n";
    $foundB++;
}
if ($foundB === 0) echo "     ❌ 未发现族B模式\n";
echo "\n";

// 族C: 连续5个4s + 后续非4s
echo "   ▶ 族C (连续4s块 + 后续非4s) 检测:\n";
$foundC = 0;
$i = 0;
while ($i <= count($durations) - 5) {
    $runLen = 0;
    for ($k = $i; $k < count($durations); $k++) {
        if (abs($durations[$k] - 4.00) <= 0.05) $runLen++;
        else break;
    }
    if ($runLen >= 5 && ($i + $runLen) < count($durations)) {
        echo "     ✅ 位置[$i-" . ($i + $runLen - 1) . "] 连续{$runLen}个4s → 命中族C\n";
        echo "       后续片段时长: " . number_format($durations[$i + $runLen], 2) . "s (即正片起点)\n";
        $foundC++;
        $i += $runLen;
    } else {
        $i++;
    }
}
if ($foundC === 0) echo "     ❌ 未发现族C模式\n";
echo "\n";

// --- 步骤6: 正式应用如意算法清洗 ---
echo "=== 步骤6: 应用如意算法进行清洗 ===\n";
require_once __DIR__ . '/algorithms/AbstractAlgorithm.php';
require_once __DIR__ . '/algorithms/AlgorithmRegistry.php';
require_once __DIR__ . '/algorithms/ruyi_pattern_cleaner.php';
$algo = new RuyiPatternCleaner();
$cleaned = $algo->apply($res['content']);

$cleanSegs = parseSegments($cleaned);
$cleanCount = count($cleanSegs);
$cleanDur = 0;
foreach ($cleanSegs as $s) $cleanDur += $s['duration'];

$removed = $totalSegs - $cleanCount;
$saved = $totalDur - $cleanDur;

echo "   清洗前: {$totalSegs} 段, " . number_format($totalDur/60, 2) . " 分钟\n";
echo "   清洗后: {$cleanCount} 段, " . number_format($cleanDur/60, 2) . " 分钟\n";
echo "   删除: {$removed} 段, 节省 " . number_format($saved/60, 2) . " 分钟\n";
if ($totalSegs > 0) {
    echo "   识别率: " . round($removed * 100 / $totalSegs, 1) . "% 被识别为广告\n";
}

if ($removed > 0) {
    echo "\n   前5个保留片段时长示例: [";
    $durs = array_map(function($s) { return number_format($s['duration'], 2); }, array_slice($cleanSegs, 0, 5));
    echo implode(', ', $durs) . "]\n";
}
echo "\n=== 检测完成! ===\n";
exit(0);
