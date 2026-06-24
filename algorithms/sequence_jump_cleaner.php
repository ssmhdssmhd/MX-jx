<?php
/**
 * 序号跳跃广告检测算法 - Sequence Jump Cleaner
 *
 * ╔═══════════════════════════════════════════════════════════════╗
 * ║  核心原理：                                                    ║
 * ║  ============================================================  ║
 * ║  某些资源站将广告片段以"序号跳跃"的方式插入到正片序列中：       ║
 * ║  正片序号(0-100) → 广告序号(800000-800010) → 正片序号(101-...) ║
 * ║                                                               ║
 * ║  这种广告通常伴随 #EXT-X-DISCONTINUITY 标签，形成"夹心"模式。  ║
 * ║  通过检测序号跳跃的方向和幅度，可以精准识别广告块。            ║
 * ║                                                               ║
 * ║  检测策略（可组合使用）：                                      ║
 * ║  1. 阈值模式：序号超过阈值即为广告（最简单高效）               ║
 * ║  2. 跳跃模式：DISCONTINUITY 两侧序号差超过阈值                ║
 * ║  3. 夹心模式：先升后降的序号序列（精准但复杂）                 ║
 * ║                                                               ║
 * ║  适用站点：lfthirtytwo.com、lzcdn27.com 等使用序号命名的资源站 ║
 * ╚═══════════════════════════════════════════════════════════════╝
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractAlgorithm', false)) {
    require_once __DIR__ . '/AbstractAlgorithm.php';
}

class SequenceJumpCleaner extends AbstractAlgorithm {

    public $id       = 'sequence_jump_cleaner';
    public $priority = 35;
    public $enabled  = true;
    public $scope    = 'm3u8';

    public $matchPatterns = [
        'lfthirtytwo.com',
        'lzcdn27.com',
    ];

    public $config = [
        'mode' => 'auto',
        'seq_threshold' => 800000,
        'jump_threshold' => 1000,
        'min_ad_segments' => 1,
        'max_ad_segments' => 50,
        'enable_discon_detection' => true,
        'enable_sandwich_detection' => true,
        'debug' => false,
    ];

    public function name() { return '序号跳跃广告检测器'; }
    public function description() { return '通过检测 TS 文件名序号的跳跃模式（如 0→800000→0），精准识别插播广告块，特别适合 lfthirtytwo 等使用序号命名的资源站'; }
    public function version() { return '1.0.0'; }
    public function author() { return 'MX-射手沫蝴蝶'; }

    public function apply($input, $context = []) {
        if ($input === '' || strpos($input, '#EXTM3U') === false) {
            return $input;
        }

        $lines = preg_split('/\r\n|\r|\n/', $input);
        $segments = $this->parseSegments($lines);

        if (count($segments) === 0) {
            return $input;
        }

        $adIndices = $this->detectAdSegments($segments, $context);

        if (count($adIndices) === 0) {
            return $input;
        }

        $output = $this->buildCleanM3u8($lines, $segments, $adIndices);

        if (!empty($this->config['debug'])) {
            error_log("[SequenceJumpCleaner] 移除广告片段 " . count($adIndices) . " 个");
        }

        return $output;
    }

    /**
     * 解析 M3U8 片段，提取序号信息
     */
    private function parseSegments($lines) {
        $segments = [];
        $currentDuration = 0;
        $currentExtinf = '';
        $disconCount = 0;
        $segmentIndex = 0;

        foreach ($lines as $lineNum => $line) {
            $trim = trim($line);
            if ($trim === '') continue;

            if (stripos($trim, '#EXT-X-DISCONTINUITY') === 0) {
                $disconCount++;
                $segments[] = [
                    'type' => 'discontinuity',
                    'line_num' => $lineNum,
                    'discon_index' => $disconCount,
                ];
                continue;
            }

            if (stripos($trim, '#EXTINF:') === 0) {
                $currentExtinf = $trim;
                $durStr = substr($trim, 8);
                $commaPos = strpos($durStr, ',');
                if ($commaPos !== false) $durStr = substr($durStr, 0, $commaPos);
                $currentDuration = (float)trim($durStr);
                continue;
            }

            if ($trim && strpos($trim, '#') !== 0 && 
                (stripos($trim, '.ts') !== false || stripos($trim, '.m4s') !== false)) {
                $seqNum = $this->extractSeqNum($trim);
                $segments[] = [
                    'type' => 'segment',
                    'uri' => $trim,
                    'extinf' => $currentExtinf,
                    'duration' => $currentDuration,
                    'seq_num' => $seqNum,
                    'line_num' => $lineNum,
                    'segment_index' => $segmentIndex,
                ];
                $segmentIndex++;
                $currentDuration = 0;
                $currentExtinf = '';
            }
        }

        return $segments;
    }

    /**
     * 从文件名中提取序号
     */
    private function extractSeqNum($uri) {
        $fileName = basename($uri);
        $seqNum = null;

        if (preg_match('/(\d+)\.ts$/i', $fileName, $matches)) {
            $seqNum = (int)$matches[1];
        } elseif (preg_match('/_(\d+)[_\.]/', $fileName, $matches)) {
            $seqNum = (int)$matches[1];
        } elseif (preg_match('/-(\d+)\./', $fileName, $matches)) {
            $seqNum = (int)$matches[1];
        }

        return $seqNum;
    }

    /**
     * 检测广告片段
     */
    private function detectAdSegments($segments, $context) {
        $adSegmentIndices = [];
        $mode = $this->config['mode'] ?? 'auto';

        if ($mode === 'threshold' || $mode === 'auto') {
            $result = $this->detectByThreshold($segments);
            $adSegmentIndices = array_merge($adSegmentIndices, $result);
        }

        if ($mode === 'jump' || $mode === 'auto') {
            $result = $this->detectByJump($segments);
            $adSegmentIndices = array_merge($adSegmentIndices, $result);
        }

        if ($mode === 'sandwich' || $mode === 'auto') {
            $result = $this->detectSandwichPattern($segments);
            $adSegmentIndices = array_merge($adSegmentIndices, $result);
        }

        $adSegmentIndices = array_unique($adSegmentIndices);
        sort($adSegmentIndices);

        return $adSegmentIndices;
    }

    /**
     * 策略1：阈值检测 - 序号超过阈值即为广告
     */
    private function detectByThreshold($segments) {
        $adIndices = [];
        $threshold = $this->config['seq_threshold'] ?? 800000;

        foreach ($segments as $seg) {
            if ($seg['type'] !== 'segment') continue;
            if ($seg['seq_num'] !== null && $seg['seq_num'] >= $threshold) {
                $adIndices[] = $seg['segment_index'];
            }
        }

        return $adIndices;
    }

    /**
     * 策略2：跳跃检测 - DISCONTINUITY 两侧序号差超过阈值
     */
    private function detectByJump($segments) {
        $adIndices = [];
        $jumpThreshold = $this->config['jump_threshold'] ?? 1000;
        $enableDiscon = !empty($this->config['enable_discon_detection']);

        if (!$enableDiscon) {
            return $adIndices;
        }

        for ($i = 0; $i < count($segments); $i++) {
            if ($segments[$i]['type'] !== 'discontinuity') continue;

            $prevSeg = $this->findPrevSegment($segments, $i);
            $nextSeg = $this->findNextSegment($segments, $i);

            if (!$prevSeg || !$nextSeg) continue;
            if ($prevSeg['seq_num'] === null || $nextSeg['seq_num'] === null) continue;

            $jump = $nextSeg['seq_num'] - $prevSeg['seq_num'];

            if (abs($jump) > $jumpThreshold) {
                if ($jump > 0) {
                    $adBlock = $this->collectForwardJumpAdBlock($segments, $i, $prevSeg['seq_num']);
                    foreach ($adBlock as $idx) {
                        $adIndices[] = $idx;
                    }
                }
            }
        }

        return $adIndices;
    }

    /**
     * 策略3：夹心模式检测 - 序号先升后降，中间部分为广告
     */
    private function detectSandwichPattern($segments) {
        $adIndices = [];
        if (empty($this->config['enable_sandwich_detection'])) {
            return $adIndices;
        }

        $jumpThreshold = $this->config['jump_threshold'] ?? 1000;
        $maxAdSegs = $this->config['max_ad_segments'] ?? 50;

        $disconPositions = [];
        foreach ($segments as $i => $seg) {
            if ($seg['type'] === 'discontinuity') {
                $disconPositions[] = $i;
            }
        }

        for ($i = 0; $i < count($disconPositions) - 1; $i++) {
            $firstDiscon = $disconPositions[$i];
            $secondDiscon = $disconPositions[$i + 1];

            $segCount = 0;
            for ($j = $firstDiscon + 1; $j < $secondDiscon; $j++) {
                if ($segments[$j]['type'] === 'segment') $segCount++;
            }

            if ($segCount === 0 || $segCount > $maxAdSegs) continue;

            $prevSeg = $this->findPrevSegment($segments, $firstDiscon);
            $nextSeg = $this->findNextSegment($segments, $secondDiscon);
            $firstBlockSeg = $this->findNextSegment($segments, $firstDiscon);
            $lastBlockSeg = $this->findPrevSegment($segments, $secondDiscon);

            if (!$prevSeg || !$nextSeg || !$firstBlockSeg || !$lastBlockSeg) continue;
            if ($prevSeg['seq_num'] === null || $nextSeg['seq_num'] === null) continue;
            if ($firstBlockSeg['seq_num'] === null || $lastBlockSeg['seq_num'] === null) continue;

            $jumpUp = $firstBlockSeg['seq_num'] - $prevSeg['seq_num'];
            $jumpDown = $lastBlockSeg['seq_num'] - $nextSeg['seq_num'];

            if ($jumpUp > $jumpThreshold && $jumpDown > $jumpThreshold) {
                for ($j = $firstDiscon + 1; $j < $secondDiscon; $j++) {
                    if ($segments[$j]['type'] === 'segment') {
                        $adIndices[] = $segments[$j]['segment_index'];
                    }
                }
            }
        }

        return $adIndices;
    }

    /**
     * 收集向前跳跃后的广告块
     */
    private function collectForwardJumpAdBlock($segments, $disconIdx, $prevSeq) {
        $adIndices = [];
        $maxAdSegs = $this->config['max_ad_segments'] ?? 50;
        $jumpThreshold = $this->config['jump_threshold'] ?? 1000;

        $count = 0;
        for ($i = $disconIdx + 1; $i < count($segments) && $count < $maxAdSegs; $i++) {
            if ($segments[$i]['type'] === 'discontinuity') {
                $nextSeg = $this->findNextSegment($segments, $i);
                if ($nextSeg && $nextSeg['seq_num'] !== null) {
                    $jumpBack = abs($nextSeg['seq_num'] - $prevSeq - 1);
                    if ($jumpBack < $jumpThreshold) {
                        break;
                    }
                }
            }
            if ($segments[$i]['type'] === 'segment') {
                $adIndices[] = $segments[$i]['segment_index'];
                $count++;
            }
        }

        return $adIndices;
    }

    /**
     * 找到指定位置前一个片段
     */
    private function findPrevSegment($segments, $idx) {
        for ($i = $idx - 1; $i >= 0; $i--) {
            if ($segments[$i]['type'] === 'segment') {
                return $segments[$i];
            }
        }
        return null;
    }

    /**
     * 找到指定位置后一个片段
     */
    private function findNextSegment($segments, $idx) {
        for ($i = $idx + 1; $i < count($segments); $i++) {
            if ($segments[$i]['type'] === 'segment') {
                return $segments[$i];
            }
        }
        return null;
    }

    /**
     * 构建清理后的 M3U8
     */
    private function buildCleanM3u8($lines, $segments, $adSegmentIndices) {
        $adLineNums = [];
        $adSet = array_flip($adSegmentIndices);

        foreach ($segments as $seg) {
            if ($seg['type'] === 'segment' && isset($adSet[$seg['segment_index']])) {
                $adLineNums[$seg['line_num']] = true;
                $prevLine = $seg['line_num'] - 1;
                while ($prevLine >= 0 && trim($lines[$prevLine]) === '') {
                    $prevLine--;
                }
                if ($prevLine >= 0 && stripos(trim($lines[$prevLine]), '#EXTINF:') === 0) {
                    $adLineNums[$prevLine] = true;
                }
            }
        }

        $disconToRemove = [];
        $disconIndex = 0;
        foreach ($segments as $i => $seg) {
            if ($seg['type'] !== 'discontinuity') continue;
            $disconIndex++;

            $prevSeg = $this->findPrevSegment($segments, $i);
            $nextSeg = $this->findNextSegment($segments, $i);

            $prevIsAd = $prevSeg && isset($adSet[$prevSeg['segment_index']]);
            $nextIsAd = $nextSeg && isset($adSet[$nextSeg['segment_index']]);

            if ($prevIsAd || $nextIsAd) {
                $disconToRemove[$seg['line_num']] = true;
            }
        }

        $output = [];
        foreach ($lines as $lineNum => $line) {
            if (isset($adLineNums[$lineNum])) continue;
            if (isset($disconToRemove[$lineNum])) continue;
            $output[] = $line;
        }

        return implode("\n", $output);
    }
}
