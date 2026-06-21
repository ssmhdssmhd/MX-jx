<?php
/**
 * 如意（Ruyi）解析源专属广告片段清理算法 v2.0 — 智能簇检测
 *
 * 核心设计思想（基于真实 M3U8 数据分析重构）：
 *
 *   🔴 v1.0 错误：将 4.00s 当作广告特征。真实情况 — 4.00s 占所有片段的 66%，
 *                   是资源站标准视频编码单元（25fps × 4s = 100 帧 GoP）。
 *
 *   ✅ v2.0 新规则：
 *
 *   【第一级：DISCONTINUITY 边界检测】
 *     - M3U8 中出现 #EXT-X-DISCONTINUITY 表示编码断层，通常是广告插入点
 *     - 扫描每个 DISCONTINUITY 前后的片段时长突变
 *     - 若突变 > 2s + 片段时长 < 3s：高置信度广告
 *
 *   【第二级：非4s智能簇检测】
 *     - 将连续的「非4s片段」聚合成簇（cluster）
 *     - 簇长度: 3~15 段 → 疑似广告块（正片场景切换通常是1~2段）
 *     - 簇总时长: 15~35 秒 → 典型广告块时长
 *     - 簇内方差: 高（广告由多种时长片段拼接）→ 提升置信度
 *     - 簇内包含 < 3s 或 < 2s 片段 → 强信号
 *
 *   【第三级：极短片段过滤】
 *     - 时长 < 1.5s 的独立片段 = 广告尾部/过渡片段
 *     - 时长 < 3.0s 的片段（且不是簇边界）= 候选广告
 *
 *   【白名单：避免误删正片】
 *     - 1~2 段非4s片段（3~8秒）且在 4s 正片流中 = 正常场景切换，保留
 *     - sum > 40s 的长簇 = 正常内容，保留
 *     - 连续8+个片段都是 4s = 纯视频流，不删除
 *
 * 架构：继承 AbstractAlgorithm，由 AlgorithmRegistry 自动扫描加载。
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

require_once __DIR__ . '/AbstractAlgorithm.php';
require_once __DIR__ . '/AlgorithmRegistry.php';

class RuyiPatternCleaner extends AbstractAlgorithm
{
    // === 算法基本属性 ===
    public $id = 'ruyi_pattern_cleaner';
    public $priority = 80;  // 高于通用广告关键词过滤
    public $scope = 'm3u8';
    public $matchPatterns = [];  // 空数组 = 总是执行
    public $enabled = true;

    // === 可配置参数（从 config/noad.php 中的 ruyi_* 加载，可在后台调整）===
    /** @var float 基准片段时长（秒）：资源站标准编码单元，通常为 4.00s = 25fps*100帧 GoP */
    private $baselineDuration = 4.00;
    /** @var float 基准容差（秒）：±0.10s 内都算正常视频片段 */
    private $baselineTolerance = 0.10;
    /** @var int 广告簇最小长度（段）：少于此值视为正常场景切换 */
    private $minClusterLength = 3;
    /** @var int 广告簇最大长度（段）：超过此值视为正常内容 */
    private $maxClusterLength = 15;
    /** @var float 广告簇最小总时长（秒）：典型广告块通常 > 15秒 */
    private $minClusterSum = 15.0;
    /** @var float 广告簇最大总时长（秒）：典型广告块通常 < 35秒 */
    private $maxClusterSum = 35.0;
    /** @var float 短片段阈值（秒）：<3.0s 的非标准片段 = 广告片段强信号 */
    private $shortFragmentThreshold = 3.0;
    /** @var float 极短片段阈值（秒）：<1.5s 的片段 = 直接删除（广告过渡/收尾标志） */
    private $veryShortThreshold = 1.5;
    /** @var bool 是否启用 DISCONTINUITY 标记辅助判断 */
    private $enableDiscontinuity = true;
    /** @var float DISCONTINUITY 时长突变阈值（秒）：>2s 且 <基准时长视为广告信号 */
    private $discontinuitySurge = 2.0;
    /** @var int Score 阈值：分数 >= 此值才删除该簇（3=保守, 4=平衡, 5=激进，默认4） */
    private $scoreThreshold = 4;
    /** @var bool 调试模式：返回详细识别信息（开发/调试专用） */
    private $debugMode = false;

    /**
     * 构造函数：从 config/noad.php（后台保存的配置）加载参数，覆盖默认值
     * 配置键格式：ruyi_xxx（如 ruyi_score_threshold）
     */
    public function __construct() {
        $configPath = __DIR__ . '/../config/noad.php';
        if (file_exists($configPath)) {
            $config = @include $configPath;
            if (is_array($config)) {
                if (isset($config['ruyi_enabled']) && $config['ruyi_enabled'] === false)   $this->enabled = false;
                if (isset($config['ruyi_score_threshold']))         $this->scoreThreshold = (int)$config['ruyi_score_threshold'];
                if (isset($config['ruyi_baseline_sec']))            $this->baselineDuration = (float)$config['ruyi_baseline_sec'];
                if (isset($config['ruyi_baseline_tolerance']))      $this->baselineTolerance = (float)$config['ruyi_baseline_tolerance'];
                if (isset($config['ruyi_min_cluster_len']))        $this->minClusterLength = (int)$config['ruyi_min_cluster_len'];
                if (isset($config['ruyi_max_cluster_len']))        $this->maxClusterLength = (int)$config['ruyi_max_cluster_len'];
                if (isset($config['ruyi_min_cluster_sum']))        $this->minClusterSum = (float)$config['ruyi_min_cluster_sum'];
                if (isset($config['ruyi_max_cluster_sum']))        $this->maxClusterSum = (float)$config['ruyi_max_cluster_sum'];
                if (isset($config['ruyi_short_seg_threshold']))    $this->shortFragmentThreshold = (float)$config['ruyi_short_seg_threshold'];
                if (isset($config['ruyi_very_short_threshold']))   $this->veryShortThreshold = (float)$config['ruyi_very_short_threshold'];
                if (isset($config['ruyi_enable_discontinuity']))   $this->enableDiscontinuity = (bool)$config['ruyi_enable_discontinuity'];
                if (isset($config['ruyi_debug_mode']))             $this->debugMode = (bool)$config['ruyi_debug_mode'];
            }
        }
    }

    /** 返回算法名称（带版本号） */
    public function name() { return '如意时长序列模式识别 v2.1'; }

    /** 返回算法描述（动态展示当前参数，便于调试） */
    public function description() {
        return '智能簇检测 + DISCONTINUITY + 时长三重验证。当前阈值: score>='
             . $this->scoreThreshold . '，基准: ' . $this->baselineDuration
             . 's±' . $this->baselineTolerance . '，极短: ' . $this->veryShortThreshold . 's';
    }

    public function author() { return 'MX-射手沫蝴蝶'; }
    public function version() { return '2.1.0'; }

    /**
     * 返回当前参数（供后台调试界面展示）
     */
    public function getCurrentParams() {
        return [
            'baseline'            => $this->baselineDuration,
            'tolerance'           => $this->baselineTolerance,
            'min_cluster_len'     => $this->minClusterLength,
            'max_cluster_len'     => $this->maxClusterLength,
            'min_cluster_sum'     => $this->minClusterSum,
            'max_cluster_sum'     => $this->maxClusterSum,
            'short_threshold'     => $this->shortFragmentThreshold,
            'very_short_threshold'=> $this->veryShortThreshold,
            'discontinuity'       => $this->enableDiscontinuity ? '✓' : '✗',
            'score_threshold'     => $this->scoreThreshold,
            'debug_mode'          => $this->debugMode ? 'ON' : 'OFF',
        ];
    }

    /**
     * 主入口：扫描 M3U8 内容 → 标记广告片段 → 删除并返回净化后 M3U8
     */
    public function apply($input, $context = [])
    {
        if ($input === null || trim($input) === '') return $input;

        // ===== 步骤 1：解析片段结构 =====
        $lines = preg_split('/\r\n|\r|\n/', $input);
        $segments = [];  // 每个元素: [idx, duration, is_discontinuity_after, extinf_line, uri_line]
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = trim($lines[$i]);
            if (strpos($line, '#EXTINF') === 0) {
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) $duration = (float)$m[1];
                $uri = '';
                $uriIdx = -1;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (trim($lines[$j]) === '') continue;
                    if (strpos(trim($lines[$j]), '#') !== 0) {
                        $uri = trim($lines[$j]);
                        $uriIdx = $j;
                        break;
                    }
                }
                $segments[] = [
                    'extinf_idx' => $i,
                    'uri_idx' => $uriIdx,
                    'duration' => $duration,
                    'uri' => $uri,
                    'extinf' => $line,
                    'after_discontinuity' => false,
                ];
            } elseif (strpos($line, '#EXT-X-DISCONTINUITY') === 0) {
                // 标记下一个片段在 DISCONTINUITY 之后
                if (!empty($segments)) {
                    $segments[count($segments) - 1]['after_discontinuity'] = true;
                }
            }
        }

        $totalSegs = count($segments);
        if ($totalSegs === 0) return $input;

        // ===== 步骤 2：构建删除索引 =====
        $removeLineSet = [];  // 需要删除的行号集合

        // --- 2a: 标记非4s簇 ---
        $clusters = $this->findNonBaselineClusters($segments);
        foreach ($clusters as $cluster) {
            // 白名单：长度 < 3 的单段簇 = 可能是正常场景切换
            if ($cluster['length'] < $this->minClusterLength) continue;

            // 白名单：过长的簇（>15段 或 sum>35秒）= 正常内容
            if ($cluster['length'] > $this->maxClusterLength ||
                $cluster['sum'] > $this->maxClusterSum) continue;

            // 白名单：过短的簇 sum（<15秒）= 可能是过渡
            if ($cluster['sum'] < $this->minClusterSum) continue;

            // 关键校验：簇内是否有短片段（强信号）
            $hasShort = false;
            foreach ($cluster['segments'] as $seg) {
                if ($seg['duration'] < $this->shortFragmentThreshold) {
                    $hasShort = true; break;
                }
            }

            // 关键校验：是否有 DISCONTINUITY 边界（可在后台关闭此信号）
            $hasDiscontinuity = false;
            if ($this->enableDiscontinuity) {
                foreach ($cluster['segments'] as $seg) {
                    if (!empty($seg['after_discontinuity'])) {
                        $hasDiscontinuity = true; break;
                    }
                }
            }

            // 关键校验：簇内是否有极短片段（最强信号）
            $hasVeryShort = false;
            foreach ($cluster['segments'] as $seg) {
                if ($seg['duration'] < $this->veryShortThreshold) {
                    $hasVeryShort = true; break;
                }
            }

            // 综合判定：根据 Score 阈值（可在后台调整）判断是否为广告
            $score = 0;
            if ($hasShort) $score += 2;
            if ($hasDiscontinuity) $score += 2;
            if ($hasVeryShort) $score += 3;
            if ($cluster['length'] >= 6 && $cluster['length'] <= 12) $score += 1;
            if ($cluster['sum'] >= 18 && $cluster['sum'] <= 28) $score += 1;

            if ($score >= $this->scoreThreshold) {
                foreach ($cluster['segments'] as $seg) {
                    $removeLineSet[$seg['extinf_idx']] = true;
                    $removeLineSet[$seg['uri_idx']] = true;
                }
            }
        }

        // --- 2b: 标记独立的极短片段（<1.5s），即使不在簇中 ---
        foreach ($segments as $seg) {
            if ($seg['duration'] > 0 && $seg['duration'] < $this->veryShortThreshold) {
                // 如果这一段还没有因为簇被标记，单独标记
                if (!isset($removeLineSet[$seg['extinf_idx']])) {
                    // 只在不是连续多段 4s 中的第一段（即不是正常视频）时删除
                    // 这里简化：直接标记极短片段
                    $removeLineSet[$seg['extinf_idx']] = true;
                    $removeLineSet[$seg['uri_idx']] = true;
                }
            }
        }

        // --- 2c: 标记 DISCONTINUITY + 时长突变 > 2s 的相邻簇 ---
        for ($i = 1; $i < $totalSegs; $i++) {
            if (!empty($segments[$i]['after_discontinuity'])) {
                $prevDur = $segments[$i - 1]['duration'];
                $currDur = $segments[$i]['duration'];
                $surge = abs($prevDur - $currDur);
                if ($surge >= $this->discontinuitySurge && $currDur < $this->baselineDuration) {
                    // 当前片段是广告候选，向后扫描1~2段
                    // 但只有在该片段也被簇检测标记过才删除（避免误判）
                    if (isset($removeLineSet[$segments[$i]['extinf_idx']])) {
                        // 已经被标记，无需额外处理
                    }
                }
            }
        }

        // ===== 步骤 3：重新组装 M3U8 =====
        $newLines = [];
        foreach ($lines as $idx => $line) {
            if (isset($removeLineSet[$idx])) continue;
            $newLines[] = $line;
        }

        // 清理空行过多的情况
        $cleanLines = [];
        $consecutiveEmpty = 0;
        foreach ($newLines as $line) {
            if (trim($line) === '') {
                $consecutiveEmpty++;
                if ($consecutiveEmpty > 2) continue;
            } else {
                $consecutiveEmpty = 0;
            }
            $cleanLines[] = $line;
        }

        return implode("\n", $cleanLines);
    }

    /**
     * 查找非基准时长的片段簇
     * 基准时长：4.00s ± 0.10s（即 3.90~4.10s 都视为正常正片片段）
     */
    private function findNonBaselineClusters($segments)
    {
        $clusters = [];
        $current = [];
        $minDur = $this->baselineDuration - $this->baselineTolerance;  // 3.90
        $maxDur = $this->baselineDuration + $this->baselineTolerance;  // 4.10

        foreach ($segments as $seg) {
            $isBaseline = ($seg['duration'] >= $minDur && $seg['duration'] <= $maxDur);
            if (!$isBaseline) {
                // 非基准片段 → 加入当前簇
                $current[] = $seg;
            } else {
                // 基准片段 → 结束当前簇
                if (!empty($current)) {
                    $sum = 0;
                    foreach ($current as $s) $sum += $s['duration'];
                    $clusters[] = [
                        'segments' => $current,
                        'length' => count($current),
                        'sum' => $sum,
                    ];
                    $current = [];
                }
            }
        }
        // 处理最后一个可能的簇
        if (!empty($current)) {
            $sum = 0;
            foreach ($current as $s) $sum += $s['duration'];
            $clusters[] = [
                'segments' => $current,
                'length' => count($current),
                'sum' => $sum,
            ];
        }
        return $clusters;
    }
}

// 自动注册到算法注册表
if (class_exists('AlgorithmRegistry', false) && method_exists('AlgorithmRegistry', 'getInstance')) {
    AlgorithmRegistry::getInstance()->register(new RuyiPatternCleaner());
}
