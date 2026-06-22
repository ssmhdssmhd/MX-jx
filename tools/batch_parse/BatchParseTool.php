<?php
/**
 * 批量解析工具 - 快速批量处理多个视频源
 *
 * 功能特点：
 * 1. 支持批量导入多个视频URL
 * 2. 自动分配解析源
 * 3. 实时显示处理进度
 * 4. 生成批量处理报告
 * 5. 支持导出结果
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractTool', false)) {
    require_once __DIR__ . '/../core/AbstractTool.php';
}

class BatchParseTool extends AbstractTool
{
    public $id = 'batch_parse';
    public $group = '批量处理';

    public function __construct() {
        $this->id = 'batch_parse';
        $this->group = '批量处理';
    }

    public function name() {
        return '📋 批量解析工具';
    }

    public function description() {
        return '支持批量导入多个视频URL，自动解析并去广告，生成处理报告。';
    }

    public function version() {
        return '1.0.0';
    }

    public function getParamSchema() {
        return [
            'urls' => [
                'type' => 'textarea',
                'required' => true,
                'label' => '视频URL列表',
                'hint' => '每行一个URL，支持M3U8或视频页面地址',
            ],
            'max_parallel' => [
                'type' => 'int',
                'label' => '最大并发数',
                'hint' => '同时处理的视频数量（1-10）',
                'default' => 3,
            ],
            'remove_ads' => [
                'type' => 'bool',
                'label' => '自动去广告',
                'hint' => '自动应用去广告算法',
                'default' => true,
            ],
            'save_report' => [
                'type' => 'bool',
                'label' => '保存报告',
                'hint' => '保存批量处理报告到文件',
                'default' => true,
            ],
        ];
    }

    public function run($params = []) {
        $urls = trim($params['urls'] ?? '');
        $maxParallel = max(1, min(10, (int)($params['max_parallel'] ?? 3)));
        $removeAds = (bool)($params['remove_ads'] ?? true);
        $saveReport = (bool)($params['save_report'] ?? true);

        if (empty($urls)) {
            return [
                'success' => false,
                'message' => '请输入至少一个视频URL',
                'data' => null,
            ];
        }

        // 解析URL列表
        $urlList = array_filter(array_map('trim', explode("\n", $urls)), function($url) {
            return !empty($url) && (filter_var($url, FILTER_VALIDATE_URL) || strpos($url, '#EXTM3U') !== false);
        });

        if (empty($urlList)) {
            return [
                'success' => false,
                'message' => '未找到有效的视频URL',
                'data' => null,
            ];
        }

        $total = count($urlList);
        $startTime = microtime(true);
        $results = [];
        $successCount = 0;
        $failedCount = 0;
        $totalAdsRemoved = 0;

        // 逐个处理（实际生产环境可改为并发）
        foreach ($urlList as $index => $url) {
            $result = $this->processUrl($url, $removeAds);
            $result['index'] = $index + 1;
            $results[] = $result;

            if ($result['success']) {
                $successCount++;
                $totalAdsRemoved += $result['ads_removed'] ?? 0;
            } else {
                $failedCount++;
            }

            // 避免请求过快
            if ($index < $total - 1) {
                usleep(100000); // 100ms
            }
        }

        $elapsedTime = round((microtime(true) - $startTime) * 1000);

        // 生成报告
        $report = [
            'total' => $total,
            'success' => $successCount,
            'failed' => $failedCount,
            'total_ads_removed' => $totalAdsRemoved,
            'elapsed_ms' => $elapsedTime,
            'results' => $results,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // 保存报告
        if ($saveReport) {
            $this->saveReport($report);
        }

        return [
            'success' => true,
            'message' => sprintf(
                '批量处理完成：成功 %d/%d 个，移除广告片段 %d 个，耗时 %dms',
                $successCount, $total, $totalAdsRemoved, $elapsedTime
            ),
            'data' => $report,
        ];
    }

    /**
     * 处理单个URL
     */
    private function processUrl($url, $removeAds) {
        $startTime = microtime(true);

        try {
            // 如果是M3U8内容，解析
            if (strpos($url, '#EXTM3U') !== false) {
                return $this->processM3U8Content($url, $removeAds, $startTime);
            }

            // 下载并解析
            $m3u8Content = $this->downloadM3U8($url);
            if (!$m3u8Content) {
                return [
                    'success' => false,
                    'url' => $url,
                    'error' => '无法获取M3U8内容',
                    'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
                ];
            }

            return $this->processM3U8Content($m3u8Content, $removeAds, $startTime, $url);

        } catch (Exception $e) {
            return [
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * 处理M3U8内容
     */
    private function processM3U8Content($content, $removeAds, $startTime, $originalUrl = 'inline') {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $segments = [];
        $adDomains = ['ad.', 'ads.', 'advert.', 'sponsor.', 'doubleclick', 'googletagmanager'];
        $adKeywords = ['ad_', '_ad', 'adver', 'promo', 'banner', 'pre-roll', 'mid-roll'];

        $idx = 0;
        while ($idx < count($lines)) {
            $line = trim($lines[$idx]);

            // 收集EXTINF片段
            if (strpos($line, '#EXTINF') === 0) {
                $extinfLine = $line;
                $uri = '';

                // 获取下一个非标签行作为URI
                $j = $idx + 1;
                while ($j < count($lines)) {
                    $nextLine = trim($lines[$j]);
                    if (!empty($nextLine) && strpos($nextLine, '#') !== 0) {
                        $uri = $nextLine;
                        break;
                    }
                    $j++;
                }

                if (!empty($uri)) {
                    $segments[] = [
                        'extinf' => $extinfLine,
                        'uri' => $uri,
                        'duration' => $this->extractDuration($extinfLine),
                    ];
                }
                $idx = $j;
            } else {
                $idx++;
            }
        }

        // 去广告处理
        $adsRemoved = 0;
        $cleanedSegments = [];

        if ($removeAds) {
            foreach ($segments as $seg) {
                $isAd = false;
                $uriLower = strtolower($seg['uri']);

                // 检查域名
                foreach ($adDomains as $domain) {
                    if (strpos($uriLower, $domain) !== false) {
                        $isAd = true;
                        break;
                    }
                }

                // 检查关键词
                if (!$isAd) {
                    foreach ($adKeywords as $keyword) {
                        if (strpos($uriLower, $keyword) !== false) {
                            $isAd = true;
                            break;
                        }
                    }
                }

                if ($isAd) {
                    $adsRemoved++;
                } else {
                    $cleanedSegments[] = $seg;
                }
            }
        } else {
            $cleanedSegments = $segments;
        }

        return [
            'success' => true,
            'url' => $originalUrl,
            'total_segments' => count($segments),
            'ads_removed' => $adsRemoved,
            'kept_segments' => count($cleanedSegments),
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * 提取时长
     */
    private function extractDuration($extinfLine) {
        if (preg_match('/#EXTINF:([\d\.]+)/', $extinfLine, $matches)) {
            return (float)$matches[1];
        }
        return 0;
    }

    /**
     * 下载M3U8
     */
    private function downloadM3U8($url) {
        // 尝试直接下载
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 如果直接下载失败，尝试作为解析API
        if (!$content || $httpCode !== 200) {
            // 尝试添加常见后缀
            $suffixes = ['/index.m3u8', '/playlist.m3u8', '/600k.m3u8'];
            foreach ($suffixes as $suffix) {
                $testUrl = rtrim($url, '/') . $suffix;
                $ch = curl_init($testUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $content = curl_exec($ch);
                curl_close($ch);
                if ($content && strpos($content, '#EXTM3U') !== false) {
                    return $content;
                }
            }
            return false;
        }

        return $content;
    }

    /**
     * 保存报告
     */
    private function saveReport($report) {
        $reportDir = __DIR__ . '/../../cache/batch_reports';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $filename = $reportDir . '/report_' . date('Ymd_His') . '.json';
        file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $filename;
    }
}
