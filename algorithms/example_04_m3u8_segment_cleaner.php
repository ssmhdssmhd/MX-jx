<?php
/**
 * 示例算法 4: M3U8 片段级过滤
 * 针对播放列表（m3u8 文本）做逐片段的智能识别：
 *   - 极短视频（<3 秒）可能是广告片头
 *   - 含广告关键词 URI 的片段
 *   - EXTINF 描述中含广告信息
 *
 * 作用域: m3u8
 * 优先级: 30
 */
class AlgoM3u8SegmentCleaner extends AbstractAlgorithm {

    public $id       = 'm3u8_segment_cleaner';
    public $priority = 30;
    public $enabled  = true;
    public $scope    = 'm3u8';

    public $config = [
        'min_duration' => 2.0,          // 短于此秒数且命中关键词的片段视为广告
        'short_segment_always_drop' => false, // 是否直接丢弃所有 <2 秒的片段（可能误伤片头）
        'ad_keywords'  => [
            'ad_', 'ads/', 'advert', 'promo', 'banner', 'sponsor',
            'pre-roll', 'mid-roll', 'post-roll', '广告', '贴片', '推广',
            'tongji', 'analytics', 'track', 'tracker', '/ad/', '/promo/',
            'xigua_ad', 'dytt_ad', 'iqiyi_ad', 'youku_ad',
        ],
        'safe_patterns' => [
            '/video/', '/play/', '/vod/', '/live/', '/main/', '/content/',
            'm3u8', 'segment', 'frag', 'hls', 'index', 'chunk',
            '正片', '高清', '超清', '蓝光',
        ],
    ];

    public function name() { return 'M3U8 片段级清洗器'; }
    public function description() { return '针对 M3U8 播放列表逐片段扫描：短片段 + 广告关键词 = 自动剔除；保留正片/主视频内容'; }
    public function version() { return '1.0.0'; }

    public function apply($input, $context = []) {
        if ($input === '' || strpos($input, '#EXTM3U') === false) {
            // 非 m3u8 内容：若含换行则仍尝试逐行清理
            return $input;
        }

        $lines = preg_split('/\r\n|\r|\n/', $input);
        $out = [];
        $pendingExtinf = null;
        $pendingDuration = 0;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;

            // 头部 & 标签
            if (strpos($trim, '#EXTM3U') === 0
                || strpos($trim, '#EXT-X-VERSION') === 0
                || strpos($trim, '#EXT-X-TARGETDURATION') === 0
                || strpos($trim, '#EXT-X-MEDIA-SEQUENCE') === 0
                || strpos($trim, '#EXT-X-STREAM-INF') === 0
                || strpos($trim, '#EXT-X-ENDLIST') === 0) {
                $out[] = $trim;
                continue;
            }

            // #EXTINF: 缓存片段描述
            if (strpos($trim, '#EXTINF') === 0) {
                $pendingExtinf = $trim;
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $trim, $m)) {
                    $duration = (float)$m[1];
                }
                $pendingDuration = $duration;
                continue;
            }

            // 其他注释标签 → 直接过滤（除非含白名单）
            if (strpos($trim, '#') === 0) {
                $safe = false;
                foreach ($this->config['safe_patterns'] as $p) {
                    if (stripos($trim, $p) !== false) { $safe = true; break; }
                }
                if ($safe) $out[] = $trim;
                continue;
            }

            // 片段 URI（紧跟 #EXTINF 后的那一行）
            if ($pendingExtinf !== null) {
                $combined = $pendingExtinf . ' ' . $trim;
                // 白名单 → 保留
                $safe = false;
                foreach ($this->config['safe_patterns'] as $p) {
                    if (stripos($combined, $p) !== false) { $safe = true; break; }
                }
                if ($safe) {
                    $out[] = $pendingExtinf;
                    $out[] = $trim;
                    $pendingExtinf = null;
                    continue;
                }
                // 关键词命中 → 丢弃
                $hit = false;
                foreach ($this->config['ad_keywords'] as $kw) {
                    if (stripos($combined, $kw) !== false) { $hit = true; break; }
                }
                if ($hit) { $pendingExtinf = null; continue; }
                // 超短视频 → 可选丢弃
                if (!empty($this->config['short_segment_always_drop'])
                    && $pendingDuration > 0 && $pendingDuration < $this->config['min_duration']) {
                    $pendingExtinf = null; continue;
                }
                // 否则保留
                $out[] = $pendingExtinf;
                $out[] = $trim;
                $pendingExtinf = null;
                continue;
            }

            // 无 EXTINF 的裸 URI（容错）：直接保留，除非命中关键词
            $hit = false;
            foreach ($this->config['ad_keywords'] as $kw) {
                if (stripos($trim, $kw) !== false) { $hit = true; break; }
            }
            if (!$hit) $out[] = $trim;
        }

        // 末尾若无 ENDLIST 则补充
        $hasEnd = false;
        foreach (array_reverse($out) as $l) {
            if (strpos(trim($l), '#EXT-X-ENDLIST') === 0) { $hasEnd = true; break; }
            if (trim($l) !== '') break;
        }
        if (!$hasEnd) $out[] = '#EXT-X-ENDLIST';

        return implode("\n", $out);
    }
}
