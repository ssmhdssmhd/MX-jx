<?php
/**
 * 如意（Ruyi）解析源专属广告片段清理算法 v1.0
 *
 * 基于 M3U8 片段的「时长序列模式识别」，针对如意解析源的广告特征设计。
 * 核心思路：广告块的时长构成具有固定"指纹"，通过族分类 + sum(总时长) 双重验证。
 *
 * 三大族（Family）规则：
 *   ┌────────┬──────────────────────────────────┬─────────────┬──────────────┐
 *   │ 族 A    │ 含 5.48s 标志性时长片段          │ sum≈20/21/22│ 删除 5~6 片段 │
 *   │ 族 B    │ 前5个=4s 且 第6个≠4s              │   sum≈22    │ 删除 6 片段   │
 *   │ 族 C    │ 连续 5~6 个 4s 片段                │  位置截断   │ 删前5个保留正片│
 *   └────────┴──────────────────────────────────┴─────────────┴──────────────┘
 *
 * 广告特征时长字典：
 *   4.00, 5.48, 3.24, 3.28, 2.00, 1.28, 0.28
 *
 * 架构：继承 AbstractAlgorithm，由 AlgorithmRegistry 自动扫描加载。
 *       优先级 = 80（高于通用广告关键词过滤），作用域 = m3u8。
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

require_once __DIR__ . '/AbstractAlgorithm.php';
require_once __DIR__ . '/AlgorithmRegistry.php';

class RuyiPatternCleaner extends AbstractAlgorithm
{
    /** @var array 广告特征时长 —— 含 ±0.05 容差 */
    private $adDurations = [4.00, 5.48, 3.24, 3.28, 2.00, 1.28, 0.28];

    /** @var float 单片段时长容差（秒） */
    private $tolerance = 0.05;

    /** @var array 目标广告块总时长（秒） */
    private $targetSums = [20.0, 21.0, 22.0];

    /** @var float sum 容差（秒） */
    private $sumTolerance = 0.2;

    // ============ AbstractAlgorithm 必需字段 / 方法 ============

    public $id = 'ruyi_pattern_cleaner';
    public $priority = 80;
    public $scope = 'm3u8';
    public $matchPatterns = [];
    public $enabled = true;

    public function name() { return '如意时长序列模式识别'; }
    public function description() { return '识别如意解析源的 M3U8 广告块：族A（5.48s标志+sum20~22）、族B（前5=4s）、族C（连续4s）三类时长序列模式识别'; }
    public function author() { return 'MX-射手沫蝴蝶'; }
    public function version() { return '1.0.0'; }

    /**
     * 主入口：扫描 M3U8 → 删除广告块 → 返回清理后的 M3U8
     */
    public function apply($input, $context = [])
    {
        if ($input === null || trim($input) === '') return $input;

        // 步骤 1：解析片段结构
        $segments = $this->parseSegments($input);
        if (empty($segments)) return $input;

        // 步骤 2：按族标记需要删除的行索引
        $removeIndices = [];
        $removeIndices = array_merge($removeIndices, $this->detectFamilyA($segments));
        $removeIndices = array_merge($removeIndices, $this->detectFamilyB($segments));
        $removeIndices = array_merge($removeIndices, $this->detectFamilyC($segments));

        $removeIndices = array_unique($removeIndices);
        if (empty($removeIndices)) return $input;

        // 步骤 3：删除标记片段，返回净化后的 M3U8
        return $this->removeSegments($input, $removeIndices);
    }

    // ============ 内部：解析片段结构 ============

    private function parseSegments($m3u8Content)
    {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8Content);
        $segments = [];
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = trim($lines[$i]);
            if (strpos($line, '#EXTINF') === 0) {
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $duration = (float)$m[1];
                for ($j = $i + 1; $j < $n; $j++) {
                    $next = trim($lines[$j]);
                    if ($next === '' || strpos($next, '#EXT-X-') === 0) continue;
                    if (strpos($next, '#') !== 0) {
                        $segments[] = [
                            'line_idx' => $i,
                            'uri_line' => $j,
                            'duration' => $duration,
                            'extinf_src' => $line,
                            'uri_src' => $next,
                        ];
                        break;
                    }
                }
            }
        }
        return $segments;
    }

    // ============ 族 A：含 5.48s 标志性时长 ============

    private function detectFamilyA($segments)
    {
        $removeIdx = [];
        foreach ($segments as $pos => $seg) {
            if (!$this->matchDuration($seg['duration'], 5.48)) continue;

            // 以 5.48s 为锚点，向前/向后构建 5~6 片段窗口，寻找 sum 目标值
            $best = null;
            $total = count($segments);
            for ($start = max(0, $pos - 5); $start <= $pos; $start++) {
                for ($length = 5; $length <= 6; $length++) {
                    $end = $start + $length - 1;
                    if ($end >= $total) continue;
                    if ($pos < $start || $pos > $end) continue;
                    $sum = 0;
                    for ($k = $start; $k <= $end; $k++) $sum += $segments[$k]['duration'];
                    foreach ($this->targetSums as $target) {
                        if (abs($sum - $target) <= $this->sumTolerance) {
                            $best = ['start' => $start, 'end' => $end, 'sum' => $sum];
                            break 3;
                        }
                    }
                }
            }
            if ($best !== null) {
                for ($k = $best['start']; $k <= $best['end']; $k++) {
                    $removeIdx[] = $segments[$k]['line_idx'];
                    $removeIdx[] = $segments[$k]['uri_line'];
                }
            }
        }
        return $removeIdx;
    }

    // ============ 族 B：前5=4s 第6≠4s sum=22 ============

    private function detectFamilyB($segments)
    {
        $removeIdx = [];
        $n = count($segments);
        for ($i = 0; $i <= $n - 6; $i++) {
            $allFour = true;
            for ($k = 0; $k < 5; $k++) {
                if (!$this->matchDuration($segments[$i + $k]['duration'], 4.00)) {
                    $allFour = false; break;
                }
            }
            if (!$allFour) continue;
            if ($this->matchDuration($segments[$i + 5]['duration'], 4.00)) continue;

            $sum = 0;
            for ($k = 0; $k < 6; $k++) $sum += $segments[$i + $k]['duration'];
            if (abs($sum - 22.0) > $this->sumTolerance) continue;

            // 增强校验：窗口内至少 4 个在广告特征字典
            $hit = 0;
            for ($k = 0; $k < 6; $k++) {
                foreach ($this->adDurations as $d) {
                    if ($this->matchDuration($segments[$i + $k]['duration'], $d)) {
                        $hit++; break;
                    }
                }
            }
            if ($hit < 4) continue;

            for ($k = 0; $k < 6; $k++) {
                $removeIdx[] = $segments[$i + $k]['line_idx'];
                $removeIdx[] = $segments[$i + $k]['uri_line'];
            }
            $i += 5;
        }
        return $removeIdx;
    }

    // ============ 族 C：连续 4s + 后续非 4s 触发位置截断 ============

    private function detectFamilyC($segments)
    {
        $removeIdx = [];
        $n = count($segments);
        $i = 0;
        while ($i <= $n - 5) {
            // 检测从 i 开始的连续 4s 片段数量
            $runLen = 0;
            for ($k = $i; $k < $n; $k++) {
                if ($this->matchDuration($segments[$k]['duration'], 4.00)) {
                    $runLen++;
                } else {
                    break;
                }
            }
            // 需要连续 5~6 个以上 4s，且后续存在非 4s 片段（即正片开始）
            if ($runLen >= 5 && ($i + $runLen) < $n) {
                // 删除整个连续 4s 广告块
                for ($k = $i; $k < $i + $runLen; $k++) {
                    $removeIdx[] = $segments[$k]['line_idx'];
                    $removeIdx[] = $segments[$k]['uri_line'];
                }
                $i += $runLen; // 跳过已处理块
            } else {
                $i++;
            }
        }
        return $removeIdx;
    }

    // ============ 工具方法 ============

    private function matchDuration($actual, $expected)
    {
        return abs($actual - $expected) <= $this->tolerance;
    }

    private function removeSegments($m3u8Content, $removeLineIndices)
    {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8Content);
        $removeSet = array_flip($removeLineIndices);
        $newLines = [];
        foreach ($lines as $idx => $line) {
            if (isset($removeSet[$idx])) continue;
            $newLines[] = $line;
        }
        return implode("\n", $newLines);
    }
}

// 自动注册到算法注册表
if (class_exists('AlgorithmRegistry', false) && method_exists('AlgorithmRegistry', 'getInstance')) {
    AlgorithmRegistry::getInstance()->register(new RuyiPatternCleaner());
}
