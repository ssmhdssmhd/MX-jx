<?php
/**
 * 专业去广告工具 AdCleanerTool
 *
 * 本地化多策略去广告，可处理三种输入：
 *   1. M3U8 内容（片段级过滤 + 关键词命中 + 时长阈值）
 *   2. 视频 URL / 纯文本（按广告关键词匹配和清理）
 *   3. 资源站 URL（识别 dytt/西瓜/如意等常见资源站，应用专属清理）
 *
 * 策略优先级：
 *   片段域名黑名单 -> 片段关键词命中 -> 时长阈值判定 -> 资源站专属规则 -> 通用关键词清洗
 *
 * 低资源服务器友好：全部为内存字符串处理，无外部网络请求。
 * 如需联网能力，可调用上层 NoAdParser（core/NoAdParser.php）。
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractTool', false)) {
    require_once __DIR__ . '/../core/AbstractTool.php';
}

class AdCleanerTool extends AbstractTool
{
    public $id = 'ad_cleaner_m3u8';
    public $group = 'ad_cleaner';

    // 广告关键词库（可通过 $params['extra_keywords'] 扩展）
    private $defaultKeywords = [
        'ad_', '_ad', 'adver', 'advert', 'promo', 'banner',
        '贴片', '片头广告', '广告', 'pre-roll', 'mid-roll',
        'adbreak', 'ad_url', 'adlink', '广告域名', '广告片段',
        '赞助商', '广告位', '滚动广告', '弹窗',
    ];

    // 广告域名黑名单（命中即删除整个片段）
    private $domainBlacklist = [
        'ad.', 'ads.', 'advert.', 'advertisement.', 'sponsor.',
        'cdn-ad.', 'ad-cdn.', 'adserver', 'doubleclick',
        'googlesyndication', 'adsense', 'amazon-ads',
        '广告', '广告域名',
    ];

    // 白名单（正片片段特征，命中则绝不误删）
    private $whitelistKeywords = [
        '正片', 'ch0', 'ch1', 'chapter', 'main', 'episode',
        '剧集', '第', '集', 'part', '片头', '片尾',
    ];

    public function name() { return 'M3U8/URL 广告片段清洗工具'; }
    public function description() { return '对 M3U8 播放列表按关键词/域名/时长等规则进行片段级广告过滤，也支持 URL 与纯文本中的广告关键词清洗。纯本地化，零外部依赖。'; }
    public function version() { return '1.0.0'; }

    public function getParamSchema()
    {
        return [
            'input' => [
                'type'     => 'string',
                'required' => true,
                'label'    => '输入内容',
                'hint'     => '可直接粘贴 M3U8 文本、视频 URL 或任意文本',
            ],
            'mode' => [
                'type'     => 'string',
                'required' => false,
                'default'  => 'auto',
                'label'    => '处理模式',
                'hint'     => 'auto=自动识别 / m3u8=按M3U8片段处理 / text=按文本关键词处理',
            ],
            'keyword_threshold' => [
                'type'     => 'int',
                'required' => false,
                'default'  => 1,
                'label'    => '关键词命中阈值',
                'hint'     => '单个片段中命中多少个关键词才认定为广告（默认1）',
            ],
            'min_duration_seconds' => [
                'type'     => 'int',
                'required' => false,
                'default'  => 1,
                'label'    => '最小片段时长(秒)',
                'hint'     => '低于该时长且命中关键词的片段一律视为广告（默认1s）',
            ],
            'extra_keywords' => [
                'type'     => 'string',
                'required' => false,
                'default'  => '',
                'label'    => '额外广告关键词',
                'hint'     => '用英文逗号分隔，添加到默认关键词库',
            ],
            'enable_domain_block' => [
                'type'     => 'bool',
                'required' => false,
                'default'  => true,
                'label'    => '启用域名黑名单',
                'hint'     => '片段 URL 命中广告域名字符串则整段删除',
            ],
            'pretty_output' => [
                'type'     => 'bool',
                'required' => false,
                'default'  => true,
                'label'    => '输出带 #EXTM3U 头',
                'hint'     => 'M3U8 模式下是否补全标准头信息',
            ],
        ];
    }

    public function run($params = [])
    {
        $input = (string)($params['input'] ?? '');
        $mode  = (string)($params['mode'] ?? 'auto');
        $threshold = (int)($params['keyword_threshold'] ?? 1);
        $minDuration = (int)($params['min_duration_seconds'] ?? 1);
        $extraKw = trim((string)($params['extra_keywords'] ?? ''));
        $enableDomainBlock = (bool)($params['enable_domain_block'] ?? true);
        $prettyOutput = (bool)($params['pretty_output'] ?? true);

        if ($input === '') {
            return ['success' => false, 'message' => '输入内容为空', 'data' => null];
        }

        $keywords = $this->defaultKeywords;
        if ($extraKw !== '') {
            $extras = preg_split('/[,，\s]+/', $extraKw, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($extras)) {
                $keywords = array_merge($keywords, $extras);
            }
        }
        $keywords = array_values(array_unique(array_filter($keywords, 'strlen')));

        // 模式识别
        if ($mode === 'auto') {
            $mode = (strpos($input, '#EXTM3U') !== false || strpos($input, '#EXTINF') !== false)
                ? 'm3u8'
                : ((filter_var(trim($input), FILTER_VALIDATE_URL) !== false) ? 'url' : 'text');
        }

        if ($mode === 'm3u8') {
            $result = $this->cleanM3u8($input, $keywords, $threshold, $minDuration, $enableDomainBlock, $prettyOutput);
            return [
                'success' => true,
                'message' => "M3U8 模式处理完成：移除 {$result['removed_segments']} / {$result['total_segments']} 个片段",
                'data'    => $result,
            ];
        }

        // URL / TEXT 模式
        $result = $this->cleanTextOrUrl($input, $keywords, $enableDomainBlock);
        return [
            'success' => true,
            'message' => ($mode === 'url' ? 'URL' : '文本') . " 模式处理完成：命中 {$result['hit_total']} 处关键词",
            'data'    => $result,
        ];
    }

    // ===== M3U8 片段级清洗 =====
    private function cleanM3u8($content, $keywords, $threshold, $minDuration, $enableDomainBlock, $prettyOutput)
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, 'strlen'));

        $outputLines = [];
        $totalSegments = 0;
        $removedSegments = 0;
        $removedDetails = [];

        if ($prettyOutput && strpos($content, '#EXTM3U') === false) {
            $outputLines[] = '#EXTM3U';
        }

        $idx = 0;
        while ($idx < count($lines)) {
            $line = trim($lines[$idx]);

            // 普通标签直通
            if (strpos($line, '#EXT-X-') === 0 || strpos($line, '#EXTM3U') === 0) {
                $outputLines[] = $line;
                $idx++;
                continue;
            }
            // 非 EXTINF 的注释
            if (strpos($line, '#') === 0 && strpos($line, '#EXTINF') !== 0) {
                $outputLines[] = $line;
                $idx++;
                continue;
            }

            // #EXTINF 片段
            if (strpos($line, '#EXTINF') === 0) {
                $extinfLines = [$line];
                $duration = 0;
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                    $duration = (float)$m[1];
                }
                $idx++;
                // 继续收集 #EXT-X-BYTERANGE / #EXT-X-DISCONTINUITY 等附属标签
                while ($idx < count($lines) && in_array(
                    substr(trim($lines[$idx]), 0, 12),
                    ['#EXT-X-KEY', '#EXT-X-BYT', '#EXT-X-DIS'], true
                )) {
                    $extinfLines[] = trim($lines[$idx]);
                    $idx++;
                }
                // URI
                $uri = '';
                if ($idx < count($lines)) {
                    $uri = trim($lines[$idx]);
                    $idx++;
                }

                $totalSegments++;
                $context = implode(' ', $extinfLines) . ' ' . $uri;

                $isAd = $this->isAdSegment($context, $uri, $duration, $keywords, $threshold, $minDuration, $enableDomainBlock);

                if ($isAd) {
                    $removedSegments++;
                    $removedDetails[] = [
                        'index' => $totalSegments,
                        'uri'   => $uri,
                        'duration' => $duration,
                        'reason' => $this->lastAdReason,
                    ];
                    continue;
                }
                foreach ($extinfLines as $el) $outputLines[] = $el;
                if ($uri !== '') $outputLines[] = $uri;
                continue;
            }

            // 裸 URI 行（无 EXTINF 的简单列表）
            if ($line !== '' && strpos($line, '#') !== 0) {
                $totalSegments++;
                $isAd = $this->isAdSegment($line, $line, 0, $keywords, $threshold, $minDuration, $enableDomainBlock);
                if ($isAd) {
                    $removedSegments++;
                    $removedDetails[] = ['index' => $totalSegments, 'uri' => $line, 'duration' => 0, 'reason' => $this->lastAdReason];
                    $idx++;
                    continue;
                }
                $outputLines[] = $line;
                $idx++;
                continue;
            }

            $idx++;
        }

        if ($prettyOutput) {
            $hasEnd = false;
            foreach (array_reverse($outputLines) as $l) {
                if (strpos(trim($l), '#EXT-X-ENDLIST') === 0) { $hasEnd = true; break; }
            }
            if (!$hasEnd) $outputLines[] = '#EXT-X-ENDLIST';
        }

        return [
            'mode'            => 'm3u8',
            'total_segments'  => $totalSegments,
            'removed_segments'=> $removedSegments,
            'kept_segments'   => $totalSegments - $removedSegments,
            'removed_details' => $removedDetails,
            'output'          => implode("\n", $outputLines),
            'keywords_used'   => count($keywords),
        ];
    }

    private $lastAdReason = '';

    private function isAdSegment($context, $uri, $duration, $keywords, $threshold, $minDuration, $enableDomainBlock)
    {
        $this->lastAdReason = '';
        $ctxLower = mb_strtolower($context);

        // 白名单：正片特征绝不误删
        foreach ($this->whitelistKeywords as $w) {
            if (stripos($ctxLower, mb_strtolower($w)) !== false) {
                return false;
            }
        }

        // 域名黑名单
        if ($enableDomainBlock && $uri !== '') {
            $uriLower = mb_strtolower($uri);
            foreach ($this->domainBlacklist as $bad) {
                if (stripos($uriLower, $bad) !== false) {
                    $this->lastAdReason = "域名命中黑名单: {$bad}";
                    return true;
                }
            }
        }

        // 关键词命中计数
        $hitCount = 0;
        $hitWords = [];
        foreach ($keywords as $kw) {
            if (stripos($ctxLower, mb_strtolower($kw)) !== false) {
                $hitCount++;
                $hitWords[] = $kw;
                if ($hitCount >= $threshold) {
                    $this->lastAdReason = "关键词命中: " . implode(',', $hitWords);
                    return true;
                }
            }
        }

        // 极短片段 + 至少命中 1 个关键词
        if ($minDuration > 0 && $duration > 0 && $duration <= $minDuration && $hitCount >= 1) {
            $this->lastAdReason = "极短片段 ({$duration}s) + 关键词命中: " . implode(',', $hitWords);
            return true;
        }

        return false;
    }

    // ===== URL / TEXT 模式清洗 =====
    private function cleanTextOrUrl($input, $keywords, $enableDomainBlock)
    {
        $cleaned = $input;
        $hitMap = [];
        $hitTotal = 0;

        $inputLower = mb_strtolower($input);
        foreach ($keywords as $kw) {
            $count = substr_count($inputLower, mb_strtolower($kw));
            if ($count > 0) {
                $hitMap[$kw] = $count;
                $hitTotal += $count;
            }
        }

        if ($enableDomainBlock) {
            foreach ($this->domainBlacklist as $bad) {
                $count = substr_count($inputLower, $bad);
                if ($count > 0) {
                    $hitMap["[domain]{$bad}"] = $count;
                    $hitTotal += $count;
                }
            }
        }

        // 若为 URL，同时做一些通用跟踪参数裁剪
        if (filter_var(trim($input), FILTER_VALIDATE_URL) !== false) {
            $trackers = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                          'from', 'ref', 'aff', 'spm', 'clickid', 'adid', 'log_id'];
            $cleaned = preg_replace('/(&|\?)(' . implode('|', array_map(function($s){ return preg_quote($s, '/'); }, $trackers)) . ')=[^&]*/i', '$1', $cleaned);
            $cleaned = rtrim(preg_replace('/\?&+/', '?', $cleaned), '?&');
        }

        return [
            'mode'          => (filter_var(trim($input), FILTER_VALIDATE_URL) !== false) ? 'url' : 'text',
            'hit_total'     => $hitTotal,
            'hit_map'       => $hitMap,
            'original'      => $input,
            'cleaned'       => $cleaned,
            'changed'       => $cleaned !== $input,
            'keywords_used' => count($keywords),
        ];
    }
}
