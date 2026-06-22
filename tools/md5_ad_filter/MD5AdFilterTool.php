<?php
/**
 * MD5指纹广告过滤工具 - 专业版
 *
 * 功能特点：
 * 1. 智能识别广告片段（通过MD5指纹重复频率）
 * 2. 自动学习和更新指纹数据库
 * 3. 支持白名单/黑名单管理
 * 4. 实时统计和监控
 * 5. 批量处理能力
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractTool', false)) {
    require_once __DIR__ . '/../core/AbstractTool.php';
}

class MD5AdFilterTool extends AbstractTool
{
    public $id = 'md5_ad_filter';
    public $group = '去广告';

    /** @var MD5PatternCleaner */
    private $cleaner;

    /** @var MD5FingerprintDB */
    private $db;

    public function __construct() {
        $this->id = 'md5_ad_filter';
        $this->group = '去广告';

        // 初始化MD5清洁器
        if (class_exists('MD5PatternCleaner')) {
            $this->cleaner = new MD5PatternCleaner();
        }

        // 初始化数据库
        if (class_exists('MD5FingerprintDB')) {
            $this->db = new MD5FingerprintDB();
        }
    }

    public function name() {
        return '🎯 MD5指纹广告过滤';
    }

    public function description() {
        return '基于MD5指纹的视频广告片段智能识别和过滤，支持自动学习、白名单管理和实时统计。';
    }

    public function version() {
        return '1.0.0';
    }

    public function getParamSchema() {
        return [
            'input' => [
                'type' => 'string',
                'required' => true,
                'label' => 'M3U8链接或内容',
                'hint' => '输入视频M3U8地址或直接粘贴M3U8内容',
            ],
            'mode' => [
                'type' => 'select',
                'label' => '处理模式',
                'options' => [
                    ['value' => 'auto', 'label' => '智能模式（自动识别）'],
                    ['value' => 'aggressive', 'label' => '激进模式（高敏感度）'],
                    ['value' => 'conservative', 'label' => '保守模式（低误删率）'],
                ],
                'default' => 'auto',
            ],
            'auto_learn' => [
                'type' => 'bool',
                'label' => '自动学习新指纹',
                'hint' => '自动将识别出的广告片段添加到指纹库',
                'default' => true,
            ],
            'use_whitelist' => [
                'type' => 'bool',
                'label' => '启用白名单保护',
                'hint' => '保护已知正片片段不被误删',
                'default' => true,
            ],
        ];
    }

    public function run($params = []) {
        $input = trim($params['input'] ?? '');
        $mode = $params['mode'] ?? 'auto';
        $autoLearn = (bool)($params['auto_learn'] ?? true);
        $useWhitelist = (bool)($params['use_whitelist'] ?? true);

        if (empty($input)) {
            return [
                'success' => false,
                'message' => '参数 input 不能为空',
                'data' => null,
            ];
        }

        // 判断输入类型：URL还是M3U8内容
        $isUrl = filter_var($input, FILTER_VALIDATE_URL);
        $m3u8Content = $input;

        if ($isUrl) {
            // 如果是URL，先下载内容
            $m3u8Content = $this->downloadM3U8($input);
            if (!$m3u8Content) {
                return [
                    'success' => false,
                    'message' => '无法下载M3U8内容，请检查URL是否正确',
                    'data' => null,
                ];
            }
        }

        // 检查是否为有效M3U8
        if (strpos($m3u8Content, '#EXTM3U') === false) {
            return [
                'success' => false,
                'message' => '输入内容不是有效的M3U8格式',
                'data' => null,
            ];
        }

        // 应用MD5广告过滤
        if ($this->cleaner) {
            $result = $this->cleaner->apply($m3u8Content, [
                'original_url' => $isUrl ? $input : 'inline_content',
                'mode' => $mode,
                'auto_learn' => $autoLearn,
                'use_whitelist' => $useWhitelist,
            ]);

            if ($result) {
                // 获取统计信息
                $stats = $result['stats'] ?? [];
                $dbStats = $this->db ? $this->db->getStats() : [];

                return [
                    'success' => true,
                    'message' => sprintf(
                        '处理完成：移除 %d 个广告片段，保留 %d 个正片片段',
                        $stats['removed_ad'] ?? 0,
                        ($stats['kept_whitelist'] ?? 0) + ($stats['kept_new'] ?? 0) + ($stats['kept_low_freq'] ?? 0)
                    ),
                    'data' => [
                        'original_segments' => $stats['total_segments'] ?? 0,
                        'removed_ad' => $stats['removed_ad'] ?? 0,
                        'kept_segments' => ($stats['kept_whitelist'] ?? 0) + ($stats['kept_new'] ?? 0) + ($stats['kept_low_freq'] ?? 0),
                        'downloaded' => $stats['downloaded'] ?? 0,
                        'failed_downloads' => $stats['failed_downloads'] ?? 0,
                        'elapsed_ms' => $stats['elapsed_ms'] ?? 0,
                        'cleaned_m3u8' => $result['cleaned'] ?? '',
                        'fingerprint_db' => [
                            'total_fingerprints' => $dbStats['total'] ?? 0,
                            'today_fingerprints' => $dbStats['today_segments'] ?? 0,
                            'ad_fingerprints' => $dbStats['ad_count'] ?? 0,
                        ],
                    ],
                ];
            }
        }

        // 如果没有MD5清洁器，使用基础过滤
        return $this->basicFilter($m3u8Content);
    }

    /**
     * 基础过滤（不依赖MD5下载）
     */
    private function basicFilter($m3u8Content) {
        $lines = preg_split('/\r\n|\r|\n/', $m3u8Content);
        $outputLines = [];
        $totalSegments = 0;
        $removedSegments = 0;
        $adDomains = ['ad.', 'ads.', 'advert.', 'sponsor.', 'doubleclick'];
        $adKeywords = ['ad_', '_ad', 'adver', 'promo', 'banner', 'pre-roll'];

        $idx = 0;
        while ($idx < count($lines)) {
            $line = trim($lines[$idx]);

            // 直接输出所有标签行
            if (strpos($line, '#') === 0) {
                $outputLines[] = $line;
                $idx++;
                continue;
            }

            // 裸URI行
            if ($line !== '') {
                $totalSegments++;
                $lineLower = strtolower($line);

                // 检查是否为广告片段
                $isAd = false;
                foreach ($adDomains as $domain) {
                    if (strpos($lineLower, $domain) !== false) {
                        $isAd = true;
                        break;
                    }
                }

                if (!$isAd) {
                    foreach ($adKeywords as $keyword) {
                        if (strpos($lineLower, $keyword) !== false) {
                            $isAd = true;
                            break;
                        }
                    }
                }

                if ($isAd) {
                    $removedSegments++;
                } else {
                    $outputLines[] = $line;
                }
            }
            $idx++;
        }

        return [
            'success' => true,
            'message' => sprintf('基础过滤完成：移除 %d 个广告片段，保留 %d 个正片片段', $removedSegments, $totalSegments - $removedSegments),
            'data' => [
                'original_segments' => $totalSegments,
                'removed_ad' => $removedSegments,
                'kept_segments' => $totalSegments - $removedSegments,
                'cleaned_m3u8' => implode("\n", $outputLines),
            ],
        ];
    }

    /**
     * 下载M3U8内容
     */
    private function downloadM3U8($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content && $httpCode === 200) {
            return $content;
        }

        return false;
    }
}
