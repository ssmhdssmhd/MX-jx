<?php
/**
 * MD5 指纹数据库 - 万能规则1核心存储
 *
 * 核心思想：广告片段会在不同视频/不同集数中重复出现，其 MD5 指纹具有
 * 高频率重复的特征。正片内容则是独一无二的。通过统计 MD5 出现次数，
 * 自动识别并过滤广告片段。
 *
 * 数据库结构：
 *   ├─ fingerprints:  MD5 指纹统计表（核心）
 *   ├─ segments:      片段下载记录（用于数据追溯）
 *   ├─ blacklist_md5: 人工确认的广告 MD5（高优先级）
 *   └─ whitelist_md5: 人工确认的正片 MD5（永不删除）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

class MD5FingerprintDB
{
    /** @var PDO|null */
    private $db = null;

    /** @var string 数据库文件路径 */
    private $dbPath;

    /** @var bool 是否启用调试日志 */
    private $debug = false;

    /**
     * 构造函数
     * @param string $dbPath 数据库路径，默认为 cache/md5_fingerprints.db
     */
    public function __construct($dbPath = null)
    {
        if ($dbPath === null) {
            $dbPath = __DIR__ . '/../cache/md5_fingerprints.db';
        }
        $this->dbPath = $dbPath;

        if (!is_dir(dirname($dbPath))) {
            @mkdir(dirname($dbPath), 0755, true);
        }

        $this->init();
    }

    /**
     * 初始化数据库连接和表结构
     */
    private function init()
    {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_TIMEOUT, 30);

            // 优化 SQLite 性能（写入安全 + 快速读取）
            $this->db->exec('PRAGMA journal_mode = WAL');       // 读写分离，防止锁死
            $this->db->exec('PRAGMA synchronous = NORMAL');     // 性能与安全平衡
            $this->db->exec('PRAGMA temp_store = MEMORY');      // 临时表放内存
            $this->db->exec('PRAGMA cache_size = 2000');        // 2MB 页面缓存
            $this->db->exec('PRAGMA mmap_size = 268435456');    // 256MB 内存映射

            // 创建表结构（IF NOT EXISTS 防止重复创建报错）
            $this->db->exec("CREATE TABLE IF NOT EXISTS fingerprints (
                md5 TEXT PRIMARY KEY,
                count INTEGER DEFAULT 1,
                total_size INTEGER DEFAULT 0,
                avg_duration REAL DEFAULT 0,
                first_seen INTEGER,
                last_seen INTEGER,
                is_ad INTEGER DEFAULT 0,
                is_whitelist INTEGER DEFAULT 0
            )");

            $this->db->exec("CREATE TABLE IF NOT EXISTS segments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                video_url TEXT,
                segment_uri TEXT,
                md5 TEXT,
                size_bytes INTEGER,
                duration REAL,
                download_time_ms INTEGER,
                ip_used TEXT DEFAULT '',
                proxy_used TEXT DEFAULT '',
                timestamp INTEGER
            )");

            $this->db->exec("CREATE TABLE IF NOT EXISTS blacklist_md5 (
                md5 TEXT PRIMARY KEY,
                reason TEXT DEFAULT '自动识别',
                added_at INTEGER
            )");

            $this->db->exec("CREATE TABLE IF NOT EXISTS whitelist_md5 (
                md5 TEXT PRIMARY KEY,
                reason TEXT DEFAULT '人工确认',
                added_at INTEGER
            )");

            // 索引加速查询
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fp_count ON fingerprints(count DESC)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fp_ad ON fingerprints(is_ad)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_seg_md5 ON segments(md5)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_seg_time ON segments(timestamp DESC)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fp_lastseen ON fingerprints(last_seen DESC)");

        } catch (Exception $e) {
            // 数据库失败时记录日志但不抛出致命错误
            error_log('[MD5FingerprintDB] 初始化失败: ' . $e->getMessage());
            $this->db = null;
        }
    }

    /**
     * 检查数据库是否可用
     * @return bool
     */
    public function isReady()
    {
        return $this->db !== null;
    }

    /**
     * 记录/更新一个 MD5 指纹（每次下载片段后调用）
     * @param string $md5 片段的 MD5
     * @param int $sizeBytes 文件大小
     * @param float $duration 片段时长
     * @param string $videoUrl 所属视频地址（用于区分"不同视频相同片段 = 广告"）
     * @param string $segUri 片段 URI
     * @param int $downloadMs 下载耗时
     * @param string $ipUsed 使用的 IP
     * @param string $proxyUsed 使用的代理
     * @return array ['is_new' => bool, 'count' => int, 'is_ad' => bool]
     */
    public function record($md5, $sizeBytes, $duration, $videoUrl = '', $segUri = '', $downloadMs = 0, $ipUsed = '', $proxyUsed = '')
    {
        if (!$this->isReady() || $md5 === '') {
            return ['is_new' => false, 'count' => 0, 'is_ad' => false];
        }

        try {
            $this->db->beginTransaction();

            $now = time();
            $md5 = strtolower($md5);

            // 1. 查询现有记录
            $stmt = $this->db->prepare("SELECT md5, count, is_ad, is_whitelist FROM fingerprints WHERE md5 = ?");
            $stmt->execute([$md5]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $isNew = false;
            $count = 1;
            $isAd = false;

            if ($existing) {
                // 更新已存在的指纹
                $count = (int)$existing['count'] + 1;
                $this->db->prepare("UPDATE fingerprints SET
                    count = count + 1,
                    total_size = total_size + ?,
                    avg_duration = (avg_duration * (count - 1) + ?) / count,
                    last_seen = ?
                    WHERE md5 = ?
                ")->execute([$sizeBytes, $duration, $now, $md5]);
                $isAd = !empty($existing['is_ad']);
            } else {
                // 新增指纹
                $this->db->prepare("INSERT INTO fingerprints
                    (md5, count, total_size, avg_duration, first_seen, last_seen, is_ad, is_whitelist)
                    VALUES (?, 1, ?, ?, ?, ?, 0, 0)
                ")->execute([$md5, $sizeBytes, $duration, $now, $now]);
                $isNew = true;
            }

            // 2. 记录片段来源（最多保留每个视频的一条记录，避免数据库过大）
            if ($videoUrl !== '') {
                $segHash = md5($videoUrl . '|' . $segUri);
                $stmt = $this->db->prepare("SELECT id FROM segments WHERE md5 = ? AND segment_uri = ? LIMIT 1");
                $stmt->execute([$md5, $segUri]);
                if (!$stmt->fetch()) {
                    $this->db->prepare("INSERT INTO segments
                        (video_url, segment_uri, md5, size_bytes, duration, download_time_ms, ip_used, proxy_used, timestamp)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$videoUrl, $segUri, $md5, $sizeBytes, $duration, $downloadMs, $ipUsed, $proxyUsed, $now]);
                }
            }

            $this->db->commit();
            return ['is_new' => $isNew, 'count' => $count, 'is_ad' => $isAd];

        } catch (Exception $e) {
            if ($this->db && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Exception $_) {}
            }
            error_log('[MD5FingerprintDB] record失败: ' . $e->getMessage());
            return ['is_new' => false, 'count' => 0, 'is_ad' => false];
        }
    }

    /**
     * 批量查询多个 MD5 的状态（用于一次查询所有片段状态，减少 DB 往返）
     * @param array $md5List MD5 数组
     * @return array ['md5' => ['count' => int, 'is_ad' => bool, 'is_whitelist' => bool, 'in_blacklist' => bool]]
     */
    public function queryBatch($md5List)
    {
        if (!$this->isReady() || empty($md5List)) return [];

        $result = [];
        try {
            // 1. 查询指纹库
            $placeholders = implode(',', array_fill(0, count($md5List), '?'));
            $params = array_map('strtolower', $md5List);

            $stmt = $this->db->prepare("SELECT md5, count, is_ad, is_whitelist FROM fingerprints WHERE md5 IN ($placeholders)");
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['md5']] = [
                    'count' => (int)$row['count'],
                    'is_ad' => !empty($row['is_ad']),
                    'is_whitelist' => !empty($row['is_whitelist']),
                    'in_blacklist' => false,
                ];
            }

            // 2. 查询黑名单（高优先级）
            $stmt = $this->db->prepare("SELECT md5 FROM blacklist_md5 WHERE md5 IN ($placeholders)");
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($result[$row['md5']])) {
                    $result[$row['md5']] = ['count' => 0, 'is_ad' => false, 'is_whitelist' => false];
                }
                $result[$row['md5']]['in_blacklist'] = true;
            }

            // 3. 查询白名单（最高优先级，永久保留）
            $stmt = $this->db->prepare("SELECT md5 FROM whitelist_md5 WHERE md5 IN ($placeholders)");
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($result[$row['md5']])) {
                    $result[$row['md5']] = ['count' => 0, 'is_ad' => false, 'in_blacklist' => false];
                }
                $result[$row['md5']]['is_whitelist'] = true;
            }

        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] queryBatch失败: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * 标记某个 MD5 为广告（人工/自动）
     * @param string $md5
     * @param bool $isAd
     */
    public function markAsAd($md5, $isAd = true)
    {
        if (!$this->isReady() || $md5 === '') return;
        $md5 = strtolower($md5);
        try {
            $stmt = $this->db->prepare("INSERT INTO blacklist_md5 (md5, reason, added_at) VALUES (?, '自动识别', ?) ON CONFLICT(md5) DO NOTHING");
            $stmt->execute([$md5, time()]);

            $stmt = $this->db->prepare("UPDATE fingerprints SET is_ad = ? WHERE md5 = ?");
            $stmt->execute([$isAd ? 1 : 0, $md5]);
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] markAsAd失败: ' . $e->getMessage());
        }
    }

    /**
     * 添加到白名单（永不删除）
     */
    public function addToWhitelist($md5, $reason = '人工确认')
    {
        if (!$this->isReady() || $md5 === '') return;
        $md5 = strtolower($md5);
        try {
            $this->db->prepare("INSERT INTO whitelist_md5 (md5, reason, added_at) VALUES (?, ?, ?) ON CONFLICT(md5) DO NOTHING")
                     ->execute([$md5, $reason, time()]);
            $this->db->prepare("UPDATE fingerprints SET is_whitelist = 1, is_ad = 0 WHERE md5 = ?")
                     ->execute([$md5]);
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] addToWhitelist失败: ' . $e->getMessage());
        }
    }

    /**
     * 从白名单/黑名单移除
     */
    public function removeFromList($md5, $list = 'blacklist')
    {
        if (!$this->isReady() || $md5 === '') return;
        $md5 = strtolower($md5);
        try {
            $table = $list === 'whitelist' ? 'whitelist_md5' : 'blacklist_md5';
            $this->db->prepare("DELETE FROM $table WHERE md5 = ?")->execute([$md5]);
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] removeFromList失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取统计信息（供后台展示）
     * @return array
     */
    public function getStats()
    {
        if (!$this->isReady()) {
            return ['total' => 0, 'ad_count' => 0, 'whitelist_count' => 0, 'total_segments' => 0, 'today_segments' => 0];
        }
        try {
            $total = (int)$this->db->query("SELECT COUNT(*) FROM fingerprints")->fetchColumn();
            $adCount = (int)$this->db->query("SELECT COUNT(*) FROM blacklist_md5")->fetchColumn();
            $wlCount = (int)$this->db->query("SELECT COUNT(*) FROM whitelist_md5")->fetchColumn();
            $totalSeg = (int)$this->db->query("SELECT COUNT(*) FROM segments")->fetchColumn();
            $todayStart = mktime(0, 0, 0);
            $todaySeg = (int)$this->db->query("SELECT COUNT(*) FROM segments WHERE timestamp >= $todayStart")->fetchColumn();
            return [
                'total' => $total,
                'ad_count' => $adCount,
                'whitelist_count' => $wlCount,
                'total_segments' => $totalSeg,
                'today_segments' => $todaySeg,
            ];
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] getStats失败: ' . $e->getMessage());
            return ['total' => 0, 'ad_count' => 0, 'whitelist_count' => 0, 'total_segments' => 0, 'today_segments' => 0];
        }
    }

    /**
     * 获取高频率出现的 MD5 Top N（供人工确认）
     */
    public function getTopFrequent($limit = 20)
    {
        if (!$this->isReady()) return [];
        try {
            $stmt = $this->db->prepare("SELECT md5, count, total_size, avg_duration, last_seen, is_ad, is_whitelist
                FROM fingerprints WHERE is_whitelist = 0 ORDER BY count DESC LIMIT ?");
            $stmt->execute([(int)$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] getTopFrequent失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取黑名单列表
     */
    public function getBlacklist($limit = 50)
    {
        if (!$this->isReady()) return [];
        try {
            $stmt = $this->db->prepare("SELECT b.md5, b.reason, b.added_at, f.count, f.avg_duration
                FROM blacklist_md5 b LEFT JOIN fingerprints f ON b.md5 = f.md5
                ORDER BY b.added_at DESC LIMIT ?");
            $stmt->execute([(int)$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 获取白名单列表
     */
    public function getWhitelist($limit = 50)
    {
        if (!$this->isReady()) return [];
        try {
            $stmt = $this->db->prepare("SELECT w.md5, w.reason, w.added_at, f.count, f.avg_duration
                FROM whitelist_md5 w LEFT JOIN fingerprints f ON w.md5 = f.md5
                ORDER BY w.added_at DESC LIMIT ?");
            $stmt->execute([(int)$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 清理数据库（删除7天前的片段记录，控制大小）
     */
    public function cleanup($daysToKeep = 30)
    {
        if (!$this->isReady()) return 0;
        try {
            $cutoff = time() - ($daysToKeep * 86400);
            $deleted = (int)$this->db->exec("DELETE FROM segments WHERE timestamp < $cutoff");

            // 对老的指纹也降低计数（不是删除，只是防止历史错误累积）
            // 这里只是清理片段记录，指纹库保留
            return $deleted;
        } catch (Exception $e) {
            error_log('[MD5FingerprintDB] cleanup失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 获取数据库大小（字节）
     */
    public function getDbSize()
    {
        if (file_exists($this->dbPath)) {
            return (int)filesize($this->dbPath);
        }
        return 0;
    }
}
