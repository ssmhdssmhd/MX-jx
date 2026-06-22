<?php
/**
 * 万能规则2 - 正片特征数据库
 *
 * 核心原理：
 *   - 利用专业去广告工具批量解析同一视频
 *   - 多个解析源共同出现的片段/域名 = 正片特征
 *   - 某个解析源独有的片段/域名 = 广告特征
 *   - 通过统计多次解析结果建立稳定的特征库
 *
 * 数据库表结构：
 *   - feature_domains:     域名特征表（正片域名白名单/广告域名黑名单）
 *   - feature_paths:       路径模式特征表（正片路径/广告路径模式）
 *   - feature_segments:    片段级别特征（用于精确匹配）
 *   - feature_source_map:  解析源→片段映射关系（用于判断哪些片段在所有解析源中都出现）
 *   - feature_stats:       统计表（记录每次学习过程）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

class PatternFeatureDB
{
    private $db;
    private $dbPath;
    private $debug = false;

    public function __construct($dbPath = null, $debug = false)
    {
        $this->debug = $debug;
        if ($dbPath === null) {
            $configPath = __DIR__ . '/../config/noad.php';
            $config = file_exists($configPath) ? require $configPath : null;
            $this->dbPath = $config['sqlite_path'] ?? (__DIR__ . '/../cache/feature.db');
        } else {
            $this->dbPath = $dbPath;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->initTables();
        } catch (Exception $e) {
            error_log('[PatternFeatureDB] 初始化失败: ' . $e->getMessage());
            $this->db = null;
        }
    }

    /**
     * 初始化数据库表（低资源模式：极简索引）
     */
    private function initTables()
    {
        if ($this->db === null) return;

        $this->db->exec("CREATE TABLE IF NOT EXISTS feature_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            type TEXT NOT NULL DEFAULT 'unknown',  -- 'content' 正片 / 'ad' 广告 / 'unknown'
            hit_count INTEGER DEFAULT 0,           -- 被解析源命中的次数
            source_votes INTEGER DEFAULT 0,        -- 有多少个解析源投票这个域名
            first_seen INTEGER DEFAULT 0,
            last_seen INTEGER DEFAULT 0
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS feature_paths (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path_pattern TEXT UNIQUE NOT NULL,    -- 路径模式，如 /vod/playlist/xxx.m3u8
            type TEXT NOT NULL DEFAULT 'unknown',
            hit_count INTEGER DEFAULT 0,
            source_votes INTEGER DEFAULT 0,
            first_seen INTEGER DEFAULT 0,
            last_seen INTEGER DEFAULT 0
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS feature_segments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seg_hash TEXT UNIQUE NOT NULL,        -- 片段唯一标识（URL hash 或 大小+时长）
            type TEXT NOT NULL DEFAULT 'unknown',
            hit_count INTEGER DEFAULT 0,
            source_votes INTEGER DEFAULT 0,
            first_seen INTEGER DEFAULT 0,
            last_seen INTEGER DEFAULT 0
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS feature_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            video_url TEXT NOT NULL,
            parse_time INTEGER DEFAULT 0,
            sources_used INTEGER DEFAULT 0,
            segments_total INTEGER DEFAULT 0,
            segments_content INTEGER DEFAULT 0,   -- 共识正片片段数
            segments_ad INTEGER DEFAULT 0,        -- 识别广告片段数
            result TEXT,                          -- JSON 摘要
            created_at INTEGER DEFAULT 0
        )");

        // 极简索引（低资源模式）
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_domain_type ON feature_domains(type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_path_type ON feature_paths(type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_seg_type ON feature_segments(type)");
    }

    /**
     * 判断域名是否为正片域名
     */
    public function isContentDomain($domain)
    {
        if ($this->db === null || empty($domain)) return false;
        $stmt = $this->db->prepare("SELECT type, source_votes FROM feature_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        if ($row && $row['type'] === 'content' && $row['source_votes'] >= 2) {
            return true;
        }
        return false;
    }

    /**
     * 判断域名是否为广告域名
     */
    public function isAdDomain($domain)
    {
        if ($this->db === null || empty($domain)) return false;
        $stmt = $this->db->prepare("SELECT type, source_votes FROM feature_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        if ($row && $row['type'] === 'ad' && $row['source_votes'] >= 2) {
            return true;
        }
        return false;
    }

    /**
     * 学习：记录一次批量解析结果
     *
     * @param string $videoUrl 原始视频URL
     * @param array $sourceResults 格式: [ [source_name, [segment_urls...], [domains...], [paths...]] ... ]
     * @param int $minVotes 最小投票数（默认2，即至少2个解析源一致）
     * @return array 学习结果摘要
     */
    public function learn($videoUrl, $sourceResults, $minVotes = 2)
    {
        if ($this->db === null || empty($sourceResults)) {
            return ['success' => false, 'error' => '数据库不可用'];
        }

        $now = time();
        $totalSources = count($sourceResults);

        // --- 统计：每个域名/路径/片段出现在多少个解析源中 ---
        $domainVotes = [];   // domain => [source_count, total_occurrences]
        $pathVotes = [];     // path_pattern => ...
        $segmentVotes = [];  // seg_hash => ...

        foreach ($sourceResults as $sr) {
            list($sourceName, $segUrls, $domains, $paths) = $sr;
            foreach ($domains as $d) {
                if (!isset($domainVotes[$d])) {
                    $domainVotes[$d] = ['votes' => 0, 'hits' => 0];
                }
                $domainVotes[$d]['votes']++;  // 有多少个不同解析源命中
                $domainVotes[$d]['hits']++;   // 总出现次数
            }
            foreach ($paths as $p) {
                if (!isset($pathVotes[$p])) {
                    $pathVotes[$p] = ['votes' => 0, 'hits' => 0];
                }
                $pathVotes[$p]['votes']++;
                $pathVotes[$p]['hits']++;
            }
            foreach ($segUrls as $segUrl) {
                $segHash = md5($segUrl);
                if (!isset($segmentVotes[$segHash])) {
                    $segmentVotes[$segHash] = ['votes' => 0, 'hits' => 0];
                }
                $segmentVotes[$segHash]['votes']++;
                $segmentVotes[$segHash]['hits']++;
            }
        }

        // --- 更新数据库 ---
        $this->db->beginTransaction();
        try {
            $contentDomains = 0;
            $adDomains = 0;
            $unknownDomains = 0;

            // 更新域名表
            foreach ($domainVotes as $domain => $v) {
                $votes = $v['votes'];
                $hits = $v['hits'];

                // 判断类型：
                // - 出现在 >= minVotes 个解析源中 → content (正片特征)
                // - 只在 1 个解析源中出现 → unknown (等待更多数据)
                // - 从未被多数解析源共享但在多个视频中作为独有特征 → ad
                $type = 'unknown';
                if ($votes >= $minVotes) {
                    $type = 'content';
                    $contentDomains++;
                } elseif ($votes === 1 && $totalSources >= 2) {
                    $type = 'ad';  // 只在一个解析源中出现 → 疑似广告（该源特有的广告特征）
                    $adDomains++;
                } else {
                    $unknownDomains++;
                }

                $stmt = $this->db->prepare("SELECT id FROM feature_domains WHERE domain = ?");
                $stmt->execute([$domain]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $this->db->prepare("UPDATE feature_domains SET type = ?,
                        hit_count = hit_count + ?, source_votes = MAX(source_votes, ?), last_seen = ?
                        WHERE domain = ?")->execute([$type, $hits, $votes, $now, $domain]);
                } else {
                    $this->db->prepare("INSERT INTO feature_domains
                        (domain, type, hit_count, source_votes, first_seen, last_seen)
                        VALUES (?, ?, ?, ?, ?, ?)")->execute([$domain, $type, $hits, $votes, $now, $now]);
                }
            }

            // 更新路径表（同样逻辑）
            foreach ($pathVotes as $path => $v) {
                $votes = $v['votes'];
                $hits = $v['hits'];
                $type = ($votes >= $minVotes) ? 'content' : (($votes === 1 && $totalSources >= 2) ? 'ad' : 'unknown');

                $stmt = $this->db->prepare("SELECT id FROM feature_paths WHERE path_pattern = ?");
                $stmt->execute([$path]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $this->db->prepare("UPDATE feature_paths SET type = ?,
                        hit_count = hit_count + ?, source_votes = MAX(source_votes, ?), last_seen = ?
                        WHERE path_pattern = ?")->execute([$type, $hits, $votes, $now, $path]);
                } else {
                    $this->db->prepare("INSERT INTO feature_paths
                        (path_pattern, type, hit_count, source_votes, first_seen, last_seen)
                        VALUES (?, ?, ?, ?, ?, ?)")->execute([$path, $type, $hits, $votes, $now, $now]);
                }
            }

            // 片段级别（只记录内容/广告判断结果，不存完整 URL）
            foreach ($segmentVotes as $segHash => $v) {
                $votes = $v['votes'];
                $hits = $v['hits'];
                $type = ($votes >= $minVotes) ? 'content' : (($votes === 1 && $totalSources >= 2) ? 'ad' : 'unknown');

                $stmt = $this->db->prepare("SELECT id FROM feature_segments WHERE seg_hash = ?");
                $stmt->execute([$segHash]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $this->db->prepare("UPDATE feature_segments SET type = ?,
                        hit_count = hit_count + ?, source_votes = MAX(source_votes, ?), last_seen = ?
                        WHERE seg_hash = ?")->execute([$type, $hits, $votes, $now, $segHash]);
                } else {
                    $this->db->prepare("INSERT INTO feature_segments
                        (seg_hash, type, hit_count, source_votes, first_seen, last_seen)
                        VALUES (?, ?, ?, ?, ?, ?)")->execute([$segHash, $type, $hits, $votes, $now, $now]);
                }
            }

            // 记录学习统计
            $totalSegs = count($segmentVotes);
            $contentSegs = 0;
            $adSegs = 0;
            foreach ($segmentVotes as $v) {
                if ($v['votes'] >= $minVotes) $contentSegs++;
                elseif ($v['votes'] === 1) $adSegs++;
            }

            $summary = json_encode([
                'sources' => $totalSources,
                'domains' => ['content' => $contentDomains, 'ad' => $adDomains, 'unknown' => $unknownDomains],
                'segments' => ['content' => $contentSegs, 'ad' => $adSegs, 'total' => $totalSegs],
            ], JSON_UNESCAPED_UNICODE);

            $this->db->prepare("INSERT INTO feature_stats
                (video_url, parse_time, sources_used, segments_total, segments_content, segments_ad, result, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $videoUrl, 0, $totalSources, $totalSegs, $contentSegs, $adSegs, $summary, $now
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'sources' => $totalSources,
                'content_domains' => $contentDomains,
                'ad_domains' => $adDomains,
                'content_segments' => $contentSegs,
                'ad_segments' => $adSegs,
                'total_segments' => $totalSegs,
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[PatternFeatureDB::learn] ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 批量检查 URL 列表 → 返回每个 URL 的类型判断
     *
     * @param array $urls 片段 URL 列表
     * @return array [url => 'content' | 'ad' | 'unknown', ...]
     */
    public function classifyUrls($urls)
    {
        if ($this->db === null || empty($urls)) return [];

        $result = [];
        $checkedDomains = [];  // 缓存域名判断结果

        foreach ($urls as $url) {
            if (empty($url)) { $result[$url] = 'unknown'; continue; }

            $domain = $this->extractDomain($url);
            $path = $this->extractPathPattern($url);
            $segHash = md5($url);

            $verdict = 'unknown';
            $confidence = 0;

            // 1. 先查域名（最快）
            if (isset($checkedDomains[$domain])) {
                $domainInfo = $checkedDomains[$domain];
            } else {
                $stmt = $this->db->prepare("SELECT type, source_votes FROM feature_domains WHERE domain = ?");
                $stmt->execute([$domain]);
                $domainInfo = $stmt->fetch() ?: null;
                $checkedDomains[$domain] = $domainInfo;
            }

            if ($domainInfo && !empty($domainInfo['type'])) {
                if ($domainInfo['type'] === 'content' && $domainInfo['source_votes'] >= 2) {
                    $verdict = 'content';
                    $confidence = 80;
                } elseif ($domainInfo['type'] === 'ad' && $domainInfo['source_votes'] >= 1) {
                    $verdict = 'ad';
                    $confidence = 60;
                }
            }

            // 2. 再查路径（辅助判断）
            if ($verdict === 'unknown' || $confidence < 80) {
                $stmt = $this->db->prepare("SELECT type, source_votes FROM feature_paths WHERE path_pattern = ?");
                $stmt->execute([$path]);
                $pathInfo = $stmt->fetch();
                if ($pathInfo && !empty($pathInfo['type'])) {
                    if ($pathInfo['type'] === 'content' && $pathInfo['source_votes'] >= 2) {
                        $verdict = 'content';
                        $confidence = max($confidence, 70);
                    } elseif ($pathInfo['type'] === 'ad') {
                        $verdict = 'ad';
                        $confidence = max($confidence, 50);
                    }
                }
            }

            // 3. 最后查片段（最精确但最慢）
            if ($confidence < 80) {
                $stmt = $this->db->prepare("SELECT type, source_votes FROM feature_segments WHERE seg_hash = ?");
                $stmt->execute([$segHash]);
                $segInfo = $stmt->fetch();
                if ($segInfo && !empty($segInfo['type'])) {
                    if ($segInfo['type'] === 'content' && $segInfo['source_votes'] >= 2) {
                        $verdict = 'content';
                        $confidence = 90;
                    } elseif ($segInfo['type'] === 'ad') {
                        $verdict = 'ad';
                        $confidence = max($confidence, 70);
                    }
                }
            }

            $result[$url] = $verdict;
        }

        return $result;
    }

    /**
     * 获取正片域名列表（供外部快速检查）
     */
    public function getContentDomains()
    {
        if ($this->db === null) return [];
        $stmt = $this->db->query("SELECT domain FROM feature_domains WHERE type = 'content' AND source_votes >= 2");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    /**
     * 获取广告域名列表
     */
    public function getAdDomains()
    {
        if ($this->db === null) return [];
        $stmt = $this->db->query("SELECT domain FROM feature_domains WHERE type = 'ad'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    /**
     * 统计信息（供后台面板展示）
     */
    public function getStats()
    {
        if ($this->db === null) {
            return ['enabled' => false, 'error' => '数据库不可用'];
        }
        try {
            $contentDomains = (int)$this->db->query("SELECT COUNT(*) FROM feature_domains WHERE type = 'content'")->fetchColumn();
            $adDomains = (int)$this->db->query("SELECT COUNT(*) FROM feature_domains WHERE type = 'ad'")->fetchColumn();
            $totalDomains = (int)$this->db->query("SELECT COUNT(*) FROM feature_domains")->fetchColumn();
            $totalPaths = (int)$this->db->query("SELECT COUNT(*) FROM feature_paths")->fetchColumn();
            $totalSegments = (int)$this->db->query("SELECT COUNT(*) FROM feature_segments")->fetchColumn();
            $totalLearns = (int)$this->db->query("SELECT COUNT(*) FROM feature_stats")->fetchColumn();

            $dbSize = @filesize($this->dbPath);

            return [
                'enabled' => true,
                'content_domains' => $contentDomains,
                'ad_domains' => $adDomains,
                'total_domains' => $totalDomains,
                'total_paths' => $totalPaths,
                'total_segments' => $totalSegments,
                'total_learns' => $totalLearns,
                'db_size_kb' => $dbSize ? round($dbSize / 1024, 1) : 0,
            ];
        } catch (Exception $e) {
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 清理 N 天前的旧数据
     */
    public function cleanupOldData($days = 60)
    {
        if ($this->db === null) return 0;
        $threshold = time() - ($days * 86400);
        try {
            $this->db->beginTransaction();
            $c1 = (int)$this->db->exec("DELETE FROM feature_domains WHERE last_seen < $threshold");
            $c2 = (int)$this->db->exec("DELETE FROM feature_paths WHERE last_seen < $threshold");
            $c3 = (int)$this->db->exec("DELETE FROM feature_segments WHERE last_seen < $threshold");
            $c4 = (int)$this->db->exec("DELETE FROM feature_stats WHERE created_at < $threshold");
            $this->db->commit();
            return $c1 + $c2 + $c3 + $c4;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[PatternFeatureDB::cleanup] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 从 URL 提取域名
     */
    public function extractDomain($url)
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) return '';
        return strtolower($parts['host']);
    }

    /**
     * 从 URL 提取路径模式（简化到一级目录）
     * 例如: https://cdn.example.com/vod/2024/playlist/ch01.m3u8 → /vod/
     */
    public function extractPathPattern($url)
    {
        $parts = parse_url($url);
        if (empty($parts['path'])) return '';
        $path = $parts['path'];
        // 简化到第一级目录
        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) >= 2) {
            return '/' . $segments[0] . '/' . $segments[1] . '/';
        } elseif (count($segments) >= 1) {
            return '/' . $segments[0] . '/';
        }
        return $path;
    }

    /**
     * 手动标记域名（后台操作）
     */
    public function markDomain($domain, $type)
    {
        if ($this->db === null) return false;
        if (!in_array($type, ['content', 'ad', 'unknown'])) return false;
        try {
            $stmt = $this->db->prepare("SELECT id FROM feature_domains WHERE domain = ?");
            $stmt->execute([$domain]);
            $existing = $stmt->fetch();
            if ($existing) {
                $this->db->prepare("UPDATE feature_domains SET type = ?, source_votes = MAX(source_votes, 2), last_seen = ? WHERE domain = ?")
                    ->execute([$type, time(), $domain]);
            } else {
                $this->db->prepare("INSERT INTO feature_domains (domain, type, hit_count, source_votes, first_seen, last_seen) VALUES (?, ?, 0, 2, ?, ?)")
                    ->execute([$domain, $type, time(), time()]);
            }
            return true;
        } catch (Exception $e) {
            error_log('[PatternFeatureDB::markDomain] ' . $e->getMessage());
            return false;
        }
    }
}
