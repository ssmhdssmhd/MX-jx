<?php
/**
 * 专业特征码提取工具 FeatureExtractorTool
 *
 * 从 M3U8 / URL 列表 / 文本 中提取稳定的"特征指纹"，包括：
 *   1. 片段 URL 的 MD5 指纹（逐片段）
 *   2. 域名特征（主机名 / 二级域名 / TLD / 计数）
 *   3. 路径模式（如 /vod/2024/xxx/ → 归一化后 hash）
 *   4. 片段时长分布（min/max/avg/直方图）
 *   5. 全局特征签名（整份内容的综合指纹，用于比对去重）
 *
 * 设计目标：
 *   - 提取出的特征可直接喂给 core/PatternFeatureDB.php 的 learn 方法
 *   - 为 M3U8/URL 做"结构指纹"，方便后续规则库精确命中
 *   - 纯 PHP 计算，内存线性消耗，低性能服务器可跑
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractTool', false)) {
    require_once __DIR__ . '/../core/AbstractTool.php';
}

class FeatureExtractorTool extends AbstractTool
{
    public $id = 'feature_extractor';
    public $group = 'feature_extractor';

    public function name() { return '特征码提取工具'; }
    public function description() { return '对 M3U8 / URL 列表 / 任意文本提取指纹：MD5 指纹、域名特征、路径模式、时长分布、全局特征签名。'; }
    public function version() { return '1.0.0'; }

    public function getParamSchema()
    {
        return [
            'input' => [
                'type'     => 'string',
                'required' => true,
                'label'    => '输入内容',
                'hint'     => '可粘贴 M3U8 文本、URL 列表（一行一个）、或任意文本',
            ],
            'mode' => [
                'type'     => 'string',
                'required' => false,
                'default'  => 'auto',
                'label'    => '解析模式',
                'hint'     => 'auto=自动识别 / m3u8=按片段解析 / urls=按行解析URL / text=按词与子串分析',
            ],
            'path_segments' => [
                'type'     => 'int',
                'required' => false,
                'default'  => 3,
                'label'    => '路径模式保留层级',
                'hint'     => '对 URL 路径做归一化时保留前 N 级目录（默认 3，1~5）',
            ],
            'max_items' => [
                'type'     => 'int',
                'required' => false,
                'default'  => 2000,
                'label'    => '最大处理条目数',
                'hint'     => '防止过大 M3U8 占用内存（默认 2000）',
            ],
            'include_md5' => [
                'type'     => 'bool',
                'required' => false,
                'default'  => true,
                'label'    => '输出 MD5 指纹',
                'hint'     => '是否生成每个 URL 的 MD5（默认开）',
            ],
            'include_domain_tree' => [
                'type'     => 'bool',
                'required' => false,
                'default'  => true,
                'label'    => '输出域名树',
                'hint'     => '是否统计主机名/二级域名/TLD（默认开）',
            ],
            'include_signature' => [
                'type'     => 'bool',
                'required' => false,
                'default'  => true,
                'label'    => '输出全局签名',
                'hint'     => '是否输出整体内容 hash、top 特征等签名',
            ],
        ];
    }

    public function run($params = [])
    {
        $input = (string)($params['input'] ?? '');
        $mode  = (string)($params['mode'] ?? 'auto');
        $pathSegs = (int)($params['path_segments'] ?? 3);
        if ($pathSegs < 1) $pathSegs = 1;
        if ($pathSegs > 5) $pathSegs = 5;
        $maxItems = (int)($params['max_items'] ?? 2000);
        if ($maxItems < 1) $maxItems = 100;

        $includeMd5 = (bool)($params['include_md5'] ?? true);
        $includeDomainTree = (bool)($params['include_domain_tree'] ?? true);
        $includeSignature = (bool)($params['include_signature'] ?? true);

        if ($input === '') {
            return ['success' => false, 'message' => '输入内容为空', 'data' => null];
        }

        // 自动模式识别
        if ($mode === 'auto') {
            if (strpos($input, '#EXTM3U') !== false || strpos($input, '#EXTINF') !== false) {
                $mode = 'm3u8';
            } elseif (preg_match('/^https?:\/\//m', $input)) {
                $mode = 'urls';
            } else {
                $mode = 'text';
            }
        }

        switch ($mode) {
            case 'm3u8':
                $data = $this->extractFromM3u8($input, $pathSegs, $maxItems, $includeMd5, $includeDomainTree, $includeSignature);
                break;
            case 'urls':
                $data = $this->extractFromUrls($input, $pathSegs, $maxItems, $includeMd5, $includeDomainTree, $includeSignature);
                break;
            case 'text':
            default:
                $data = $this->extractFromText($input);
                $mode = 'text';
        }

        return [
            'success' => true,
            'message' => "特征码提取完成（模式：{$mode}）",
            'data'    => $data,
        ];
    }

    // ===== M3U8 模式解析 =====
    private function extractFromM3u8($content, $pathSegs, $maxItems, $includeMd5, $includeDomainTree, $includeSignature)
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $items = [];
        $durations = [];
        $domainCounter = [];
        $pathPatternCounter = [];
        $allUrls = '';
        $rawMd5List = [];

        $n = count($lines);
        $i = 0;
        $itemsCount = 0;
        $totalSegments = 0;

        while ($i < $n && $itemsCount < $maxItems) {
            $line = trim($lines[$i]);
            if ($line === '') { $i++; continue; }
            if (strpos($line, '#EXTINF') === 0) {
                $totalSegments++;
                $item = ['line_no' => $i + 1, 'extinf' => $line, 'uri' => '', 'duration' => 0];
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                    $item['duration'] = (float)$m[1];
                    $durations[] = $item['duration'];
                }
                $i++;
                // 跳过 EXT-X-KEY / BYTERANGE / DISCONTINUITY
                while ($i < $n && in_array(substr(trim($lines[$i]), 0, 12), ['#EXT-X-KEY', '#EXT-X-BYT', '#EXT-X-DIS'], true)) {
                    $i++;
                }
                if ($i < $n) {
                    $item['uri'] = trim($lines[$i]);
                    $i++;
                }
                $item = $this->enrichUrl($item, $item['uri'], $pathSegs, $includeMd5, $domainCounter, $pathPatternCounter);
                $items[] = $item;
                $itemsCount++;
                $allUrls .= $item['uri'] . "\n";
                if ($includeMd5) $rawMd5List[] = $item['md5'] ?? md5($item['uri']);
                continue;
            }
            // 裸 URI 行（非#开头）
            if (strpos($line, '#') !== 0) {
                $totalSegments++;
                $item = ['line_no' => $i + 1, 'extinf' => '', 'uri' => $line, 'duration' => 0];
                $item = $this->enrichUrl($item, $line, $pathSegs, $includeMd5, $domainCounter, $pathPatternCounter);
                $items[] = $item;
                $itemsCount++;
                $allUrls .= $line . "\n";
                if ($includeMd5) $rawMd5List[] = $item['md5'] ?? md5($line);
                $i++;
                continue;
            }
            $i++;
        }

        $data = [
            'mode'            => 'm3u8',
            'total_segments'  => $totalSegments,
            'processed_items' => count($items),
            'items'           => $items,
            'duration_stats'  => $this->durationStats($durations),
        ];

        if ($includeDomainTree) {
            arsort($domainCounter);
            $data['domain_top'] = array_slice($domainCounter, 0, 50, true);
            $data['domain_count'] = count($domainCounter);

            $secondLevel = [];
            $tldCounter   = [];
            foreach (array_keys($domainCounter) as $host) {
                $parts = $this->splitDomain($host);
                if (!empty($parts['second_level'])) $secondLevel[$parts['second_level']] = ($secondLevel[$parts['second_level']] ?? 0) + $domainCounter[$host];
                if (!empty($parts['tld']))         $tldCounter[$parts['tld']] = ($tldCounter[$parts['tld']] ?? 0) + $domainCounter[$host];
            }
            arsort($secondLevel);
            arsort($tldCounter);
            $data['second_level_domain_top'] = array_slice($secondLevel, 0, 30, true);
            $data['tld_top']                 = array_slice($tldCounter, 0, 20, true);
        }

        if (!empty($pathPatternCounter)) {
            arsort($pathPatternCounter);
            $data['path_pattern_top'] = array_slice($pathPatternCounter, 0, 50, true);
        }

        if ($includeSignature) {
            $data['signature'] = [
                'content_md5'   => md5($content),
                'content_sha1'  => sha1($content),
                'url_list_md5'  => md5($allUrls),
                'urls_count'    => count($items),
                'top_domain'    => empty($domainCounter) ? '' : (string)array_keys($domainCounter)[0],
                'top_path_pattern' => empty($pathPatternCounter) ? '' : (string)array_keys($pathPatternCounter)[0],
            ];
        }

        return $data;
    }

    // ===== URL 列表模式解析 =====
    private function extractFromUrls($content, $pathSegs, $maxItems, $includeMd5, $includeDomainTree, $includeSignature)
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $items = [];
        $domainCounter = [];
        $pathPatternCounter = [];
        $allUrls = '';
        $itemsCount = 0;
        $totalUrls = 0;

        foreach ($lines as $idx => $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            if (strpos($line, '#') === 0) continue;
            $totalUrls++;
            if ($itemsCount >= $maxItems) continue;
            $item = ['line_no' => $idx + 1, 'uri' => $line];
            $item = $this->enrichUrl($item, $line, $pathSegs, $includeMd5, $domainCounter, $pathPatternCounter);
            $items[] = $item;
            $itemsCount++;
            $allUrls .= $line . "\n";
        }

        $data = [
            'mode'            => 'urls',
            'total_urls'      => $totalUrls,
            'processed_items' => count($items),
            'items'           => $items,
        ];

        if ($includeDomainTree) {
            arsort($domainCounter);
            $data['domain_top'] = array_slice($domainCounter, 0, 50, true);
            $secondLevel = [];
            $tldCounter   = [];
            foreach (array_keys($domainCounter) as $host) {
                $parts = $this->splitDomain($host);
                if (!empty($parts['second_level'])) $secondLevel[$parts['second_level']] = ($secondLevel[$parts['second_level']] ?? 0) + $domainCounter[$host];
                if (!empty($parts['tld']))         $tldCounter[$parts['tld']] = ($tldCounter[$parts['tld']] ?? 0) + $domainCounter[$host];
            }
            arsort($secondLevel);
            arsort($tldCounter);
            $data['second_level_domain_top'] = array_slice($secondLevel, 0, 30, true);
            $data['tld_top']                 = array_slice($tldCounter, 0, 20, true);
        }

        if (!empty($pathPatternCounter)) {
            arsort($pathPatternCounter);
            $data['path_pattern_top'] = array_slice($pathPatternCounter, 0, 50, true);
        }

        if ($includeSignature) {
            $data['signature'] = [
                'content_md5'  => md5($content),
                'content_sha1' => sha1($content),
                'url_list_md5' => md5($allUrls),
                'urls_count'   => count($items),
                'top_domain'   => empty($domainCounter) ? '' : (string)array_keys($domainCounter)[0],
                'top_path_pattern' => empty($pathPatternCounter) ? '' : (string)array_keys($pathPatternCounter)[0],
            ];
        }

        return $data;
    }

    // ===== 文本模式：词频 + n-gram =====
    private function extractFromText($content)
    {
        $tokens = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $tokenCount = [];
        foreach ($tokens as $t) {
            $tokenCount[$t] = ($tokenCount[$t] ?? 0) + 1;
        }
        arsort($tokenCount);

        // 简单 bigram（连续两个 token）
        $bigrams = [];
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $bg = $tokens[$i] . ' ' . $tokens[$i + 1];
            $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1;
        }
        arsort($bigrams);

        // 字符级 4-gram hash（用于内容指纹）
        $grams4 = [];
        $len = mb_strlen($content);
        for ($i = 0; $i < $len - 3; $i++) {
            $g = mb_substr($content, $i, 4);
            $grams4[$g] = ($grams4[$g] ?? 0) + 1;
        }
        arsort($grams4);

        return [
            'mode'           => 'text',
            'total_tokens'   => count($tokens),
            'unique_tokens'  => count($tokenCount),
            'char_length'    => $len,
            'top_tokens'     => array_slice($tokenCount, 0, 50, true),
            'top_bigrams'    => array_slice($bigrams, 0, 30, true),
            'top_char_grams' => array_slice($grams4, 0, 50, true),
            'signature'      => [
                'content_md5'  => md5($content),
                'content_sha1' => sha1($content),
            ],
        ];
    }

    // ===== URL 特征扩展 =====
    private function enrichUrl($item, $uri, $pathSegs, $includeMd5, &$domainCounter, &$pathPatternCounter)
    {
        $parsed = parse_url($uri);
        if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
            $host = strtolower($parsed['host']);
            $item['host'] = $host;
            $item['scheme'] = $parsed['scheme'];
            $item['path'] = $parsed['path'] ?? '';
            if (isset($parsed['query']))  $item['query'] = $parsed['query'];
            if (isset($parsed['fragment'])) $item['fragment'] = $parsed['fragment'];

            // 域名树
            $parts = $this->splitDomain($host);
            $item['domain'] = $parts;

            if (!isset($domainCounter[$host])) $domainCounter[$host] = 0;
            $domainCounter[$host]++;

            // 路径模式归一化
            $pathPattern = $this->normalizePathPattern($parsed['path'] ?? '', $pathSegs);
            $item['path_pattern'] = $pathPattern;
            if ($pathPattern !== '') {
                if (!isset($pathPatternCounter[$pathPattern])) $pathPatternCounter[$pathPattern] = 0;
                $pathPatternCounter[$pathPattern]++;
            }

            if ($includeMd5) {
                $item['md5']       = md5($uri);
                $item['md5_path']  = md5(($parsed['scheme'] ?? '') . '://' . $host . ($parsed['path'] ?? ''));
                $item['md5_host']  = md5($host);
            }
        } else {
            $item['host'] = '';
            $item['scheme'] = '';
            $item['path'] = '';
            if ($includeMd5) $item['md5'] = md5($uri);
        }
        return $item;
    }

    // 域名拆分：host = a.b.c.example.com → tld=com, sld=example.com, registerable=example.com
    private function splitDomain($host)
    {
        $host = strtolower(trim($host, '.'));
        if ($host === '') return ['tld' => '', 'second_level' => '', 'registerable' => '', 'sub' => ''];
        // IPv4
        if (preg_match('/^[\d\.]+$/', $host)) return ['tld' => 'ipv4', 'second_level' => $host, 'registerable' => $host, 'sub' => ''];
        // IPv6
        if (strpos($host, ':') !== false) return ['tld' => 'ipv6', 'second_level' => $host, 'registerable' => $host, 'sub' => ''];

        $parts = explode('.', $host);
        $count = count($parts);
        if ($count === 1) {
            return ['tld' => $host, 'second_level' => $host, 'registerable' => $host, 'sub' => ''];
        }
        $tld = $parts[$count - 1];
        $secondLevel = $parts[$count - 2] . '.' . $tld;
        $sub = ($count >= 3) ? implode('.', array_slice($parts, 0, $count - 2)) : '';
        return [
            'tld'           => $tld,
            'second_level'  => $secondLevel,
            'registerable'  => $secondLevel,
            'sub'           => $sub,
        ];
    }

    private function normalizePathPattern($path, $segments)
    {
        $path = trim($path, '/');
        if ($path === '') return '';
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts, 'strlen'));
        $head = array_slice($parts, 0, $segments);
        return '/' . implode('/', $head) . '/';
    }

    private function durationStats($durations)
    {
        if (empty($durations)) {
            return ['count' => 0, 'min' => 0, 'max' => 0, 'avg' => 0, 'median' => 0, 'sum' => 0];
        }
        sort($durations, SORT_NUMERIC);
        $sum = array_sum($durations);
        $c = count($durations);
        $mid = (int)floor($c / 2);
        $median = ($c % 2 === 0)
            ? ($durations[$mid - 1] + $durations[$mid]) / 2
            : $durations[$mid];
        return [
            'count'  => $c,
            'min'    => round($durations[0], 3),
            'max'    => round(end($durations), 3),
            'avg'    => round($sum / $c, 3),
            'median' => round($median, 3),
            'sum'    => round($sum, 3),
        ];
    }
}
