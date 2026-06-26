<?php
/**
 * SQLite 轻量级数据库抽象层 - Noad 去广告系统
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version v5.1.1
 *
 * 特性:
 *  - 零配置部署，PHP 8.0 ~ 8.5 原生 PDO 支持
 *  - 自动建表、自动迁移
 *  - 资源站点、访问统计、广告规则库 三大核心表
 */

class Database {
    private $pdo;
    private $dbFile;
    private static $instances = array();

    /**
     * 获取单例（按数据库路径区分）
     */
    public static function getInstance($dbFile = null) {
        if ($dbFile === null) {
            $config = require __DIR__ . '/../config/noad.php';
            $dbFile = $config['sqlite_path'];
        }
        $key = md5($dbFile);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($dbFile);
        }
        return self::$instances[$key];
    }

    /**
     * 构造函数：初始化数据库，自动建表
     */
    public function __construct($dbFile) {
        $this->dbFile = $dbFile;
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $isNew = !file_exists($dbFile);
        $dsn = 'sqlite:' . $dbFile;

        try {
            $this->pdo = new PDO($dsn, null, null, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
            $this->pdo->exec("PRAGMA journal_mode = WAL;");
            $this->pdo->exec("PRAGMA synchronous = NORMAL;");
            $this->pdo->exec("PRAGMA foreign_keys = ON;");

            if ($isNew || !$this->tableExists('noad_sources')) {
                $this->createTables();
                $this->seedDefaultData();
            }
        } catch (Exception $e) {
            error_log('[NoadDB] 初始化失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取原始 PDO 对象
     */
    public function getPdo() {
        return $this->pdo;
    }

    /**
     * 检查表是否存在
     */
    private function tableExists($table) {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute(array($table));
        return (bool)$stmt->fetch();
    }

    /**
     * 创建所有表
     */
    private function createTables() {
        // 表1: 去广告解析源（noad_sources）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            type_id INTEGER DEFAULT 1,
            timeout INTEGER DEFAULT 8,
            enabled INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            match_rules TEXT DEFAULT '',
            remark TEXT DEFAULT '',
            created_at INTEGER DEFAULT 0,
            updated_at INTEGER DEFAULT 0
        );");

        // 表2: 访问统计（noad_stats）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stat_date TEXT NOT NULL,
            stat_hour INTEGER DEFAULT 0,
            total_requests INTEGER DEFAULT 0,
            ad_removed_count INTEGER DEFAULT 0,
            cache_hit_count INTEGER DEFAULT 0,
            source_used TEXT DEFAULT '',
            avg_response_time REAL DEFAULT 0,
            UNIQUE(stat_date, stat_hour)
        );");

        // 表3: 广告规则库（noad_ad_rules）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_ad_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            keyword TEXT NOT NULL,
            rule_type TEXT DEFAULT 'keyword',
            enabled INTEGER DEFAULT 1,
            hit_count INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT 0
        );");

        // 表4: 访问日志（noad_access_log）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_access_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            access_time INTEGER DEFAULT 0,
            ip TEXT DEFAULT '',
            source_id INTEGER DEFAULT 0,
            source_name TEXT DEFAULT '',
            request_url TEXT DEFAULT '',
            original_url TEXT DEFAULT '',
            video_type TEXT DEFAULT '',
            ad_segments_removed INTEGER DEFAULT 0,
            total_segments INTEGER DEFAULT 0,
            response_time REAL DEFAULT 0,
            is_from_cache INTEGER DEFAULT 0,
            status TEXT DEFAULT 'ok'
        );");

        // 表5: 资源站点（关联站点，用于 M3U8 解析下拉选择）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            short_code TEXT DEFAULT '',
            base_url TEXT DEFAULT '',
            match_pattern TEXT DEFAULT '',
            algorithms TEXT DEFAULT '',
            enabled INTEGER DEFAULT 1,
            parse_count INTEGER DEFAULT 0,
            remark TEXT DEFAULT '',
            created_at INTEGER DEFAULT 0,
            updated_at INTEGER DEFAULT 0
        );");
        // 兼容旧数据库: 若无 algorithms 列则添加
        try {
            $this->pdo->exec("ALTER TABLE noad_sites ADD COLUMN algorithms TEXT DEFAULT ''");
        } catch (Exception $e) { /* 已存在则忽略 */ }

        // 表6: M3U8 解析日志（记录手动解析的历史）
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS noad_parse_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parse_time INTEGER DEFAULT 0,
            site_id INTEGER DEFAULT 0,
            site_name TEXT DEFAULT '',
            input_url TEXT DEFAULT '',
            total_segments INTEGER DEFAULT 0,
            ad_segments INTEGER DEFAULT 0,
            keep_segments INTEGER DEFAULT 0,
            total_duration REAL DEFAULT 0,
            ad_duration REAL DEFAULT 0,
            ip TEXT DEFAULT ''
        );");

        // 索引
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_stats_date   ON noad_stats(stat_date);");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sources_type  ON noad_sources(type_id);");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_log_time      ON noad_access_log(access_time DESC);");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_parse_time    ON noad_parse_log(parse_time DESC);");
    }

    /**
     * 初始化默认数据
     */
    private function seedDefaultData() {
        $config = require __DIR__ . '/../config/noad.php';
        $now = time();

        // 默认解析源
        foreach ($config['default_sources'] as $source) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO noad_sources(name,url,type_id,timeout,enabled,sort_order,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute(array(
                $source['name'], $source['url'], $source['type'],
                $source['timeout'], 1, 0, $now, $now
            ));
        }

        // 默认广告规则关键词
        foreach ($config['ad_keywords'] as $idx => $kw) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO noad_ad_rules(keyword,rule_type,enabled,hit_count,created_at)
                 VALUES (?,?,?,?,?)"
            );
            $stmt->execute(array($kw, 'keyword', 1, 0, $now));
        }

        // 默认资源站点（供 M3U8 解析页的下拉使用）
        $defaultSites = array(
            array('name' => '优质资源',      'short_code' => 'yzzy',   'remark' => '通用优质资源聚合'),
            array('name' => '官方资源站',    'short_code' => 'gfzy',   'remark' => '官方高清资源'),
            array('name' => '第三方解析',    'short_code' => 'dsf',    'remark' => '第三方视频解析源'),
        );
        foreach ($defaultSites as $s) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO noad_sites(name,short_code,base_url,match_pattern,enabled,
                 parse_count,remark,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute(array($s['name'], $s['short_code'], '', '', 1, 0, $s['remark'], $now, $now));
        }
    }

    // ========== 资源源 CRUD ==========

    public function getSources($typeId = 0, $onlyEnabled = true) {
        $sql = "SELECT * FROM noad_sources";
        $where = array();
        $bind = array();
        if ($onlyEnabled) { $where[] = "enabled=1"; }
        if ($typeId > 0) { $where[] = "type_id=?"; $bind[] = $typeId; }
        if (count($where)) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }

    public function getSourceById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM noad_sources WHERE id=?");
        $stmt->execute(array($id));
        return $stmt->fetch();
    }

    public function addSource($data) {
        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO noad_sources(name,url,type_id,timeout,enabled,sort_order,match_rules,remark,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute(array(
            $data['name'], $data['url'],
            (int)($data['type_id'] ?? 1),
            (int)($data['timeout'] ?? 8),
            (int)($data['enabled'] ?? 1),
            (int)($data['sort_order'] ?? 0),
            $data['match_rules'] ?? '',
            $data['remark'] ?? '',
            $now, $now
        ));
        return $this->pdo->lastInsertId();
    }

    public function updateSource($id, $data) {
        $now = time();
        $stmt = $this->pdo->prepare(
            "UPDATE noad_sources SET name=?, url=?, type_id=?, timeout=?, enabled=?,
             sort_order=?, match_rules=?, remark=?, updated_at=? WHERE id=?"
        );
        return $stmt->execute(array(
            $data['name'], $data['url'],
            (int)($data['type_id'] ?? 1),
            (int)($data['timeout'] ?? 8),
            (int)($data['enabled'] ?? 1),
            (int)($data['sort_order'] ?? 0),
            $data['match_rules'] ?? '',
            $data['remark'] ?? '',
            $now, $id
        ));
    }

    public function deleteSource($id) {
        $stmt = $this->pdo->prepare("DELETE FROM noad_sources WHERE id=?");
        return $stmt->execute(array($id));
    }

    public function toggleSource($id) {
        $stmt = $this->pdo->prepare("UPDATE noad_sources SET enabled = 1 - enabled WHERE id=?");
        return $stmt->execute(array($id));
    }

    // ========== 统计 CRUD ==========

    public function recordRequest($sourceId, $sourceName, $requestUrl, $originalUrl,
                                   $videoType, $adRemoved, $totalSegs, $respTime,
                                   $fromCache, $status = 'ok') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $now = time();
        $date = date('Y-m-d', $now);
        $hour = (int)date('H', $now);

        // 写入日志
        $stmt = $this->pdo->prepare(
            "INSERT INTO noad_access_log(access_time,ip,source_id,source_name,request_url,
             original_url,video_type,ad_segments_removed,total_segments,response_time,
             is_from_cache,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute(array(
            $now, $ip, $sourceId, $sourceName, $requestUrl, $originalUrl,
            $videoType, $adRemoved, $totalSegs, $respTime,
            (int)$fromCache, $status
        ));

        // 写入/更新小时统计
        $stmt = $this->pdo->prepare(
            "SELECT id FROM noad_stats WHERE stat_date=? AND stat_hour=?"
        );
        $stmt->execute(array($date, $hour));
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->pdo->prepare(
                "UPDATE noad_stats SET total_requests=total_requests+1,
                 ad_removed_count=ad_removed_count+?, cache_hit_count=cache_hit_count+?,
                 avg_response_time=(avg_response_time + ?)/2.0 WHERE id=?"
            );
            $stmt->execute(array((int)$adRemoved, (int)$fromCache, (float)$respTime, $existing['id']));
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO noad_stats(stat_date,stat_hour,total_requests,ad_removed_count,
                 cache_hit_count,avg_response_time) VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute(array($date, $hour, 1, (int)$adRemoved, (int)$fromCache, (float)$respTime));
        }
    }

    /**
     * 获取总览统计数据（仪表盘）
     */
    public function getOverviewStats() {
        $today = date('Y-m-d');
        $result = array(
            'total_requests'      => (int)$this->pdo->query(
                "SELECT COALESCE(SUM(total_requests),0) FROM noad_stats")->fetchColumn(),
            'today_requests'      => (int)$this->pdo->query(
                "SELECT COALESCE(SUM(total_requests),0) FROM noad_stats WHERE stat_date='$today'")->fetchColumn(),
            'total_ad_removed'    => (int)$this->pdo->query(
                "SELECT COALESCE(SUM(ad_removed_count),0) FROM noad_stats")->fetchColumn(),
            'total_cache_hits'    => (int)$this->pdo->query(
                "SELECT COALESCE(SUM(cache_hit_count),0) FROM noad_stats")->fetchColumn(),
            'source_count'        => (int)$this->pdo->query(
                "SELECT COUNT(*) FROM noad_sources WHERE enabled=1")->fetchColumn(),
            'avg_response_time'   => round((float)$this->pdo->query(
                "SELECT COALESCE(AVG(avg_response_time),0) FROM noad_stats")->fetchColumn(), 2),
        );
        // 缓存命中率
        if ($result['total_requests'] > 0) {
            $result['cache_hit_rate'] = round($result['total_cache_hits'] / $result['total_requests'] * 100, 2);
        } else {
            $result['cache_hit_rate'] = 0;
        }
        // 广告清理率
        if ($result['total_requests'] > 0) {
            $result['ad_rate'] = round($result['total_ad_removed'] / $result['total_requests'] * 100, 2);
        } else {
            $result['ad_rate'] = 0;
        }
        return $result;
    }

    /**
     * 获取近 7 天按日统计
     */
    public function getDailyStats($days = 7) {
        $dates = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-$i days"));
        }
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $sql = "SELECT stat_date,
                       SUM(total_requests) as total_requests,
                       SUM(ad_removed_count) as ad_removed_count,
                       SUM(cache_hit_count) as cache_hit_count
                FROM noad_stats WHERE stat_date IN ($placeholders)
                GROUP BY stat_date ORDER BY stat_date ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($dates);
        $rows = $stmt->fetchAll();

        $map = array();
        foreach ($rows as $r) $map[$r['stat_date']] = $r;

        $out = array();
        foreach ($dates as $d) {
            $out[] = $map[$d] ?? array(
                'stat_date' => $d,
                'total_requests' => 0,
                'ad_removed_count' => 0,
                'cache_hit_count' => 0,
            );
        }
        return $out;
    }

    /**
     * 获取热门解析源统计
     */
    public function getTopSources($limit = 10) {
        $stmt = $this->pdo->prepare(
            "SELECT source_name, COUNT(*) as use_count
             FROM noad_access_log
             GROUP BY source_name ORDER BY use_count DESC LIMIT ?"
        );
        $stmt->execute(array($limit));
        return $stmt->fetchAll();
    }

    /**
     * 获取最近的访问日志
     */
    public function getRecentLogs($limit = 30) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM noad_access_log ORDER BY access_time DESC LIMIT ?"
        );
        $stmt->execute(array($limit));
        return $stmt->fetchAll();
    }

    // ========== 广告规则 CRUD ==========

    public function getAdRules($onlyEnabled = false) {
        $sql = "SELECT * FROM noad_ad_rules";
        if ($onlyEnabled) $sql .= " WHERE enabled=1";
        $sql .= " ORDER BY hit_count DESC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addAdRule($keyword, $type = 'keyword') {
        $stmt = $this->pdo->prepare(
            "INSERT INTO noad_ad_rules(keyword,rule_type,enabled,hit_count,created_at)
             VALUES (?,?,?,?,?)"
        );
        $stmt->execute(array($keyword, $type, 1, 0, time()));
        return $this->pdo->lastInsertId();
    }

    public function deleteAdRule($id) {
        $stmt = $this->pdo->prepare("DELETE FROM noad_ad_rules WHERE id=?");
        return $stmt->execute(array($id));
    }

    public function toggleAdRule($id) {
        $stmt = $this->pdo->prepare("UPDATE noad_ad_rules SET enabled = 1 - enabled WHERE id=?");
        return $stmt->execute(array($id));
    }

    public function incrementRuleHit($ids) {
        if (empty($ids)) return;
        foreach ($ids as $id) {
            $stmt = $this->pdo->prepare(
                "UPDATE noad_ad_rules SET hit_count = hit_count + 1 WHERE id=?"
            );
            $stmt->execute(array($id));
        }
    }

    // ========== 清理/重置 ==========

    public function clearAccessLog() {
        $this->pdo->exec("DELETE FROM noad_access_log");
        $this->pdo->exec("DELETE FROM noad_stats");
        $this->pdo->exec("VACUUM");
        return true;
    }

    // ========== 资源站点 CRUD ==========

    public function getSites($onlyEnabled = false) {
        $sql = "SELECT * FROM noad_sites";
        if ($onlyEnabled) $sql .= " WHERE enabled=1";
        $sql .= " ORDER BY parse_count DESC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSiteById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM noad_sites WHERE id=?");
        $stmt->execute(array($id));
        return $stmt->fetch();
    }

    public function addSite($data) {
        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO noad_sites(name,short_code,base_url,match_pattern,algorithms,enabled,
             parse_count,remark,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute(array(
            trim($data['name'] ?? ''),
            trim($data['short_code'] ?? ''),
            trim($data['base_url'] ?? ''),
            trim($data['match_pattern'] ?? ''),
            trim($data['algorithms'] ?? ''),
            (int)($data['enabled'] ?? 1),
            0,
            trim($data['remark'] ?? ''),
            $now, $now
        ));
        return $this->pdo->lastInsertId();
    }

    public function updateSite($id, $data) {
        $now = time();
        $stmt = $this->pdo->prepare(
            "UPDATE noad_sites SET name=?, short_code=?, base_url=?, match_pattern=?, algorithms=?,
             enabled=?, remark=?, updated_at=? WHERE id=?"
        );
        return $stmt->execute(array(
            trim($data['name'] ?? ''),
            trim($data['short_code'] ?? ''),
            trim($data['base_url'] ?? ''),
            trim($data['match_pattern'] ?? ''),
            trim($data['algorithms'] ?? ''),
            (int)($data['enabled'] ?? 1),
            trim($data['remark'] ?? ''),
            $now, $id
        ));
    }

    public function deleteSite($id) {
        $stmt = $this->pdo->prepare("DELETE FROM noad_sites WHERE id=?");
        return $stmt->execute(array($id));
    }

    public function toggleSite($id) {
        $stmt = $this->pdo->prepare("UPDATE noad_sites SET enabled = 1 - enabled WHERE id=?");
        return $stmt->execute(array($id));
    }

    public function incrementSiteParseCount($id) {
        if ($id <= 0) return;
        $stmt = $this->pdo->prepare("UPDATE noad_sites SET parse_count = parse_count + 1 WHERE id=?");
        $stmt->execute(array($id));
    }

    // ========== M3U8 解析日志 CRUD ==========

    public function logM3u8Parse($siteId, $siteName, $inputUrl, $totalSegments,
                                   $adSegments, $keepSegments, $totalDuration, $adDuration) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $now = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO noad_parse_log(parse_time,site_id,site_name,input_url,
             total_segments,ad_segments,keep_segments,total_duration,ad_duration,ip)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute(array(
            $now, (int)$siteId, $siteName, $inputUrl,
            (int)$totalSegments, (int)$adSegments, (int)$keepSegments,
            (float)$totalDuration, (float)$adDuration, $ip
        ));
        // 同步累计资源站点的使用次数
        if ((int)$siteId > 0) $this->incrementSiteParseCount((int)$siteId);
        return $this->pdo->lastInsertId();
    }

    public function getParseLog($limit = 50) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM noad_parse_log ORDER BY parse_time DESC LIMIT ?"
        );
        $stmt->execute(array($limit));
        return $stmt->fetchAll();
    }

    public function clearParseLog() {
        $this->pdo->exec("DELETE FROM noad_parse_log");
        return true;
    }
}
