<?php
/**
 * 示例算法 1: URL 跟踪参数清洗
 * 去除常见的跟踪参数如 utm_*, spm, clickid, fbclid, gclid, _=timestamp 等
 *
 * 作用域: url  (仅对 URL 字符串生效)
 * 优先级: 50  (较高优先级，早期执行)
 */
class AlgoUrlTrackerStrip extends AbstractAlgorithm {

    public $id       = 'url_tracker_strip';
    public $priority = 50;
    public $enabled  = true;
    public $scope    = 'url';

    /** 要移除的参数前缀（大小写不敏感） */
    public $config = [
        'prefixes' => [
            'utm_', 'spm', 'gclid', 'fbclid', 'clickid', 'click_id',
            '_hs', '__hs', 'hstc', '__hstc', 'mkt_tok', 'mkt_tok',
            'track', 'tracker', 'tracking', 'ref_', 'ref=',
            'from_', 'from=', 'src_', 'log_', 'campaign_', 'aff_',
            'referer_id', 'referer', 'referrer', 'adwords',
        ],
        // 纯键名的参数（完整匹配）
        'exact' => ['_', 't', 'ts', 'cb', 'v'],
    ];

    public function name() { return 'URL 跟踪参数清洗'; }
    public function description() { return '去除 utm_*, spm, gclid, fbclid, _=timestamp 等常见跟踪/广告参数，保留纯净播放地址'; }
    public function version() { return '1.0.0'; }

    public function apply($input, $context = []) {
        if ($input === '' || filter_var($input, FILTER_VALIDATE_URL) === false) {
            return $input;
        }

        $parts = parse_url($input);
        if ($parts === false || empty($parts['query'])) {
            return $input;
        }

        $query = $parts['query'];
        $params = [];
        parse_str($query, $params);

        $cleaned = [];
        foreach ($params as $key => $value) {
            $lower = strtolower($key);
            $skip = false;
            foreach ($this->config['prefixes'] as $prefix) {
                if (strpos($lower, strtolower($prefix)) === 0) { $skip = true; break; }
            }
            if ($skip) continue;
            if (in_array($lower, $this->config['exact'], true)) continue;
            $cleaned[$key] = $value;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';
        $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $user   = isset($parts['user']) ? $parts['user'] : '';
        $pass   = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth   = ($user !== '' || $pass !== '') ? $user . $pass . '@' : '';

        $newQuery = http_build_query($cleaned);
        $base = $scheme . '://' . $auth . $host . $port . $path;
        if ($newQuery !== '') $base .= '?' . $newQuery;
        $base .= $frag;

        return $base;
    }
}
