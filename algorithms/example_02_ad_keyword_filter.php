<?php
/**
 * 示例算法 2: 广告关键词过滤（适用于 URL 字符串 / M3U8 文本）
 * 命中以下任一关键词的行/URL 都会被清理/标记为广告
 *
 * 作用域: all (同时用于 URL 和 M3U8)
 * 优先级: 40
 */
class AlgoAdKeywordFilter extends AbstractAlgorithm {

    public $id       = 'ad_keyword_filter';
    public $priority = 40;
    public $enabled  = true;
    public $scope    = 'all';

    /** 广告关键词（命中则该行被移除） */
    public $config = [
        'keywords' => [
            'ad_', '_ad', 'ad.', '.ad/', 'ads/', '/ads/', 'adblock',
            'advert', 'advertisement', 'advertising', 'banner',
            'pre-roll', 'mid-roll', 'post-roll', 'promo', 'promotion',
            'sponsor', 'sponsored', '贴片', '片头广告', '片中广告',
            '片尾广告', '广告片段', '推广', '广告', '角标', '滚动条广告',
            'adserver', 'ad-network', 'adslot', 'adbreak', '广告位',
            'xigua_ad', 'xiguang_ad', 'dytt_ad', 'iqiyi_ad', 'youku_ad',
            'qq_ad', 'mgtv_ad', 'vip_ads', 'player_ad', 'track_',
            '/ad/', '/promo/', '/banner/', '/track/', '/tongji/',
            'google-analytics', 'googlesyndication', 'doubleclick',
        ],
        // 白名单（命中时保留，即便命中关键词）
        'whitelist' => [
            '/video/', '/play/', '/player/', '/vod/', '/live/',
            '正片', 'main', 'content', '高清', '超清', '蓝光',
            '/m3u8', 'index.m3u8', 'playlist.m3u8',
            '.aliyun', '.bcelive', '.huaweicloud', '.tencent',
        ],
    ];

    public function name() { return '广告关键词过滤器'; }
    public function description() { return '在 URL 字符串或 M3U8 文本中扫描广告关键词，命中行将被去除；命中白名单则保留'; }
    public function version() { return '1.0.0'; }

    public function apply($input, $context = []) {
        if ($input === '') return $input;

        // 1) 纯 URL 字符串 → 若命中关键词则尝试只保留基础域名
        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $lower = strtolower($input);
            foreach ($this->config['whitelist'] as $safe) {
                if (strpos($lower, strtolower($safe)) !== false) return $input;
            }
            $hit = false;
            foreach ($this->config['keywords'] as $kw) {
                if (stripos($input, $kw) !== false) { $hit = true; break; }
            }
            if ($hit) {
                // 仅保留基础地址（scheme + host + 主路径）
                $parts = parse_url($input);
                if ($parts === false) return $input;
                $scheme = $parts['scheme'] ?? 'https';
                $host   = $parts['host'] ?? '';
                $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
                $path   = $parts['path'] ?? '/';
                // 去除 path 中广告相关段
                $segs = array_values(array_filter(explode('/', $path), function ($s) {
                    foreach ($this->config['keywords'] as $kw) {
                        if (stripos($s, $kw) !== false) return false;
                    }
                    return true;
                }));
                $cleanPath = '/' . implode('/', $segs);
                return $scheme . '://' . $host . $port . $cleanPath;
            }
            return $input;
        }

        // 2) 多行长文本（可能是 M3U8） → 逐行处理
        if (strpos($input, "\n") !== false || strpos($input, "\r") !== false) {
            $lines = preg_split('/\r\n|\r|\n/', $input);
            $out = [];
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '') { $out[] = $line; continue; }
                // M3U8 头部标签: 直通
                if (strpos($trim, '#EXTM3U') === 0
                    || strpos($trim, '#EXT-X-VERSION') === 0
                    || strpos($trim, '#EXT-X-TARGETDURATION') === 0) {
                    $out[] = $line; continue;
                }
                // 白名单 → 保留
                $keep = false;
                foreach ($this->config['whitelist'] as $safe) {
                    if (stripos($trim, $safe) !== false) { $keep = true; break; }
                }
                if ($keep) { $out[] = $line; continue; }
                // 关键词命中 → 丢弃
                $hit = false;
                foreach ($this->config['keywords'] as $kw) {
                    if (stripos($trim, $kw) !== false) { $hit = true; break; }
                }
                if ($hit) continue;
                $out[] = $line;
            }
            return implode("\n", $out);
        }

        return $input;
    }
}
