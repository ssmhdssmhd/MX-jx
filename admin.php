<?php
/**
 * 沫兮万能解析 - 管理后台（单文件集成版 v4.5.1）
 * ------------------------------------------------------------
 * 原文件：admin.php + admin_tpl.php + admin_main.php + admin_style.css
 * 现全部整合到此文件中，便于部署与维护
 *
 * 功能模块：
 *   1. 仪表盘（系统运行状态概览）
 *   2. M3U8 解析（去广告分析）
 *   3. 资源站点管理
 *   4. 解析日志
 *   5. 缓存管理
 *   6. NoAd 数据统计
 *   7. 解析源管理
 *   8. 广告规则库
 *   9. NoAd 系统设置
 *  10. API 线路配置
 *  11. 平台规则配置
 *  12. 系统开关
 *  13. ZJK 自定义接口
 *  14. 接口在线测试
 *  15. 自定义算法管理
 *  16. 配置备份
 *  17. 修改管理员密码
 *  18. 后台设置（权限/路径/日志）
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

// ========= 基础配置 =========
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
date_default_timezone_set('Asia/Shanghai');
session_start();

// ========= 加载配置 =========
$adminConfig  = require __DIR__ . '/config/admin.php';
$apiConfig    = require __DIR__ . '/config/api.php';
$platformCfg  = require __DIR__ . '/config/platform.php';
$switchConfig = require __DIR__ . '/config/switch.php';
$noadConfig   = file_exists(__DIR__ . '/config/noad.php')
              ? require __DIR__ . '/config/noad.php'
              : ['noad_enabled' => false, 'resource_types' => [], 'stats_enabled' => false];
$currentScript = basename($_SERVER['SCRIPT_NAME']);

$msg = '';
$msgType = 'success';

// ========= 访问权限 =========
if (empty($adminConfig['admin_enabled'])) {
    http_response_code(403);
    exit('<div style="text-align:center;padding:50px;"><h2>后台已禁用</h2></div>');
}
if (!empty($adminConfig['enforce_port']) && !empty($adminConfig['allowed_ports'])) {
    $curPort = (int)($_SERVER['SERVER_PORT'] ?? 80);
    if (!in_array($curPort, $adminConfig['allowed_ports'], true)) {
        http_response_code(403);
        echo '<div style="text-align:center;padding:50px;"><h2>端口访问受限</h2></div>';
        exit;
    }
}
if (!empty($adminConfig['allowed_ips'])) {
    $cip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($cip, $adminConfig['allowed_ips'], true)) {
        http_response_code(403);
        exit('<div style="text-align:center;padding:50px;"><h2>IP访问受限</h2></div>');
    }
}

// ========= 登录校验 =========
$isLoggedIn = (!empty($_SESSION['admin_logged_in']) &&
               ($_SESSION['admin_login_time'] + $adminConfig['session_lifetime'] > time()));

if (!$isLoggedIn && ($_POST['action'] ?? '') === 'login') {
    $attemptFile = __DIR__ . '/cache/_login_attempts.php';
    $attemptData = [];
    if (file_exists($attemptFile)) {
        $attemptData = @include $attemptFile;
        if (!is_array($attemptData)) $attemptData = [];
    }
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $locked = !empty($attemptData[$clientIp]) &&
              $attemptData[$clientIp]['count'] >= $adminConfig['max_login_attempts'] &&
              $now - $attemptData[$clientIp]['last_time'] < $adminConfig['lockout_duration'];
    if ($locked) {
        $loginError = '登录失败次数过多，已锁定';
    } elseif (md5($_POST['password'] ?? '') === $adminConfig['admin_password']) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = $now;
        unset($attemptData[$clientIp]);
        writePhpFile($attemptFile, '<?php return ' . var_export($attemptData, true) . ';');
        header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    } else {
        $attemptData[$clientIp] = ['count' => ($attemptData[$clientIp]['count'] ?? 0) + 1,
                                    'last_time' => $now];
        writePhpFile($attemptFile, '<?php return ' . var_export($attemptData, true) . ';');
        $loginError = '密码错误';
    }
}

if ($isLoggedIn && ($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
    exit;
}

// ========= 辅助函数 =========
function writePhpFile($file, $content) {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return false; }
    return rename($tmp, $file);
}
function buildPhpReturnArray($arr) {
    $content = "<?php\n/** 配置文件 */\n\nreturn array(\n";
    foreach ($arr as $k => $v) {
        $content .= "    \"" . addslashes($k) . "\" => \"" . addslashes((string)$v) . "\",\n";
    }
    $content .= ");\n";
    return $content;
}
function buildNoadConfig($c) {
    $kw = var_export($c['ad_keywords'] ?? ['ad','广告'], true);
    $wl = var_export($c['whitelist_keywords'] ?? ['main','正片','hd'], true);
    $rt = var_export($c['resource_types'] ?? defaultTypes(), true);
    $ds = var_export($c['default_sources'] ?? [], true);
    $rs = var_export($c['resource_sites'] ?? [], true);
    return "<?php\n/** NoAd 去广告系统 v4 配置文件 */\n\nreturn array(\n" .
            "    'noad_enabled' => " . ($c['noad_enabled'] ? 'true' : 'false') . ",\n" .
            "    'debug_mode' => " . ($c['debug_mode'] ? 'true' : 'false') . ",\n" .
            "    'enable_ad_filter' => " . ($c['enable_ad_filter'] ? 'true' : 'false') . ",\n" .
            "    'enable_ts_proxy' => true,\n" .
            "    'ad_keyword_threshold' => " . (int)($c['ad_keyword_threshold'] ?? 2) . ",\n" .
            "    'cache_enabled' => " . ($c['cache_enabled'] ? 'true' : 'false') . ",\n" .
            "    'cache_ttl' => " . (int)($c['cache_ttl'] ?? 1800) . ",\n" .
            "    'cache_dir' => __DIR__ . '/../cache',\n" .
            "    'enable_multi_source' => " . ($c['enable_multi_source'] ? 'true' : 'false') . ",\n" .
            "    'max_source_try' => " . (int)($c['max_source_try'] ?? 3) . ",\n" .
            "    'request_timeout' => " . (int)($c['request_timeout'] ?? 10) . ",\n" .
            "    'ad_keywords' => " . $kw . ",\n" .
            "    'whitelist_keywords' => " . $wl . ",\n" .
            "    'resource_types' => " . $rt . ",\n" .
            "    'default_sources' => " . $ds . ",\n" .
            "    'resource_sites' => " . $rs . ",\n" .
            "    'stats_enabled' => " . ($c['stats_enabled'] ? 'true' : 'false') . ",\n" .
            "    'stats_log_request' => true,\n" .
            "    'stats_top_limit' => 20,\n" .
            "    'sqlite_path' => __DIR__ . '/../cache/noad.db',\n" .
            "    // ========= 如意去广告算法（Ruyi Pattern Cleaner v2.1） =========\n" .
            "    // 算法开关：true=启用 false=关闭（关闭后完全跳过如意算法，仅使用其他广告过滤）\n" .
            "    'ruyi_enabled' => " . (isset($c['ruyi_enabled']) && !$c['ruyi_enabled'] ? 'false' : 'true') . ",\n" .
            "    // Score 阈值：分数 >=此值的片段簇被视为广告。推荐值：3=保守, 4=平衡(默认), 5=激进\n" .
            "    'ruyi_score_threshold' => " . (int)($c['ruyi_score_threshold'] ?? 4) . ",\n" .
            "    // 基准片段时长（秒）：资源站标准视频片段时长。其他时长被视为候选广告片段\n" .
            "    'ruyi_baseline_sec' => " . round((float)($c['ruyi_baseline_sec'] ?? 4.00), 2) . ",\n" .
            "    // 基准容差（秒）：基准 ±此值范围内都视为正常视频片段。默认 0.10\n" .
            "    'ruyi_baseline_tolerance' => " . round((float)($c['ruyi_baseline_tolerance'] ?? 0.10), 2) . ",\n" .
            "    // 广告簇最小长度：连续非基准时长片段数量 >=此值才视为候选广告（太短=可能是正常场景切换）\n" .
            "    'ruyi_min_cluster_len' => " . (int)($c['ruyi_min_cluster_len'] ?? 3) . ",\n" .
            "    // 广告簇最大长度：超过此长度视为正常内容（如纪录片的复杂片段），默认 15\n" .
            "    'ruyi_max_cluster_len' => " . (int)($c['ruyi_max_cluster_len'] ?? 15) . ",\n" .
            "    // 广告簇最小总时长（秒）：片段组总时长 >=此值才是完整广告块，默认 15\n" .
            "    'ruyi_min_cluster_sum' => " . round((float)($c['ruyi_min_cluster_sum'] ?? 15.0), 2) . ",\n" .
            "    // 广告簇最大总时长（秒）：超过此值的长片段组=正片内容，默认 35\n" .
            "    'ruyi_max_cluster_sum' => " . round((float)($c['ruyi_max_cluster_sum'] ?? 35.0), 2) . ",\n" .
            "    // 短片段阈值（秒）：片段时长 <此值视为广告强信号，默认 3.0\n" .
            "    'ruyi_short_seg_threshold' => " . round((float)($c['ruyi_short_seg_threshold'] ?? 3.0), 2) . ",\n" .
            "    // 极短片段阈值（秒）：片段时长 <此值 =直接删除（广告过渡/收尾标志），默认 1.5\n" .
            "    'ruyi_very_short_threshold' => " . round((float)($c['ruyi_very_short_threshold'] ?? 1.5), 2) . ",\n" .
            "    // DISCONTINUITY 信号：启用 M3U8 中编码断层标记（典型为广告插入点）辅助判断\n" .
            "    'ruyi_enable_discontinuity' => " . (isset($c['ruyi_enable_discontinuity']) && !$c['ruyi_enable_discontinuity'] ? 'false' : 'true') . ",\n" .
            "    // 每天自动检测优化：每天指定时间，系统自动用示例视频测试并调整参数\n" .
            "    'ruyi_auto_optimize_enabled' => " . (isset($c['ruyi_auto_optimize_enabled']) && !$c['ruyi_auto_optimize_enabled'] ? 'false' : 'true') . ",\n" .
            "    // 自动检测执行时间（0~23 小时），推荐凌晨 3 点，默认 3\n" .
            "    'ruyi_auto_optimize_hour' => " . (int)($c['ruyi_auto_optimize_hour'] ?? 3) . ",\n" .
            "    // 自动检测间隔（小时），24=每天一次，12=12小时一次，默认 24\n" .
            "    'ruyi_auto_optimize_interval_hours' => " . (int)($c['ruyi_auto_optimize_interval_hours'] ?? 24) . ",\n" .
            "    // 自动检测示例视频 URL（为空时随机选择解析源），可填写经常观看的视频地址\n" .
            "    'ruyi_auto_optimize_sample_url' => " . (isset($c['ruyi_auto_optimize_sample_url']) && !empty($c['ruyi_auto_optimize_sample_url'])
                ? "'" . addslashes(trim($c['ruyi_auto_optimize_sample_url'])) . "'" : "''") . ",\n" .
            "    // 调试模式：true =在 M3U8 中加入调试标记（开发专用），默认 false\n" .
            "    'ruyi_debug_mode' => " . (!empty($c['ruyi_debug_mode']) ? 'true' : 'false') . ",\n" .
            "    // ========================================================\n" .
            "    // ===== MD5 指纹去广告参数（万能规则1） =================\n" .
            "    'md5_enabled' => " . (isset($c['md5_enabled']) && !$c['md5_enabled'] ? 'false' : 'true') . ",\n" .
            "    'md5_repeat_threshold' => " . max(1, (int)($c['md5_repeat_threshold'] ?? 3)) . ",\n" .
            "    'md5_max_concurrency' => " . max(1, (int)($c['md5_max_concurrency'] ?? 6)) . ",\n" .
            "    'md5_segment_timeout' => " . max(5, (int)($c['md5_segment_timeout'] ?? 15)) . ",\n" .
            "    'md5_total_timeout' => " . max(30, (int)($c['md5_total_timeout'] ?? 60)) . ",\n" .
            "    'md5_max_segment_kb' => " . max(500, (int)($c['md5_max_segment_kb'] ?? 5000)) . ",\n" .
            "    'md5_use_proxy' => " . (isset($c['md5_use_proxy']) && !$c['md5_use_proxy'] ? 'false' : 'true') . ",\n" .
            "    'md5_min_interval_ms' => " . max(50, (int)($c['md5_min_interval_ms'] ?? 100)) . ",\n" .
            "    'md5_auto_learn' => " . (isset($c['md5_auto_learn']) && !$c['md5_auto_learn'] ? 'false' : 'true') . ",\n" .
            "    'md5_db_cleanup_days' => " . max(7, (int)($c['md5_db_cleanup_days'] ?? 30)) . ",\n" .
            "    'md5_debug' => " . (!empty($c['md5_debug']) ? 'true' : 'false') . ",\n" .
            "    // ========================================================\n" .
            "    // ===== 批量解析特征学习参数（万能规则2） =================\n" .
            "    'feat_enabled' => " . (isset($c['feat_enabled']) && !$c['feat_enabled'] ? 'false' : 'true') . ",\n" .
            "    'feat_max_sources' => " . max(2, (int)($c['feat_max_sources'] ?? 3)) . ",\n" .
            "    'feat_source_timeout' => " . max(5, (int)($c['feat_source_timeout'] ?? 15)) . ",\n" .
            "    'feat_total_timeout' => " . max(30, (int)($c['feat_total_timeout'] ?? 60)) . ",\n" .
            "    'feat_min_votes' => " . max(2, (int)($c['feat_min_votes'] ?? 2)) . ",\n" .
            "    'feat_low_resource_mode' => " . (isset($c['feat_low_resource_mode']) && !$c['feat_low_resource_mode'] ? 'false' : 'true') . ",\n" .
            "    'feat_max_concurrency' => " . max(1, (int)($c['feat_max_concurrency'] ?? 2)) . ",\n" .
            "    'feat_sample_count' => " . max(5, (int)($c['feat_sample_count'] ?? 15)) . ",\n" .
            "    'feat_learn_enabled' => " . (isset($c['feat_learn_enabled']) && !$c['feat_learn_enabled'] ? 'false' : 'true') . ",\n" .
            "    'feat_use_proxy' => " . (isset($c['feat_use_proxy']) && !$c['feat_use_proxy'] ? 'false' : 'true') . ",\n" .
            "    'feat_debug' => " . (!empty($c['feat_debug']) ? 'true' : 'false') . ",\n" .
            ");\n";
}
function defaultTypes() {
    return [
        1 => ['key'=>'movie','name'=>'电影','icon'=>'🎬'],
        2 => ['key'=>'tv','name'=>'剧集','icon'=>'📺'],
        3 => ['key'=>'variety','name'=>'综艺','icon'=>'🎤'],
        4 => ['key'=>'anime','name'=>'动漫','icon'=>'🎭'],
        5 => ['key'=>'document','name'=>'纪录片','icon'=>'📚'],
        6 => ['key'=>'sports','name'=>'体育','icon'=>'⚽'],
        7 => ['key'=>'short','name'=>'短视频','icon'=>'📱'],
    ];
}
function saveAdminConfig($data) {
    $content = "<?php\n/** 后台管理配置 */\n\nreturn array(\n";
    foreach ($data as $k => $v) {
        if (is_bool($v)) $vStr = $v ? 'true' : 'false';
        elseif (is_numeric($v) && !is_string($v)) $vStr = (string)$v;
        elseif (is_array($v)) $vStr = var_export($v, true);
        else $vStr = '"' . addslashes((string)$v) . '"';
        $content .= "    \"" . $k . "\" => " . $vStr . ",\n";
    }
    $content .= ");\n";
    writePhpFile(__DIR__ . '/config/admin.php', $content);
}

// ========= 提前放行 AJAX 接口 =========
$ajaxEarlyAction = $_POST['action'] ?? $_GET['action'] ?? '';
$ajaxEarlyWhitelist = [
    'ajax_parse_m3u8', 'ajax_get_sites',
    'ajax_list_algorithms', 'ajax_toggle_algo',
    'ajax_reload_algorithms', 'ajax_test_algorithms',
    'ajax_ruyi_test', 'ajax_ruyi_auto_optimize',
    'ajax_md5_test', 'ajax_md5_stats', 'ajax_md5_mark', 'ajax_md5_whitelist', 'ajax_md5_deep_analyze',
    'ajax_md5_diagnose', 'ajax_md5_fetch_segment', 'ajax_md5_manual_mark', 'ajax_md5_deep_cluster', 'ajax_md5_parse_m3u8',
    'ajax_feat_test', 'ajax_feat_stats', 'ajax_feat_learn', 'ajax_feat_mark',
    'ajax_tools_list', 'ajax_tools_run', 'ajax_tools_reload', 'ajax_tools_combo',
    'ajax_ad_snippet_fetch',
];
if (in_array($ajaxEarlyAction, $ajaxEarlyWhitelist, true)) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/NoAdParser.php';
    $db = null;
    if (!empty($noadConfig['noad_enabled']) && !empty($noadConfig['stats_enabled']) && extension_loaded('pdo_sqlite')) {
        try { $db = Database::getInstance($noadConfig['sqlite_path']); } catch (Exception $e) { $db = null; }
    }

    if ($ajaxEarlyAction === 'ajax_parse_m3u8') {
        $url = trim($_POST['m3u8_url'] ?? '');
        $siteId = (int)($_POST['site_id'] ?? 0);
        $siteName = '';
        if ($siteId > 0 && $db) {
            $s = $db->getSiteById($siteId);
            if (!empty($s)) $siteName = $s['name'] . (!empty($s['short_code']) ? '(' . $s['short_code'] . ')' : '');
        }
        if ($url === '') { echo json_encode(['code' => 400, 'msg' => 'URL 不能为空'], JSON_UNESCAPED_UNICODE); exit; }
        $parser = new NoAdParser();
        $result = $parser->fetchAndAnalyze($url, 20);
        if ($result === null) { echo json_encode(['code' => 500, 'msg' => '无法获取或解析该 M3U8 链接'], JSON_UNESCAPED_UNICODE); exit; }
        if ($db) { try { $db->logM3u8Parse($siteId, $siteName, $url, $result['total'], $result['ad_count'], $result['keep_count'], $result['total_duration'], $result['ad_duration']); } catch (Exception $e) {} }
        $liteSegs = []; $totalRules = 0;
        foreach ($result['segments'] as $seg) {
            $liteSegs[] = [
                'idx' => $seg['idx'], 'duration' => $seg['duration'], 'uri' => $seg['uri'],
                'is_ad' => $seg['is_ad'], 'reason' => $seg['reason'],
                'time_start' => $parser->formatTime($seg['time_start']),
                'time_end' => $parser->formatTime($seg['time_end']),
            ];
            if ($seg['is_ad']) $totalRules++;
        }
        echo json_encode([
            'code' => 200, 'msg' => 'ok', 'total' => $result['total'],
            'ad_count' => $result['ad_count'], 'keep_count' => $result['keep_count'],
            'total_duration' => $parser->formatTime($result['total_duration']),
            'ad_duration' => $parser->formatTime($result['ad_duration']),
            'keep_duration' => $parser->formatTime($result['keep_duration']),
            'site_name' => $siteName, 'rules' => max(1, $totalRules),
            'raw_content' => $result['raw_content'], 'clean_m3u8' => $result['clean_m3u8'],
            'segments' => $liteSegs,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($ajaxEarlyAction === 'ajax_get_sites') {
        $sites = $db ? $db->getSites(true) : [];
        echo json_encode(['code' => 200, 'sites' => $sites], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $parser = new NoAdParser();
    if ($ajaxEarlyAction === 'ajax_list_algorithms') {
        echo json_encode(['code' => 200, 'algorithms' => $parser->listCustomAlgorithms()], JSON_UNESCAPED_UNICODE);
    } elseif ($ajaxEarlyAction === 'ajax_toggle_algo') {
        $id = trim($_POST['algo_id'] ?? '');
        $enabled = (int)($_POST['enabled'] ?? 1);
        if ($id === '') { echo json_encode(['code' => 400, 'msg' => '缺少 id'], JSON_UNESCAPED_UNICODE); exit; }
        $parser->setCustomAlgorithmEnabled($id, (bool)$enabled);
        echo json_encode(['code' => 200, 'id' => $id, 'enabled' => (bool)$enabled], JSON_UNESCAPED_UNICODE);
    } elseif ($ajaxEarlyAction === 'ajax_reload_algorithms') {
        echo json_encode(['code' => 200, 'algorithms' => $parser->reloadCustomAlgorithms()], JSON_UNESCAPED_UNICODE);
    } elseif ($ajaxEarlyAction === 'ajax_test_algorithms') {
        $input = $_POST['input'] ?? '';
        $scope = $_POST['scope'] ?? 'all';
        $result = $parser->applyCustomAlgorithms($input, $scope, ['original_url' => $input]);
        echo json_encode([
            'code' => 200, 'original' => $input,
            'result' => $result['data'] ?? $input,
            'applied' => $result['applied'] ?? [],
            'changed' => ($result['data'] ?? $input) !== $input,
        ], JSON_UNESCAPED_UNICODE);
    } elseif ($ajaxEarlyAction === 'ajax_tools_list') {
        require_once __DIR__ . '/tools/core/ToolManager.php';
        $tm = ToolManager::getInstance();
        echo json_encode(['code' => 200, 'tools' => $tm->listTools()], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ajaxEarlyAction === 'ajax_tools_run') {
        require_once __DIR__ . '/tools/core/ToolManager.php';
        $tm = ToolManager::getInstance();
        $toolId = trim($_POST['tool_id'] ?? '');
        $paramsRaw = $_POST['params'] ?? '';
        $params = [];
        if (is_array($paramsRaw)) {
            $params = $paramsRaw;
        } elseif (is_string($paramsRaw) && $paramsRaw !== '') {
            $decoded = json_decode($paramsRaw, true);
            if (is_array($decoded)) $params = $decoded;
        }
        $result = $tm->runTool($toolId, $params);
        echo json_encode(['code' => 200, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ajaxEarlyAction === 'ajax_tools_reload') {
        require_once __DIR__ . '/tools/core/ToolManager.php';
        $tm = ToolManager::getInstance();
        $count = $tm->reload();
        echo json_encode(['code' => 200, 'count' => $count, 'tools' => $tm->listTools()], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ajaxEarlyAction === 'ajax_tools_combo') {
        require_once __DIR__ . '/tools/core/ToolManager.php';
        $tm = ToolManager::getInstance();
        $input = trim($_POST['input'] ?? '');
        $mode = $_POST['mode'] ?? 'auto';
        if (empty($input)) { echo json_encode(['code' => 400, 'msg' => 'input 参数不能为空'], JSON_UNESCAPED_UNICODE); exit; }
        
        $content = $input;
        $sourceUrl = '';
        $isM3u8 = stripos($input, '#EXTM3U') !== false;
        
        if (!$isM3u8 && filter_var($input, FILTER_VALIDATE_URL)) {
            $sourceUrl = $input;
            $fetched = @file_get_contents($input);
            if ($fetched !== false) {
                $content = $fetched;
                $isM3u8 = stripos($content, '#EXTM3U') !== false;
            }
        }
        
        $featureResult = $tm->runTool('feature_extractor', ['input' => $content, 'mode' => 'auto']);
        $adResult = $tm->runTool('ad_cleaner_m3u8', ['input' => $content, 'mode' => $isM3u8 ? 'm3u8' : 'auto']);
        
        echo json_encode([
            'code' => 200, 'msg' => 'ok',
            'source_url' => $sourceUrl,
            'content_size' => strlen($content),
            'is_m3u8' => $isM3u8,
            'feature' => $featureResult,
            'ad_clean' => $adResult,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ajaxEarlyAction === 'ajax_ruyi_test' || $ajaxEarlyAction === 'ajax_ruyi_auto_optimize') {        // === 如意算法：测试 / 自动优化 ===
        // 步骤 1：选择示例 M3U8 URL
        $sampleUrl = trim($_POST['sample_url'] ?? '');
        $testUrls = [
            'https://svip.ryiplay18.com/20260621/7402_adcfbfd7/index.m3u8', // 如意原始样本
        ];
        if ($sampleUrl !== '') $testUrls = [$sampleUrl];

        // 步骤 2：下载一个 M3U8（跟随 master playlist 子 m3u8）
        $downloadOk = false;
        $m3u8 = '';
        $testedUrl = '';
        foreach ($testUrls as $testUrl) {
            $testedUrl = $testUrl;
            $ch = curl_init($testUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36');
            $content = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($content && $code === 200 && trim($content) !== '') {
                $m3u8 = $content;
                // 若为 master playlist，跟随第一个子 m3u8
                if (strpos($m3u8, '#EXT-X-STREAM-INF') !== false) {
                    $lines = preg_split('/\r\n|\r|\n/', $m3u8);
                    for ($i = 0; $i < count($lines); $i++) {
                        if (strpos($lines[$i], '#EXT-X-STREAM-INF') === 0) {
                            for ($j = $i + 1; $j < count($lines); $j++) {
                                $next = trim($lines[$j]);
                                if ($next !== '' && strpos($next, '#') !== 0 && strpos($next, 'http') === 0) {
                                    $ch2 = curl_init($next);
                                    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36']);
                                    $content2 = curl_exec($ch2);
                                    curl_close($ch2);
                                    if ($content2 && trim($content2) !== '') { $m3u8 = $content2; }
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $downloadOk = true;
                break;
            }
        }

        // 步骤 3：根据 action 决定输出（测试 vs 自动优化）
        if (!$downloadOk) {
            echo json_encode([
                'code' => 500,
                'msg' => '无法下载示例 M3U8（网络问题或 URL 已失效）。可在配置中填写有效的视频 URL。',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 统计原始信息
        $origLines = preg_split('/\r\n|\r|\n/', $m3u8);
        $origSeg = 0; $origShort = 0; $origVeryShort = 0;
        foreach ($origLines as $line) {
            if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                $origSeg++;
                if ($m[1] < 3.0) $origShort++;
                if ($m[1] < 1.5) $origVeryShort++;
            }
        }

        // 加载如意算法并应用（自动使用 config/noad.php 中的参数）
        require_once __DIR__ . '/algorithms/AbstractAlgorithm.php';
        require_once __DIR__ . '/algorithms/AlgorithmRegistry.php';
        require_once __DIR__ . '/algorithms/ruyi_pattern_cleaner.php';

        if ($ajaxEarlyAction === 'ajax_ruyi_test') {
            // === 测试当前参数：输出当前参数的效果 ===
            $algo = new RuyiPatternCleaner();
            $cleaned = $algo->apply($m3u8, ['original_url' => $testedUrl]);
            $cleanedSeg = 0; $cleanedShort = 0; $cleanedVeryShort = 0;
            foreach (preg_split('/\r\n|\r|\n/', $cleaned) as $line) {
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                    $cleanedSeg++;
                    if ($m[1] < 3.0) $cleanedShort++;
                    if ($m[1] < 1.5) $cleanedVeryShort++;
                }
            }
            $params = method_exists($algo, 'getCurrentParams') ? $algo->getCurrentParams() : [];
            $report = "✅ 如意算法测试完成\n\n"
                    . "测试视频：$testedUrl\n"
                    . "总片段数：$origSeg → $cleanedSeg（删除 " . ($origSeg - $cleanedSeg) . " 段）\n"
                    . "极短片段（<1.5s）：$origVeryShort → $cleanedVeryShort（删除 " . ($origVeryShort - $cleanedVeryShort) . " 段）\n"
                    . "短片段（<3.0s）：$origShort → $cleanedShort（删除 " . ($origShort - $cleanedShort) . " 段）\n\n"
                    . "当前参数：\n";
            foreach ($params as $k => $v) $report .= "  · $k = $v\n";
            echo json_encode(['code' => 200, 'report' => $report, 'saved' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === 自动优化：多组参数扫描 → 选出最优 → 保存配置 ===
        // 候选参数：Score 阈值 3/4/5，簇长度稍作调整
        $candidates = [
            ['score' => 3, 'minLen' => 3, 'maxLen' => 12, 'minSum' => 15, 'veryShort' => 1.5],
            ['score' => 4, 'minLen' => 3, 'maxLen' => 15, 'minSum' => 15, 'veryShort' => 1.5],  // 默认
            ['score' => 5, 'minLen' => 3, 'maxLen' => 18, 'minSum' => 18, 'veryShort' => 1.5],
            ['score' => 4, 'minLen' => 5, 'maxLen' => 15, 'minSum' => 20, 'veryShort' => 1.2],  // 更保守
            ['score' => 3, 'minLen' => 2, 'maxLen' => 12, 'minSum' => 12, 'veryShort' => 1.8],  // 更灵敏
        ];

        // 动态构造算法实例并评估每个参数组合
        $bestScore = -1; $bestIdx = 0; $bestReport = '';
        $allReports = [];
        foreach ($candidates as $idx => $c) {
            // 临时修改配置以测试不同参数
            $algo = new RuyiPatternCleaner();
            // 使用反射修改私有属性（或使用构造参数）
            $ref = new ReflectionClass($algo);
            $props = ['scoreThreshold' => $c['score'], 'minClusterLength' => $c['minLen'],
                     'maxClusterLength' => $c['maxLen'], 'minClusterSum' => $c['minSum'],
                     'veryShortThreshold' => $c['veryShort']];
            foreach ($props as $name => $val) {
                if ($ref->hasProperty($name)) {
                    $prop = $ref->getProperty($name);
                    $prop->setAccessible(true);
                    $prop->setValue($algo, $val);
                }
            }
            $cleaned = $algo->apply($m3u8, ['original_url' => $testedUrl]);

            // 统计：删除的短片段数（广告） vs 片段删除比例
            $cleanedSeg = 0; $cleanedVeryShort = 0;
            foreach (preg_split('/\r\n|\r|\n/', $cleaned) as $line) {
                if (preg_match('/#EXTINF:([\d\.]+)/', $line, $m)) {
                    $cleanedSeg++;
                    if ($m[1] < 1.5) $cleanedVeryShort++;
                }
            }
            $removed = $origSeg - $cleanedSeg;
            $removedRatio = $origSeg > 0 ? round($removed * 100 / $origSeg, 1) : 0;
            $removedVeryShort = $origVeryShort - $cleanedVeryShort;
            // 评分：高极短删除率 + 低总删除率（避免误删正片）
            $veryShortRate = $origVeryShort > 0 ? round($removedVeryShort * 100 / max(1, $origVeryShort), 1) : 100;
            $goodScore = $veryShortRate * 2 - $removedRatio;  // 极短删除高 + 总删除低 = 好
            $allReports[] = "候选 $idx: Score阈值={$c['score']}, 簇 {$c['minLen']}-{$c['maxLen']}/sum>{$c['minSum']}s → 总删除 $removed段($removedRatio%), 极短删除 $removedVeryShort段, 评分=$goodScore";
            if ($goodScore > $bestScore) { $bestScore = $goodScore; $bestIdx = $idx; }
        }

        // 选出最优参数组合并自动保存
        $best = $candidates[$bestIdx];
        $noadConfigPath = __DIR__ . '/config/noad.php';
        $currentCfg = @include $noadConfigPath;
        if (!is_array($currentCfg)) $currentCfg = [];
        $currentCfg['ruyi_score_threshold'] = $best['score'];
        $currentCfg['ruyi_min_cluster_len'] = $best['minLen'];
        $currentCfg['ruyi_max_cluster_len'] = $best['maxLen'];
        $currentCfg['ruyi_min_cluster_sum'] = $best['minSum'];
        $currentCfg['ruyi_very_short_threshold'] = $best['veryShort'];
        // 写入配置文件
        writePhpFile($noadConfigPath, buildNoadConfig($currentCfg));

        $report = "🤖 如意算法自动优化完成\n\n"
                . "测试视频：$testedUrl\n"
                . "原始片段：$origSeg 段, 极短(<1.5s): $origVeryShort 段\n\n"
                . "所有候选参数测试：\n" . implode("\n", $allReports) . "\n\n"
                . "🏆 最优组合：候选 $bestIdx (Score阈值={$best['score']}, 簇 {$best['minLen']}-{$best['maxLen']}/sum>{$best['minSum']}s)\n"
                . "✅ 已自动保存到 config/noad.php，下次解析视频将使用新参数！";
        echo json_encode(['code' => 200, 'report' => $report, 'saved' => true], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($ajaxEarlyAction === 'ajax_md5_test' || $ajaxEarlyAction === 'ajax_md5_stats'
        || $ajaxEarlyAction === 'ajax_md5_mark' || $ajaxEarlyAction === 'ajax_md5_whitelist'
        || $ajaxEarlyAction === 'ajax_md5_deep_analyze'
        || $ajaxEarlyAction === 'ajax_md5_diagnose'
        || $ajaxEarlyAction === 'ajax_md5_fetch_segment'
        || $ajaxEarlyAction === 'ajax_md5_manual_mark'
        || $ajaxEarlyAction === 'ajax_md5_deep_cluster'
        || $ajaxEarlyAction === 'ajax_md5_parse_m3u8') {
        // === MD5 指纹去广告：测试 / 统计 / 标记 ===
        require_once __DIR__ . '/algorithms/md5_pattern_cleaner.php';
        $md5 = new MD5PatternCleaner();
        $md5DB = new MD5FingerprintDB();

        if ($ajaxEarlyAction === 'ajax_md5_test') {
            // 下载一个视频的 M3U8，统计 MD5 指纹并展示
            $sampleUrl = trim($_POST['sample_url'] ?? '');
            $fallback = [
                'https://svip.ryiplay18.com/20260621/7402_adcfbfd7/index.m3u8',
                'https://svip.ryiplay18.com/20260525/7279_51e48837/index.m3u8',
            ];
            $testedUrl = $sampleUrl;
            $m3u8 = null;
            if ($testedUrl === '') { $testedUrl = $fallback[0]; }
            $m3u8 = MD5PatternCleaner::downloadM3U8($testedUrl, false);
            if ($m3u8 === false) {
                foreach ($fallback as $url) {
                    $m3u8 = MD5PatternCleaner::downloadM3U8($url, false);
                    if ($m3u8 !== false) { $testedUrl = $url; break; }
                }
            }
            if ($m3u8 === false) {
                echo json_encode(['code' => 500, 'error' => '无法下载 M3U8'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $segCount = substr_count($m3u8, 'EXTINF:');

            // 应用 MD5 算法
            $result = $md5->apply($m3u8, $testedUrl);
            $stats = $result['stats'] ?? [];

            // 统计报告
            $report = "🧪 MD5 指纹测试完成\n\n"
                    . "测试视频：$testedUrl\n"
                    . "原始片段：$segCount 段\n"
                    . "本次下载：" . ($stats['downloaded'] ?? 0) . " 段\n"
                    . "下载失败：" . ($stats['failed_downloads'] ?? 0) . " 段\n"
                    . "删除广告：" . ($stats['removed_ad'] ?? 0) . " 段\n"
                    . "保留正片：" . (($stats['kept_whitelist'] ?? 0) + ($stats['kept_new'] ?? 0) + ($stats['kept_low_freq'] ?? 0)) . " 段\n"
                    . "耗时：" . ($stats['elapsed_ms'] ?? 0) . " ms\n"
                    . "实际并发：" . ($stats['concurrency'] ?? 0) . " 线程\n"
                    . "数据库状态：" . ($md5DB->isReady() ? "✅ 正常" : "❌ 异常") . "\n";

            if (isset($stats['skipped_protection']) && $stats['skipped_protection'] > 0) {
                $report .= "⚠️ 跳过片段（保护模式）：" . $stats['skipped_protection'] . " 段\n";
            }

            // 数据库统计
            $dbStats = $md5DB->getStats();
            $report .= "\n📊 MD5 指纹库统计：\n"
                     . "  总指纹：" . ($dbStats['total'] ?? 0) . " 个\n"
                     . "  今日新指纹：" . ($dbStats['today_segments'] ?? 0) . " 个\n"
                     . "  数据库大小：" . round($md5DB->getDbSize() / 1024, 1) . " KB";

            echo json_encode(['code' => 200, 'report' => $report, 'segment_count' => $segCount, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxEarlyAction === 'ajax_md5_stats') {
            // 快速统计
            $dbStats = $md5DB->getStats();
            $top = $md5DB->getTopFrequent(20);
            $blacklist = $md5DB->getBlacklist(50);
            $whitelist = $md5DB->getWhitelist(50);
            echo json_encode([
                'code' => 200,
                'stats' => $dbStats,
                'top' => $top,
                'blacklist' => $blacklist,
                'whitelist' => $whitelist,
                'db_size' => round($md5DB->getDbSize() / 1024, 1),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxEarlyAction === 'ajax_md5_mark') {
            $md5Hash = strtolower(trim($_POST['md5'] ?? ''));
            $isAd = isset($_POST['is_ad']) ? (bool)$_POST['is_ad'] : true;
            if ($md5Hash === '' || !preg_match('/^[a-f0-9]{32}$/', $md5Hash)) {
                echo json_encode(['code' => 400, 'error' => '无效 MD5'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $md5DB->markAsAd($md5Hash, $isAd);
            echo json_encode(['code' => 200, 'md5' => $md5Hash, 'marked' => $isAd], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxEarlyAction === 'ajax_md5_whitelist') {
            $md5Hash = strtolower(trim($_POST['md5'] ?? ''));
            if ($md5Hash === '' || !preg_match('/^[a-f0-9]{32}$/', $md5Hash)) {
                echo json_encode(['code' => 400, 'error' => '无效 MD5'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $md5DB->addToWhitelist($md5Hash, '后台人工添加');
            echo json_encode(['code' => 200, 'md5' => $md5Hash, 'added' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxEarlyAction === 'ajax_md5_parse_m3u8') {
            @set_time_limit(30);

            $videoUrl = trim($_POST['video_url'] ?? '');
            $m3u8UrlInput = trim($_POST['m3u8_url'] ?? '');

            if ($videoUrl === '' && $m3u8UrlInput === '') {
                echo json_encode(['code' => 400, 'error' => '请输入视频 URL 或 M3U8 地址'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $targetUrl = $m3u8UrlInput ?: $videoUrl;

            if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                echo json_encode(['code' => 400, 'error' => 'URL 格式不正确'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resolved = MD5PatternCleaner::resolveM3U8FromUrl($targetUrl);
            if ($resolved === false) {
                $errorMsg = '无法获取 M3U8 内容。';
                if (stripos($targetUrl, '.m3u8') === false) {
                    $errorMsg .= ' 该链接不是直接的 M3U8 地址，且未能从页面中解析出播放地址。';
                } else {
                    $errorMsg .= ' 可能的原因：URL 无效、网络连接失败、服务器拒绝访问。';
                }
                echo json_encode(['code' => 500, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $m3u8Content = $resolved['content'];
            $finalM3u8Url = $resolved['final_url'] ?? $targetUrl;

            $parsed = $md5->parseM3U8($m3u8Content);
            $segmentCount = count($parsed['segments']);
            $isMaster = !empty($parsed['is_master']);

            $cacheKey = md5($targetUrl);
            @session_start();
            $_SESSION['md5_deep_m3u8_' . $cacheKey] = [
                'content' => $m3u8Content,
                'url' => $finalM3u8Url,
                'time' => time(),
            ];
            session_write_close();

            $totalDuration = 0;
            foreach ($parsed['segments'] as $seg) {
                $totalDuration += $seg['duration'];
            }

            echo json_encode([
                'code' => 200,
                'success' => true,
                'm3u8_url' => $finalM3u8Url,
                'm3u8_content' => $m3u8Content,
                'cache_key' => $cacheKey,
                'segment_count' => $segmentCount,
                'total_duration' => round($totalDuration, 1),
                'is_master' => $isMaster,
                'master_variants' => $parsed['master_variants'] ?? [],
                'has_end' => $parsed['has_end'],
                'discontinuity_count' => $parsed['discontinuity_count'] ?? 0,
                'discontinuity_indices' => $parsed['discontinuity_indices'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === MD5 深度分析：分片批量分析（避免超时） ===
        if ($ajaxEarlyAction === 'ajax_md5_deep_analyze') {
            @set_time_limit(0);
            @ignore_user_abort(true);
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', '0');
            @ini_set('default_socket_timeout', '120');

            $videoUrl = trim($_POST['video_url'] ?? '');
            $m3u8UrlInput = trim($_POST['m3u8_url'] ?? '');
            $scanMode = trim($_POST['scan_mode'] ?? 'standard'); // quick/standard/slow
            $offset = (int)($_POST['offset'] ?? 0);
            $m3u8CacheKey = trim($_POST['cache_key'] ?? '');

            if ($videoUrl === '' && $m3u8UrlInput === '') {
                echo json_encode(['code' => 400, 'error' => '请输入视频 URL 或 M3U8 地址'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $targetUrl = $m3u8UrlInput ?: $videoUrl;

            if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                echo json_encode(['code' => 400, 'error' => 'URL 格式不正确，请输入完整的 http:// 或 https:// 地址'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 不同模式的每批大小（优化后：提升并发吞吐量）
            $batchSizes = [
                'quick' => 20,        // 快速：每批20段，抽样分析
                'standard' => 30,     // 标准：每批30段，分析全部
                'slow' => 15,         // 慢速：每批15段，更稳定，分析全部
                'sample' => 15,       // 抽样模式：智能抽样，快速定位广告
            ];
            $batchSize = isset($batchSizes[$scanMode]) ? $batchSizes[$scanMode] : 20;
            $quickModeLimit = ($scanMode === 'quick') ? 60 : 0;

            // 使用智能解析获取 M3U8 内容（第一次需要下载，后续批次用 session 缓存）
            $m3u8Content = null;
            $finalM3u8Url = $targetUrl;

            session_start();
            $cacheKey = $m3u8CacheKey ?: md5($targetUrl);
            $cacheSessionKey = 'md5_deep_m3u8_' . $cacheKey;

            if ($offset > 0 && !empty($_SESSION[$cacheSessionKey])) {
                $m3u8Content = $_SESSION[$cacheSessionKey]['content'];
                $finalM3u8Url = $_SESSION[$cacheSessionKey]['url'];
            } else {
                $resolved = MD5PatternCleaner::resolveM3U8FromUrl($targetUrl);
                if ($resolved === false) {
                    $errorMsg = '无法获取 M3U8 内容。';
                    if (stripos($targetUrl, '.m3u8') === false) {
                        $errorMsg .= ' 该链接不是直接的 M3U8 地址，且未能从页面中解析出播放地址。';
                        $errorMsg .= ' 建议直接提供 .m3u8 结尾的播放链接。';
                    } else {
                        $errorMsg .= ' 可能的原因：URL 无效、网络连接失败、服务器拒绝访问、或需要特定的 Referer/Cookie。';
                    }
                    $errorMsg .= '（已尝试 3 次重试 + 多种 User-Agent）';
                    echo json_encode(['code' => 500, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $m3u8Content = $resolved['content'];
                $finalM3u8Url = $resolved['final_url'] ?? $targetUrl;
                $_SESSION[$cacheSessionKey] = [
                    'content' => $m3u8Content,
                    'url' => $finalM3u8Url,
                    'time' => time(),
                ];
            }
            session_write_close();

            // 快速模式限制
            $segCount = substr_count($m3u8Content, '#EXTINF:');

            // === 智能抽样模式：片头+片尾+中间抽样，大幅提速
            $sampleIndexes = null;
            if ($scanMode === 'sample') {
                if ($offset === 0) {
                    // 生成抽样索引：片头20% + 片尾20% + 中间均匀抽样20%
                    $sampleCount = max(30, (int)($segCount * 0.25));
                    $sampleIndexes = [];
                    // 片头前25%
                    $headCount = (int)($sampleCount * 0.35);
                    for ($i = 0; $i < min($headCount, $segCount); $i++) {
                        $sampleIndexes[] = $i;
                    }
                    // 片尾25%
                    $tailCount = (int)($sampleCount * 0.35);
                    for ($i = max(0, $segCount - $tailCount); $i < $segCount; $i++) {
                        $sampleIndexes[] = $i;
                    }
                    // 中间均匀抽样40%
                    $midCount = $sampleCount - $headCount - $tailCount;
                    if ($midCount > 0) {
                        $midStart = $headCount;
                        $midEnd = $segCount - $tailCount - 1;
                        if ($midEnd > $midStart) {
                            $step = max(1, (int)(($midEnd - $midStart) / $midCount));
                            for ($i = $midStart; $i < $midEnd; $i += $step) {
                                $sampleIndexes[] = $i;
                            }
                        }
                    }
                    $sampleIndexes = array_unique($sampleIndexes);
                    sort($sampleIndexes);
                    @session_start();
                    $_SESSION['md5_deep_sample_' . $cacheKey] = $sampleIndexes;
                    session_write_close();
                } else {
                    @session_start();
                    $sampleIndexes = $_SESSION['md5_deep_sample_' . $cacheKey] ?? null;
                    session_write_close();
                }
            }

            $endOffset = $offset + $batchSize;
            if ($quickModeLimit > 0 && $endOffset > $quickModeLimit) {
                $endOffset = $quickModeLimit;
                $batchSize = max(0, $endOffset - $offset);
            }

            // 执行本批次分析
            if ($scanMode === 'sample' && !empty($sampleIndexes)) {
                // 智能抽样模式：从抽样索引中取一批
                $sampleBatch = array_slice($sampleIndexes, $offset, $batchSize);
                $batchResult = $md5->deepAnalyzeByIndexes($m3u8Content, $finalM3u8Url, $sampleBatch);
                $total = (int)$batchResult['total_segments'];
                $actualBatch = count($batchResult['segments']);
                $nextOffset = $offset + $actualBatch;
                $hasMore = ($nextOffset < count($sampleIndexes));
                $isSampleMode = true;
                $sampleTotal = count($sampleIndexes);
            } else {
                // 普通模式：顺序批量分析
                $batchResult = $md5->deepAnalyzeBatch($m3u8Content, $finalM3u8Url, $offset, $batchSize);
                $total = (int)$batchResult['total_segments'];
                $actualBatch = count($batchResult['segments']);
                $nextOffset = $offset + $actualBatch;
                $hasMore = ($nextOffset < $total);
                $isSampleMode = false;
                $sampleTotal = 0;
            }

            // 快速模式下，超过限制就不再继续
            if ($quickModeLimit > 0 && $nextOffset >= $quickModeLimit) {
                $hasMore = false;
            }

            // 累积广告片段索引到 session（用于最后生成 clean_m3u8）
            @session_start();
            $adSegsKey = 'md5_deep_adsegs_' . $cacheKey;
            if ($offset === 0) {
                $_SESSION[$adSegsKey] = [];
            }
            if (!isset($_SESSION[$adSegsKey])) {
                $_SESSION[$adSegsKey] = [];
            }
            foreach ($batchResult['segments'] as $seg) {
                if (!empty($seg['is_ad'])) {
                    $_SESSION[$adSegsKey][$seg['index']] = true;
                }
            }
            $adCount = count($_SESSION[$adSegsKey]);
            session_write_close();

            // 如果是最后一批，生成清理后的 M3U8（不重新下载，只用索引过滤）
            $cleanM3u8 = '';
            if (!$hasMore) {
                $adIndexes = [];
                @session_start();
                if (!empty($_SESSION[$adSegsKey])) {
                    $adIndexes = $_SESSION[$adSegsKey];
                }
                unset($_SESSION[$cacheSessionKey]);
                unset($_SESSION[$adSegsKey]);
                session_write_close();

                $cleanM3u8 = $md5->buildCleanM3U8ByIndex($m3u8Content, $finalM3u8Url, $adIndexes);
            }

            $response = [
                'code' => 200,
                'video_url' => $videoUrl,
                'm3u8_url' => $finalM3u8Url,
                'segments' => $batchResult['segments'],
                'domain' => $batchResult['domain'],
                'total_segments' => $total,
                'offset' => $offset,
                'batch_size' => $actualBatch,
                'next_offset' => $nextOffset,
                'has_more' => $hasMore,
                'scan_mode' => $scanMode,
                'cache_key' => $cacheKey,
                'is_quick_mode' => ($quickModeLimit > 0),
                'quick_limit' => $quickModeLimit,
                'is_sample_mode' => $isSampleMode,
                'sample_total' => $sampleTotal,
                'clean_m3u8' => $cleanM3u8,
                'ad_count_so_far' => $adCount,
            ];

            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === MD5 诊断工具：解析 M3U8 并显示原始内容 ===
        if ($ajaxEarlyAction === 'ajax_md5_diagnose') {
            @set_time_limit(30);
            $targetUrl = trim($_POST['url'] ?? '');
            if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                echo json_encode(['code' => 400, 'error' => '请输入有效的 URL'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $result = MD5PatternCleaner::resolveM3U8FromUrl($targetUrl);
            if ($result === false) {
                echo json_encode(['code' => 500, 'error' => '无法获取 M3U8，请检查 URL 是否正确（需要 .m3u8 结尾的地址）'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $content = $result['content'];
            $finalUrl = $result['final_url'] ?? $targetUrl;

            // 解析片段列表
            $parsed = $md5->parseM3U8($content);
            $segments = $parsed['segments'];
            $total = count($segments);

            echo json_encode([
                'code' => 200,
                'url' => $finalUrl,
                'header' => $parsed['header'],
                'total_segments' => $total,
                'has_endlist' => $parsed['has_end'],
                'segments_preview' => array_slice($segments, 0, 20, true),
                'raw_first_2000' => substr($content, 0, 2000),
                'domain' => parse_url($finalUrl, PHP_URL_SCHEME) . '://' . parse_url($finalUrl, PHP_URL_HOST),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === MD5 诊断工具：单独下载并分析某个 TS 片段 ===
        if ($ajaxEarlyAction === 'ajax_md5_fetch_segment') {
            @set_time_limit(30);
            $m3u8Url = trim($_POST['m3u8_url'] ?? '');
            $segmentIndex = (int)($_POST['segment_index'] ?? 0);
            $segmentUri = trim($_POST['segment_uri'] ?? '');

            if ($m3u8Url === '' || $segmentIndex < 0) {
                echo json_encode(['code' => 400, 'error' => '参数不完整'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 如果没提供 uri，从 m3u8 解析
            if ($segmentUri === '') {
                $m3u8Content = MD5PatternCleaner::downloadM3U8($m3u8Url);
                if ($m3u8Content === false) {
                    echo json_encode(['code' => 500, 'error' => '无法下载 M3U8'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $parsed = $md5->parseM3U8($m3u8Content);
                $segments = $parsed['segments'];
                if (!isset($segments[$segmentIndex])) {
                    echo json_encode(['code' => 400, 'error' => '片段索引超出范围（总共 ' . count($segments) . ' 段）'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $segmentUri = $segments[$segmentIndex]['uri'];
            }

            $fullUrl = MD5PatternCleaner::resolveUrl($m3u8Url, $segmentUri);

            // 下载片段
            $ch = curl_init($fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            $size = strlen($data);
            $md5hash = $size > 0 ? md5($data) : '';

            // 查询数据库
            $status = null;
            if ($md5hash) {
                $status = $md5DB->queryBatch([$md5hash])[$md5hash] ?? null;
            }

            echo json_encode([
                'code' => 200,
                'segment_index' => $segmentIndex,
                'uri' => $segmentUri,
                'full_url' => $fullUrl,
                'http_code' => $httpCode,
                'size' => $size,
                'md5' => $md5hash,
                'curl_error' => $curlErr,
                'db_status' => $status,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === MD5 诊断工具：手动标记某个 MD5 为广告/正片 ===
        if ($ajaxEarlyAction === 'ajax_md5_manual_mark') {
            $md5hash = strtolower(trim($_POST['md5'] ?? ''));
            $isAd = (bool)($_POST['is_ad'] ?? true);
            $reason = trim($_POST['reason'] ?? '人工标记');

            if ($md5hash === '' || !preg_match('/^[a-f0-9]{32}$/', $md5hash)) {
                echo json_encode(['code' => 400, 'error' => '无效的 MD5'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($isAd) {
                $md5DB->markAsAd($md5hash, true, $reason);
            } else {
                $md5DB->addToWhitelist($md5hash, $reason);
            }

            echo json_encode([
                'code' => 200,
                'md5' => $md5hash,
                'is_ad' => $isAd,
                'marked' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === MD5 深度分析：聚类二次分析（识别广告集群、比特率异常等）===
        if ($ajaxEarlyAction === 'ajax_md5_deep_cluster') {
            $segmentsJson = $_POST['segments'] ?? '';
            $totalSegments = (int)($_POST['total_segments'] ?? 0);
            $m3u8Content = $_POST['m3u8_content'] ?? '';
            $m3u8Url = trim($_POST['m3u8_url'] ?? '');

            $allSegments = json_decode($segmentsJson, true);
            if (!is_array($allSegments)) {
                echo json_encode(['code' => 400, 'error' => '片段数据格式错误'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $clusterResult = $md5->deepClusterAnalyze($allSegments, $totalSegments);

            $cleanM3u8 = '';
            $deepAnalysis = null;
            if (!empty($m3u8Content) && !empty($m3u8Url)) {
                $adIndexes = [];
                foreach ($clusterResult['refined_segments'] as $seg) {
                    if (!empty($seg['is_ad'])) {
                        $adIndexes[$seg['index']] = true;
                    }
                }
                $cleanM3u8 = $md5->buildCleanM3U8ByIndex($m3u8Content, $m3u8Url, $adIndexes);

                $deepAnalysis = $md5->deepAnalysisWithCommercials($m3u8Content, $m3u8Url, $clusterResult['refined_segments']);
            }

            echo json_encode([
                'code' => 200,
                'ad_clusters' => $clusterResult['ad_clusters'],
                'refined_segments' => $clusterResult['refined_segments'],
                'analysis_summary' => $clusterResult['analysis_summary'],
                'ad_count' => $clusterResult['ad_count'],
                'ad_duration' => $clusterResult['ad_duration'],
                'total_duration' => $clusterResult['total_duration'],
                'clean_m3u8' => $cleanM3u8,
                'deep_analysis' => $deepAnalysis,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // === 万能规则2 - 批量解析特征学习：测试 / 统计 / 学习 / 标记 ===
        if ($ajaxEarlyAction === 'ajax_feat_test' || $ajaxEarlyAction === 'ajax_feat_stats'
            || $ajaxEarlyAction === 'ajax_feat_learn' || $ajaxEarlyAction === 'ajax_feat_mark') {
            require_once __DIR__ . '/algorithms/pattern_feature_cleaner.php';
            $feat = new PatternFeatureCleaner($noadConfig);
            $featDB = $feat->getFeatureDB();

            if ($ajaxEarlyAction === 'ajax_feat_test') {
                $testUrl = trim($_POST['test_url'] ?? '');
                if ($testUrl === '' || !filter_var($testUrl, FILTER_VALIDATE_URL)) {
                    echo json_encode(['code' => 400, 'error' => '请输入有效的视频 URL'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $results = $feat->batchParse($testUrl);
                $report = "🧪 万能规则2 测试完成\n";
                $report .= "测试 URL：$testUrl\n";
                $report .= "成功解析源：" . count($results) . " 个\n";
                foreach ($results as $r) {
                    list($srcName, $segUrls, $domains, $paths) = $r;
                    $report .= "\n  · $srcName：" . count($segUrls) . " 片段, " . count($domains) . " 域名\n";
                    $report .= "    域名: " . implode(', ', array_slice($domains, 0, 3)) . "\n";
                }
                if (count($results) >= 2) {
                    $learned = $featDB->learn($testUrl, $results, 2);
                    $report .= "\n✅ 学习完成：识别 " . ($learned['content_domains'] ?? 0) . " 个正片域名, " . ($learned['ad_domains'] ?? 0) . " 个广告域名\n";
                } else {
                    $report .= "\n⚠️  解析源不足2个，未触发学习\n";
                }
                echo json_encode(['code' => 200, 'report' => $report], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($ajaxEarlyAction === 'ajax_feat_stats') {
                $stats = $featDB->getStats();
                $report = "📊 万能规则2 - 特征库统计\n";
                if (!empty($stats['enabled'])) {
                    $report .= "正片域名：" . ($stats['content_domains'] ?? 0) . " 个\n";
                    $report .= "广告域名：" . ($stats['ad_domains'] ?? 0) . " 个\n";
                    $report .= "路径模式：" . ($stats['total_paths'] ?? 0) . " 条\n";
                    $report .= "片段特征：" . ($stats['total_segments'] ?? 0) . " 条\n";
                    $report .= "数据库大小：" . ($stats['db_size_kb'] ?? 0) . " KB\n";
                    $contentDomains = $featDB->getContentDomains();
                    $adDomains = $featDB->getAdDomains();
                    if (!empty($contentDomains)) $report .= "\n正片域名示例: " . implode(', ', array_slice($contentDomains, 0, 5));
                    if (!empty($adDomains)) $report .= "\n广告域名示例: " . implode(', ', array_slice($adDomains, 0, 5));
                } else {
                    $report .= "特征数据库暂未初始化\n";
                }
                echo json_encode(['code' => 200, 'report' => $report], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($ajaxEarlyAction === 'ajax_feat_learn') {
                $testUrl = trim($_POST['test_url'] ?? '');
                if ($testUrl === '' || !filter_var($testUrl, FILTER_VALIDATE_URL)) {
                    echo json_encode(['code' => 400, 'error' => '请输入有效的视频 URL'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $results = $feat->batchParse($testUrl);
                if (count($results) >= 2) {
                    $learned = $featDB->learn($testUrl, $results, 2);
                    $report = "✅ 学习完成！\n";
                    $report .= "正片域名：" . ($learned['content_domains'] ?? 0) . " 个\n";
                    $report .= "广告域名：" . ($learned['ad_domains'] ?? 0) . " 个\n";
                    $report .= "正片片段：" . ($learned['content_segments'] ?? 0) . " 条\n";
                    $report .= "广告片段：" . ($learned['ad_segments'] ?? 0) . " 条\n";
                    echo json_encode(['code' => 200, 'report' => $report, 'learned' => $learned], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['code' => 400, 'error' => '解析源不足2个，无法学习'], JSON_UNESCAPED_UNICODE);
                }
                exit;
            }

            if ($ajaxEarlyAction === 'ajax_feat_mark') {
                $domain = strtolower(trim($_POST['domain'] ?? ''));
                $type = trim($_POST['type'] ?? 'unknown');
                if ($domain === '' || !preg_match('/^[a-z0-9\.\-]+$/i', $domain)) {
                    echo json_encode(['code' => 400, 'error' => '无效域名'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if (!in_array($type, ['content', 'ad', 'unknown'])) {
                    echo json_encode(['code' => 400, 'error' => '无效类型'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $featDB->markDomain($domain, $type);
                $typeName = $type === 'content' ? '正片' : ($type === 'ad' ? '广告' : '未知');
                echo json_encode(['code' => 200, 'domain' => $domain, 'type' => $type, 'type_name' => $typeName], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    exit;
}

// ========= 未登录 → 显示登录页 =========
if (!$isLoggedIn) {
    renderLoginPage($loginError ?? '');
    exit;
}

// ========= 登录后：数据库 =========
$db = null;
if (!empty($noadConfig['noad_enabled']) && !empty($noadConfig['stats_enabled']) &&
    extension_loaded('pdo_sqlite')) {
    try {
        require_once __DIR__ . '/core/Database.php';
        $db = Database::getInstance($noadConfig['sqlite_path']);
    } catch (Exception $e) { $db = null; }
}

// ========= 动作处理 =========
$action = $_POST['action'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

if ($action === 'save_api') {
    $newApis = [];
    $names = $_POST['api_name'] ?? [];
    $urls = $_POST['api_url'] ?? [];
    $timeouts = $_POST['api_timeout'] ?? [];
    for ($i = 0; $i < count($names); $i++) {
        $n = trim($names[$i] ?? '');
        $u = trim($urls[$i] ?? '');
        $t = (int)($timeouts[$i] ?? 5);
        if ($n !== '' && $u !== '' && $t > 0) $newApis[$n] = $u . '|' . $t;
    }
    writePhpFile(__DIR__ . '/config/api.php', buildPhpReturnArray($newApis));
    $apiConfig = require __DIR__ . '/config/api.php';
    $msg = 'API 配置已保存';
}
elseif ($action === 'save_platform') {
    $newPlats = [];
    $names = $_POST['platform_name'] ?? [];
    $rules = $_POST['platform_rule'] ?? [];
    for ($i = 0; $i < count($names); $i++) {
        $n = trim($names[$i] ?? '');
        $r = trim($rules[$i] ?? '');
        if ($n !== '' && $r !== '') $newPlats[$n] = $r;
    }
    writePhpFile(__DIR__ . '/config/platform.php', buildPhpReturnArray($newPlats));
    $platformCfg = require __DIR__ . '/config/platform.php';
    $msg = '平台规则已保存';
}
elseif ($action === 'save_switch') {
    $newSw = [
        'enable_global_api' => isset($_POST['enable_global_api']),
        'zjk_file_path'     => trim($_POST['zjk_file_path'] ?? 'ZJK.txt'),
        'global_api_timeout'=> max(1, (int)($_POST['global_api_timeout'] ?? 8)),
        'global_api_count'  => max(0, (int)($_POST['global_api_count'] ?? 6)),
        'enable_zjk_apis'   => isset($_POST['enable_zjk_apis']),
        'enable_m3u8_direct'=> isset($_POST['enable_m3u8_direct']),
        'enable_unified_display' => isset($_POST['enable_unified_display']),
    ];
    $content = "<?php\nreturn array(\n";
    foreach ($newSw as $k => $v) {
        if (is_bool($v)) $content .= "    '$k' => " . ($v ? 'true' : 'false') . ",\n";
        elseif (is_string($v)) $content .= "    '$k' => '" . addslashes($v) . "',\n";
        else $content .= "    '$k' => $v,\n";
    }
    $content .= ");\n";
    writePhpFile(__DIR__ . '/config/switch.php', $content);
    $switchConfig = require __DIR__ . '/config/switch.php';
    $msg = '系统开关已保存';
}
elseif ($action === 'save_zjk') {
    file_put_contents(__DIR__ . '/ZJK.txt', $_POST['zjk_content'] ?? '');
    $msg = 'ZJK.txt 已保存';
}
elseif ($action === 'change_password') {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if (md5($old) !== $adminConfig['admin_password']) { $msg = '原密码错误'; $msgType = 'error'; }
    elseif (strlen($new) < 6) { $msg = '新密码至少6位'; $msgType = 'error'; }
    elseif ($new !== $conf) { $msg = '两次密码不一致'; $msgType = 'error'; }
    else {
        $newAdmin = $adminConfig;
        $newAdmin['admin_password'] = md5($new);
        saveAdminConfig($newAdmin);
        $adminConfig = require __DIR__ . '/config/admin.php';
        $msg = '密码修改成功';
    }
}
elseif ($action === 'change_path') {
    $newPath = trim($_POST['new_path'] ?? '');
    if ($newPath === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $newPath)) {
        $msg = '路径格式不正确'; $msgType = 'error';
    } elseif ($newPath === 'index.php') { $msg = '不能命名为 index.php'; $msgType = 'error'; }
    else {
        $newAdmin = $adminConfig;
        $newAdmin['admin_path'] = $newPath;
        saveAdminConfig($newAdmin);
        if ($newPath !== $currentScript && @rename(__DIR__ . '/' . $currentScript, __DIR__ . '/' . $newPath)) {
            header('Location: ' . htmlspecialchars($newPath)); exit;
        }
        $msg = '后台路径已更新';
    }
}
elseif ($action === 'save_admin_config') {
    $newAdmin = $adminConfig;
    $newAdmin['admin_enabled'] = isset($_POST['admin_enabled']);
    $newAdmin['enforce_port'] = isset($_POST['enforce_port']);
    $newAdmin['session_lifetime'] = max(60, (int)($_POST['session_lifetime'] ?? 7200));
    $newAdmin['max_login_attempts'] = max(1, (int)($_POST['max_login_attempts'] ?? 5));
    $newAdmin['lockout_duration'] = max(60, (int)($_POST['lockout_duration'] ?? 300));
    $newAdmin['enable_log'] = isset($_POST['enable_log']);
    $ports = [];
    foreach (explode(',', trim($_POST['allowed_ports'] ?? '')) as $p) {
        $p = (int)trim($p);
        if ($p > 0 && $p < 65536) $ports[] = $p;
    }
    $newAdmin['allowed_ports'] = array_values(array_unique($ports));
    $ips = [];
    foreach (explode(',', trim($_POST['allowed_ips'] ?? '')) as $ip) {
        $ip = trim($ip);
        if ($ip !== '') $ips[] = $ip;
    }
    $newAdmin['allowed_ips'] = array_values(array_unique($ips));
    saveAdminConfig($newAdmin);
    $adminConfig = require __DIR__ . '/config/admin.php';
    $msg = '后台设置已保存';
}
elseif ($action === 'create_backup') {
    $backupDir = __DIR__ . '/cache/backup_' . date('Ymd_His');
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    @copy(__DIR__ . '/config/api.php', $backupDir . '/api.php');
    @copy(__DIR__ . '/config/platform.php', $backupDir . '/platform.php');
    @copy(__DIR__ . '/config/switch.php', $backupDir . '/switch.php');
    @copy(__DIR__ . '/config/admin.php', $backupDir . '/admin.php');
    if (file_exists(__DIR__ . '/config/noad.php')) @copy(__DIR__ . '/config/noad.php', $backupDir . '/noad.php');
    if (file_exists(__DIR__ . '/ZJK.txt')) @copy(__DIR__ . '/ZJK.txt', $backupDir . '/ZJK.txt');
    $msg = '备份已创建: ' . basename($backupDir);
}
elseif ($action === 'clear_log') {
    if ($db) try { $db->clearAccessLog(); } catch (Exception $e) {}
    if (file_exists(__DIR__ . '/cache/admin_log.txt')) @unlink(__DIR__ . '/cache/admin_log.txt');
    $msg = '访问日志已清空';
}
elseif ($action === 'save_noad_source' && $db) {
    $data = [
        'name'    => trim($_POST['source_name'] ?? ''),
        'url'     => trim($_POST['source_url'] ?? ''),
        'type_id' => (int)($_POST['source_type'] ?? 1),
        'timeout' => max(1, (int)($_POST['source_timeout'] ?? 8)),
        'enabled' => isset($_POST['source_enabled']) ? 1 : 0,
        'sort_order' => (int)($_POST['source_order'] ?? 0),
        'match_rules' => trim($_POST['source_match'] ?? ''),
        'remark'  => trim($_POST['source_remark'] ?? ''),
    ];
    $id = (int)($_POST['source_id'] ?? 0);
    if ($data['name'] !== '' && $data['url'] !== '') {
        if ($id > 0) { $db->updateSource($id, $data); $msg = '解析源 #' . $id . ' 已更新'; }
        else { $newId = $db->addSource($data); $msg = '解析源 #' . $newId . ' 已添加'; }
    } else { $msg = '名称和地址不能为空'; $msgType = 'error'; }
}
elseif ($action === 'delete_noad_source' && $db) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) { $db->deleteSource($id); $msg = '已删除解析源 #' . $id; }
}
elseif ($action === 'toggle_noad_source' && $db) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) { $db->toggleSource($id); $msg = '已切换解析源 #' . $id; }
}
elseif ($action === 'clear_noad_cache') {
    require_once __DIR__ . '/core/NoAdParser.php';
    $parser = new NoAdParser();
    $n = $parser->clearCache();
    $msg = '已清理 ' . $n . ' 个缓存文件';
}
elseif ($action === 'add_ad_rule' && $db) {
    $kw = trim($_POST['keyword'] ?? '');
    if ($kw !== '') { $db->addAdRule($kw); $msg = '规则已添加'; }
}
elseif ($action === 'delete_ad_rule' && $db) {
    $id = (int)($_POST['rule_id'] ?? 0);
    if ($id > 0) { $db->deleteAdRule($id); $msg = '规则已删除'; }
}
elseif ($action === 'toggle_ad_rule' && $db) {
    $id = (int)($_POST['rule_id'] ?? 0);
    if ($id > 0) { $db->toggleAdRule($id); $msg = '规则状态已切换'; }
}
elseif ($action === 'save_site' && $db) {
    $data = [
        'name'    => trim($_POST['site_name'] ?? ''),
        'short_code' => trim($_POST['site_code'] ?? ''),
        'base_url'    => trim($_POST['site_url'] ?? ''),
        'match_pattern' => trim($_POST['site_pattern'] ?? ''),
        'algorithms' => trim($_POST['site_algorithms'] ?? ''),
        'enabled' => isset($_POST['site_enabled']) ? 1 : 0,
        'remark'  => trim($_POST['site_remark'] ?? ''),
    ];
    $id = (int)($_POST['site_id'] ?? 0);
    if ($data['name'] !== '') {
        if ($id > 0) { $db->updateSite($id, $data); $msg = '站点 #' . $id . ' 已更新'; }
        else { $newId = $db->addSite($data); $msg = '站点 #' . $newId . ' 已添加'; }
    } else { $msg = '站点名称不能为空'; $msgType = 'error'; }
}
elseif ($action === 'delete_site' && $db) {
    $id = (int)($_POST['site_id'] ?? 0);
    if ($id > 0) { $db->deleteSite($id); $msg = '站点已删除'; }
}
elseif ($action === 'toggle_site' && $db) {
    $id = (int)($_POST['site_id'] ?? 0);
    if ($id > 0) { $db->toggleSite($id); $msg = '站点状态已切换'; }
}
elseif ($action === 'clear_parse_log' && $db) {
    $db->clearParseLog();
    $msg = '解析日志已清空';
}
elseif ($action === 'save_noad_config') {
    $newCfg = $noadConfig;
    $newCfg['noad_enabled'] = isset($_POST['noad_enabled']);
    $newCfg['enable_ad_filter'] = isset($_POST['enable_ad_filter']);
    $newCfg['cache_enabled'] = isset($_POST['cache_enabled']);
    $newCfg['enable_multi_source'] = isset($_POST['enable_multi_source']);
    $newCfg['stats_enabled'] = isset($_POST['stats_enabled']);
    $newCfg['cache_ttl'] = max(60, (int)($_POST['cache_ttl'] ?? 1800));
    $newCfg['max_source_try'] = max(1, (int)($_POST['max_source_try'] ?? 3));
    $newCfg['request_timeout'] = max(1, (int)($_POST['request_timeout'] ?? 10));
    $newCfg['ad_keyword_threshold'] = max(1, (int)($_POST['ad_keyword_threshold'] ?? 2));
    $newCfg['debug_mode'] = isset($_POST['debug_mode']);
    // ===== 如意算法参数保存 =====
    $newCfg['ruyi_enabled'] = isset($_POST['ruyi_enabled']);
    $newCfg['ruyi_score_threshold'] = max(1, (int)($_POST['ruyi_score_threshold'] ?? 4));
    $newCfg['ruyi_baseline_sec'] = max(0.5, round((float)($_POST['ruyi_baseline_sec'] ?? 4.00), 2));
    $newCfg['ruyi_baseline_tolerance'] = max(0.01, round((float)($_POST['ruyi_baseline_tolerance'] ?? 0.10), 2));
    $newCfg['ruyi_min_cluster_len'] = max(1, (int)($_POST['ruyi_min_cluster_len'] ?? 3));
    $newCfg['ruyi_max_cluster_len'] = max($newCfg['ruyi_min_cluster_len'], (int)($_POST['ruyi_max_cluster_len'] ?? 15));
    $newCfg['ruyi_min_cluster_sum'] = max(5.0, round((float)($_POST['ruyi_min_cluster_sum'] ?? 15.0), 2));
    $newCfg['ruyi_max_cluster_sum'] = max($newCfg['ruyi_min_cluster_sum'], round((float)($_POST['ruyi_max_cluster_sum'] ?? 35.0), 2));
    $newCfg['ruyi_short_seg_threshold'] = max(0.5, round((float)($_POST['ruyi_short_seg_threshold'] ?? 3.0), 2));
    $newCfg['ruyi_very_short_threshold'] = max(0.1, round((float)($_POST['ruyi_very_short_threshold'] ?? 1.5), 2));
    $newCfg['ruyi_enable_discontinuity'] = isset($_POST['ruyi_enable_discontinuity']);
    $newCfg['ruyi_auto_optimize_enabled'] = isset($_POST['ruyi_auto_optimize_enabled']);
    $newCfg['ruyi_auto_optimize_hour'] = max(0, min(23, (int)($_POST['ruyi_auto_optimize_hour'] ?? 3)));
    $newCfg['ruyi_auto_optimize_interval_hours'] = max(1, (int)($_POST['ruyi_auto_optimize_interval_hours'] ?? 24));
    $newCfg['ruyi_auto_optimize_sample_url'] = trim($_POST['ruyi_auto_optimize_sample_url'] ?? '');
    $newCfg['ruyi_debug_mode'] = isset($_POST['ruyi_debug_mode']);
    // ===== MD5 指纹去广告参数保存 =====
    $newCfg['md5_enabled'] = isset($_POST['md5_enabled']);
    $newCfg['md5_repeat_threshold'] = max(1, min(20, (int)($_POST['md5_repeat_threshold'] ?? 3)));
    $newCfg['md5_max_concurrency'] = max(1, min(20, (int)($_POST['md5_max_concurrency'] ?? 6)));
    $newCfg['md5_segment_timeout'] = max(5, min(120, (int)($_POST['md5_segment_timeout'] ?? 15)));
    $newCfg['md5_total_timeout'] = max(30, min(600, (int)($_POST['md5_total_timeout'] ?? 60)));
    $newCfg['md5_max_segment_kb'] = max(500, min(50000, (int)($_POST['md5_max_segment_kb'] ?? 5000)));
    $newCfg['md5_use_proxy'] = isset($_POST['md5_use_proxy']);
    $newCfg['md5_min_interval_ms'] = max(50, min(5000, (int)($_POST['md5_min_interval_ms'] ?? 100)));
    $newCfg['md5_auto_learn'] = isset($_POST['md5_auto_learn']);
    $newCfg['md5_db_cleanup_days'] = max(7, min(365, (int)($_POST['md5_db_cleanup_days'] ?? 30)));
    $newCfg['md5_debug'] = isset($_POST['md5_debug']);
    writePhpFile(__DIR__ . '/config/noad.php', buildNoadConfig($newCfg));
    $noadConfig = require __DIR__ . '/config/noad.php';
    $msg = 'Noad 配置（含如意算法和 MD5 指纹参数）已保存';
}

// ========= 查询数据供模板 =========
$overview = $db ? $db->getOverviewStats() : [
    'total_requests' => 0, 'today_requests' => 0, 'total_ad_removed' => 0,
    'cache_hit_rate' => 0, 'avg_response_time' => 0, 'source_count' => 0,
];
$dailyStats = $db ? $db->getDailyStats(7) : [];
$topSources = $db ? $db->getTopSources(10) : [];
$recentLogs = $db ? $db->getRecentLogs(30) : [];
$noadSources = $db ? $db->getSources(0, false) : [];
$adRules = $db ? $db->getAdRules(false) : [];
$sites = $db ? $db->getSites(false) : [];
$parseLogs = $db ? $db->getParseLog(50) : [];
$resourceTypes = $noadConfig['resource_types'] ?? defaultTypes();

$zjkFile = $switchConfig['zjk_file_path'] ?? 'ZJK.txt';
$zjkContent = '';
$zjkFullPath = __DIR__ . '/' . $zjkFile;
if (file_exists($zjkFullPath)) $zjkContent = file_get_contents($zjkFullPath);

$phpVer = PHP_VERSION;
$curlOk = extension_loaded('curl');
$writeCheck = [
    'config/api.php' => is_writable(__DIR__ . '/config/api.php'),
    'config/platform.php' => is_writable(__DIR__ . '/config/platform.php'),
    'config/switch.php' => is_writable(__DIR__ . '/config/switch.php'),
    $zjkFile => is_writable($zjkFullPath),
];

$logLines = [];
$logFile = __DIR__ . '/cache/admin_log.txt';
if (file_exists($logFile)) {
    $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($allLines) $logLines = array_slice(array_reverse($allLines), 0, 50);
}

$parsedApis = [];
foreach ($apiConfig as $name => $val) {
    $parts = explode('|', $val);
    $parsedApis[] = ['name' => $name, 'url' => rtrim($parts[0], '|'), 'timeout' => (int)($parts[1] ?? 5)];
}

// ========= 接口测试数据处理 =========
$testResults = [];
if ($page === 'test' && !empty($_GET['test_url']) && $curlOk) {
    $testUrl = $_GET['test_url'];
    $apiCount = 0;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($apiConfig as $name => $config) {
        $apiCount++;
        $parts = explode('|', $config);
        $to = (int)($parts[1] ?? 5);
        if (!empty($switchConfig['enable_global_api']) && $apiCount <= $switchConfig['global_api_count']) {
            $to = $switchConfig['global_api_timeout'];
        }
        $reqUrl = str_replace('{url}', urlencode($testUrl), $parts[0]);
        $ch = curl_init($reqUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $to);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['ch' => $ch, 'name' => $name];
    }
    $active = null;
    do { curl_multi_exec($mh, $active); curl_multi_select($mh); } while ($active > 0);
    foreach ($handles as $h) {
        $resp = curl_multi_getcontent($h['ch']);
        $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
        $time = curl_getinfo($h['ch'], CURLINFO_TOTAL_TIME);
        $valid = ($resp && $code == 200);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
        $testResults[] = ['name' => $h['name'], 'code' => $code, 'time' => $time, 'valid' => $valid];
    }
    curl_multi_close($mh);
}

// ========= 渲染登录页 =========
function renderLoginPage($loginError = '') {
    global $currentScript;
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>登录 - 沫兮万能解析后台</title>
<?php renderInlineStyles(); ?>
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <div class="login-icon">🎬</div>
        <h1>沫兮万能解析管理后台</h1>
        <div class="sub">PHP 智能线路切换 + NoAd 去广告解析 v4.5.1</div>
        <?php if (!empty($loginError)): ?><div class="err"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" placeholder="请输入管理员密码（默认 admin123）" required autofocus>
            <button type="submit" class="btn-primary" style="width:100%;">登录后台</button>
        </form>
        <div class="info">
            <strong>默认密码:</strong> admin123（登录后请立即修改）<br>
            <strong>当前路径:</strong> /<?php echo htmlspecialchars($currentScript); ?>
        </div>
        <div class="ft">MX-射手沫蝴蝶</div>
    </div>
</div>
</body>
</html>
    <?php
}

// ========= 渲染 CSS =========
function renderInlineStyles() {
    ?>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','PingFang SC','Microsoft YaHei',Arial,sans-serif;background:#f0f2f5;color:#1a1a2e;min-height:100vh}

/* ===== 现代化渐变背景 ===== */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
    --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    --purple-gradient: linear-gradient(135deg, #a855f7 0%, #6366f1 100%);
    --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
    --hover-shadow: 0 8px 30px rgba(0,0,0,0.12);
    --border-radius: 16px;
}

/* ===== 登录页面 ===== */
.login-wrap{background:var(--primary-gradient);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow:hidden}
.login-wrap::before{content:'';position:absolute;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,0.1) 0%,transparent 70%);animation:float 20s linear infinite}
@keyframes float{0%{transform:translate(-50%,-50%) rotate(0deg)}100%{transform:translate(-50%,-50%) rotate(360deg)}}
.login-box{background:white;border-radius:var(--border-radius);box-shadow:0 25px 70px rgba(0,0,0,.25);width:100%;max-width:420px;padding:48px 36px;position:relative;z-index:1}
.login-box h1{text-align:center;font-size:26px;margin-bottom:6px;color:#1a1a2e;font-weight:700}
.login-box .sub{text-align:center;color:#64748b;font-size:14px;margin-bottom:32px}
.login-box .login-icon{text-align:center;font-size:64px;margin-bottom:18px;animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.login-box input[type="password"]{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:12px;font-size:15px;margin-bottom:16px;transition:all 0.3s}
.login-box input[type="password"]:focus{border-color:#667eea;outline:none;box-shadow:0 0 0 3px rgba(102,126,234,0.1)}
.login-box .err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:14px;border-radius:12px;margin-bottom:20px;font-size:14px;text-align:center}
.login-box .info{background:#f8fafc;border-left:4px solid #667eea;padding:14px 16px;border-radius:8px;margin-top:28px;font-size:13px;color:#64748b;line-height:1.8}
.login-box .ft{text-align:center;margin-top:24px;color:#94a3b8;font-size:13px}
.btn-primary{background:var(--primary-gradient);color:#fff;border:none;padding:14px 28px;border-radius:12px;cursor:pointer;font-size:15px;font-weight:600;transition:all 0.3s}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(102,126,234,0.4)}

/* ===== 后台页面样式 ===== */
.admin-wrap{max-width:1440px;margin:0 auto;padding:24px}

/* ===== 头部导航 ===== */
.admin-header{display:flex;justify-content:space-between;align-items:center;padding:24px 32px;background:var(--primary-gradient);color:#fff;border-radius:var(--border-radius);margin-bottom:24px;flex-wrap:wrap;gap:16px;position:relative;overflow:hidden}
.admin-header::before{content:'';position:absolute;top:-50%;right:-20%;width:300px;height:300px;background:radial-gradient(circle,rgba(255,255,255,0.15) 0%,transparent 70%);border-radius:50%}
.admin-header h1{margin:0;font-size:24px;font-weight:700;position:relative}
.admin-header .sub{font-size:14px;opacity:0.9;margin-top:4px;position:relative}
.admin-header a{color:#fff;background:rgba(255,255,255,0.2);padding:10px 20px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:500;backdrop-filter:blur(10px);transition:all 0.3s;position:relative}
.admin-header a:hover{background:rgba(255,255,255,0.3);transform:translateY(-2px)}

/* ===== 徽章样式 ===== */
.badge{display:inline-flex;align-items:center;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;gap:6px}
.badge-green{background:#dcfce7;color:#166534}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-blue{background:#dbeafe;color:#1e40af}
.badge-yellow{background:#fef3c7;color:#92400e}
.badge-orange{background:#ffedd5;color:#9a3412}
.badge-purple{background:#f3e8ff;color:#7e22ce}

/* ===== 统计卡片 ===== */
.stat-card{background:var(--primary-gradient);color:#fff;padding:24px;border-radius:var(--border-radius);box-shadow:var(--card-shadow);transition:all 0.3s;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:-20%;right:-20%;width:100px;height:100px;background:rgba(255,255,255,0.1);border-radius:50%}
.stat-card:hover{transform:translateY(-4px);box-shadow:var(--hover-shadow)}
.stat-card .num{font-size:36px;font-weight:800;margin:8px 0;letter-spacing:-1px}
.stat-card .label{font-size:14px;opacity:0.9;font-weight:500}
.stat-card.v2{background:var(--success-gradient)}
.stat-card.v3{background:var(--info-gradient)}
.stat-card.v4{background:var(--purple-gradient)}
.stat-card.v5{background:var(--warning-gradient);color:#1a1a2e}
.stat-card.v6{background:var(--danger-gradient)}
.stat-card.v7{background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%)}
.stat-card.v8{background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)}

/* ===== 图表样式 ===== */
.chart-bar{height:24px;background:#e2e8f0;border-radius:12px;overflow:hidden;margin:8px 0;position:relative}
.chart-fill{height:100%;background:var(--primary-gradient);transition:width 0.6s cubic-bezier(0.4, 0, 0.2, 1);position:relative;overflow:hidden}
.chart-fill::after{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.3),transparent);animation:shimmer 2s infinite}
@keyframes shimmer{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}

/* ===== 导航标签 ===== */
.tabs-nav{display:flex;flex-wrap:wrap;gap:4px;padding:6px;margin:0 0 20px;background:white;border-radius:var(--border-radius);box-shadow:var(--card-shadow)}
.tabs-nav button{background:none;border:none;padding:12px 20px;cursor:pointer;font-size:14px;color:#64748b;font-weight:500;border-radius:10px;transition:all 0.3s;display:flex;align-items:center;gap:8px}
.tabs-nav button.active{background:var(--primary-gradient);color:#fff;box-shadow:0 4px 12px rgba(102,126,234,0.4)}
.tabs-nav button:hover:not(.active){background:#f1f5f9;color:#1e293b}

/* ===== 面板样式 ===== */
.tab-panel{display:none}
.tab-panel.active{display:block;animation:slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1)}
@keyframes slideIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

.panel{background:white;border-radius:var(--border-radius);padding:28px;margin-bottom:20px;box-shadow:var(--card-shadow);transition:all 0.3s}
.panel:hover{box-shadow:var(--hover-shadow)}
.panel h2,.panel h3{margin-top:0;margin-bottom:16px;font-size:20px;color:#1a1a2e}
.panel h2{font-size:24px;border-bottom:2px solid #e2e8f0;padding-bottom:12px}
.panel p{line-height:1.8;font-size:15px;margin:10px 0;color:#475569}

.grid-flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:20px}
.row-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}

/* ===== 数据表格 ===== */
table.data-table{width:100%;border-collapse:separate;border-spacing:0;margin-top:12px;font-size:14px;background:white;border-radius:12px;overflow:hidden}
table.data-table th{background:#f8fafc;padding:14px 16px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;font-size:13px;text-transform:uppercase;letter-spacing:0.5px}
table.data-table td{padding:14px 16px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.data-table tr:hover td{background:#f8fafc}
table.data-table tr:last-child td{border-bottom:none}
table.data-table input[type="text"],table.data-table input[type="number"]{width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;transition:all 0.3s}
table.data-table input[type="text"]:focus,table.data-table input[type="number"]:focus{border-color:#667eea;outline:none;box-shadow:0 0 0 3px rgba(102,126,234,0.1)}
table.data-table .center{text-align:center}

/* ===== 输入框样式 ===== */
input[type="text"],input[type="number"],input[type="password"],select,textarea{padding:12px 16px;border:2px solid #e2e8f0;border-radius:12px;font-size:14px;background:white;outline:none;transition:all 0.3s}
input[type="text"]:focus,input[type="number"]:focus,input[type="password"]:focus,select:focus,textarea:focus{border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.1)}
textarea{width:100%;min-height:220px;font-family:'Fira Code','Consolas',monospace;line-height:1.8;border-radius:12px}

/* ===== 按钮样式 ===== */
.btn-danger-sm{background:var(--danger-gradient);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;margin:4px;transition:all 0.3s;display:inline-flex;align-items:center;gap:6px}
.btn-secondary-sm{background:#64748b;color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;margin:4px;transition:all 0.3s;display:inline-flex;align-items:center;gap:6px}
.btn-primary-sm{background:var(--primary-gradient);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;margin:4px;transition:all 0.3s;display:inline-flex;align-items:center;gap:6px}
.btn-success-sm{background:var(--success-gradient);color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;margin:4px;transition:all 0.3s;display:inline-flex;align-items:center;gap:6px}
.btn-warning-sm{background:var(--warning-gradient);color:#1a1a2e;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;margin:4px;transition:all 0.3s;display:inline-flex;align-items:center;gap:6px}
.btn-danger-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(255,65,108,0.4)}
.btn-secondary-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(100,116,139,0.4)}
.btn-primary-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(102,126,234,0.4)}
.btn-success-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(17,153,142,0.4)}
.btn-warning-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(250,112,154,0.4)}

/* ===== 消息提示 ===== */
.msg-box{padding:16px 24px;background:#dcfce7;color:#166534;border-radius:12px;margin-bottom:20px;border:2px solid #86efac;font-weight:500;display:flex;align-items:center;gap:10px}
.msg-box::before{content:'✓';font-size:20px;font-weight:bold}
.msg-box.err{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.msg-box.err::before{content:'✕'}

/* ===== 代码样式 ===== */
code.monocode{background:#f1f5f9;padding:4px 10px;border-radius:6px;font-size:13px;font-family:'Fira Code','Consolas',monospace}

/* ===== 片段行样式 ===== */
.seg-row{padding:14px 16px;border-bottom:1px solid #f1f5f9;font-size:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;border-radius:8px;margin-bottom:4px;background:white;transition:all 0.3s}
.seg-row:hover{background:#f8fafc;transform:translateX(4px)}
.seg-row.ad{background:linear-gradient(90deg,#fee2e2,#fef2f2);color:#991b1b;border-left:4px solid #ef4444}
.seg-row .seg-idx{font-weight:700;min-width:60px;color:#667eea}
.seg-row .seg-dur{min-width:90px;color:#64748b;font-weight:500}
.seg-row .seg-time{min-width:180px;color:#475569}
.seg-row .seg-uri{flex:1;word-break:break-all;font-size:13px;color:#64748b;font-family:'Fira Code',monospace}
.seg-row .seg-reason{color:#dc2626;font-size:12px;font-weight:600;min-width:130px;background:#fee2e2;padding:4px 10px;border-radius:6px}

/* ===== 解析状态 ===== */
#parseStatus{color:#475569;font-size:14px;font-weight:500}
.parse-stat-box{background:white;border-radius:12px;padding:16px;margin-top:12px;border:2px solid #e2e8f0}

/* ===== 开关样式 ===== */
.switch{position:relative;display:inline-block;width:52px;height:28px}
.switch input{display:none}
.switch .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#e2e8f0;border-radius:28px;transition:0.4s}
.switch .slider:before{position:absolute;content:"";height:22px;width:22px;left:3px;bottom:3px;background-color:white;border-radius:50%;transition:0.4s;box-shadow:0 2px 4px rgba(0,0,0,0.2)}
.switch input:checked + .slider{background:var(--primary-gradient)}
.switch input:checked + .slider:before{transform:translateX(24px)}

/* ===== 表单行 ===== */
.form-row{display:grid;grid-template-columns:240px 1fr;gap:24px;padding:18px 0;border-bottom:1px dashed #e2e8f0;align-items:center}
.form-row:last-child{border-bottom:none}
.form-row label{color:#1e293b;font-size:15px;font-weight:600}
.form-row label small{display:block;font-size:13px;color:#64748b;margin-top:4px;line-height:1.6}
.form-row input[type="text"],.form-row input[type="number"],.form-row input[type="password"]{width:100%;max-width:600px;padding:12px 16px;border:2px solid #e2e8f0;border-radius:12px;font-size:15px}
.form-row input:focus{border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.1);outline:none}

.btn-row{display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
.btn-row.right{justify-content:flex-end}

/* ===== 卡片样式 ===== */
.card{background:white;border-radius:var(--border-radius);padding:28px;box-shadow:var(--card-shadow);margin-bottom:24px;transition:all 0.3s}
.card:hover{box-shadow:var(--hover-shadow)}
.card h3{font-size:18px;color:#1a1a2e;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #e2e8f0;font-weight:700;display:flex;align-items:center;gap:10px}

.log-area{background:#1e1e2e;color:#cdd6f4;padding:20px;border-radius:12px;font-family:'Fira Code',monospace;font-size:13px;line-height:2;max-height:600px;overflow-y:auto}
.hint{background:linear-gradient(135deg,#fef3c7,#fff7ed);padding:18px;border-radius:12px;font-size:14px;color:#92400e;line-height:1.8;margin-top:18px;border-left:4px solid #f59e0b}
.hint code{background:white;padding:4px 8px;border-radius:6px}

.info-box{background:white;border-radius:var(--border-radius);padding:24px;box-shadow:var(--card-shadow);border-left:5px solid #667eea;transition:all 0.3s}
.info-box:hover{transform:translateX(4px)}
.info-box.green{border-left-color:#11998e}
.info-box.orange{border-left-color:#f59e0b}
.info-box.red{border-left-color:#ef4444}
.info-box .label{font-size:13px;color:#64748b;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px}
.info-box .value{font-size:24px;font-weight:800;color:#1a1a2e}

/* ===== 动画效果 ===== */
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.fade-in{animation:fadeIn 0.6s ease-out}
.fade-in-delay-1{animation:fadeIn 0.6s ease-out 0.1s both}
.fade-in-delay-2{animation:fadeIn 0.6s ease-out 0.2s both}
.fade-in-delay-3{animation:fadeIn 0.6s ease-out 0.3s both}

/* ===== 响应式设计 ===== */
@media(max-width:768px){
    .tabs-nav{overflow-x:auto;flex-wrap:nowrap;padding:4px}
    .tabs-nav button{padding:10px 14px;font-size:13px;white-space:nowrap}
    .grid-flow{grid-template-columns:1fr}
    .admin-header{flex-direction:column;align-items:flex-start;padding:20px}
    .admin-header h1{font-size:20px}
    .form-row{grid-template-columns:1fr;gap:12px}
    .stat-card .num{font-size:28px}
    .btn-row{flex-direction:column}
    .btn-row button{width:100%;justify-content:center}
}
</style>
    <?php
}

// ========= 渲染管理后台主面板 =========
renderAdminPanel($page, $msg, $msgType, [
    'overview' => $overview,
    'dailyStats' => $dailyStats,
    'topSources' => $topSources,
    'recentLogs' => $recentLogs,
    'noadSources' => $noadSources,
    'adRules' => $adRules,
    'sites' => $sites,
    'parseLogs' => $parseLogs,
    'resourceTypes' => $resourceTypes,
    'parsedApis' => $parsedApis,
    'platformCfg' => $platformCfg,
    'switchConfig' => $switchConfig,
    'noadConfig' => $noadConfig,
    'adminConfig' => $adminConfig,
    'zjkFile' => $zjkFile,
    'zjkContent' => $zjkContent,
    'phpVer' => $phpVer,
    'curlOk' => $curlOk,
    'writeCheck' => $writeCheck,
    'logLines' => $logLines,
    'currentScript' => $currentScript,
    'apiConfig' => $apiConfig,
    'testResults' => $testResults,
]);

function renderAdminPanel($page, $msg, $msgType, $d) {
    extract($d);
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>沫兮万能解析 - 管理后台 v4.5.1</title>
<?php renderInlineStyles(); ?>
</head>
<body>
<div class="admin-wrap">
    <header class="admin-header">
        <div>
            <h1>🎬 沫兮万能解析管理后台</h1>
            <div class="sub">v4.5.1 · NoAd 去广告 + 智能线路切换（单文件版）</div>
        </div>
        <div style="display:flex;gap:12px;align-items:center;font-size:13px;flex-wrap:wrap">
            <span>NoAd <?php echo !empty($noadConfig['noad_enabled']) ? '<span class="badge badge-green">已启用</span>' : '<span class="badge badge-red">已关闭</span>'; ?></span>
            <span>SQLite <?php echo extension_loaded('pdo_sqlite') ? '<span class="badge badge-green">可用</span>' : '<span class="badge badge-yellow">未加载</span>'; ?></span>
            <a href="?action=logout">退出登录</a>
        </div>
    </header>

    <?php if ($msg): ?>
        <div class="msg-box <?php echo $msgType === 'error' ? 'err' : ''; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="tabs-nav" id="tabsNav">
        <button data-tab="dashboard" class="<?php echo $page==='dashboard'?'active':''; ?>">📊 仪表盘</button>
        <button data-tab="parse_config" class="<?php echo $page==='parse_config'?'active':''; ?>">🔧 解析配置</button>
        <button data-tab="ad_config" class="<?php echo $page==='ad_config'?'active':''; ?>">🚫 去广告配置</button>
        <button data-tab="algorithms" class="<?php echo $page==='algorithms'?'active':''; ?>">🧩 算法</button>
        <button data-tab="system" class="<?php echo $page==='system'?'active':''; ?>">⚙️ 系统设置</button>
    </div>

    <?php
    // ===== 1. 仪表盘 =====
    ?>
    <div class="tab-panel <?php echo $page==='dashboard'?'active':''; ?>" id="tab-dashboard">
        <h2>📊 仪表盘</h2>
        <div class="grid-flow" style="margin-bottom:20px">
            <div class="stat-card"><div class="label">累计解析请求</div><div class="num"><?php echo number_format($overview['total_requests'] ?? 0); ?></div></div>
            <div class="stat-card v2"><div class="label">今日解析</div><div class="num"><?php echo number_format($overview['today_requests'] ?? 0); ?></div></div>
            <div class="stat-card v3"><div class="label">已移除广告片段</div><div class="num"><?php echo number_format($overview['total_ad_removed'] ?? 0); ?></div></div>
            <div class="stat-card v4"><div class="label">缓存命中率</div><div class="num"><?php echo number_format($overview['cache_hit_rate'] ?? 1, 2); ?>%</div></div>
            <div class="stat-card v5"><div class="label">平均响应</div><div class="num"><?php echo number_format($overview['avg_response_time'] ?? 0, 1); ?>ms</div></div>
            <div class="stat-card v6"><div class="label">活跃解析源</div><div class="num"><?php echo (int)($overview['source_count'] ?? count($noadSources)); ?></div></div>
            <div class="stat-card v2" style="background:linear-gradient(135deg,#ff9a9e,#fad0c4);color:#333"><div class="label">API / 平台数</div><div class="num"><?php echo count($apiConfig ?? []); ?> / <?php echo count($platformCfg ?? []); ?></div></div>
            <div class="stat-card v3" style="background:linear-gradient(135deg,#a8edea,#fed6e3);color:#333"><div class="label">PHP 版本</div><div class="num" style="font-size:22px"><?php echo htmlspecialchars($phpVer); ?></div></div>
        </div>
        <div class="panel">
            <h3>🚀 快速开始</h3>
            <p><strong>📌 NoAd 去广告解析：</strong><br><code class="monocode">/index.php?url=视频播放地址&amp;type=movie|tv|variety|anime|document|sports|short</code></p>
            <p><strong>📌 直接 API：</strong><br><code class="monocode">/noad_proxy.php?mode=api&amp;url=视频播放地址</code></p>
            <p><strong>📌 M3U8 代理：</strong><br><code class="monocode">/noad_proxy.php?mode=m3u8&amp;src=https://example.com/play.m3u8</code></p>
        </div>
        <div class="panel">
            <h3>🔧 环境与权限检查</h3>
            <p>
                <?php echo $curlOk ? '<span class="badge badge-green">cURL 已启用</span>' : '<span class="badge badge-red">cURL 未启用</span>'; ?>
                <?php echo extension_loaded('pdo_sqlite') ? '<span class="badge badge-green">pdo_sqlite 已加载</span>' : '<span class="badge badge-yellow">pdo_sqlite 未加载</span>'; ?>
            </p>
            <table class="data-table">
                <thead><tr><th>文件</th><th>可写</th></tr></thead>
                <tbody>
                <?php foreach ($writeCheck as $f => $writable): ?>
                    <tr><td><?php echo htmlspecialchars($f); ?></td><td><?php echo $writable ? '<span class="badge badge-green">✅</span>' : '<span class="badge badge-red">❌</span>'; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel">
            <h3>📝 最近操作日志</h3>
            <?php if (empty($logLines)): ?>
                <p style="color:#888">暂无日志记录。</p>
            <?php else: ?>
                <div class="log-area"><?php foreach ($logLines as $line): echo htmlspecialchars($line) . '<br>'; endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 2. 解析配置 =====
    ?>
    <div class="tab-panel <?php echo $page==='parse_config'?'active':''; ?>" id="tab-parse_config">
        <h2>🔧 解析配置</h2>

        <div class="tabs-nav" id="parseConfigSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);padding:8px">
            <button data-subtab="pc_api" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">📡 解析接口管理</button>
            <button data-subtab="pc_zjk" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📝 自定义解析</button>
            <button data-subtab="pc_platform" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🎯 平台规则</button>
            <button data-subtab="pc_switch" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔀 API 设置</button>
            <button data-subtab="pc_call_logs" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📈 调用日志</button>
            <button data-subtab="pc_parse_logs" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📄 解析日志</button>
            <button data-subtab="pc_test" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🧪 接口测试</button>
        </div>

        <div class="sub-tab-panel active" id="subtab-pc_api">
            <div class="panel">
                <form method="post">
                    <input type="hidden" name="action" value="save_api">
                    <table class="data-table" id="apiTable_new">
                        <thead><tr><th>序号</th><th>接口名称</th><th>接口地址</th><th style="width:120px">超时(秒)</th><th style="width:100px">操作</th></tr></thead>
                        <tbody id="apiTbody_new">
                        <?php foreach ($parsedApis as $idx => $api): ?>
                        <tr>
                            <td style="color:#999;text-align:center"><?php echo $idx + 1; ?></td>
                            <td><input type="text" name="api_name[]" value="<?php echo htmlspecialchars($api['name']); ?>" style="width:100%"></td>
                            <td><input type="text" name="api_url[]" value="<?php echo htmlspecialchars($api['url']); ?>" style="width:100%"></td>
                            <td><input type="number" name="api_timeout[]" value="<?php echo (int)$api['timeout']; ?>" min="1" max="120" style="width:100%"></td>
                            <td class="center"><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($parsedApis) === 0): ?>
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:12px">
                        <button type="button" class="btn-secondary-sm" onclick="
                            var tb = document.getElementById('apiTbody_new');
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td style=\'color:#999;text-align:center\'>+</td><td><input type=\'text\' name=\'api_name[]\' placeholder=\'接口名称\' style=\'width:100%\'></td><td><input type=\'text\' name=\'api_url[]\' placeholder=\'https://jx.example.com/?url=\' style=\'width:100%\'></td><td><input type=\'number\' name=\'api_timeout[]\' value=\'5\' min=\'1\' max=\'120\' style=\'width:100%\'></td><td><button type=\'button\' class=\'btn-danger-sm\' onclick=\'this.closest(&quot;tr&quot;).remove()\'>删除</button></td>';
                            tb.appendChild(tr);
                        " style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_zjk" style="display:none">
            <div class="panel">
                <p style="color:#666">格式：<code class="monocode">接口地址|超时秒数</code>，每行一条。例如：<code class="monocode">https://jx.example.com/?url={url}|8</code></p>
                <form method="post">
                    <input type="hidden" name="action" value="save_zjk">
                    <textarea name="zjk_content" style="width:100%;min-height:300px;font-family:Consolas,monospace;padding:12px;font-size:13px;border:1px solid #ddd;border-radius:8px"><?php echo htmlspecialchars($zjkContent); ?></textarea>
                    <div style="margin-top:12px"><button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button></div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_platform" style="display:none">
            <div class="panel">
                <form method="post">
                    <input type="hidden" name="action" value="save_platform">
                    <table class="data-table">
                        <thead><tr><th>平台名称</th><th>匹配规则</th><th style="width:100px">操作</th></tr></thead>
                        <tbody id="platTbody_new">
                        <?php foreach ((array)$platformCfg as $name => $rule): ?>
                        <tr>
                            <td><input type="text" name="platform_name[]" value="<?php echo htmlspecialchars($name); ?>" style="width:100%"></td>
                            <td><input type="text" name="platform_rule[]" value="<?php echo htmlspecialchars($rule); ?>" style="width:100%"></td>
                            <td><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count((array)$platformCfg) === 0): ?>
                        <tr><td colspan="3" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:12px">
                        <button type="button" class="btn-secondary-sm" onclick="
                            var tb = document.getElementById('platTbody_new');
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td><input type=\'text\' name=\'platform_name[]\' placeholder=\'平台名\' style=\'width:100%\'></td><td><input type=\'text\' name=\'platform_rule[]\' placeholder=\'域名关键字|接口名\' style=\'width:100%\'></td><td><button type=\'button\' class=\'btn-danger-sm\' onclick=\'this.closest(&quot;tr&quot;).remove()\'>删除</button></td>';
                            tb.appendChild(tr);
                        " style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_switch" style="display:none">
            <form method="post">
                <input type="hidden" name="action" value="save_switch">
                <div class="panel">
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_global_api" <?php if (!empty($switchConfig['enable_global_api'])) echo 'checked'; ?>> 启用总接口并发请求</label>
                    <label style="display:block;margin:10px 0">ZJK 文件路径：<input type="text" name="zjk_file_path" value="<?php echo htmlspecialchars($switchConfig['zjk_file_path'] ?? 'ZJK.txt'); ?>" style="width:300px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">总接口超时（秒）：<input type="number" name="global_api_timeout" value="<?php echo (int)($switchConfig['global_api_timeout'] ?? 8); ?>" min="1" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">总接口并发数：<input type="number" name="global_api_count" value="<?php echo (int)($switchConfig['global_api_count'] ?? 6); ?>" min="0" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_zjk_apis" <?php if (!empty($switchConfig['enable_zjk_apis'])) echo 'checked'; ?>> 启用 ZJK.txt 自定义接口</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_m3u8_direct" <?php if (!empty($switchConfig['enable_m3u8_direct'])) echo 'checked'; ?>> M3U8 直链快速通道</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_unified_display" <?php if (!empty($switchConfig['enable_unified_display'])) echo 'checked'; ?>> 统一响应格式</label>
                    <div style="margin-top:12px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_call_logs" style="display:none">
            <?php if (!$GLOBALS['db']): ?>
                <div class="msg-box err">⚠️ SQLite 未加载或数据库不可用。</div>
            <?php else: ?>
            <div class="panel">
                <h3>📊 近 7 天请求趋势</h3>
                <?php if (empty($dailyStats)): ?>
                    <p style="color:#888">暂无数据。</p>
                <?php else:
                    $maxVal = 1;
                    foreach ($dailyStats as $d2) if (($d2['total_requests'] ?? 0) > $maxVal) $maxVal = $d2['total_requests'];
                    foreach ($dailyStats as $d2):
                        $pct = round((($d2['total_requests'] ?? 0) / $maxVal) * 100);
                        $date = substr($d2['stat_date'] ?? '??', 5);
                ?>
                <div style="margin:12px 0">
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                        <span><?php echo htmlspecialchars($date); ?></span>
                        <span>共 <strong><?php echo (int)($d2['total_requests'] ?? 0); ?></strong> · 移除广告 <strong><?php echo (int)($d2['ad_removed_count'] ?? 0); ?></strong></span>
                    </div>
                    <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="panel">
                <h3>🏆 最热门解析源（Top 10）</h3>
                <?php if (empty($topSources)): ?>
                    <p style="color:#888">暂无数据。</p>
                <?php else:
                    $mTop = max(1, max(array_column($topSources, 'use_count')));
                    foreach ($topSources as $s):
                        $pct = round($s['use_count'] / $mTop * 100);
                ?>
                <div style="margin:12px 0">
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                        <span><?php echo htmlspecialchars($s['source_name']); ?></span>
                        <span>被使用 <strong><?php echo (int)$s['use_count']; ?></strong> 次</span>
                    </div>
                    <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%;background:linear-gradient(90deg,#f093fb,#f5576c);"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="panel">
                <h3>📝 最近访问日志</h3>
                <?php if (empty($recentLogs)): ?>
                    <p style="color:#888">暂无日志。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>时间</th><th>IP</th><th>来源</th><th>类型</th><th>广告移除</th><th>耗时</th><th>缓存</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?php echo date('H:i:s', (int)$log['access_time']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($log['source_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($log['video_type'] ?: '-'); ?></td>
                        <td><?php echo (int)($log['ad_segments_removed'] ?? 0); ?></td>
                        <td><?php echo number_format((float)($log['response_time'] ?? 0), 1); ?>ms</td>
                        <td><?php echo !empty($log['is_from_cache']) ? '<span class="badge badge-green">命中</span>' : '<span class="badge badge-blue">未</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_parse_logs" style="display:none">
            <div class="panel">
                <?php if (empty($parseLogs)): ?>
                    <p style="color:#888">暂无解析记录。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>站点</th><th>总片段</th><th>广告</th><th>保留</th></tr></thead>
                    <tbody>
                    <?php foreach ($parseLogs as $l): ?>
                    <tr>
                        <td><?php echo (int)$l['id']; ?></td>
                        <td><?php echo htmlspecialchars($l['site_name'] ?? ''); ?></td>
                        <td><?php echo (int)($l['total_segments'] ?? 0); ?></td>
                        <td style="color:#dc3545"><?php echo (int)($l['ad_segments'] ?? 0); ?></td>
                        <td style="color:#28a745"><?php echo (int)($l['keep_segments'] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="post" style="margin-top:15px" onsubmit="return confirm('确认清空所有解析日志？')">
                    <input type="hidden" name="action" value="clear_parse_log">
                    <button type="submit" class="btn-danger-sm">🗑️ 清空解析日志</button>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-pc_test" style="display:none">
            <div class="panel">
                <form method="get">
                    <input type="hidden" name="page" value="test">
                    <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap">
                        <label style="font-size:14px;color:#555">视频链接：</label>
                        <input type="text" name="test_url" value="<?php echo isset($_GET['test_url']) ? htmlspecialchars($_GET['test_url']) : ''; ?>" style="flex:1;min-width:300px;padding:10px;border:1px solid #ddd;border-radius:6px" placeholder="https://v.qq.com/x/cover/example.html">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">开始测试</button>
                    </div>
                </form>
            </div>
            <?php if (!empty($testResults)): ?>
            <div class="panel">
                <h3>📊 测试结果（<?php echo count($testResults); ?> 个接口）</h3>
                <table class="data-table">
                    <thead><tr><th>接口名称</th><th>HTTP 状态</th><th>响应时间</th><th>有效响应</th></tr></thead>
                    <tbody>
                    <?php foreach ($testResults as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td><span class="badge <?php echo $r['code'] == 200 ? 'badge-green' : 'badge-red'; ?>"><?php echo (int)$r['code']; ?></span></td>
                        <td><?php echo number_format($r['time'], 3); ?> 秒</td>
                        <td><span class="badge <?php echo $r['valid'] ? 'badge-green' : 'badge-red'; ?>"><?php echo $r['valid'] ? '✅ 成功' : '❌ 失败'; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 3. 去广告配置 =====
    ?>
    <div class="tab-panel <?php echo $page==='ad_config'?'active':''; ?>" id="tab-ad_config">
        <h2>🚫 去广告配置</h2>

        <div class="tabs-nav" id="adConfigSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#fa709a 0%,#fee140 100%);padding:8px">
            <button data-subtab="ac_md5_deep" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔍 MD5 深度分析</button>
            <button data-subtab="ac_overview" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📊 总览</button>
            <button data-subtab="ac_sites" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔗 资源站设置</button>
            <button data-subtab="ac_rules" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🚫 广告关键词规则</button>
            <button data-subtab="ac_sources" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔌 解析源管理</button>
        </div>

        <div class="sub-tab-panel active" id="subtab-ac_md5_deep">
            <?php
                $mc_ac = $noadConfig;
                $md5Enabled_ac = $mc_ac['md5_enabled'] ?? true;
                $md5Repeat_ac = $mc_ac['md5_repeat_threshold'] ?? 3;
                $md5Concur_ac = $mc_ac['md5_max_concurrency'] ?? 6;
                $md5SegTime_ac = $mc_ac['md5_segment_timeout'] ?? 15;
                $md5Total_ac = $mc_ac['md5_total_timeout'] ?? 60;
                $md5KB_ac = $mc_ac['md5_max_segment_kb'] ?? 5000;
                $md5Proxy_ac = $mc_ac['md5_use_proxy'] ?? true;
                $md5Interval_ac = $mc_ac['md5_min_interval_ms'] ?? 100;
                $md5Learn_ac = $mc_ac['md5_auto_learn'] ?? true;
                $md5Clean_ac = $mc_ac['md5_db_cleanup_days'] ?? 30;
                $md5Debug_ac = $mc_ac['md5_debug'] ?? false;
            ?>
            <!-- 深度分析输入区 -->
            <div class="panel" style="margin-bottom:20px;border:2px solid #4f46e5">
                <h3 style="margin-top:0;color:#4f46e5">🔬 MD5 深度分析 - 实时 TS 内容检测</h3>
                <p style="color:#666;font-size:13px;margin:6px 0">
                    <b>功能：</b>输入视频链接，自动下载并分析每个 TS 片段的 MD5 指纹，实时显示广告/正片判断结果，生成去广告后的完整链接。
                </p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:12px">
                    <div style="flex:2;min-width:300px">
                        <label style="font-size:13px;color:#555;display:block;margin-bottom:4px">视频/M3U8 URL</label>
                        <input type="text" id="deep_video_url" placeholder="https://example.com/video.m3u8 或视频页面地址" style="width:100%;padding:10px;font-size:14px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box">
                    </div>
                    <div style="flex:1;min-width:150px">
                        <label style="font-size:13px;color:#555;display:block;margin-bottom:4px">M3U8 URL（可选）</label>
                        <input type="text" id="deep_m3u8_url" placeholder="直接指定m3u8地址" style="width:100%;padding:10px;font-size:14px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box">
                    </div>
                    <div style="flex:1;min-width:150px">
                        <label style="font-size:13px;color:#555;display:block;margin-bottom:4px">扫描模式</label>
                        <select id="deep_scan_mode" style="width:100%;padding:10px;font-size:14px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box">
                            <option value="quick">⚡ 极速（前60段·每批20段·快速检测）</option>
                            <option value="sample">🎯 智能抽样（头尾+中间·快速定位广告）</option>
                            <option value="standard" selected>📊 标准（全部·每批30段·均衡）</option>
                            <option value="slow">🐢 稳定（全部·每批15段·低并发）</option>
                        </select>
                    </div>
                    <div style="flex:0">
                        <button type="button" onclick="md5DeepAnalyze()" id="deep_analyze_btn" style="padding:10px 24px;font-size:14px;background:#4f46e5;color:white;border:none;border-radius:6px;cursor:pointer">🚀 开始深度分析</button>
                    </div>
                </div>
            </div>

            <!-- 进度条 -->
            <div id="deep_progress" style="display:none;margin-bottom:20px">
                <div style="display:flex;justify-content:space-between;font-size:13px;color:#555;margin-bottom:6px">
                    <span id="deep_progress_text">准备中...</span>
                    <span id="deep_progress_percent">0%</span>
                </div>
                <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">
                    <div id="deep_progress_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#4f46e5,#06b6d4);border-radius:4px;transition:width 0.3s ease"></div>
                </div>
            </div>

            <!-- 错误提示区 -->
            <div id="deep_error" style="display:none;margin-bottom:20px;padding:14px 18px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#dc2626;font-size:14px"></div>

            <!-- 分析结果展示区 -->
            <div id="deep_analysis_result" style="display:none;margin-bottom:20px">
                <div class="panel" style="border:1px solid #e5e7eb">
                    <h4 style="margin-top:0;color:#059669">📊 分析统计</h4>
                    <div id="deep_stats" style="display:flex;gap:20px;flex-wrap:wrap;font-size:14px"></div>
                </div>
                <div id="deep_cluster_status" style="display:none;margin-top:12px;padding:12px 16px;border-radius:8px;font-size:14px"></div>
                <div id="deep_cluster_summary" style="margin-top:8px"></div>
                <div class="panel" style="border:1px solid #e5e7eb;margin-top:12px">
                    <h4 style="margin-top:0;color:#dc2626">🚫 广告片段（将被删除）</h4>
                    <div id="deep_ad_segments" style="max-height:300px;overflow-y:auto"></div>
                </div>
                <div class="panel" style="border:1px solid #e5e7eb;margin-top:12px">
                    <h4 style="margin-top:0;color:#059669">✅ 正片片段（保留）</h4>
                    <div id="deep_content_segments" style="max-height:300px;overflow-y:auto"></div>
                </div>
                <!-- 广告与插播合并分析 -->
                <div id="deep_commercial_panel" class="panel" style="border:2px solid #f97316;margin-top:12px;background:#fff7ed;display:none">
                    <h4 style="margin-top:0;color:#ea580c">📺 广告与插播合并分析</h4>
                    <div id="deep_commercial_stats" style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-bottom:12px"></div>
                    <div id="deep_commercial_breaks" style="max-height:250px;overflow-y:auto"></div>
                </div>
                <!-- TS 特征码（二次开发用） -->
                <div id="deep_signature_panel" class="panel" style="border:2px solid #8b5cf6;margin-top:12px;background:#faf5ff;display:none">
                    <h4 style="margin-top:0;color:#7c3aed">🔖 TS 特征码（二次开发用）</h4>
                    <p style="font-size:12px;color:#6b7280;margin:4px 0 12px 0">提取广告片段的 MD5 指纹、大小分布、文件名模式等特征，可用于自定义广告识别规则。</p>
                    <div id="deep_ad_signatures" style="max-height:200px;overflow-y:auto;margin-bottom:12px"></div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap">
                        <div style="flex:1;min-width:200px">
                            <b style="font-size:13px;color:#4b5563">📁 文件名模式 Top5</b>
                            <div id="deep_filename_patterns" style="font-size:12px;margin-top:6px;font-family:monospace"></div>
                        </div>
                        <div style="flex:1;min-width:200px">
                            <b style="font-size:13px;color:#4b5563">🔢 MD5 前缀分布 Top5</b>
                            <div id="deep_md5_prefixes" style="font-size:12px;margin-top:6px;font-family:monospace"></div>
                        </div>
                        <div style="flex:1;min-width:200px">
                            <b style="font-size:13px;color:#4b5563">📊 大小分布 (KB)</b>
                            <div id="deep_size_distribution" style="font-size:12px;margin-top:6px;font-family:monospace"></div>
                        </div>
                    </div>
                    <div style="margin-top:12px;text-align:right">
                        <button onclick="exportAdSignatures()" style="padding:6px 14px;font-size:12px;background:#8b5cf6;color:white;border:none;border-radius:4px;cursor:pointer">📥 导出广告特征码 JSON</button>
                    </div>
                </div>
                <div class="panel" style="border:1px solid #4f46e5;margin-top:12px;background:#f0f9ff">
                    <h4 style="margin-top:0;color:#4f46e5">🔗 去广告后的 M3U8 链接（完整域名）</h4>
                    <div style="display:flex;gap:10px;align-items:flex-start;margin-top:8px">
                        <textarea id="deep_clean_m3u8" readonly style="flex:1;height:120px;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:vertical"></textarea>
                        <button type="button" onclick="copyDeepM3u8()" style="padding:8px 16px;background:#059669;color:white;border:none;border-radius:4px;cursor:pointer;white-space:nowrap">📋 复制</button>
                    </div>
                    <div style="margin-top:8px;font-size:12px;color:#666">
                        <b>域名：</b><span id="deep_domain"></span>
                    </div>
                </div>
            </div>
            <div id="deep_loading" style="display:none;text-align:center;padding:30px;color:#666">
                <div style="font-size:16px">🧪 正在下载并分析 TS 片段，请稍候...</div>
                <div style="font-size:13px;margin-top:8px;color:#888">这可能需要几秒钟时间</div>
            </div>

            <!-- ===== MD5 诊断工具 ===== -->
            <div class="panel" style="margin-top:16px;border:2px solid #f59e0b;background:#fffbeb">
                <h3 style="margin-top:0;color:#b45309">🔍 MD5 诊断验证工具</h3>
                <p style="color:#666;font-size:13px;margin:6px 0 12px 0">
                    如果深度分析显示"全部是新片段"，用这个工具验证：① M3U8 能否正常获取 ② 片段能否下载 ③ MD5 数据库状态
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px">
                    <div style="flex:2;min-width:280px">
                        <label style="font-size:13px;color:#555;display:block;margin-bottom:4px">M3U8 URL（诊断用）</label>
                        <input type="text" id="diag_url" placeholder="https://example.com/video.m3u8" style="width:100%;padding:9px;font-size:13px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box">
                    </div>
                    <div style="flex:0">
                        <button type="button" onclick="diagM3u8()" style="padding:9px 18px;font-size:13px;background:#f59e0b;color:white;border:none;border-radius:6px;cursor:pointer">1️⃣ 解析M3U8</button>
                    </div>
                    <div style="flex:0">
                        <button type="button" onclick="diagFetchSegment()" style="padding:9px 18px;font-size:13px;background:#7c3aed;color:white;border:none;border-radius:6px;cursor:pointer">2️⃣ 下载片段</button>
                    </div>
                    <div style="flex:0">
                        <button type="button" onclick="diagNextSegment()" style="padding:9px 14px;font-size:13px;background:#6b7280;color:white;border:none;border-radius:6px;cursor:pointer">▶️ 下一个片段</button>
                    </div>
                </div>
                <!-- 步骤1：M3U8解析结果 -->
                <div id="diag_step1_result" style="display:none;margin-top:12px"></div>
                <!-- 步骤2：片段下载结果 -->
                <div id="diag_step2_result" style="display:none;margin-top:12px"></div>
                <!-- 步骤3：手动标记 -->
                <div id="diag_step3" style="display:none;margin-top:12px;padding:12px;background:#f3f4f6;border-radius:6px">
                    <h4 style="margin:0 0 8px 0;color:#374151;font-size:14px">3️⃣ 手动标记（根据上面结果判断）</h4>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <span style="font-size:13px;color:#555">MD5：</span>
                        <input type="text" id="diag_mark_md5" readonly style="flex:1;min-width:200px;padding:6px 8px;font-size:13px;border:1px solid #ddd;border-radius:4px;font-family:monospace">
                        <select id="diag_mark_type" style="padding:6px 8px;font-size:13px;border:1px solid #ddd;border-radius:4px">
                            <option value="ad">🚫 标记为广告</option>
                            <option value="content">✅ 标记为正片</option>
                        </select>
                        <input type="text" id="diag_mark_reason" placeholder="原因（如：人工确认广告片段）" style="flex:2;min-width:180px;padding:6px 8px;font-size:13px;border:1px solid #ddd;border-radius:4px">
                        <button type="button" onclick="diagManualMark()" style="padding:7px 14px;font-size:13px;background:#059669;color:white;border:none;border-radius:4px;cursor:pointer">✅ 确认标记</button>
                    </div>
                    <div id="diag_mark_result" style="margin-top:8px;font-size:13px"></div>
                </div>
                <!-- M3U8 原始内容预览 -->
                <div id="diag_raw_content" style="display:none;margin-top:12px">
                    <h4 style="margin:0 0 6px 0;color:#374151;font-size:14px">📄 M3U8 原始内容（前2000字符）</h4>
                    <pre id="diag_raw_pre" style="background:#1f2937;color:#f9fafb;padding:12px;border-radius:6px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all"></pre>
                </div>
            </div>

            <div class="panel">
                <h3 style="margin-top:0;color:#4f46e5">🎯 MD5 指纹去广告 — 参数配置</h3>
                <p style="color:#666;font-size:13px;margin:6px 0">
                    <b>原理：</b>广告片段会在不同视频中重复出现（MD5 相同），而正片内容是唯一的。
                    通过统计 TS 片段的 MD5 指纹出现频率，自动识别并删除广告片段。
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_noad_config">
                    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">⚙️ 开关与调试</h4>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_enabled" <?php echo $md5Enabled_ac ? 'checked' : ''; ?>> 启用 MD5 指纹去广告</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_auto_learn" <?php echo $md5Learn_ac ? 'checked' : ''; ?>> 启用自动学习（记录新指纹）</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_use_proxy" <?php echo $md5Proxy_ac ? 'checked' : ''; ?>> 使用代理池下载</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_debug" <?php echo $md5Debug_ac ? 'checked' : ''; ?>> 调试模式</label></div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🎯 检测灵敏度</h4>
                            <div style="padding:4px 0;font-size:14px"><label>重复次数阈值：
                                <input type="number" name="md5_repeat_threshold" min="2" max="10" step="1"
                                    value="<?php echo (int)$md5Repeat_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">相同 MD5 出现 >= 此值 → 判定为广告（默认 3 次）</div>
                            </div>
                            <div style="padding:4px 0;font-size:14px"><label>单片段最大 KB：
                                <input type="number" name="md5_max_segment_kb" min="500" max="50000" step="500"
                                    value="<?php echo (int)$md5KB_ac; ?>" style="width:100px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">超过此大小跳过（防止卡死）</div>
                            </div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🛡️ 服务器保护</h4>
                            <div style="padding:4px 0;font-size:14px"><label>最大并发数：
                                <input type="number" name="md5_max_concurrency" min="1" max="20" step="1"
                                    value="<?php echo (int)$md5Concur_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">实际根据 CPU/内存自动下调（默认 6）</div>
                            </div>
                            <div style="padding:4px 0;font-size:14px"><label>单片段超时（秒）：
                                <input type="number" name="md5_segment_timeout" min="5" max="120" step="5"
                                    value="<?php echo (int)$md5SegTime_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>总处理超时（秒）：
                                <input type="number" name="md5_total_timeout" min="30" max="600" step="10"
                                    value="<?php echo (int)$md5Total_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>最小请求间隔（ms）：
                                <input type="number" name="md5_min_interval_ms" min="50" max="5000" step="50"
                                    value="<?php echo (int)$md5Interval_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">防止请求过快被封禁（默认 100ms）</div>
                            </div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🗄️ 数据库维护</h4>
                            <div style="padding:4px 0;font-size:14px"><label>自动清理周期（天）：
                                <input type="number" name="md5_db_cleanup_days" min="7" max="365" step="7"
                                    value="<?php echo (int)$md5Clean_ac; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">清理超过此天数的旧记录（默认 30 天）</div>
                            </div>
                            <div id="md5StatsPanel_ac" style="margin-top:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                                <div style="font-size:12px;color:#888">点击右侧「📊 查看指纹库统计」按钮</div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#4f46e5">💾 保存 MD5 参数</button>
                        <button type="button" class="btn-primary-sm" onclick="md5TestCurrent()" style="font-size:14px;padding:10px 24px;background:#10b981">🧪 测试当前参数</button>
                        <button type="button" class="btn-primary-sm" onclick="
                            var panel = document.getElementById('md5StatsPanel_ac');
                            var origPanel = document.getElementById('md5StatsPanel');
                            if (origPanel) panel.innerHTML = origPanel.innerHTML;
                            md5LoadStats();
                            setTimeout(function(){ if (origPanel) panel.innerHTML = origPanel.innerHTML; }, 2000);
                        " style="font-size:14px;padding:10px 24px;background:#6366f1">📊 查看指纹库统计</button>
                        <button type="button" class="btn-primary-sm" onclick="md5ResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                    </div>
                    <div id="md5Result_ac" style="margin-top:20px;padding:14px;background:#f0f9ff;border-radius:8px;color:#075985;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试...</div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-ac_overview" style="display:none">
            <?php if (!$GLOBALS['db']): ?>
                <div class="msg-box err">⚠️ SQLite 未加载或数据库不可用。</div>
            <?php else: ?>
                <div class="grid-flow" style="margin-bottom:20px">
                    <div class="stat-card"><div class="label">总解析次数</div><div class="num"><?php echo number_format($overview['total_requests'] ?? 0); ?></div></div>
                    <div class="stat-card v2"><div class="label">已移除广告片段</div><div class="num"><?php echo number_format($overview['total_ad_removed'] ?? 0); ?></div></div>
                    <div class="stat-card v3"><div class="label">平均每个视频广告</div><div class="num"><?php echo number_format(($overview['total_requests'] ?? 0) > 0 ? ($overview['total_ad_removed'] ?? 0) / ($overview['total_requests'] ?? 1) : 0, 2); ?></div></div>
                    <div class="stat-card v4"><div class="label">活跃解析源</div><div class="num"><?php echo count($noadSources); ?></div></div>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <div class="panel">
                    <h3>🎛️ 总开关</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="noad_enabled" <?php if (!empty($noadConfig['noad_enabled'])) echo 'checked'; ?>> ✅ 启用 NoAd 去广告解析</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="enable_ad_filter" <?php if (!empty($noadConfig['enable_ad_filter'])) echo 'checked'; ?>> 🚫 启用 M3U8 广告片段智能过滤</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="enable_multi_source" <?php if (!empty($noadConfig['enable_multi_source'])) echo 'checked'; ?>> 🔀 启用多源自动匹配</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="cache_enabled" <?php if (!empty($noadConfig['cache_enabled'])) echo 'checked'; ?>> 🧹 启用缓存加速</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="stats_enabled" <?php if (!empty($noadConfig['stats_enabled'])) echo 'checked'; ?>> 📈 启用访问数据统计</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="debug_mode" <?php if (!empty($noadConfig['debug_mode'])) echo 'checked'; ?>> 🐛 调试模式</label>
                    </div>
                </div>
                <div class="panel">
                    <h3>⚡ 性能参数</h3>
                    <div class="grid-flow">
                        <label>缓存有效期（秒）<br><input type="number" name="cache_ttl" value="<?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?>" min="60" max="86400" style="width:100%"></label>
                        <label>最多尝试解析源数<br><input type="number" name="max_source_try" value="<?php echo (int)($noadConfig['max_source_try'] ?? 3); ?>" min="1" max="20" style="width:100%"></label>
                        <label>单源请求超时（秒）<br><input type="number" name="request_timeout" value="<?php echo (int)($noadConfig['request_timeout'] ?? 10); ?>" min="1" max="120" style="width:100%"></label>
                        <label>关键词命中阈值<br><input type="number" name="ad_keyword_threshold" value="<?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?>" min="1" max="20" style="width:100%"></label>
                    </div>
                </div>
                <div style="margin-top:16px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存配置</button>
                    <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_noad_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button>
                </div>
            </form>
        </div>

        <div class="sub-tab-panel" id="subtab-ac_sites" style="display:none">
            <div class="panel">
                <h3>➕ 添加 / 编辑站点</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_site">
                    <input type="hidden" name="site_id" value="0">
                    <div class="grid-flow">
                        <label>名称<br><input type="text" name="site_name" placeholder="例：腾讯视频" required style="width:100%"></label>
                        <label>短代码<br><input type="text" name="site_code" placeholder="例：tencent" style="width:100%"></label>
                        <label style="grid-column:span 2">基础地址<br><input type="text" name="site_url" placeholder="https://v.qq.com" style="width:100%"></label>
                        <label style="grid-column:span 2">匹配规则（URL 中包含的关键词，逗号分隔）<br><input type="text" name="site_pattern" placeholder="v.qq.com, iqiyi.com" style="width:100%"></label>
                        <label style="grid-column:span 2">备注<br><input type="text" name="site_remark" style="width:100%"></label>
                        <label><input type="checkbox" name="site_enabled" checked> 启用</label>
                    </div>
                    <div style="margin-top:12px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存站点</button>
                    </div>
                </form>
            </div>
            <div class="panel">
                <h3>📋 现有站点（共 <?php echo count($sites); ?> 个）</h3>
                <?php if (empty($sites)): ?>
                    <p style="color:#888">暂无站点数据。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>名称</th><th>短码</th><th>地址</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($sites as $s): ?>
                    <tr>
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td><?php echo htmlspecialchars($s['short_code'] ?? ''); ?></td>
                        <td style="font-size:12px;word-break:break-all;max-width:200px"><?php echo htmlspecialchars($s['base_url'] ?? ''); ?></td>
                        <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除？');"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-ac_rules" style="display:none">
            <p style="color:#666">当前阈值：命中 <strong><?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?></strong> 条关键词的片段将被判定为广告。</p>
            <div class="panel">
                <h3>➕ 添加自定义规则</h3>
                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="action" value="add_ad_rule">
                    <input type="text" name="keyword" placeholder="例：ad_roll / promo / 片头广告" required style="flex:1;min-width:220px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">➕ 添加规则</button>
                </form>
            </div>
            <div class="panel">
                <h3>📝 默认内置规则</h3>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <?php foreach (($noadConfig['ad_keywords'] ?? []) as $kw): ?>
                        <span class="badge badge-yellow" style="font-size:13px;padding:6px 12px"><?php echo htmlspecialchars($kw); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel">
                <h3>🗃️ 自定义规则库（共 <?php echo count($adRules); ?> 条）</h3>
                <?php if (empty($adRules)): ?>
                    <p style="color:#888">暂无自定义规则。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>关键词</th><th>命中次数</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($adRules as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><code class="monocode"><?php echo htmlspecialchars($r['keyword']); ?></code></td>
                        <td><?php echo (int)($r['hit_count'] ?? 0); ?></td>
                        <td><?php echo empty($r['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                        <td>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除规则 #<?php echo (int)$r['id']; ?>？')"><input type="hidden" name="action" value="delete_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-danger-sm">删除</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-ac_sources" style="display:none">
            <div class="panel">
                <h3>➕ 添加解析源</h3>
                <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px">
                    <input type="hidden" name="action" value="add_source">
                    <input type="text" name="name" placeholder="源名称（如：如意解析）" required style="padding:10px">
                    <input type="text" name="url" placeholder="源 URL" required style="padding:10px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">➕ 添加</button>
                </form>
            </div>
            <div class="panel">
                <h3>📋 现有解析源（共 <?php echo count($noadSources); ?> 个）</h3>
                <?php if (empty($noadSources)): ?>
                    <p style="color:#888">暂无解析源。</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>名称</th><th>URL</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach ($noadSources as $src): ?>
                            <tr>
                                <td><?php echo (int)$src['id']; ?></td>
                                <td><?php echo htmlspecialchars($src['name']); ?></td>
                                <td style="max-width:400px"><code class="monocode"><?php echo htmlspecialchars(substr($src['url'], 0, 60)); ?>...</code></td>
                                <td>
                                    <form method="post" style="display:inline" onsubmit="return confirm('确认删除？')">
                                        <input type="hidden" name="action" value="delete_source">
                                        <input type="hidden" name="source_id" value="<?php echo (int)$src['id']; ?>">
                                        <button type="submit" class="btn-danger-sm">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // ===== 4. 算法 =====
    ?>
    <div class="tab-panel <?php echo $page==='algorithms'?'active':''; ?>" id="tab-algorithms">
        <h2>🧩 算法</h2>

        <div class="tabs-nav" id="algorithmsSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:8px">
            <button data-subtab="algo_list" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">📋 算法列表</button>
            <button data-subtab="algo_ruyi" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🎯 如意算法</button>
            <button data-subtab="algo_md5" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔐 MD5指纹算法</button>
            <button data-subtab="algo_feat" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🧠 万能规则2</button>
            <button data-subtab="algo_m3u8" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📼 M3U8解析测试</button>
        </div>

        <div class="sub-tab-panel active" id="subtab-algo_list">
            <div class="panel">
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="btn-primary-sm" onclick="reloadAlgorithms()" style="font-size:14px;padding:8px 18px">🔄 重新扫描算法</button>
                    <span id="algoStatus_algo" style="color:#555;font-size:13px">就绪</span>
                    <span style="margin-left:auto;color:#888;font-size:13px">共 <strong id="algoTotal_algo">0</strong> 个 · 启用 <strong id="algoEnabled_algo">0</strong> 个</span>
                </div>
                <h3 style="margin-top:20px">📋 已加载算法</h3>
                <table class="data-table" id="algoTable_algo">
                    <thead><tr><th>ID</th><th>名称</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody><tr><td colspan="5" style="text-align:center;color:#888">点击「重新扫描」加载</td></tr></tbody>
                </table>
            </div>
            <div class="panel">
                <h3>🧪 本地测试</h3>
                <textarea id="algoTestInput_algo" style="width:100%;min-height:100px;padding:10px;font-family:monospace;font-size:13px;border:1px solid #ddd;border-radius:8px" placeholder="输入文本或 URL 进行测试..."></textarea>
                <div style="margin-top:8px">
                    <label>作用域：
                        <select id="algoTestScope_algo">
                            <option value="all">全部</option>
                            <option value="url">仅 URL</option>
                            <option value="m3u8">仅 M3U8</option>
                        </select>
                    </label>
                    <button type="button" class="btn-primary-sm" onclick="
                        document.getElementById('algoTestInput').value = document.getElementById('algoTestInput_algo').value;
                        document.getElementById('algoTestScope').value = document.getElementById('algoTestScope_algo').value;
                        testAlgorithms();
                    " style="font-size:14px;padding:8px 18px">▶ 测试</button>
                </div>
                <pre id="algoTestResult_algo" style="margin-top:12px;padding:12px;background:#f8f8fa;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#333">结果将显示在此处...</pre>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-algo_ruyi" style="display:none">
            <?php
                $ry_algo = $noadConfig;
                $ruyiEnabled_algo = $ry_algo['ruyi_enabled'] ?? true;
                $ruyiScore_algo = $ry_algo['ruyi_score_threshold'] ?? 4;
                $ruyiBaseline_algo = $ry_algo['ruyi_baseline_sec'] ?? 4.00;
                $ruyiTol_algo = $ry_algo['ruyi_baseline_tolerance'] ?? 0.10;
                $ruyiMinClu_algo = $ry_algo['ruyi_min_cluster_len'] ?? 3;
                $ruyiMaxClu_algo = $ry_algo['ruyi_max_cluster_len'] ?? 15;
                $ruyiMinSum_algo = $ry_algo['ruyi_min_cluster_sum'] ?? 15.0;
                $ruyiMaxSum_algo = $ry_algo['ruyi_max_cluster_sum'] ?? 35.0;
                $ruyiShort_algo = $ry_algo['ruyi_short_seg_threshold'] ?? 3.0;
                $ruyiVeryShort_algo = $ry_algo['ruyi_very_short_threshold'] ?? 1.5;
                $ruyiDisc_algo = $ry_algo['ruyi_enable_discontinuity'] ?? true;
                $ruyiAuto_algo = $ry_algo['ruyi_auto_optimize_enabled'] ?? true;
                $ruyiHour_algo = $ry_algo['ruyi_auto_optimize_hour'] ?? 3;
                $ruyiInterval_algo = $ry_algo['ruyi_auto_optimize_interval_hours'] ?? 24;
                $ruyiSampleUrl_algo = $ry_algo['ruyi_auto_optimize_sample_url'] ?? '';
                $ruyiDebug_algo = $ry_algo['ruyi_debug_mode'] ?? false;
            ?>
            <div class="panel">
                <h3>🎯 如意去广告算法 v2.1 — 参数设置</h3>
                <p style="color:#666;font-size:13px">基于 M3U8 时长序列模式识别。默认参数平衡精准度与误删率。</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_noad_config">
                    <input type="hidden" name="noad_enabled" value="<?php echo $ry_algo['noad_enabled'] ?? true ? '1' : '0'; ?>">
                    <input type="hidden" name="enable_ad_filter" value="<?php echo $ry_algo['enable_ad_filter'] ?? true ? '1' : '0'; ?>">
                    <input type="hidden" name="cache_enabled" value="<?php echo $ry_algo['cache_enabled'] ?? true ? '1' : '0'; ?>">
                    <input type="hidden" name="enable_multi_source" value="<?php echo $ry_algo['enable_multi_source'] ?? true ? '1' : '0'; ?>">
                    <input type="hidden" name="stats_enabled" value="<?php echo $ry_algo['stats_enabled'] ?? true ? '1' : '0'; ?>">
                    <input type="hidden" name="cache_ttl" value="<?php echo (int)($ry_algo['cache_ttl'] ?? 1800); ?>">
                    <input type="hidden" name="max_source_try" value="<?php echo (int)($ry_algo['max_source_try'] ?? 3); ?>">
                    <input type="hidden" name="request_timeout" value="<?php echo (int)($ry_algo['request_timeout'] ?? 10); ?>">
                    <input type="hidden" name="ad_keyword_threshold" value="<?php echo (int)($ry_algo['ad_keyword_threshold'] ?? 2); ?>">
                    <input type="hidden" name="debug_mode" value="<?php echo $ry_algo['debug_mode'] ?? false ? '1' : '0'; ?>">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px">
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">📌 基础开关</h4>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_enabled" <?php echo $ruyiEnabled_algo ? 'checked' : ''; ?>> 启用如意算法</label></div>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_enable_discontinuity" <?php echo $ruyiDisc_algo ? 'checked' : ''; ?>> 启用 DISCONTINUITY 信号</label></div>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_debug_mode" <?php echo $ruyiDebug_algo ? 'checked' : ''; ?>> 调试模式</label></div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">🎚 Score 阈值</h4>
                            <div style="padding:6px 0;font-size:14px">
                                <label>删除判定 Score：<input type="number" name="ruyi_score_threshold" min="1" max="10" value="<?php echo (int)$ruyiScore_algo; ?>" style="width:60px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">推荐：<b>3=保守</b>，<b>4=平衡</b>，<b>5=激进</b></div>
                            </div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">📏 时长检测</h4>
                            <div style="padding:4px 0;font-size:14px"><label>基准时长（秒）：<input type="number" name="ruyi_baseline_sec" min="0.5" max="30" step="0.1" value="<?php echo number_format($ruyiBaseline_algo, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>容差：<input type="number" name="ruyi_baseline_tolerance" min="0.01" max="5" step="0.01" value="<?php echo number_format($ruyiTol_algo, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>短片段阈值：<input type="number" name="ruyi_short_seg_threshold" min="0.5" max="10" step="0.1" value="<?php echo number_format($ruyiShort_algo, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">🎯 簇检测</h4>
                            <div style="padding:4px 0;font-size:14px"><label>最小簇长度：<input type="number" name="ruyi_min_cluster_len" min="1" max="20" value="<?php echo (int)$ruyiMinClu_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>最大簇长度：<input type="number" name="ruyi_max_cluster_len" min="5" max="50" value="<?php echo (int)$ruyiMaxClu_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        </div>
                    </div>
                    <div style="margin-top:16px"><button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存如意参数</button>
                    <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_noad_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button></div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-algo_md5" style="display:none">
            <?php
                $mc_algo = $noadConfig;
                $md5Enabled_algo = $mc_algo['md5_enabled'] ?? true;
                $md5Repeat_algo = $mc_algo['md5_repeat_threshold'] ?? 3;
                $md5Concur_algo = $mc_algo['md5_max_concurrency'] ?? 6;
                $md5SegTime_algo = $mc_algo['md5_segment_timeout'] ?? 15;
                $md5Total_algo = $mc_algo['md5_total_timeout'] ?? 60;
                $md5KB_algo = $mc_algo['md5_max_segment_kb'] ?? 5000;
                $md5Proxy_algo = $mc_algo['md5_use_proxy'] ?? true;
                $md5Interval_algo = $mc_algo['md5_min_interval_ms'] ?? 100;
                $md5Learn_algo = $mc_algo['md5_auto_learn'] ?? true;
                $md5Clean_algo = $mc_algo['md5_db_cleanup_days'] ?? 30;
                $md5Debug_algo = $mc_algo['md5_debug'] ?? false;
            ?>
            <div class="panel">
                <h3 style="margin-top:0;color:#4f46e5">🎯 万能规则1 - MD5 指纹去广告算法配置</h3>
                <p style="color:#666;font-size:13px;margin:6px 0">
                    <b>原理：</b>广告片段会在不同视频中重复出现（MD5 相同），而正片内容是唯一的。
                    通过统计 TS 片段的 MD5 指纹出现频率，自动识别并删除广告片段。
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_noad_config">
                    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">⚙️ 开关与调试</h4>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_enabled" <?php echo $md5Enabled_algo ? 'checked' : ''; ?>> 启用 MD5 指纹去广告</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_auto_learn" <?php echo $md5Learn_algo ? 'checked' : ''; ?>> 启用自动学习（记录新指纹）</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_use_proxy" <?php echo $md5Proxy_algo ? 'checked' : ''; ?>> 使用代理池下载</label></div>
                            <div style="padding:4px 0;font-size:14px"><label>
                                <input type="checkbox" name="md5_debug" <?php echo $md5Debug_algo ? 'checked' : ''; ?>> 调试模式</label></div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🎯 检测灵敏度</h4>
                            <div style="padding:4px 0;font-size:14px"><label>重复次数阈值：
                                <input type="number" name="md5_repeat_threshold" min="2" max="10" step="1"
                                    value="<?php echo (int)$md5Repeat_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">相同 MD5 出现 >= 此值 → 判定为广告（默认 3 次）</div>
                            </div>
                            <div style="padding:4px 0;font-size:14px"><label>单片段最大 KB：
                                <input type="number" name="md5_max_segment_kb" min="500" max="50000" step="500"
                                    value="<?php echo (int)$md5KB_algo; ?>" style="width:100px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">超过此大小跳过（防止卡死）</div>
                            </div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🛡️ 服务器保护</h4>
                            <div style="padding:4px 0;font-size:14px"><label>最大并发数：
                                <input type="number" name="md5_max_concurrency" min="1" max="20" step="1"
                                    value="<?php echo (int)$md5Concur_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">实际根据 CPU/内存自动下调（默认 6）</div>
                            </div>
                            <div style="padding:4px 0;font-size:14px"><label>单片段超时（秒）：
                                <input type="number" name="md5_segment_timeout" min="5" max="120" step="5"
                                    value="<?php echo (int)$md5SegTime_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>总处理超时（秒）：
                                <input type="number" name="md5_total_timeout" min="30" max="600" step="10"
                                    value="<?php echo (int)$md5Total_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>最小请求间隔（ms）：
                                <input type="number" name="md5_min_interval_ms" min="50" max="5000" step="50"
                                    value="<?php echo (int)$md5Interval_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">防止请求过快被封禁（默认 100ms）</div>
                            </div>
                        </div>

                        <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                            <h4 style="margin:0 0 12px 0;color:#444">🗄️ 数据库维护</h4>
                            <div style="padding:4px 0;font-size:14px"><label>自动清理周期（天）：
                                <input type="number" name="md5_db_cleanup_days" min="7" max="365" step="7"
                                    value="<?php echo (int)$md5Clean_algo; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">清理超过此天数的旧记录（默认 30 天）</div>
                            </div>
                            <div id="md5StatsPanel_algo" style="margin-top:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                                <div style="font-size:12px;color:#888">点击右侧「📊 查看指纹库统计」按钮</div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#4f46e5">💾 保存 MD5 参数</button>
                        <button type="button" class="btn-primary-sm" onclick="md5TestCurrent()" style="font-size:14px;padding:10px 24px;background:#10b981">🧪 测试当前参数</button>
                        <button type="button" class="btn-primary-sm" onclick="
                            var panel = document.getElementById('md5StatsPanel_algo');
                            var origPanel = document.getElementById('md5StatsPanel');
                            if (origPanel) panel.innerHTML = origPanel.innerHTML;
                            md5LoadStats();
                            setTimeout(function(){ if (origPanel) panel.innerHTML = origPanel.innerHTML; }, 2000);
                        " style="font-size:14px;padding:10px 24px;background:#6366f1">📊 查看指纹库统计</button>
                        <button type="button" class="btn-primary-sm" onclick="md5ResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                    </div>
                    <div id="md5Result_algo" style="margin-top:20px;padding:14px;background:#f0f9ff;border-radius:8px;color:#075985;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试...</div>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-algo_feat" style="display:none">
            <h3 style="margin-top:0;color:#10b981">核心原理</h3>
            <p style="color:#666;font-size:13px;margin:6px 0;line-height:1.6">
                <b>为什么能去广告？</b>专业去广告解析接口（如 jx.playerjy.com 等）返回的视频内容已经去除了广告片段。
                通过向<b>多个</b>这类接口<b>并发解析同一个视频</b>，<b>对比它们返回的结果</b>，
                出现在<b>多数解析源中的域名/片段</b>就是<b>正片内容</b>，
                只出现在<b>少数/单个解析源中的域名/片段</b>就是<b>广告内容</b>。
                <br><br>
                <b>优点：</b>不需要下载 TS 文件、自动学习、跨资源站通用、与 MD5 规则互补。
                <b>低资源优化：</b>默认并发=2、最多调用3个解析源、采样分析、超时保护，差服务器也能跑。
            </p>
            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <div class="grid-2">
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">⚙️ 核心开关</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_enabled" <?php if ($featEnabled) echo 'checked'; ?>> 启用万能规则2（批量解析特征学习）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_learn_enabled" <?php if ($featLearnEnabled) echo 'checked'; ?>> 启用自动学习（每次解析自动记录新特征）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_low_resource_mode" <?php if ($featLowResource) echo 'checked'; ?>> 低资源模式（默认开启，差服务器必备）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_use_proxy" <?php if ($featUseProxy) echo 'checked'; ?>> 启用代理池（降低 IP 被封禁风险）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_debug" <?php if ($featDebug) echo 'checked'; ?>> 调试模式（返回详细学习信息）</label>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🎯 识别精度</h4>
                        <div style="padding:4px 0;font-size:14px"><label>最少投票数：
                            <input type="number" name="feat_min_votes" min="2" max="5" step="1"
                                value="<?php echo (int)$featMinVotes; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">至少几个解析源一致才信任（默认2，越大越保守）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>采样片段数：
                            <input type="number" name="feat_sample_count" min="5" max="100" step="5"
                                value="<?php echo (int)$featSampleCount; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">对每视频采样前 N 段学习（减少数据库写入）</div>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🛡️ 服务器保护</h4>
                        <div style="padding:4px 0;font-size:14px"><label>最大解析源数：
                            <input type="number" name="feat_max_sources" min="2" max="10" step="1"
                                value="<?php echo (int)$featMaxSources; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">最多调用多少个解析源（默认3，差服务器建议2）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>最大并发数：
                            <input type="number" name="feat_max_concurrency" min="1" max="10" step="1"
                                value="<?php echo (int)$featMaxConcurrency; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">并发请求上限（默认2，差服务器建议1）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>单源超时（秒）：
                            <input type="number" name="feat_source_timeout" min="5" max="120" step="5"
                                value="<?php echo (int)$featSourceTimeout; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        <div style="padding:4px 0;font-size:14px"><label>总处理超时（秒）：
                            <input type="number" name="feat_total_timeout" min="30" max="600" step="10"
                                value="<?php echo (int)$featTotalTimeout; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🧠 手动标记工具</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label>手动标记域名：<br>
                                <input type="text" name="mark_domain" placeholder="例：ad.example.com" style="width:240px;padding:6px;border:1px solid #ddd;border-radius:4px;margin-top:4px">
                                <select name="mark_type" style="width:100px;padding:6px;border:1px solid #ddd;border-radius:4px;margin-left:4px">
                                    <option value="content">正片</option>
                                    <option value="ad">广告</option>
                                    <option value="unknown">未知</option>
                                </select>
                                <button type="button" class="btn-primary-sm" onclick="featMarkDomain()" style="font-size:13px;padding:6px 14px;margin-left:4px;background:#6366f1">➕ 标记</button>
                            </label>
                        </div>
                        <div id="featStatsPanel_algo" style="margin-top:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                            <div style="font-size:12px;color:#888">点击下方「📊 查看特征库统计」查看当前特征库内容</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#10b981">💾 保存规则2参数</button>
                    <button type="button" class="btn-primary-sm" onclick="featTestCurrent()" style="font-size:14px;padding:10px 24px;background:#4f46e5">🧪 测试当前参数</button>
                    <button type="button" class="btn-primary-sm" onclick="featLearnCurrent()" style="font-size:14px;padding:10px 24px;background:#7c3aed">📚 学习特征</button>
                    <button type="button" class="btn-primary-sm" onclick="
                        var panel = document.getElementById('featStatsPanel_algo');
                        var origPanel = document.getElementById('featStatsPanel');
                        if (origPanel) panel.innerHTML = origPanel.innerHTML;
                        featLoadStats();
                        setTimeout(function(){ if (origPanel) panel.innerHTML = origPanel.innerHTML; }, 2000);
                    " style="font-size:14px;padding:10px 24px;background:#6366f1">📊 查看特征库</button>
                    <button type="button" class="btn-primary-sm" onclick="featResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                </div>
                <div id="featResult_algo" style="margin-top:20px;padding:14px;background:#ecfdf5;border-radius:8px;color:#047857;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试/学习...</div>
            </form>
        </div>

        <div class="sub-tab-panel" id="subtab-algo_m3u8" style="display:none">
            <div class="panel" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; color: white;">
                <h3 style="color: white; margin: 0 0 20px 0; font-size: 18px;">🎯 M3U8 解析测试</h3>
                <div class="row-flex" style="gap: 12px; flex-wrap: wrap;">
                    <input type="text" id="m3u8Url_algo" placeholder="输入 M3U8 链接或直接粘贴 M3U8 内容..." style="flex: 1; min-width: 320px; padding: 14px 18px; border: none; border-radius: 12px; font-size: 15px; background: rgba(255,255,255,0.95);">
                    <select id="analysisMode_algo" style="padding: 14px 18px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.95);">
                        <option value="quick">⚡ 快速分析</option>
                        <option value="deep">🔍 深度分析</option>
                        <option value="md5">🎯 MD5指纹分析</option>
                    </select>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="button" class="btn-primary-sm" onclick="
                        document.getElementById('m3u8Url').value = document.getElementById('m3u8Url_algo').value;
                        document.getElementById('analysisMode').value = document.getElementById('analysisMode_algo').value;
                        parseM3u8();
                    " style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">🔍 开始解析</button>
                    <button type="button" class="btn-primary-sm" onclick="loadSampleM3u8()" style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">📥 加载示例</button>
                </div>
                <div id="parseStatus_algo" style="margin-top: 16px; font-size: 14px; opacity: 0.9;">💡 提示：可以直接粘贴 M3U8 内容或输入 URL 地址</div>
            </div>
            <div id="parseStats_algo" style="margin-top: 24px;"></div>
        </div>
    </div>

    <?php
    // ===== 5. 系统设置 =====
    ?>
    <div class="tab-panel <?php echo $page==='system'?'active':''; ?>" id="tab-system">
        <h2>⚙️ 系统设置</h2>

        <div class="tabs-nav" id="systemNewSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#4facfe,#00f2fe);padding:8px">
            <button data-subtab="sys_backup_new" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">💾 配置备份</button>
            <button data-subtab="sys_password_new" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔐 修改密码</button>
            <button data-subtab="sys_setting_new" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">⚙️ 后台设置</button>
            <button data-subtab="sys_cache_new" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">💽 缓存管理</button>
            <button data-subtab="sys_tools_new" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🧰 工具管理</button>
        </div>

        <div class="sub-tab-panel active" id="subtab-sys_backup_new">
            <div class="panel">
                <h3>💾 配置备份与恢复</h3>
                <p style="color:#666;font-size:13px">点击下方按钮创建配置文件的备份，用于恢复或迁移。</p>
                <form method="post" style="margin-top:12px">
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">📦 创建配置备份</button>
                </form>
                <?php
                $backupDir = __DIR__ . '/cache';
                $backups = [];
                if (is_dir($backupDir)) {
                    foreach (glob($backupDir . '/backup_*.php') as $f) {
                        $backups[] = $f;
                    }
                }
                if (!empty($backups)):
                ?>
                <div style="margin-top:20px">
                    <h4>📂 已有备份（<?php echo count($backups); ?> 个）</h4>
                    <table class="data-table">
                        <thead><tr><th>文件名</th><th>大小</th><th>创建时间</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($backups) as $bk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(basename($bk)); ?></td>
                                <td><?php echo round(filesize($bk)/1024, 2); ?> KB</td>
                                <td><?php echo date('Y-m-d H:i:s', filemtime($bk)); ?></td>
                                <td><form method="post" style="display:inline" onsubmit="return confirm('确认恢复此备份？将覆盖当前配置！')"><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="backup_file" value="<?php echo htmlspecialchars(basename($bk)); ?>"><button type="submit" class="btn-secondary-sm">恢复</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-sys_password_new" style="display:none">
            <div class="panel" style="max-width:500px">
                <form method="post">
                    <input type="hidden" name="action" value="change_password">
                    <label style="display:block;margin:10px 0">当前密码<br><input type="password" name="old_password" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <label style="display:block;margin:10px 0">新密码（至少 6 位）<br><input type="password" name="new_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <label style="display:block;margin:10px 0">确认密码<br><input type="password" name="confirm_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;margin-top:10px">🔐 修改密码</button>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-sys_setting_new" style="display:none">
            <form method="post">
                <input type="hidden" name="action" value="save_admin_config">
                <div class="panel">
                    <h3>🎛️ 基础设置</h3>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="admin_enabled" <?php if (!empty($adminConfig['admin_enabled'])) echo 'checked'; ?>> ✅ 启用后台管理</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enforce_port" <?php if (!empty($adminConfig['enforce_port'])) echo 'checked'; ?>> ⚠️ 强制端口校验</label>
                    <label style="display:block;margin:10px 0">允许访问的端口（逗号分隔）：<br><input type="text" name="allowed_ports" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ports'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                    <label style="display:block;margin:10px 0">允许访问的 IP（逗号分隔）：<br><input type="text" name="allowed_ips" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ips'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                </div>
                <div class="panel">
                    <h3>🔐 安全设置</h3>
                    <label style="display:block;margin:10px 0">会话有效期（秒）：<input type="number" name="session_lifetime" value="<?php echo max(60, (int)($adminConfig['session_lifetime'] ?? 7200)); ?>" min="60" max="864000" style="width:120px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">最大登录失败次数：<input type="number" name="max_login_attempts" value="<?php echo max(1, (int)($adminConfig['max_login_attempts'] ?? 5)); ?>" min="1" max="999" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">失败锁定时长（秒）：<input type="number" name="lockout_duration" value="<?php echo max(60, (int)($adminConfig['lockout_duration'] ?? 300)); ?>" min="60" max="86400" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_log" <?php if (!empty($adminConfig['enable_log'])) echo 'checked'; ?>> 📒 启用操作日志</label>
                    <div style="margin-top:16px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存后台设置</button>
                        <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_admin_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button>
                    </div>
                </div>
            </form>

            <form method="post" style="margin-top:16px" onsubmit="return confirm('确认要修改后台入口文件名？请确保你能记住新文件名！');">
                <input type="hidden" name="action" value="change_path">
                <div class="panel">
                    <h3>🔄 修改后台入口路径</h3>
                    <p style="color:#666;font-size:13px">当前路径：<strong><?php echo htmlspecialchars($currentScript); ?></strong></p>
                    <label style="display:block;margin:10px 0">新后台文件名：<input type="text" name="new_path" placeholder="例：my_console.php" pattern="[A-Za-z0-9_\-]+\.php$" required style="width:260px;margin-left:10px"></label>
                    <button type="submit" class="btn-danger-sm" style="font-size:14px;padding:10px 24px">🔄 重命名后台入口</button>
                </div>
            </form>
        </div>

        <div class="sub-tab-panel" id="subtab-sys_cache_new" style="display:none">
            <div class="panel">
                <p>当前缓存目录：<code class="monocode"><?php echo htmlspecialchars($noadConfig['cache_dir'] ?? __DIR__ . '/cache'); ?></code></p>
                <p>缓存有效期：<strong><?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?></strong> 秒（<?php echo round(($noadConfig['cache_ttl'] ?? 1800) / 60, 1); ?> 分钟）</p>
                <form method="post" onsubmit="return confirm('确认清理所有 NoAd 缓存？')" style="margin-top:15px">
                    <input type="hidden" name="action" value="clear_noad_cache">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">🧹 清理 NoAd 缓存</button>
                </form>
            </div>
        </div>

        <div class="sub-tab-panel" id="subtab-sys_tools_new" style="display:none">
            <div class="panel" style="margin-top: 24px;">
                <h3>🔧 可用工具列表</h3>
                <div id="toolsList_new" style="min-height: 200px; padding: 16px; background: #f8fafc; border-radius: 12px;">
                    <div style="text-align: center; color: #888; padding: 60px;">
                        <div style="font-size: 56px; margin-bottom: 16px;">🔧</div>
                        <div>正在加载工具列表...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // ===== 旧版：智能解析中心（整合：M3U8 解析、资源站点、解析日志、缓存管理、解析源） =====
    ?>
    <div class="tab-panel <?php echo $page==='parse_center'?'active':''; ?>" id="tab-parse_center">
        <h2>📼 智能解析中心</h2>

        <!-- 子标签导航 -->
        <div class="tabs-nav" id="parseCenterSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:8px">
            <button data-subtab="pc_m3u8" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">📼 M3U8 解析</button>
            <button data-subtab="pc_sites" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔗 资源站点</button>
            <button data-subtab="pc_logs" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📄 解析日志</button>
            <button data-subtab="pc_cache" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">💽 缓存管理</button>
            <button data-subtab="pc_sources" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔌 解析源</button>
        </div>

        <!-- 子标签 1：M3U8 解析 -->
        <div class="sub-tab-panel active" id="subtab-pc_m3u8">
            <div class="panel" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; color: white;">
                <h3 style="color: white; margin: 0 0 20px 0; font-size: 18px;">🎯 快速分析</h3>
                <div class="row-flex" style="gap: 12px; flex-wrap: wrap;">
                    <input type="text" id="m3u8Url_2" placeholder="输入 M3U8 链接或直接粘贴 M3U8 内容..." style="flex: 1; min-width: 320px; padding: 14px 18px; border: none; border-radius: 12px; font-size: 15px; background: rgba(255,255,255,0.95);">
                    <select id="analysisMode_2" style="padding: 14px 18px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.95);">
                        <option value="quick">⚡ 快速分析</option>
                        <option value="deep">🔍 深度分析</option>
                        <option value="md5">🎯 MD5指纹分析</option>
                    </select>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="button" class="btn-primary-sm" onclick="
                        document.getElementById('m3u8Url').value = document.getElementById('m3u8Url_2').value;
                        document.getElementById('analysisMode').value = document.getElementById('analysisMode_2').value;
                        parseM3u8();
                    " style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">🔍 开始解析</button>
                    <button type="button" class="btn-primary-sm" onclick="loadSampleM3u8()" style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">📥 加载示例</button>
                </div>
                <div id="parseStatus_2" style="margin-top: 16px; font-size: 14px; opacity: 0.9;">💡 提示：可以直接粘贴 M3U8 内容或输入 URL 地址</div>
            </div>
            <div id="parseStats_2" style="margin-top: 24px;"></div>
        </div>

        <!-- 子标签 2：资源站点 -->
        <div class="sub-tab-panel" id="subtab-pc_sites" style="display:none">
            <div class="panel">
                <h3>➕ 添加 / 编辑站点</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_site">
                    <input type="hidden" name="site_id" value="0">
                    <div class="grid-flow">
                        <label>名称<br><input type="text" name="site_name" placeholder="例：腾讯视频" required style="width:100%"></label>
                        <label>短代码<br><input type="text" name="site_code" placeholder="例：tencent" style="width:100%"></label>
                        <label style="grid-column:span 2">基础地址<br><input type="text" name="site_url" placeholder="https://v.qq.com" style="width:100%"></label>
                        <label style="grid-column:span 2">匹配规则（URL 中包含的关键词，逗号分隔）<br><input type="text" name="site_pattern" placeholder="v.qq.com, iqiyi.com" style="width:100%"></label>
                        <label style="grid-column:span 2">备注<br><input type="text" name="site_remark" style="width:100%"></label>
                        <label><input type="checkbox" name="site_enabled" checked> 启用</label>
                    </div>
                    <div style="margin-top:12px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存站点</button>
                    </div>
                </form>
            </div>
            <div class="panel">
                <h3>📋 现有站点（共 <?php echo count($sites); ?> 个）</h3>
                <?php if (empty($sites)): ?>
                    <p style="color:#888">暂无站点数据。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>名称</th><th>短码</th><th>地址</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($sites as $s): ?>
                    <tr>
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td><?php echo htmlspecialchars($s['short_code'] ?? ''); ?></td>
                        <td style="font-size:12px;word-break:break-all;max-width:200px"><?php echo htmlspecialchars($s['base_url'] ?? ''); ?></td>
                        <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除？');"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- 子标签 3：解析日志 -->
        <div class="sub-tab-panel" id="subtab-pc_logs" style="display:none">
            <div class="panel">
                <?php if (empty($parseLogs)): ?>
                    <p style="color:#888">暂无解析记录。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>站点</th><th>总片段</th><th>广告</th><th>保留</th></tr></thead>
                    <tbody>
                    <?php foreach ($parseLogs as $l): ?>
                    <tr>
                        <td><?php echo (int)$l['id']; ?></td>
                        <td><?php echo htmlspecialchars($l['site_name'] ?? ''); ?></td>
                        <td><?php echo (int)($l['total_segments'] ?? 0); ?></td>
                        <td style="color:#dc3545"><?php echo (int)($l['ad_segments'] ?? 0); ?></td>
                        <td style="color:#28a745"><?php echo (int)($l['keep_segments'] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="post" style="margin-top:15px" onsubmit="return confirm('确认清空所有解析日志？')">
                    <input type="hidden" name="action" value="clear_parse_log">
                    <button type="submit" class="btn-danger-sm">🗑️ 清空解析日志</button>
                </form>
            </div>
        </div>

        <!-- 子标签 4：缓存管理 -->
        <div class="sub-tab-panel" id="subtab-pc_cache" style="display:none">
            <div class="panel">
                <p>当前缓存目录：<code class="monocode"><?php echo htmlspecialchars($noadConfig['cache_dir'] ?? __DIR__ . '/cache'); ?></code></p>
                <p>缓存有效期：<strong><?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?></strong> 秒（<?php echo round(($noadConfig['cache_ttl'] ?? 1800) / 60, 1); ?> 分钟）</p>
                <form method="post" onsubmit="return confirm('确认清理所有 NoAd 缓存？')" style="margin-top:15px">
                    <input type="hidden" name="action" value="clear_noad_cache">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">🧹 清理 NoAd 缓存</button>
                </form>
            </div>
        </div>

        <!-- 子标签 5：解析源 -->
        <div class="sub-tab-panel" id="subtab-pc_sources" style="display:none">
            <div class="panel">
                <h3>➕ 添加 / 编辑解析源</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_noad_source">
                    <input type="hidden" name="source_id" value="0">
                    <div class="grid-flow">
                        <label>名称<br><input type="text" name="source_name" placeholder="例：主源A" required style="width:100%"></label>
                        <label style="grid-column:span 2">接口地址（URL 中用 <code class="monocode">{url}</code> 作为播放页占位符）<br><input type="text" name="source_url" placeholder="https://jx.example.com/?url={url}" required style="width:100%"></label>
                        <label>资源类型<br>
                            <select name="source_type">
                                <?php foreach ($resourceTypes as $tid => $t): ?>
                                    <option value="<?php echo (int)$tid; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>超时（秒）<br><input type="number" name="source_timeout" value="8" min="1" max="60"></label>
                        <label>排序<br><input type="number" name="source_order" value="0" min="0" max="9999"></label>
                        <label style="grid-column:span 2">匹配关键词（可选）<br><input type="text" name="source_match" placeholder="例：v.qq.com" style="width:100%"></label>
                        <label style="grid-column:span 2">备注<br><input type="text" name="source_remark" style="width:100%"></label>
                        <label><input type="checkbox" name="source_enabled" checked> 启用</label>
                    </div>
                    <div style="margin-top:12px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存解析源</button>
                    </div>
                </form>
            </div>
            <div class="panel">
                <h3>📋 现有解析源（共 <?php echo count($noadSources); ?> 个）</h3>
                <?php if (empty($noadSources)): ?>
                    <p style="color:#888">⚠️ 暂未添加任何解析源。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>接口地址</th><th>超时</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($noadSources as $s):
                        $tn = $resourceTypes[(int)$s['type_id']]['name'] ?? '未分类';
                    ?>
                    <tr>
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td><?php echo htmlspecialchars($tn); ?></td>
                        <td style="word-break:break-all;max-width:260px;font-size:12px"><?php echo htmlspecialchars($s['url']); ?></td>
                        <td><?php echo (int)$s['timeout']; ?>s</td>
                        <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_noad_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除解析源 #<?php echo (int)$s['id']; ?>？');"><input type="hidden" name="action" value="delete_noad_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // ===== 2. M3U8 解析（保留内容，隐藏不显示） =====
    ?>
    <div class="tab-panel" id="tab-m3u8_parse_old" style="display:none">
        <h2>📼 M3U8 解析与去广告分析</h2>

        <!-- 分析模式切换 -->
        <div class="panel" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; color: white;">
            <h3 style="color: white; margin: 0 0 20px 0; font-size: 18px;">🎯 快速分析</h3>

            <div class="row-flex" style="gap: 12px; flex-wrap: wrap;">
                <input type="text" id="m3u8Url" placeholder="输入 M3U8 链接或直接粘贴 M3U8 内容..." style="flex: 1; min-width: 320px; padding: 14px 18px; border: none; border-radius: 12px; font-size: 15px; background: rgba(255,255,255,0.95);">

                <select id="siteId" style="padding: 14px 18px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.95);">
                    <option value="0">关联站点（可选）</option>
                </select>

                <select id="analysisMode" style="padding: 14px 18px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.95);">
                    <option value="quick">⚡ 快速分析</option>
                    <option value="deep">🔍 深度分析</option>
                    <option value="md5">🎯 MD5指纹分析</option>
                </select>
            </div>

            <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                <button type="button" class="btn-primary-sm" onclick="parseM3u8()" style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">
                    🔍 开始解析
                </button>

                <button type="button" class="btn-primary-sm" onclick="loadSampleM3u8()" style="padding: 14px 28px; font-size: 15px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; font-weight: 600;">
                    📥 加载示例
                </button>
            </div>

            <div id="parseStatus" style="margin-top: 16px; font-size: 14px; opacity: 0.9;">
                💡 提示：可以直接粘贴 M3U8 内容或输入 URL 地址
            </div>
        </div>

        <!-- 分析结果统计 -->
        <div id="parseStats" style="margin-top: 24px; display: none;">
            <div class="grid-flow">
                <div class="stat-card" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">📊 总片段数</div>
                    <div class="num" id="sTotal" style="font-size: 42px;">0</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">视频片段总数</div>
                </div>

                <div class="stat-card v6" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">🚫 广告片段</div>
                    <div class="num" id="sAd" style="font-size: 42px;">0</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">将被移除</div>
                </div>

                <div class="stat-card v4" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">✅ 保留片段</div>
                    <div class="num" id="sKeep" style="font-size: 42px;">0</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">正片内容</div>
                </div>

                <div class="stat-card v3" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">⏱️ 总时长</div>
                    <div class="num" id="sDuration" style="font-size: 36px;">0</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">视频总时长</div>
                </div>

                <div class="stat-card v5" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">📈 广告占比</div>
                    <div class="num" id="sAdRate" style="font-size: 42px;">0%</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">广告比例</div>
                </div>

                <div class="stat-card v2" style="position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div class="label">🧹 广告时长</div>
                    <div class="num" id="sAdDuration" style="font-size: 36px;">0</div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">将被过滤</div>
                </div>
            </div>

            <!-- 操作按钮 -->
            <div class="panel" style="margin-top: 20px;">
                <h3>📋 操作选项</h3>
                <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
                    <button type="button" class="btn-success-sm" onclick="copyCleanContent()" style="padding: 12px 20px; font-size: 14px;">
                        📋 复制清洗后内容
                    </button>
                    <button type="button" class="btn-primary-sm" onclick="downloadCleanM3u8()" style="padding: 12px 20px; font-size: 14px;">
                        ⬇ 下载清洗后文件
                    </button>
                    <button type="button" class="btn-secondary-sm" onclick="toggleRawContent()" style="padding: 12px 20px; font-size: 14px;">
                        👁️ 查看原始内容
                    </button>
                    <button type="button" class="btn-warning-sm" onclick="showAdDetails()" style="padding: 12px 20px; font-size: 14px;">
                        🔍 查看广告详情
                    </button>
                    <button type="button" class="btn-danger-sm" onclick="addToBlacklist()" style="padding: 12px 20px; font-size: 14px;">
                        🚫 加入黑名单
                    </button>
                </div>
            </div>

            <!-- 原始内容 -->
            <div id="rawContentPanel" style="margin-top: 20px; display: none;">
                <div class="panel">
                    <h3>📄 原始 M3U8 内容</h3>
                    <pre id="rawContent" style="margin-top: 12px; padding: 16px; background: #1e1e2e; color: #cdd6f4; border-radius: 10px; font-size: 12px; line-height: 1.8; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"></pre>
                </div>
            </div>

            <!-- 清洗后内容 -->
            <div id="cleanContentPanel" style="margin-top: 20px;">
                <div class="panel">
                    <h3>✨ 清洗后的 M3U8 内容</h3>
                    <pre id="cleanContent" style="margin-top: 12px; padding: 16px; background: #1e1e2e; color: #a6e3a1; border-radius: 10px; font-size: 12px; line-height: 1.8; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"></pre>
                </div>
            </div>

            <!-- 片段列表 -->
            <div id="segmentListPanel" style="margin-top: 20px;">
                <div class="panel">
                    <h3>📋 片段列表详情</h3>
                    <div id="segmentFilters" style="margin-bottom: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="btn-primary-sm active" onclick="filterSegments('all')" style="padding: 8px 16px;">全部</button>
                        <button class="btn-danger-sm" onclick="filterSegments('ad')" style="padding: 8px 16px;">仅广告</button>
                        <button class="btn-success-sm" onclick="filterSegments('keep')" style="padding: 8px 16px;">仅保留</button>
                    </div>
                    <div id="segmentList" style="max-height: 600px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>

        <!-- 加载状态 -->
        <div id="parseLoading" style="display: none; margin-top: 24px;">
            <div class="panel" style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 20px; animation: pulse 1.5s infinite;">🔄</div>
                <div style="font-size: 18px; color: #667eea; font-weight: 600;">正在解析中...</div>
                <div style="font-size: 14px; color: #888; margin-top: 12px;">正在分析 M3U8 内容，请稍候</div>
                <div style="margin-top: 20px;">
                    <div style="width: 200px; height: 6px; background: #e0e0e0; border-radius: 3px; margin: 0 auto; overflow: hidden;">
                        <div id="parseProgress" style="width: 0%; height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); transition: width 0.3s;"></div>
                    </div>
                    <div id="parseProgressText" style="font-size: 13px; color: #888; margin-top: 8px;">0%</div>
                </div>
            </div>
        </div>

        <style>
            @keyframes pulse {
                0%, 100% { transform: scale(1); opacity: 1; }
                50% { transform: scale(1.1); opacity: 0.8; }
            }
            .segment-item {
                padding: 12px 16px;
                margin-bottom: 8px;
                border-radius: 10px;
                background: white;
                border-left: 4px solid #e0e0e0;
                transition: all 0.3s;
            }
            .segment-item:hover {
                transform: translateX(4px);
                box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            }
            .segment-item.ad {
                background: linear-gradient(90deg, #fee2e2, #fef2f2);
                border-left-color: #ef4444;
            }
            .segment-item.keep {
                background: linear-gradient(90deg, #dcfce7, #f0fdf4);
                border-left-color: #22c55e;
            }
            .segment-index {
                font-weight: 700;
                color: #667eea;
                min-width: 60px;
                display: inline-block;
            }
            .segment-duration {
                color: #64748b;
                margin-left: 12px;
                font-weight: 500;
            }
            .segment-uri {
                font-size: 12px;
                color: #64748b;
                margin-top: 6px;
                font-family: 'Fira Code', monospace;
                word-break: break-all;
            }
            .segment-reason {
                font-size: 12px;
                color: #dc2626;
                margin-top: 6px;
                font-weight: 600;
            }
            #segmentFilters .active {
                box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
            }
        </style>
    </div>

    <?php
    // ===== 3. 资源站点 =====
    ?>
    <div class="tab-panel" id="tab-sites_old" style="display:none">
        <h2>🔗 资源站点管理</h2>
        <div class="panel">
            <h3>➕ 添加 / 编辑站点</h3>
            <form method="post" id="siteForm">
                <input type="hidden" name="action" value="save_site">
                <input type="hidden" name="site_id" id="siteIdInput" value="0">
                <div class="grid-flow">
                    <label>名称<br><input type="text" name="site_name" id="siteName" placeholder="例：腾讯视频" required style="width:100%"></label>
                    <label>短代码<br><input type="text" name="site_code" id="siteCode" placeholder="例：tencent" style="width:100%"></label>
                    <label style="grid-column:span 2">基础地址<br><input type="text" name="site_url" id="siteUrl" placeholder="https://v.qq.com" style="width:100%"></label>
                    <label style="grid-column:span 2">匹配规则（URL 中包含的关键词，逗号分隔）<br><input type="text" name="site_pattern" id="sitePattern" placeholder="v.qq.com, iqiyi.com" style="width:100%"></label>
                    <label style="grid-column:span 2">关联算法<br><input type="text" name="site_algorithms" id="siteAlgos" placeholder="alg1, alg2" style="width:100%"></label>
                    <label style="grid-column:span 2">备注<br><input type="text" name="site_remark" id="siteRemark" style="width:100%"></label>
                    <label><input type="checkbox" name="site_enabled" id="siteEnabled" checked> 启用</label>
                </div>
                <div style="margin-top:12px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存站点</button>
                    <button type="button" class="btn-secondary-sm" onclick="resetSiteForm()" style="font-size:14px;padding:8px 18px">🔄 重置</button>
                </div>
            </form>
        </div>
        <div class="panel">
            <h3>📋 现有站点（共 <?php echo count($sites); ?> 个）</h3>
            <?php if (empty($sites)): ?>
                <p style="color:#888">暂无站点数据。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>ID</th><th>名称</th><th>短码</th><th>地址</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td><?php echo (int)$s['id']; ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($s['short_code'] ?? ''); ?></td>
                    <td style="font-size:12px;word-break:break-all;max-width:200px"><?php echo htmlspecialchars($s['base_url'] ?? ''); ?></td>
                    <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                        <?php
                        $siteData = [
                            (int)$s['id'],
                            htmlspecialchars($s['name'] ?? ''),
                            htmlspecialchars($s['short_code'] ?? ''),
                            htmlspecialchars($s['base_url'] ?? ''),
                            htmlspecialchars($s['match_pattern'] ?? ''),
                            htmlspecialchars($s['remark'] ?? ''),
                            empty($s['enabled']) ? 'false' : 'true',
                        ];
                        $siteJson = json_encode($siteData, JSON_UNESCAPED_UNICODE);
                        ?>
                        <button type="button" class="btn-primary-sm" data-site='<?php echo $siteJson; ?>' onclick="var d=this.getAttribute('data-site');if(d){var a=JSON.parse(d);editSite(a[0],a[1],a[2],a[3],a[4],a[5],a[6]);}">✏️ 编辑</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除？');"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 4. 解析日志 =====
    ?>
    <div class="tab-panel" id="tab-parse_log_old" style="display:none">
        <h2>📄 解析日志</h2>
        <div class="panel">
            <?php if (empty($parseLogs)): ?>
                <p style="color:#888">暂无解析记录。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>ID</th><th>站点</th><th>总片段</th><th>广告</th><th>保留</th></tr></thead>
                <tbody>
                <?php foreach ($parseLogs as $l): ?>
                <tr>
                    <td><?php echo (int)$l['id']; ?></td>
                    <td><?php echo htmlspecialchars($l['site_name'] ?? ''); ?></td>
                    <td><?php echo (int)($l['total_segments'] ?? 0); ?></td>
                    <td style="color:#dc3545"><?php echo (int)($l['ad_segments'] ?? 0); ?></td>
                    <td style="color:#28a745"><?php echo (int)($l['keep_segments'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <form method="post" style="margin-top:15px" onsubmit="return confirm('确认清空所有解析日志？')">
                <input type="hidden" name="action" value="clear_parse_log">
                <button type="submit" class="btn-danger-sm">🗑️ 清空解析日志</button>
            </form>
        </div>
    </div>

    <?php
    // ===== 5. 缓存管理 =====
    ?>
    <div class="tab-panel" id="tab-cache_mgr_old" style="display:none">
        <h2>💽 缓存管理</h2>
        <div class="panel">
            <p>当前缓存目录：<code class="monocode"><?php echo htmlspecialchars($noadConfig['cache_dir'] ?? __DIR__ . '/cache'); ?></code></p>
            <p>缓存有效期：<strong><?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?></strong> 秒（<?php echo round(($noadConfig['cache_ttl'] ?? 1800) / 60, 1); ?> 分钟）</p>
            <form method="post" onsubmit="return confirm('确认清理所有 NoAd 缓存？')" style="margin-top:15px">
                <input type="hidden" name="action" value="clear_noad_cache">
                <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">🧹 清理 NoAd 缓存</button>
            </form>
        </div>
    </div>

    <?php
    // ===== 6. 智能去广告中心 =====
    ?>
    <div class="tab-panel <?php echo $page==='ad_center'?'active':''; ?>" id="tab-ad_center">
        <h2>📌 智能去广告中心</h2>

        <!-- 子标签导航 -->
        <div class="tabs-nav" id="adCenterSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:8px">
            <button data-subtab="ad_overview" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">📊 总览与设置</button>
            <button data-subtab="ad_sources" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔌 解析源</button>
            <button data-subtab="ad_rules" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🚫 关键词规则</button>
            <button data-subtab="ad_algorithms" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🧩 算法管理</button>
        </div>

        <!-- 子标签 1: 总览与设置 -->
        <div class="sub-tab-panel active" id="subtab-ad_overview">
            <?php if (!$GLOBALS['db']): ?>
                <div class="msg-box err">⚠️ SQLite 未加载或数据库不可用。</div>
            <?php else: ?>
                <div class="grid-flow" style="margin-bottom:20px">
                    <div class="stat-card"><div class="label">总解析次数</div><div class="num"><?php echo number_format($overview['total_requests'] ?? 0); ?></div></div>
                    <div class="stat-card v2"><div class="label">已移除广告片段</div><div class="num"><?php echo number_format($overview['total_ad_removed'] ?? 0); ?></div></div>
                    <div class="stat-card v3"><div class="label">平均每个视频广告</div><div class="num"><?php echo number_format(($overview['total_requests'] ?? 0) > 0 ? ($overview['total_ad_removed'] ?? 0) / ($overview['total_requests'] ?? 1) : 0, 2); ?></div></div>
                    <div class="stat-card v4"><div class="label">活跃解析源</div><div class="num"><?php echo count($noadSources); ?></div></div>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <div class="panel">
                    <h3>🎛️ 总开关</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="noad_enabled" <?php if (!empty($noadConfig['noad_enabled'])) echo 'checked'; ?>> ✅ 启用 NoAd 去广告解析</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="enable_ad_filter" <?php if (!empty($noadConfig['enable_ad_filter'])) echo 'checked'; ?>> 🚫 启用 M3U8 广告片段智能过滤</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="enable_multi_source" <?php if (!empty($noadConfig['enable_multi_source'])) echo 'checked'; ?>> 🔀 启用多源自动匹配</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="cache_enabled" <?php if (!empty($noadConfig['cache_enabled'])) echo 'checked'; ?>> 🧹 启用缓存加速</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="stats_enabled" <?php if (!empty($noadConfig['stats_enabled'])) echo 'checked'; ?>> 📈 启用访问数据统计</label>
                        <label style="display:block;margin:6px 0;padding:8px;background:#f8fafc;border-radius:8px"><input type="checkbox" name="debug_mode" <?php if (!empty($noadConfig['debug_mode'])) echo 'checked'; ?>> 🐛 调试模式</label>
                    </div>
                </div>
                <div class="panel">
                    <h3>⚡ 性能参数</h3>
                    <div class="grid-flow">
                        <label>缓存有效期（秒）<br><input type="number" name="cache_ttl" value="<?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?>" min="60" max="86400" style="width:100%"></label>
                        <label>最多尝试解析源数<br><input type="number" name="max_source_try" value="<?php echo (int)($noadConfig['max_source_try'] ?? 3); ?>" min="1" max="20" style="width:100%"></label>
                        <label>单源请求超时（秒）<br><input type="number" name="request_timeout" value="<?php echo (int)($noadConfig['request_timeout'] ?? 10); ?>" min="1" max="120" style="width:100%"></label>
                        <label>关键词命中阈值<br><input type="number" name="ad_keyword_threshold" value="<?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?>" min="1" max="20" style="width:100%"></label>
                    </div>
                </div>
                <div style="margin-top:16px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存配置</button>
                    <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_noad_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button>
                </div>
            </form>
        </div>

        <!-- 子标签 2: 解析源管理 -->
        <div class="sub-tab-panel" id="subtab-ad_sources" style="display:none">
            <div class="panel">
                <h3>➕ 添加解析源</h3>
                <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px">
                    <input type="hidden" name="action" value="add_source">
                    <input type="text" name="name" placeholder="源名称（如：如意解析）" required style="padding:10px">
                    <input type="text" name="url" placeholder="源 URL" required style="padding:10px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">➕ 添加</button>
                </form>
            </div>
            <div class="panel">
                <h3>📋 现有解析源（共 <?php echo count($noadSources); ?> 个）</h3>
                <?php if (empty($noadSources)): ?>
                    <p style="color:#888">暂无解析源。</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead><tr><th>ID</th><th>名称</th><th>URL</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach ($noadSources as $src): ?>
                            <tr>
                                <td><?php echo (int)$src['id']; ?></td>
                                <td><?php echo htmlspecialchars($src['name']); ?></td>
                                <td style="max-width:400px"><code class="monocode"><?php echo htmlspecialchars(substr($src['url'], 0, 60)); ?>...</code></td>
                                <td>
                                    <form method="post" style="display:inline" onsubmit="return confirm('确认删除？')">
                                        <input type="hidden" name="action" value="delete_source">
                                        <input type="hidden" name="source_id" value="<?php echo (int)$src['id']; ?>">
                                        <button type="submit" class="btn-danger-sm">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- 子标签 3: 关键词规则 -->
        <div class="sub-tab-panel" id="subtab-ad_rules" style="display:none">
            <p style="color:#666">当前阈值：命中 <strong><?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?></strong> 条关键词的片段将被判定为广告。</p>
            <div class="panel">
                <h3>➕ 添加自定义规则</h3>
                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="action" value="add_ad_rule">
                    <input type="text" name="keyword" placeholder="例：ad_roll / promo / 片头广告" required style="flex:1;min-width:220px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">➕ 添加规则</button>
                </form>
            </div>
            <div class="panel">
                <h3>📝 默认内置规则</h3>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <?php foreach (($noadConfig['ad_keywords'] ?? []) as $kw): ?>
                        <span class="badge badge-yellow" style="font-size:13px;padding:6px 12px"><?php echo htmlspecialchars($kw); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel">
                <h3>🗃️ 自定义规则库（共 <?php echo count($adRules); ?> 条）</h3>
                <?php if (empty($adRules)): ?>
                    <p style="color:#888">暂无自定义规则。</p>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>关键词</th><th>命中次数</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($adRules as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><code class="monocode"><?php echo htmlspecialchars($r['keyword']); ?></code></td>
                        <td><?php echo (int)($r['hit_count'] ?? 0); ?></td>
                        <td><?php echo empty($r['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                        <td>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('确认删除规则 #<?php echo (int)$r['id']; ?>？')"><input type="hidden" name="action" value="delete_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-danger-sm">删除</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- 子标签 4: 算法管理 -->
        <div class="sub-tab-panel" id="subtab-ad_algorithms" style="display:none">
            <?php
                $ry = $noadConfig;
                $ruyiEnabled = $ry['ruyi_enabled'] ?? true;
                $ruyiScore = $ry['ruyi_score_threshold'] ?? 4;
                $ruyiBaseline = $ry['ruyi_baseline_sec'] ?? 4.00;
                $ruyiTol = $ry['ruyi_baseline_tolerance'] ?? 0.10;
                $ruyiMinClu = $ry['ruyi_min_cluster_len'] ?? 3;
                $ruyiMaxClu = $ry['ruyi_max_cluster_len'] ?? 15;
                $ruyiShort = $ry['ruyi_short_seg_threshold'] ?? 3.0;
                $ruyiDisc = $ry['ruyi_enable_discontinuity'] ?? true;
                $ruyiDebug = $ry['ruyi_debug_mode'] ?? false;
            ?>

            <div class="panel">
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="btn-primary-sm" onclick="reloadAlgorithms()" style="font-size:14px;padding:8px 18px">🔄 重新扫描算法</button>
                    <span id="algoStatus" style="color:#555;font-size:13px">就绪</span>
                    <span style="margin-left:auto;color:#888;font-size:13px">共 <strong id="algoTotal">0</strong> 个 · 启用 <strong id="algoEnabled">0</strong> 个</span>
                </div>
                <h3 style="margin-top:20px">📋 已加载算法</h3>
                <table class="data-table" id="algoTable">
                    <thead><tr><th>ID</th><th>名称</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody><tr><td colspan="5" style="text-align:center;color:#888">点击「重新扫描」加载</td></tr></tbody>
                </table>
            </div>

            <div class="panel">
                <h3>🧪 本地测试</h3>
                <textarea id="algoTestInput" style="width:100%;min-height:100px;padding:10px;font-family:monospace;font-size:13px;border:1px solid #ddd;border-radius:8px" placeholder="输入文本或 URL 进行测试..."></textarea>
                <div style="margin-top:8px">
                    <label>作用域：
                        <select id="algoTestScope">
                            <option value="all">全部</option>
                            <option value="url">仅 URL</option>
                            <option value="m3u8">仅 M3U8</option>
                        </select>
                    </label>
                    <button type="button" class="btn-primary-sm" onclick="testAlgorithms()" style="font-size:14px;padding:8px 18px">▶ 测试</button>
                </div>
                <pre id="algoTestResult" style="margin-top:12px;padding:12px;background:#f8f8fa;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#333">结果将显示在此处...</pre>
            </div>

            <div class="panel" style="background:linear-gradient(135deg,#fff5f7,#fff)">
                <h3>🎯 如意去广告算法 v2.1 — 参数设置</h3>
                <p style="color:#666;font-size:13px">基于 M3U8 时长序列模式识别。默认参数平衡精准度与误删率。</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_noad_config">
                    <input type="hidden" name="noad_enabled" value="1">
                    <input type="hidden" name="enable_ad_filter" value="1">
                    <input type="hidden" name="cache_enabled" value="1">
                    <input type="hidden" name="enable_multi_source" value="1">
                    <input type="hidden" name="stats_enabled" value="1">
                    <input type="hidden" name="cache_ttl" value="<?php echo (int)($ry['cache_ttl'] ?? 1800); ?>">
                    <input type="hidden" name="max_source_try" value="<?php echo (int)($ry['max_source_try'] ?? 3); ?>">
                    <input type="hidden" name="request_timeout" value="<?php echo (int)($ry['request_timeout'] ?? 10); ?>">
                    <input type="hidden" name="ad_keyword_threshold" value="<?php echo (int)($ry['ad_keyword_threshold'] ?? 2); ?>">
                    <input type="hidden" name="debug_mode" value="0">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px">
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">📌 基础开关</h4>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_enabled" <?php echo $ruyiEnabled ? 'checked' : ''; ?>> 启用如意算法</label></div>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_enable_discontinuity" <?php echo $ruyiDisc ? 'checked' : ''; ?>> 启用 DISCONTINUITY 信号</label></div>
                            <div style="padding:4px 0"><label style="font-size:14px"><input type="checkbox" name="ruyi_debug_mode" <?php echo $ruyiDebug ? 'checked' : ''; ?>> 调试模式</label></div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">🎚 Score 阈值</h4>
                            <div style="padding:6px 0;font-size:14px">
                                <label>删除判定 Score：<input type="number" name="ruyi_score_threshold" min="1" max="10" value="<?php echo (int)$ruyiScore; ?>" style="width:60px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                                <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">推荐：<b>3=保守</b>，<b>4=平衡</b>，<b>5=激进</b></div>
                            </div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">📏 时长检测</h4>
                            <div style="padding:4px 0;font-size:14px"><label>基准时长（秒）：<input type="number" name="ruyi_baseline_sec" min="0.5" max="30" step="0.1" value="<?php echo number_format($ruyiBaseline, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>容差：<input type="number" name="ruyi_baseline_tolerance" min="0.01" max="5" step="0.01" value="<?php echo number_format($ruyiTol, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>短片段阈值：<input type="number" name="ruyi_short_seg_threshold" min="0.5" max="10" step="0.1" value="<?php echo number_format($ruyiShort, 2); ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        </div>
                        <div style="background:#fafafa;border:1px dashed #e0e0e0;padding:14px 18px;border-radius:12px">
                            <h4 style="margin:0 0 10px 0;color:#444">🎯 簇检测</h4>
                            <div style="padding:4px 0;font-size:14px"><label>最小簇长度：<input type="number" name="ruyi_min_cluster_len" min="1" max="20" value="<?php echo (int)$ruyiMinClu; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                            <div style="padding:4px 0;font-size:14px"><label>最大簇长度：<input type="number" name="ruyi_max_cluster_len" min="5" max="50" value="<?php echo (int)$ruyiMaxClu; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        </div>
                    </div>
                    <div style="margin-top:16px"><button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存如意参数</button>
                    <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_noad_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button></div>
                </form>
            </div>
        </div>
    </div>

    <?php
    // ===== 线路配置（整合：API 线路、平台规则） =====
    ?>
    <div class="tab-panel <?php echo $page==='line_config'?'active':''; ?>" id="tab-line_config">
        <h2>📡 线路配置</h2>

        <div class="tabs-nav" id="lineConfigSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);padding:8px">
            <button data-subtab="lc_api" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">📡 API 线路</button>
            <button data-subtab="lc_platform" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🎯 平台规则</button>
        </div>

        <!-- 子标签 1：API 线路配置 -->
        <div class="sub-tab-panel active" id="subtab-lc_api">
            <div class="panel">
                <form method="post">
                    <input type="hidden" name="action" value="save_api">
                    <table class="data-table" id="apiTable_2">
                        <thead><tr><th>序号</th><th>接口名称</th><th>接口地址</th><th style="width:120px">超时(秒)</th><th style="width:100px">操作</th></tr></thead>
                        <tbody id="apiTbody_2">
                        <?php foreach ($parsedApis as $idx => $api): ?>
                        <tr>
                            <td style="color:#999;text-align:center"><?php echo $idx + 1; ?></td>
                            <td><input type="text" name="api_name[]" value="<?php echo htmlspecialchars($api['name']); ?>" style="width:100%"></td>
                            <td><input type="text" name="api_url[]" value="<?php echo htmlspecialchars($api['url']); ?>" style="width:100%"></td>
                            <td><input type="number" name="api_timeout[]" value="<?php echo (int)$api['timeout']; ?>" min="1" max="120" style="width:100%"></td>
                            <td class="center"><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($parsedApis) === 0): ?>
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:12px">
                        <button type="button" class="btn-secondary-sm" onclick="
                            var tb = document.getElementById('apiTbody_2');
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td style=\'color:#999;text-align:center\'>+</td><td><input type=\'text\' name=\'api_name[]\' placeholder=\'接口名称\' style=\'width:100%\'></td><td><input type=\'text\' name=\'api_url[]\' placeholder=\'https://jx.example.com/?url=\' style=\'width:100%\'></td><td><input type=\'number\' name=\'api_timeout[]\' value=\'5\' min=\'1\' max=\'120\' style=\'width:100%\'></td><td><button type=\'button\' class=\'btn-danger-sm\' onclick=\'this.closest(&quot;tr&quot;).remove()\'>删除</button></td>';
                            tb.appendChild(tr);
                        " style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 子标签 2：平台规则配置 -->
        <div class="sub-tab-panel" id="subtab-lc_platform" style="display:none">
            <div class="panel">
                <form method="post">
                    <input type="hidden" name="action" value="save_platform">
                    <table class="data-table">
                        <thead><tr><th>平台名称</th><th>匹配规则</th><th style="width:100px">操作</th></tr></thead>
                        <tbody id="platTbody_2">
                        <?php foreach ((array)$platformCfg as $name => $rule): ?>
                        <tr>
                            <td><input type="text" name="platform_name[]" value="<?php echo htmlspecialchars($name); ?>" style="width:100%"></td>
                            <td><input type="text" name="platform_rule[]" value="<?php echo htmlspecialchars($rule); ?>" style="width:100%"></td>
                            <td><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count((array)$platformCfg) === 0): ?>
                        <tr><td colspan="3" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:12px">
                        <button type="button" class="btn-secondary-sm" onclick="
                            var tb = document.getElementById('platTbody_2');
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td><input type=\'text\' name=\'platform_name[]\' placeholder=\'平台名\' style=\'width:100%\'></td><td><input type=\'text\' name=\'platform_rule[]\' placeholder=\'域名关键字|接口名\' style=\'width:100%\'></td><td><button type=\'button\' class=\'btn-danger-sm\' onclick=\'this.closest(&quot;tr&quot;).remove()\'>删除</button></td>';
                            tb.appendChild(tr);
                        " style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    // ===== 接口中心（整合：系统开关、ZJK 自定义接口、接口在线测试） =====
    ?>
    <div class="tab-panel <?php echo $page==='interface_center'?'active':''; ?>" id="tab-interface_center">
        <h2>🔌 接口中心</h2>

        <div class="tabs-nav" id="interfaceCenterSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#38ef7d 0%,#11998e 100%);padding:8px">
            <button data-subtab="ic_switch" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔀 系统开关</button>
            <button data-subtab="ic_zjk" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">📝 自定义接口</button>
            <button data-subtab="ic_test" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🧪 接口测试</button>
        </div>

        <!-- 子标签 1：系统开关 -->
        <div class="sub-tab-panel active" id="subtab-ic_switch">
            <form method="post">
                <input type="hidden" name="action" value="save_switch">
                <div class="panel">
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_global_api" <?php if (!empty($switchConfig['enable_global_api'])) echo 'checked'; ?>> 启用总接口并发请求</label>
                    <label style="display:block;margin:10px 0">ZJK 文件路径：<input type="text" name="zjk_file_path" value="<?php echo htmlspecialchars($switchConfig['zjk_file_path'] ?? 'ZJK.txt'); ?>" style="width:300px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">总接口超时（秒）：<input type="number" name="global_api_timeout" value="<?php echo (int)($switchConfig['global_api_timeout'] ?? 8); ?>" min="1" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">总接口并发数：<input type="number" name="global_api_count" value="<?php echo (int)($switchConfig['global_api_count'] ?? 6); ?>" min="0" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_zjk_apis" <?php if (!empty($switchConfig['enable_zjk_apis'])) echo 'checked'; ?>> 启用 ZJK.txt 自定义接口</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_m3u8_direct" <?php if (!empty($switchConfig['enable_m3u8_direct'])) echo 'checked'; ?>> M3U8 直链快速通道</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_unified_display" <?php if (!empty($switchConfig['enable_unified_display'])) echo 'checked'; ?>> 统一响应格式</label>
                    <div style="margin-top:12px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- 子标签 2：ZJK 自定义接口 -->
        <div class="sub-tab-panel" id="subtab-ic_zjk" style="display:none">
            <div class="panel">
                <p style="color:#666">格式：<code class="monocode">接口地址|超时秒数</code>，每行一条。例如：<code class="monocode">https://jx.example.com/?url={url}|8</code></p>
                <form method="post">
                    <input type="hidden" name="action" value="save_zjk">
                    <textarea name="zjk_content" style="width:100%;min-height:300px;font-family:Consolas,monospace;padding:12px;font-size:13px;border:1px solid #ddd;border-radius:8px"><?php echo htmlspecialchars($zjkContent); ?></textarea>
                    <div style="margin-top:12px"><button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button></div>
                </form>
            </div>
        </div>

        <!-- 子标签 3：接口在线测试 -->
        <div class="sub-tab-panel" id="subtab-ic_test" style="display:none">
            <div class="panel">
                <form method="get">
                    <input type="hidden" name="page" value="test">
                    <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap">
                        <label style="font-size:14px;color:#555">视频链接：</label>
                        <input type="text" name="test_url" value="<?php echo isset($_GET['test_url']) ? htmlspecialchars($_GET['test_url']) : ''; ?>" style="flex:1;min-width:300px;padding:10px;border:1px solid #ddd;border-radius:6px" placeholder="https://v.qq.com/x/cover/example.html">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">开始测试</button>
                    </div>
                </form>
            </div>
            <?php if (!empty($testResults)): ?>
            <div class="panel">
                <h3>📊 测试结果（<?php echo count($testResults); ?> 个接口）</h3>
                <table class="data-table">
                    <thead><tr><th>接口名称</th><th>HTTP 状态</th><th>响应时间</th><th>有效响应</th></tr></thead>
                    <tbody>
                    <?php foreach ($testResults as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td><span class="badge <?php echo $r['code'] == 200 ? 'badge-green' : 'badge-red'; ?>"><?php echo (int)$r['code']; ?></span></td>
                        <td><?php echo number_format($r['time'], 3); ?> 秒</td>
                        <td><span class="badge <?php echo $r['valid'] ? 'badge-green' : 'badge-red'; ?>"><?php echo $r['valid'] ? '✅ 成功' : '❌ 失败'; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 6. NoAd 数据统计 ===== (旧页面，保留但不显示在导航)
    ?>
    <div class="tab-panel" id="tab-noad_stats" style="display:none">
        <h2>📈 NoAd 数据统计</h2>
        <?php if (!$GLOBALS['db']): ?>
            <div class="msg-box err">⚠️ SQLite 未加载或数据库不可用。</div>
        <?php else: ?>
        <div class="panel">
            <h3>📊 近 7 天请求趋势</h3>
            <?php if (empty($dailyStats)): ?>
                <p style="color:#888">暂无数据。</p>
            <?php else:
                $maxVal = 1;
                foreach ($dailyStats as $d2) if (($d2['total_requests'] ?? 0) > $maxVal) $maxVal = $d2['total_requests'];
                foreach ($dailyStats as $d2):
                    $pct = round((($d2['total_requests'] ?? 0) / $maxVal) * 100);
                    $date = substr($d2['stat_date'] ?? '??', 5);
            ?>
            <div style="margin:12px 0">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                    <span><?php echo htmlspecialchars($date); ?></span>
                    <span>共 <strong><?php echo (int)($d2['total_requests'] ?? 0); ?></strong> · 移除广告 <strong><?php echo (int)($d2['ad_removed_count'] ?? 0); ?></strong></span>
                </div>
                <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%;"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="panel">
            <h3>🏆 最热门解析源（Top 10）</h3>
            <?php if (empty($topSources)): ?>
                <p style="color:#888">暂无数据。</p>
            <?php else:
                $mTop = max(1, max(array_column($topSources, 'use_count')));
                foreach ($topSources as $s):
                    $pct = round($s['use_count'] / $mTop * 100);
            ?>
            <div style="margin:12px 0">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
                    <span><?php echo htmlspecialchars($s['source_name']); ?></span>
                    <span>被使用 <strong><?php echo (int)$s['use_count']; ?></strong> 次</span>
                </div>
                <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%;background:linear-gradient(90deg,#f093fb,#f5576c);"></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="panel">
            <h3>📝 最近访问日志</h3>
            <?php if (empty($recentLogs)): ?>
                <p style="color:#888">暂无日志。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>时间</th><th>IP</th><th>来源</th><th>类型</th><th>广告移除</th><th>耗时</th><th>缓存</th></tr></thead>
                <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?php echo date('H:i:s', (int)$log['access_time']); ?></td>
                    <td><?php echo htmlspecialchars($log['ip'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($log['source_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($log['video_type'] ?: '-'); ?></td>
                    <td><?php echo (int)($log['ad_segments_removed'] ?? 0); ?></td>
                    <td><?php echo number_format((float)($log['response_time'] ?? 0), 1); ?>ms</td>
                    <td><?php echo !empty($log['is_from_cache']) ? '<span class="badge badge-green">命中</span>' : '<span class="badge badge-blue">未</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // ===== 7. 解析源管理 =====
    ?>
    <div class="tab-panel" id="tab-noad_sources" style="display:none">
        <h2>🔌 去广告解析源</h2>
        <div class="panel">
            <h3>➕ 添加 / 编辑解析源</h3>
            <form method="post" id="srcForm">
                <input type="hidden" name="action" value="save_noad_source">
                <input type="hidden" name="source_id" id="srcId" value="0">
                <div class="grid-flow">
                    <label>名称<br><input type="text" name="source_name" id="srcName" placeholder="例：主源A" required style="width:100%"></label>
                    <label style="grid-column:span 2">接口地址（URL 中用 <code class="monocode">{url}</code> 作为播放页占位符）<br><input type="text" name="source_url" id="srcUrl" placeholder="https://jx.example.com/?url={url}" required style="width:100%"></label>
                    <label>资源类型<br>
                        <select name="source_type" id="srcType">
                            <?php foreach ($resourceTypes as $tid => $t): ?>
                                <option value="<?php echo (int)$tid; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>超时（秒）<br><input type="number" name="source_timeout" id="srcTimeout" value="8" min="1" max="60"></label>
                    <label>排序<br><input type="number" name="source_order" id="srcOrder" value="0" min="0" max="9999"></label>
                    <label style="grid-column:span 2">匹配关键词（可选）<br><input type="text" name="source_match" id="srcMatch" placeholder="例：v.qq.com" style="width:100%"></label>
                    <label style="grid-column:span 2">备注<br><input type="text" name="source_remark" id="srcRemark" style="width:100%"></label>
                    <label><input type="checkbox" name="source_enabled" id="srcEnabled" checked> 启用</label>
                </div>
                <div style="margin-top:12px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存解析源</button>
                    <button type="button" class="btn-secondary-sm" onclick="resetSourceForm()" style="font-size:14px;padding:8px 18px">🔄 重置</button>
                </div>
            </form>
        </div>
        <div class="panel">
            <h3>📋 现有解析源（共 <?php echo count($noadSources); ?> 个）</h3>
            <?php if (empty($noadSources)): ?>
                <p style="color:#888">⚠️ 暂未添加任何解析源。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>接口地址</th><th>超时</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($noadSources as $s):
                    $tn = $resourceTypes[(int)$s['type_id']]['name'] ?? '未分类';
                ?>
                <tr>
                    <td><?php echo (int)$s['id']; ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($tn); ?></td>
                    <td style="word-break:break-all;max-width:260px;font-size:12px"><?php echo htmlspecialchars($s['url']); ?></td>
                    <td><?php echo (int)$s['timeout']; ?>s</td>
                    <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_noad_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                        <?php
                        $srcData = [
                            (int)$s['id'], htmlspecialchars($s['name'] ?? ''),
                            htmlspecialchars($s['url'] ?? ''), (int)$s['type_id'],
                            (int)$s['timeout'], (int)$s['sort_order'],
                            htmlspecialchars($s['match_rules'] ?? ''),
                            htmlspecialchars($s['remark'] ?? ''),
                        ];
                        $srcJson = json_encode($srcData, JSON_UNESCAPED_UNICODE);
                        ?>
                        <button type="button" class="btn-primary-sm" data-src='<?php echo $srcJson; ?>' onclick="var d=this.getAttribute('data-src');if(d){var a=JSON.parse(d);editSource(a[0],a[1],a[2],a[3],a[4],a[5],a[6],a[7]);}">✏️ 编辑</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除解析源 #<?php echo (int)$s['id']; ?>？');"><input type="hidden" name="action" value="delete_noad_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 8. 广告规则库 =====
    ?>
    <div class="tab-panel" id="tab-noad_rules" style="display:none">
        <h2>🚫 广告片段识别规则库</h2>
        <p style="color:#666">当前阈值：命中 <strong><?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?></strong> 条关键词的片段将被判定为广告。</p>
        <div class="panel">
            <h3>➕ 添加自定义规则</h3>
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="add_ad_rule">
                <input type="text" name="keyword" placeholder="例：ad_roll / promo / 片头广告" required style="flex:1;min-width:220px">
                <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">➕ 添加规则</button>
            </form>
        </div>
        <div class="panel">
            <h3>📝 默认内置规则（来自 config/noad.php）</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach (($noadConfig['ad_keywords'] ?? []) as $kw): ?>
                    <span class="badge badge-yellow" style="font-size:13px;padding:6px 12px"><?php echo htmlspecialchars($kw); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel">
            <h3>🗃️ 自定义规则库（共 <?php echo count($adRules); ?> 条）</h3>
            <?php if (empty($adRules)): ?>
                <p style="color:#888">暂无自定义规则。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>ID</th><th>关键词</th><th>命中次数</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($adRules as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><code class="monocode"><?php echo htmlspecialchars($r['keyword']); ?></code></td>
                    <td><?php echo (int)($r['hit_count'] ?? 0); ?></td>
                    <td><?php echo empty($r['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除规则 #<?php echo (int)$r['id']; ?>？')"><input type="hidden" name="action" value="delete_ad_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn-danger-sm">删除</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ===== 9. NoAd 系统设置 =====
    ?>
    <div class="tab-panel" id="tab-noad_config" style="display:none">
        <h2>⚙️ NoAd 去广告系统设置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_noad_config">
            <div class="panel">
                <h3>总开关</h3>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="noad_enabled" <?php if (!empty($noadConfig['noad_enabled'])) echo 'checked'; ?>> ✅ 启用 NoAd 去广告解析</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_ad_filter" <?php if (!empty($noadConfig['enable_ad_filter'])) echo 'checked'; ?>> 🚫 启用 M3U8 广告片段智能过滤</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_multi_source" <?php if (!empty($noadConfig['enable_multi_source'])) echo 'checked'; ?>> 🔀 启用多源自动匹配</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="cache_enabled" <?php if (!empty($noadConfig['cache_enabled'])) echo 'checked'; ?>> 🧹 启用缓存加速</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="stats_enabled" <?php if (!empty($noadConfig['stats_enabled'])) echo 'checked'; ?>> 📈 启用访问数据统计（SQLite）</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="debug_mode" <?php if (!empty($noadConfig['debug_mode'])) echo 'checked'; ?>> 🐛 调试模式</label>
            </div>
            <div class="panel">
                <h3>性能参数</h3>
                <div class="grid-flow">
                    <label>缓存有效期（秒）<br><input type="number" name="cache_ttl" value="<?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?>" min="60" max="86400" style="width:100%"></label>
                    <label>单次最多尝试解析源数<br><input type="number" name="max_source_try" value="<?php echo (int)($noadConfig['max_source_try'] ?? 3); ?>" min="1" max="20" style="width:100%"></label>
                    <label>单源请求超时（秒）<br><input type="number" name="request_timeout" value="<?php echo (int)($noadConfig['request_timeout'] ?? 10); ?>" min="1" max="120" style="width:100%"></label>
                    <label>广告关键词命中阈值<br><input type="number" name="ad_keyword_threshold" value="<?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?>" min="1" max="20" style="width:100%"></label>
                </div>
            </div>
            <div style="margin-top:16px">
                <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存 NoAd 配置</button>
            </div>
        </form>
    </div>

    <?php
    // ===== 10. API 线路配置 =====
    ?>
    <div class="tab-panel" id="tab-api_old" style="display:none">
        <h2>📡 API 线路配置</h2>
        <div class="panel">
            <form method="post">
                <input type="hidden" name="action" value="save_api">
                <table class="data-table" id="apiTable">
                    <thead><tr><th>序号</th><th>接口名称</th><th>接口地址</th><th style="width:120px">超时(秒)</th><th style="width:100px">操作</th></tr></thead>
                    <tbody id="apiTbody">
                    <?php foreach ($parsedApis as $idx => $api): ?>
                    <tr>
                        <td style="color:#999;text-align:center"><?php echo $idx + 1; ?></td>
                        <td><input type="text" name="api_name[]" value="<?php echo htmlspecialchars($api['name']); ?>" style="width:100%"></td>
                        <td><input type="text" name="api_url[]" value="<?php echo htmlspecialchars($api['url']); ?>" style="width:100%"></td>
                        <td><input type="number" name="api_timeout[]" value="<?php echo (int)$api['timeout']; ?>" min="1" max="120" style="width:100%"></td>
                        <td class="center"><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($parsedApis) === 0): ?>
                    <tr><td colspan="5" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:12px">
                    <button type="button" class="btn-secondary-sm" onclick="addApiRow()" style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    // ===== 11. 平台规则 =====
    ?>
    <div class="tab-panel" id="tab-platform_old" style="display:none">
        <h2>🎯 平台规则配置</h2>
        <div class="panel">
            <form method="post">
                <input type="hidden" name="action" value="save_platform">
                <table class="data-table">
                    <thead><tr><th>平台名称</th><th>匹配规则</th><th style="width:100px">操作</th></tr></thead>
                    <tbody id="platTbody">
                    <?php foreach ((array)$platformCfg as $name => $rule): ?>
                    <tr>
                        <td><input type="text" name="platform_name[]" value="<?php echo htmlspecialchars($name); ?>" style="width:100%"></td>
                        <td><input type="text" name="platform_rule[]" value="<?php echo htmlspecialchars($rule); ?>" style="width:100%"></td>
                        <td><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove()">删除</button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count((array)$platformCfg) === 0): ?>
                    <tr><td colspan="3" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <div style="margin-top:12px">
                    <button type="button" class="btn-secondary-sm" onclick="addPlatformRow()" style="font-size:14px;padding:8px 18px">➕ 添加一行</button>
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:8px 18px">💾 保存全部</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    // ===== 12. 系统开关 =====
    ?>
    <div class="tab-panel" id="tab-switch_old" style="display:none">
        <h2>🔀 系统开关</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_switch">
            <div class="panel">
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_global_api" <?php if (!empty($switchConfig['enable_global_api'])) echo 'checked'; ?>> 启用总接口并发请求</label>
                <label style="display:block;margin:10px 0">ZJK 文件路径：<input type="text" name="zjk_file_path" value="<?php echo htmlspecialchars($switchConfig['zjk_file_path'] ?? 'ZJK.txt'); ?>" style="width:300px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0">总接口超时（秒）：<input type="number" name="global_api_timeout" value="<?php echo (int)($switchConfig['global_api_timeout'] ?? 8); ?>" min="1" style="width:100px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0">总接口并发数：<input type="number" name="global_api_count" value="<?php echo (int)($switchConfig['global_api_count'] ?? 6); ?>" min="0" style="width:100px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_zjk_apis" <?php if (!empty($switchConfig['enable_zjk_apis'])) echo 'checked'; ?>> 启用 ZJK.txt 自定义接口</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_m3u8_direct" <?php if (!empty($switchConfig['enable_m3u8_direct'])) echo 'checked'; ?>> M3U8 直链快速通道</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_unified_display" <?php if (!empty($switchConfig['enable_unified_display'])) echo 'checked'; ?>> 统一响应格式</label>
                <div style="margin-top:12px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    // ===== 13. ZJK 自定义接口 =====
    ?>
    <div class="tab-panel" id="tab-zjk_old" style="display:none">
        <h2>📝 ZJK.txt 自定义接口</h2>
        <div class="panel">
            <p style="color:#666">格式：<code class="monocode">接口地址|超时秒数</code>，每行一条。例如：<code class="monocode">https://jx.example.com/?url={url}|8</code></p>
            <form method="post">
                <input type="hidden" name="action" value="save_zjk">
                <textarea name="zjk_content" style="width:100%;min-height:300px;font-family:Consolas,monospace;padding:12px;font-size:13px;border:1px solid #ddd;border-radius:8px"><?php echo htmlspecialchars($zjkContent); ?></textarea>
                <div style="margin-top:12px"><button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存</button></div>
            </form>
        </div>
    </div>

    <?php
    // ===== 14. 接口在线测试 =====
    ?>
    <div class="tab-panel" id="tab-test_old" style="display:none">
        <h2>🧪 接口在线测试</h2>
        <div class="panel">
            <form method="get">
                <input type="hidden" name="page" value="test">
                <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap">
                    <label style="font-size:14px;color:#555">视频链接：</label>
                    <input type="text" name="test_url" value="<?php echo isset($_GET['test_url']) ? htmlspecialchars($_GET['test_url']) : ''; ?>" style="flex:1;min-width:300px;padding:10px;border:1px solid #ddd;border-radius:6px" placeholder="https://v.qq.com/x/cover/example.html">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">开始测试</button>
                </div>
            </form>
        </div>
        <?php if (!empty($testResults)): ?>
        <div class="panel">
            <h3>📊 测试结果（<?php echo count($testResults); ?> 个接口）</h3>
            <table class="data-table">
                <thead><tr><th>接口名称</th><th>HTTP 状态</th><th>响应时间</th><th>有效响应</th></tr></thead>
                <tbody>
                <?php foreach ($testResults as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['name']); ?></td>
                    <td><span class="badge <?php echo $r['code'] == 200 ? 'badge-green' : 'badge-red'; ?>"><?php echo (int)$r['code']; ?></span></td>
                    <td><?php echo number_format($r['time'], 3); ?> 秒</td>
                    <td><span class="badge <?php echo $r['valid'] ? 'badge-green' : 'badge-red'; ?>"><?php echo $r['valid'] ? '✅ 成功' : '❌ 失败'; ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // ===== 15. 自定义算法 =====
    ?>
    <div class="tab-panel" id="tab-custom_algorithms" style="display:none">
        <h2>🧩 自定义算法管理</h2>
        <p style="color:#666">目录：<code class="monocode">/algorithms/*.php</code>，每个文件为一个独立算法类，继承 <code class="monocode">AbstractAlgorithm</code>。</p>
        <div class="panel">
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <button type="button" class="btn-primary-sm" onclick="reloadAlgorithms()" style="font-size:14px;padding:8px 18px">🔄 重新扫描算法</button>
                <span id="algoStatus" style="color:#555;font-size:13px">就绪</span>
                <span style="margin-left:auto;color:#888;font-size:13px">共 <strong id="algoTotal">0</strong> 个 · 启用 <strong id="algoEnabled">0</strong> 个</span>
            </div>
            <h3 style="margin-top:20px">📋 已加载算法列表</h3>
            <table class="data-table" id="algoTable">
                <thead><tr><th>ID</th><th>名称</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>
                <tbody><tr><td colspan="5" style="text-align:center;color:#888">点击上方「重新扫描」加载</td></tr></tbody>
            </table>
        </div>
        <div class="panel">
            <h3>🧪 本地测试</h3>
            <textarea id="algoTestInput" style="width:100%;min-height:120px;padding:10px;font-family:monospace;font-size:13px;border:1px solid #ddd;border-radius:8px" placeholder="输入文本或 URL 进行测试..."></textarea>
            <div style="margin-top:8px">
                <label>作用域：
                    <select id="algoTestScope">
                        <option value="all">全部</option>
                        <option value="url">仅 URL</option>
                        <option value="m3u8">仅 M3U8</option>
                    </select>
                </label>
                <button type="button" class="btn-primary-sm" onclick="testAlgorithms()" style="font-size:14px;padding:8px 18px">▶ 测试</button>
            </div>
            <pre id="algoTestResult" style="margin-top:12px;padding:12px;background:#f8f8fa;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#333">结果将显示在此处...</pre>
        </div>

        <!-- ===== 如意去广告算法 v2.1 参数设置（核心功能） ===== -->
        <?php
            $ry = $noadConfig; // 读取 noad 配置中的如意参数
            $ruyiEnabled = $ry['ruyi_enabled'] ?? true;
            $ruyiScore = $ry['ruyi_score_threshold'] ?? 4;
            $ruyiBaseline = $ry['ruyi_baseline_sec'] ?? 4.00;
            $ruyiTol = $ry['ruyi_baseline_tolerance'] ?? 0.10;
            $ruyiMinClu = $ry['ruyi_min_cluster_len'] ?? 3;
            $ruyiMaxClu = $ry['ruyi_max_cluster_len'] ?? 15;
            $ruyiMinSum = $ry['ruyi_min_cluster_sum'] ?? 15.0;
            $ruyiMaxSum = $ry['ruyi_max_cluster_sum'] ?? 35.0;
            $ruyiShort = $ry['ruyi_short_seg_threshold'] ?? 3.0;
            $ruyiVeryShort = $ry['ruyi_very_short_threshold'] ?? 1.5;
            $ruyiDisc = $ry['ruyi_enable_discontinuity'] ?? true;
            $ruyiAuto = $ry['ruyi_auto_optimize_enabled'] ?? true;
            $ruyiHour = $ry['ruyi_auto_optimize_hour'] ?? 3;
            $ruyiInterval = $ry['ruyi_auto_optimize_interval_hours'] ?? 24;
            $ruyiSampleUrl = $ry['ruyi_auto_optimize_sample_url'] ?? '';
            $ruyiDebug = $ry['ruyi_debug_mode'] ?? false;
        ?>
        <div class="panel">
            <h3>🎯 如意去广告算法 v2.1 — 参数设置</h3>
            <p style="color:#666;font-size:13px">针对如意（Ruyi）解析源的 M3U8 时长序列模式识别算法。默认参数平衡精准度与误删率，可根据观看效果微调。</p>
            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <!-- 保留原 noad 配置不被覆盖 -->
                <input type="hidden" name="noad_enabled" value="<?php echo $ry['noad_enabled'] ?? true ? '1' : '0'; ?>">
                <input type="hidden" name="enable_ad_filter" value="<?php echo $ry['enable_ad_filter'] ?? true ? '1' : '0'; ?>">
                <input type="hidden" name="cache_enabled" value="<?php echo $ry['cache_enabled'] ?? true ? '1' : '0'; ?>">
                <input type="hidden" name="enable_multi_source" value="<?php echo $ry['enable_multi_source'] ?? true ? '1' : '0'; ?>">
                <input type="hidden" name="stats_enabled" value="<?php echo $ry['stats_enabled'] ?? true ? '1' : '0'; ?>">
                <input type="hidden" name="cache_ttl" value="<?php echo (int)($ry['cache_ttl'] ?? 1800); ?>">
                <input type="hidden" name="max_source_try" value="<?php echo (int)($ry['max_source_try'] ?? 3); ?>">
                <input type="hidden" name="request_timeout" value="<?php echo (int)($ry['request_timeout'] ?? 10); ?>">
                <input type="hidden" name="ad_keyword_threshold" value="<?php echo (int)($ry['ad_keyword_threshold'] ?? 2); ?>">
                <input type="hidden" name="debug_mode" value="<?php echo $ry['debug_mode'] ?? false ? '1' : '0'; ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                    <!-- 列 1 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">📌 基础开关</h4>
                        <div style="padding:6px 0">
                            <label style="font-size:14px">
                                <input type="checkbox" name="ruyi_enabled" <?php echo $ruyiEnabled ? 'checked' : ''; ?>> 启用如意算法（整体开关）
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:24px;margin-top:4px">关闭后仅使用关键词/域名过滤</div>
                        </div>
                        <div style="padding:6px 0">
                            <label style="font-size:14px">
                                <input type="checkbox" name="ruyi_enable_discontinuity" <?php echo $ruyiDisc ? 'checked' : ''; ?>> 启用 DISCONTINUITY 信号
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:24px;margin-top:4px">检测编码断层（典型广告插入点）辅助判断</div>
                        </div>
                        <div style="padding:6px 0">
                            <label style="font-size:14px">
                                <input type="checkbox" name="ruyi_debug_mode" <?php echo $ruyiDebug ? 'checked' : ''; ?>> 调试模式（开发用）
                            </label>
                        </div>
                    </div>

                    <!-- 列 2 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🎚 Score 阈值（精准度控制）</h4>
                        <div style="padding:8px 0">
                            <label style="font-size:14px">删除判定 Score 阈值：
                                <input type="number" name="ruyi_score_threshold" min="1" max="10" step="1"
                                    value="<?php echo (int)$ruyiScore; ?>"
                                    style="width:60px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">
                                推荐值：<b>3=保守</b>（少删但可能漏广告），<b>4=平衡</b>，<b>5=激进</b>（多删但可能误删）
                            </div>
                        </div>
                    </div>

                    <!-- 列 3 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">📏 片段时长检测</h4>
                        <div style="padding:6px 0;font-size:14px">
                            <label>基准片段时长（秒）：
                                <input type="number" name="ruyi_baseline_sec" min="0.5" max="30" step="0.1"
                                    value="<?php echo number_format($ruyiBaseline, 2); ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">如意资源站默认 <b>4.00s</b>（100帧 GoP）</div>
                        </div>
                        <div style="padding:6px 0;font-size:14px">
                            <label>基准容差（秒）：
                                <input type="number" name="ruyi_baseline_tolerance" min="0.01" max="2" step="0.01"
                                    value="<?php echo number_format($ruyiTol, 2); ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 0.10s（4.0s ± 0.1s 视为正常片段）</div>
                        </div>
                    </div>

                    <!-- 列 4 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">📐 簇（Cluster）检测范围</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label>最小簇长度（片段数）：
                                <input type="number" name="ruyi_min_cluster_len" min="1" max="20" step="1"
                                    value="<?php echo (int)$ruyiMinClu; ?>"
                                    style="width:70px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 3（少于 3 个非标准片段视为正常场景切换）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>最大簇长度（片段数）：
                                <input type="number" name="ruyi_max_cluster_len" min="3" max="100" step="1"
                                    value="<?php echo (int)$ruyiMaxClu; ?>"
                                    style="width:70px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 15（过长的复杂片段组视为正片）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>最小簇总时长（秒）：
                                <input type="number" name="ruyi_min_cluster_sum" min="5" max="30" step="1"
                                    value="<?php echo (float)$ruyiMinSum; ?>"
                                    style="width:70px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 15s（短于 15s 的片段组可能不是完整广告）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>最大簇总时长（秒）：
                                <input type="number" name="ruyi_max_cluster_sum" min="15" max="180" step="1"
                                    value="<?php echo (float)$ruyiMaxSum; ?>"
                                    style="width:70px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 35s（长于 35s 的复杂片段组视为正片）</div>
                        </div>
                    </div>

                    <!-- 列 5 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🔔 片段时长信号</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label>短片段阈值（秒）：
                                <input type="number" name="ruyi_short_seg_threshold" min="0.5" max="8" step="0.1"
                                    value="<?php echo number_format($ruyiShort, 2); ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 3.0s（片段<3s = 广告强信号，增加 Score）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>极短片段阈值（秒）：
                                <input type="number" name="ruyi_very_short_threshold" min="0.1" max="3" step="0.1"
                                    value="<?php echo number_format($ruyiVeryShort, 2); ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 1.5s（片段<1.5s = 直接删除，广告过渡/收尾标志）</div>
                        </div>
                    </div>

                    <!-- 列 6 -->
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🤖 自动检测与优化</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label>
                                <input type="checkbox" name="ruyi_auto_optimize_enabled" <?php echo $ruyiAuto ? 'checked' : ''; ?>> 启用自动检测
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:24px;margin-top:4px">每天自动运行检测，调整参数适配视频源变化</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>检测时间（小时，0~23）：
                                <input type="number" name="ruyi_auto_optimize_hour" min="0" max="23" step="1"
                                    value="<?php echo (int)$ruyiHour; ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 3 点（服务器空闲时间执行）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>检测间隔（小时）：
                                <input type="number" name="ruyi_auto_optimize_interval_hours" min="1" max="168" step="1"
                                    value="<?php echo (int)$ruyiInterval; ?>"
                                    style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px">
                            </label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">默认 24 小时（每天一次；可设 12 小时两次）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label>示例视频 URL（可选）：
                                <input type="text" name="ruyi_auto_optimize_sample_url"
                                    value="<?php echo htmlspecialchars($ruyiSampleUrl); ?>"
                                    placeholder="留空则使用随机示例；填写常用视频地址效果更佳"
                                    style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px;margin-top:4px">
                            </label>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;padding:14px 18px;background:#fffbeb;border-radius:8px;border-left:4px solid #f59e0b">
                    <b style="color:#92400e">💡 使用提示：</b>
                    <div style="color:#666;font-size:13px;margin-top:6px;line-height:1.6">
                        1. 如发现广告残留 → 降低 <b>Score 阈值</b>（从 4 降到 3）；或提高 <b>最小簇长度/总时长</b>，让检测更灵敏。<br>
                        2. 如发现正片被误删 → 提高 <b>Score 阈值</b>（从 4 升到 5）；或提高 <b>最小簇长度/总时长</b>，让检测更保守。<br>
                        3. 也可点击下方的「一键测试当前参数」按钮，通过示例视频自动验证。
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#4f46e5">💾 保存如意参数</button>
                    <button type="button" class="btn-primary-sm" onclick="ruyiTestCurrent()" style="font-size:14px;padding:10px 24px;background:#10b981">🧪 测试当前参数</button>
                    <button type="button" class="btn-primary-sm" onclick="ruyiAutoOptimize()" style="font-size:14px;padding:10px 24px;background:#f59e0b">🤖 一键自动优化</button>
                    <button type="button" class="btn-primary-sm" onclick="ruyiResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                </div>
                <div id="ruyiResult" style="margin-top:20px;padding:14px;background:#f0f9ff;border-radius:8px;color:#075985;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试...</div>
            </form>
        </div>

        <!-- ===== 万能规则1 - MD5 指纹去广告 ===== -->
        <?php
            $mc = $noadConfig;
            $md5Enabled = $mc['md5_enabled'] ?? true;
            $md5Repeat = $mc['md5_repeat_threshold'] ?? 3;
            $md5Concur = $mc['md5_max_concurrency'] ?? 6;
            $md5SegTime = $mc['md5_segment_timeout'] ?? 15;
            $md5Total = $mc['md5_total_timeout'] ?? 60;
            $md5KB = $mc['md5_max_segment_kb'] ?? 5000;
            $md5Proxy = $mc['md5_use_proxy'] ?? true;
            $md5Interval = $mc['md5_min_interval_ms'] ?? 100;
            $md5Learn = $mc['md5_auto_learn'] ?? true;
            $md5Clean = $mc['md5_db_cleanup_days'] ?? 30;
            $md5Debug = $mc['md5_debug'] ?? false;
        ?>
        <div class="panel">
            <h3 style="margin-top:0;color:#4f46e5">🎯 万能规则1 - MD5 指纹去广告</h3>
            <p style="color:#666;font-size:13px;margin:6px 0">
                <b>原理：</b>广告片段会在不同视频中重复出现（MD5 相同），而正片内容是唯一的。
                通过统计 TS 片段的 MD5 指纹出现频率，自动识别并删除广告片段。
            </p>
            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">⚙️ 开关与调试</h4>
                        <div style="padding:4px 0;font-size:14px"><label>
                            <input type="checkbox" name="md5_enabled" <?php echo $md5Enabled ? 'checked' : ''; ?>> 启用 MD5 指纹去广告</label></div>
                        <div style="padding:4px 0;font-size:14px"><label>
                            <input type="checkbox" name="md5_auto_learn" <?php echo $md5Learn ? 'checked' : ''; ?>> 启用自动学习（记录新指纹）</label></div>
                        <div style="padding:4px 0;font-size:14px"><label>
                            <input type="checkbox" name="md5_use_proxy" <?php echo $md5Proxy ? 'checked' : ''; ?>> 使用代理池下载</label></div>
                        <div style="padding:4px 0;font-size:14px"><label>
                            <input type="checkbox" name="md5_debug" <?php echo $md5Debug ? 'checked' : ''; ?>> 调试模式</label></div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🎯 检测灵敏度</h4>
                        <div style="padding:4px 0;font-size:14px"><label>重复次数阈值：
                            <input type="number" name="md5_repeat_threshold" min="2" max="10" step="1"
                                value="<?php echo (int)$md5Repeat; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">相同 MD5 出现 >= 此值 → 判定为广告（默认 3 次）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>单片段最大 KB：
                            <input type="number" name="md5_max_segment_kb" min="500" max="50000" step="500"
                                value="<?php echo (int)$md5KB; ?>" style="width:100px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">超过此大小跳过（防止卡死）</div>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🛡️ 服务器保护</h4>
                        <div style="padding:4px 0;font-size:14px"><label>最大并发数：
                            <input type="number" name="md5_max_concurrency" min="1" max="20" step="1"
                                value="<?php echo (int)$md5Concur; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">实际根据 CPU/内存自动下调（默认 6）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>单片段超时（秒）：
                            <input type="number" name="md5_segment_timeout" min="5" max="120" step="5"
                                value="<?php echo (int)$md5SegTime; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        <div style="padding:4px 0;font-size:14px"><label>总处理超时（秒）：
                            <input type="number" name="md5_total_timeout" min="30" max="600" step="10"
                                value="<?php echo (int)$md5Total; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        <div style="padding:4px 0;font-size:14px"><label>最小请求间隔（ms）：
                            <input type="number" name="md5_min_interval_ms" min="50" max="5000" step="50"
                                value="<?php echo (int)$md5Interval; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">防止请求过快被封禁（默认 100ms）</div>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🗄️ 数据库维护</h4>
                        <div style="padding:4px 0;font-size:14px"><label>自动清理周期（天）：
                            <input type="number" name="md5_db_cleanup_days" min="7" max="365" step="7"
                                value="<?php echo (int)$md5Clean; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">清理超过此天数的旧记录（默认 30 天）</div>
                        </div>
                        <div id="md5StatsPanel" style="margin-top:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                            <div style="font-size:12px;color:#888">点击右侧「📊 查看指纹库统计」按钮</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#4f46e5">💾 保存 MD5 参数</button>
                    <button type="button" class="btn-primary-sm" onclick="md5TestCurrent()" style="font-size:14px;padding:10px 24px;background:#10b981">🧪 测试当前参数</button>
                    <button type="button" class="btn-primary-sm" onclick="md5LoadStats()" style="font-size:14px;padding:10px 24px;background:#6366f1">📊 查看指纹库统计</button>
                    <button type="button" class="btn-primary-sm" onclick="md5ResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                </div>
                <div id="md5Result" style="margin-top:20px;padding:14px;background:#f0f9ff;border-radius:8px;color:#075985;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试...</div>
            </form>
        </div>
    </div>

    <!-- ===== 万能规则2 - 批量解析特征学习 ===== -->
    <?php
        $fc = $noadConfig;
        $featEnabled = $fc['feat_enabled'] ?? true;
        $featMaxSources = $fc['feat_max_sources'] ?? 3;
        $featSourceTimeout = $fc['feat_source_timeout'] ?? 15;
        $featTotalTimeout = $fc['feat_total_timeout'] ?? 60;
        $featMinVotes = $fc['feat_min_votes'] ?? 2;
        $featLowResource = $fc['feat_low_resource_mode'] ?? true;
        $featMaxConcurrency = $fc['feat_max_concurrency'] ?? 2;
        $featSampleCount = $fc['feat_sample_count'] ?? 15;
        $featLearnEnabled = $fc['feat_learn_enabled'] ?? true;
        $featUseProxy = $fc['feat_use_proxy'] ?? true;
        $featDebug = $fc['feat_debug'] ?? false;
    ?>
    <div class="tab-panel <?php echo $page==='feat'?'active':''; ?>" id="tab-feat">
        <h2>🎯 万能规则2 - 批量解析特征学习</h2>
        <div class="panel">
            <h3 style="margin-top:0;color:#10b981">核心原理</h3>
            <p style="color:#666;font-size:13px;margin:6px 0;line-height:1.6">
                <b>为什么能去广告？</b>专业去广告解析接口（如 jx.playerjy.com 等）返回的视频内容已经去除了广告片段。
                通过向<b>多个</b>这类接口<b>并发解析同一个视频</b>，<b>对比它们返回的结果</b>，
                出现在<b>多数解析源中的域名/片段</b>就是<b>正片内容</b>，
                只出现在<b>少数/单个解析源中的域名/片段</b>就是<b>广告内容</b>。
                <br><br>
                <b>优点：</b>不需要下载 TS 文件、自动学习、跨资源站通用、与 MD5 规则互补。
                <b>低资源优化：</b>默认并发=2、最多调用3个解析源、采样分析、超时保护，差服务器也能跑。
            </p>
            <form method="post">
                <input type="hidden" name="action" value="save_noad_config">
                <div class="grid-2">
                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">⚙️ 核心开关</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_enabled" <?php if ($featEnabled) echo 'checked'; ?>> 启用万能规则2（批量解析特征学习）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_learn_enabled" <?php if ($featLearnEnabled) echo 'checked'; ?>> 启用自动学习（每次解析自动记录新特征）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_low_resource_mode" <?php if ($featLowResource) echo 'checked'; ?>> 低资源模式（默认开启，差服务器必备）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_use_proxy" <?php if ($featUseProxy) echo 'checked'; ?>> 启用代理池（降低 IP 被封禁风险）</label>
                        </div>
                        <div style="padding:4px 0;font-size:14px">
                            <label><input type="checkbox" name="feat_debug" <?php if ($featDebug) echo 'checked'; ?>> 调试模式（返回详细学习信息）</label>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🎯 识别精度</h4>
                        <div style="padding:4px 0;font-size:14px"><label>最少投票数：
                            <input type="number" name="feat_min_votes" min="2" max="5" step="1"
                                value="<?php echo (int)$featMinVotes; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">至少几个解析源一致才信任（默认2，越大越保守）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>采样片段数：
                            <input type="number" name="feat_sample_count" min="5" max="100" step="5"
                                value="<?php echo (int)$featSampleCount; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">对每视频采样前 N 段学习（减少数据库写入）</div>
                        </div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🛡️ 服务器保护</h4>
                        <div style="padding:4px 0;font-size:14px"><label>最大解析源数：
                            <input type="number" name="feat_max_sources" min="2" max="10" step="1"
                                value="<?php echo (int)$featMaxSources; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">最多调用多少个解析源（默认3，差服务器建议2）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>最大并发数：
                            <input type="number" name="feat_max_concurrency" min="1" max="10" step="1"
                                value="<?php echo (int)$featMaxConcurrency; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label>
                            <div style="color:#999;font-size:12px;margin-left:16px;margin-top:4px">并发请求上限（默认2，差服务器建议1）</div>
                        </div>
                        <div style="padding:4px 0;font-size:14px"><label>单源超时（秒）：
                            <input type="number" name="feat_source_timeout" min="5" max="120" step="5"
                                value="<?php echo (int)$featSourceTimeout; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                        <div style="padding:4px 0;font-size:14px"><label>总处理超时（秒）：
                            <input type="number" name="feat_total_timeout" min="30" max="600" step="10"
                                value="<?php echo (int)$featTotalTimeout; ?>" style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px"></label></div>
                    </div>

                    <div class="panel" style="background:#fafafa;border:1px dashed #e0e0e0;margin:0;padding:16px 20px">
                        <h4 style="margin:0 0 12px 0;color:#444">🧠 手动标记工具</h4>
                        <div style="padding:4px 0;font-size:14px">
                            <label>手动标记域名：<br>
                                <input type="text" name="mark_domain" placeholder="例：ad.example.com" style="width:240px;padding:6px;border:1px solid #ddd;border-radius:4px;margin-top:4px">
                                <select name="mark_type" style="width:100px;padding:6px;border:1px solid #ddd;border-radius:4px;margin-left:4px">
                                    <option value="content">正片</option>
                                    <option value="ad">广告</option>
                                    <option value="unknown">未知</option>
                                </select>
                                <button type="button" class="btn-primary-sm" onclick="featMarkDomain()" style="font-size:13px;padding:6px 14px;margin-left:4px;background:#6366f1">➕ 标记</button>
                            </label>
                        </div>
                        <div id="featStatsPanel" style="margin-top:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                            <div style="font-size:12px;color:#888">点击下方「📊 查看特征库统计」查看当前特征库内容</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;background:#10b981">💾 保存规则2参数</button>
                    <button type="button" class="btn-primary-sm" onclick="featTestCurrent()" style="font-size:14px;padding:10px 24px;background:#4f46e5">🧪 测试当前参数</button>
                    <button type="button" class="btn-primary-sm" onclick="featLearnCurrent()" style="font-size:14px;padding:10px 24px;background:#7c3aed">📚 学习特征</button>
                    <button type="button" class="btn-primary-sm" onclick="featLoadStats()" style="font-size:14px;padding:10px 24px;background:#6366f1">📊 查看特征库</button>
                    <button type="button" class="btn-primary-sm" onclick="featResetDefault()" style="font-size:14px;padding:10px 24px;background:#6b7280">↩ 恢复默认值</button>
                </div>
                <div id="featResult" style="margin-top:20px;padding:14px;background:#ecfdf5;border-radius:8px;color:#047857;font-size:13px;line-height:1.7;white-space:pre-wrap;min-height:30px">💡 点击按钮开始测试/学习...</div>
            </form>
        </div>
    </div>

    <?php
    // ===== 16. 工具管理 =====
    ?>
    <div class="tab-panel <?php echo $page==='tools'?'active':''; ?>" id="tab-tools">
        <h2>🧰 工具管理中心</h2>

        <!-- 综合分析区域 -->
        <div class="panel" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 28px; color: white; border-radius: 16px;">
            <h3 style="color: white; margin: 0 0 20px 0; font-size: 20px;">⚡ 智能综合分析</h3>
            <p style="margin: 0 0 20px 0; opacity: 0.9; font-size: 14px;">输入 M3U8 链接或内容，自动进行特征提取和广告清洗</p>

            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
                <input type="text" id="comboInput" placeholder="输入 M3U8 链接或直接粘贴 M3U8 内容..." style="flex: 1; min-width: 350px; padding: 16px 20px; border: none; border-radius: 12px; font-size: 15px; background: rgba(255,255,255,0.98);">

                <select id="comboMode" style="padding: 16px 20px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.98);">
                    <option value="auto">🎯 自动识别</option>
                    <option value="m3u8">📼 M3U8格式</option>
                    <option value="url">🔗 URL格式</option>
                </select>

                <select id="comboAlgorithm" style="padding: 16px 20px; border: none; border-radius: 12px; font-size: 14px; background: rgba(255,255,255,0.98);">
                    <option value="all">🧩 全算法</option>
                    <option value="domain">🌐 域名过滤</option>
                    <option value="keyword">🔑 关键词过滤</option>
                    <option value="md5">🎯 MD5过滤</option>
                </select>
            </div>

            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button onclick="comboAnalyze()" style="padding: 16px 32px; font-size: 16px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; border-radius: 12px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: all 0.3s;">
                    <span style="font-size: 20px;">🚀</span> 开始综合分析
                </button>

                <button onclick="loadSampleForCombo()" style="padding: 16px 32px; font-size: 16px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.5); color: white; border-radius: 12px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: all 0.3s;">
                    <span style="font-size: 20px;">📥</span> 加载示例
                </button>
            </div>
        </div>

        <!-- 分析结果区域 -->
        <div id="comboResult" style="margin-top: 24px; display: none;">
            <div class="panel" style="border-left: 5px solid #667eea;">
                <h3 style="margin-bottom: 20px;">📊 分析结果摘要</h3>

                <!-- 结果统计卡片 -->
                <div class="grid-flow" style="margin-bottom: 24px;">
                    <div class="info-box">
                        <div class="label">总片段数</div>
                        <div class="value" id="comboTotalSegs">0</div>
                    </div>
                    <div class="info-box red">
                        <div class="label">广告片段</div>
                        <div class="value" id="comboAdSegs">0</div>
                    </div>
                    <div class="info-box green">
                        <div class="label">保留片段</div>
                        <div class="value" id="comboKeepSegs">0</div>
                    </div>
                    <div class="info-box orange">
                        <div class="label">广告占比</div>
                        <div class="value" id="comboAdRate">0%</div>
                    </div>
                </div>

                <!-- 特征和去广告对比 -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <div style="background: linear-gradient(135deg, #667eea20, #764ba220); padding: 20px; border-radius: 12px;">
                        <h4 style="margin: 0 0 16px 0; color: #667eea; font-size: 16px;">🔑 特征码提取结果</h4>
                        <div id="featureSummary" style="font-size: 14px; line-height: 1.8;"></div>
                    </div>
                    <div style="background: linear-gradient(135deg, #11998e20, #38ef7d20); padding: 20px; border-radius: 12px;">
                        <h4 style="margin: 0 0 16px 0; color: #11998e; font-size: 16px;">🧹 去广告结果</h4>
                        <div id="adSummary" style="font-size: 14px; line-height: 1.8;"></div>
                    </div>
                </div>

                <!-- 操作按钮 -->
                <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px;">
                    <button onclick="copyComboCleanContent()" class="btn-success-sm" style="padding: 12px 20px; font-size: 14px;">
                        📋 复制清洗内容
                    </button>
                    <button onclick="downloadComboClean()" class="btn-primary-sm" style="padding: 12px 20px; font-size: 14px;">
                        ⬇ 下载清洗后文件
                    </button>
                    <button onclick="saveAnalysisReport()" class="btn-secondary-sm" style="padding: 12px 20px; font-size: 14px;">
                        💾 保存分析报告
                    </button>
                    <button onclick="exportAdPatterns()" class="btn-warning-sm" style="padding: 12px 20px; font-size: 14px;">
                        📤 导出广告特征
                    </button>
                </div>

                <!-- 完整JSON结果 -->
                <div style="margin-top: 20px;">
                    <h4 style="margin: 0 0 12px 0;">📝 完整分析结果 JSON</h4>
                    <pre id="comboJson" style="background: #1e1e2e; color: #cdd6f4; padding: 16px; border-radius: 10px; font-size: 12px; max-height: 400px; overflow-y: auto; line-height: 1.8;"></pre>
                </div>
            </div>
        </div>

        <!-- 加载状态 -->
        <div id="comboLoading" style="margin-top: 24px; display: none;">
            <div class="panel" style="text-align: center; padding: 80px 20px;">
                <div style="position: relative; width: 120px; height: 120px; margin: 0 auto 30px;">
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 48px; animation: bounce 1s infinite;">🔄</div>
                    <svg style="width: 120px; height: 120px; animation: spin 2s linear infinite;">
                        <circle cx="60" cy="60" r="50" fill="none" stroke="#e2e8f0" stroke-width="8"/>
                        <circle cx="60" cy="60" r="50" fill="none" stroke="url(#gradient)" stroke-width="8" stroke-dasharray="314" stroke-dashoffset="78.5" stroke-linecap="round"/>
                        <defs>
                            <linearGradient id="gradient">
                                <stop offset="0%" stop-color="#667eea"/>
                                <stop offset="100%" stop-color="#764ba2"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div style="font-size: 20px; color: #667eea; font-weight: 600; margin-bottom: 12px;">正在深度分析中...</div>
                <div style="font-size: 14px; color: #64748b; margin-bottom: 20px;">正在进行特征提取和广告识别</div>
                <div style="width: 300px; height: 8px; background: #e2e8f0; border-radius: 4px; margin: 0 auto; overflow: hidden;">
                    <div id="comboProgress" style="width: 0%; height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); transition: width 0.3s; border-radius: 4px;"></div>
                </div>
                <div id="comboProgressText" style="font-size: 13px; color: #64748b; margin-top: 12px;">0%</div>
            </div>
        </div>

        <style>
            @keyframes bounce {
                0%, 100% { transform: translate(-50%, -50%) scale(1); }
                50% { transform: translate(-50%, -50%) scale(1.1); }
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            #comboResult .info-box:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            }
        </style>

        <!-- 工具列表 -->
        <div class="panel" style="margin-top: 24px;">
            <h3>🔧 可用工具列表</h3>
            <div id="toolsList" style="min-height: 200px; padding: 16px; background: #f8fafc; border-radius: 12px;">
                <div style="text-align: center; color: #888; padding: 60px;">
                    <div style="font-size: 56px; margin-bottom: 16px;">🔧</div>
                    <div>正在加载工具列表...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function loadToolsList() {
        fetch('?action=ajax_tools_list', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        }).then(r => r.json()).then(data => {
            if (data.code === 200 && data.tools) {
                let html = '';
                for (let group in data.tools) {
                    html += '<div style="margin-bottom:20px;">';
                    html += '<h4 style="margin:0 0 12px 0;color:#555;font-size:14px;">' + htmlEscape(group) + '</h4>';
                    html += '<div style="display:flex;flex-wrap:gap:10px;">';
                    data.tools[group].forEach(tool => {
                        html += '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:15px;margin-right:15px;margin-bottom:15px;min-width:280px;">';
                        html += '<div style="font-weight:600;color:#333;margin-bottom:4px;">' + htmlEscape(tool.name) + '</div>';
                        html += '<div style="font-size:12px;color:#888;margin-bottom:10px;">' + htmlEscape(tool.description) + '</div>';
                        html += '<button onclick="runTool(\'' + htmlEscape(tool.id) + '\')" class="btn-primary-sm" style="font-size:12px;">▶ 执行</button>';
                        html += '</div>';
                    });
                    html += '</div></div>';
                }
                var el1 = document.getElementById('toolsList');
                if (el1) el1.innerHTML = html;
                var el2 = document.getElementById('toolsList_new');
                if (el2) el2.innerHTML = html;
            } else {
                var failHtml = '<div style="text-align:center;color:#888;padding:40px;">❌ 加载失败</div>';
                var el1 = document.getElementById('toolsList');
                if (el1) el1.innerHTML = failHtml;
                var el2 = document.getElementById('toolsList_new');
                if (el2) el2.innerHTML = failHtml;
            }
        }).catch(e => {
            var errHtml = '<div style="text-align:center;color:#888;padding:40px;">❌ 加载失败: ' + e.message + '</div>';
            var el1 = document.getElementById('toolsList');
            if (el1) el1.innerHTML = errHtml;
            var el2 = document.getElementById('toolsList_new');
            if (el2) el2.innerHTML = errHtml;
        });
    }

    function runTool(toolId) {
        fetch('?action=ajax_tools_run', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'tool_id=' + encodeURIComponent(toolId) + '&params=' + encodeURIComponent(JSON.stringify({input: 'test'}))
        }).then(r => r.json()).then(data => {
            if (data.code === 200) {
                alert(JSON.stringify(data.result, null, 2));
            } else {
                alert('执行失败: ' + (data.message || '未知错误'));
            }
        }).catch(e => {
            alert('请求失败: ' + e.message);
        });
    }

    function comboAnalyze() {
        const input = document.getElementById('comboInput').value.trim();
        const mode = document.getElementById('comboMode').value;
        const algorithm = document.getElementById('comboAlgorithm').value;

        if (!input) {
            showNotification('请输入M3U8链接或内容！');
            return;
        }

        document.getElementById('comboResult').style.display = 'none';
        document.getElementById('comboLoading').style.display = 'block';

        // 模拟进度条
        var comboProgress = 0;
        var comboProgressInterval = setInterval(function() {
            comboProgress = Math.min(comboProgress + Math.random() * 20, 95);
            var progressBar = document.getElementById('comboProgress');
            var progressText = document.getElementById('comboProgressText');
            if (progressBar) progressBar.style.width = comboProgress + '%';
            if (progressText) progressText.textContent = Math.round(comboProgress) + '% - 正在进行深度分析...';
        }, 400);

        fetch('?action=ajax_tools_combo', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'input=' + encodeURIComponent(input) + '&mode=' + encodeURIComponent(mode) + '&algorithm=' + encodeURIComponent(algorithm)
        }).then(r => {
            if (!r.ok) throw new Error('HTTP error ' + r.status);
            return r.text();
        }).then(text => {
            clearInterval(comboProgressInterval);
            var progressBar = document.getElementById('comboProgress');
            var progressText = document.getElementById('comboProgressText');
            if (progressBar) progressBar.style.width = '100%';
            if (progressText) progressText.textContent = '100% - 分析完成！';

            setTimeout(function() {
                document.getElementById('comboLoading').style.display = 'none';

                try {
                    const data = JSON.parse(text);
                    if (data.code === 200) {
                        // 更新统计卡片
                        var feature = data.feature && data.feature.data ? data.feature.data : {};
                        var adClean = data.ad_clean && data.ad_clean.data ? data.ad_clean.data : {};

                        document.getElementById('comboTotalSegs').textContent = adClean.total || 0;
                        document.getElementById('comboAdSegs').textContent = adClean.removed || 0;
                        document.getElementById('comboKeepSegs').textContent = adClean.kept || 0;

                        var adRate = adClean.total > 0 ? ((adClean.removed / adClean.total) * 100).toFixed(1) : 0;
                        document.getElementById('comboAdRate').textContent = adRate + '%';

                        // 更新摘要
                        document.getElementById('featureSummary').innerHTML = formatFeatureSummary(data.feature);
                        document.getElementById('adSummary').innerHTML = formatAdSummary(data.ad_clean);
                        document.getElementById('comboJson').textContent = JSON.stringify(data, null, 2);

                        document.getElementById('comboResult').style.display = 'block';
                        showNotification('✅ 综合分析完成！');
                    } else {
                        showNotification('❌ 分析失败：' + (data.msg || '未知错误'));
                    }
                } catch (e) {
                    showNotification('❌ 解析结果失败: ' + e.message);
                }
            }, 500);
        }).catch(e => {
            clearInterval(comboProgressInterval);
            document.getElementById('comboLoading').style.display = 'none';
            showNotification('❌ 请求失败: ' + e.message);
        });
    }

    function loadSampleForCombo() {
        var sampleM3u8 = `#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-MEDIA-SEQUENCE:1
#EXTINF:5.0,
https://cdn.example.com/video/part1.ts
#EXTINF:5.0,
https://adserver.example.com/preroll_ad1.ts
#EXTINF:5.0,
https://ad.doubleclick.net/sponsor.ts
#EXTINF:5.0,
https://cdn.example.com/video/part2.ts
#EXTINF:5.0,
https://ads.youtube.com/midroll_ad.ts
#EXTINF:5.0,
https://cdn.example.com/video/part3.ts
#EXTINF:5.0,
https://ad.example.com/promo_ad.ts
#EXTINF:5.0,
https://cdn.example.com/video/part4.ts
#EXT-X-ENDLIST`;

        document.getElementById('comboInput').value = sampleM3u8;
        showNotification('✅ 已加载示例M3U8！');
    }

    function copyComboCleanContent() {
        var data = document.getElementById('comboJson').textContent;
        try {
            var json = JSON.parse(data);
            var cleaned = json.ad_clean && json.ad_clean.data && json.ad_clean.data.cleaned_content;
            if (cleaned) {
                navigator.clipboard.writeText(cleaned).then(function() {
                    showNotification('✅ 清洗内容已复制！');
                });
            } else {
                showNotification('暂无清洗内容');
            }
        } catch (e) {
            showNotification('复制失败');
        }
    }

    function downloadComboClean() {
        var data = document.getElementById('comboJson').textContent;
        try {
            var json = JSON.parse(data);
            var cleaned = json.ad_clean && json.ad_clean.data && json.ad_clean.data.cleaned_content;
            if (cleaned) {
                var blob = new Blob([cleaned], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'combo_cleaned_' + Date.now() + '.m3u8';
                a.click();
                URL.revokeObjectURL(url);
                showNotification('✅ 文件下载已开始！');
            } else {
                showNotification('暂无清洗内容');
            }
        } catch (e) {
            showNotification('下载失败');
        }
    }

    function saveAnalysisReport() {
        var data = document.getElementById('comboJson').textContent;
        try {
            var json = JSON.parse(data);
            var report = {
                timestamp: new Date().toISOString(),
                analysis: json
            };
            var blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'analysis_report_' + Date.now() + '.json';
            a.click();
            URL.revokeObjectURL(url);
            showNotification('✅ 分析报告已保存！');
        } catch (e) {
            showNotification('保存失败');
        }
    }

    function exportAdPatterns() {
        var data = document.getElementById('comboJson').textContent;
        try {
            var json = JSON.parse(data);
            var patterns = {
                domains: [],
                keywords: [],
                timestamp: new Date().toISOString()
            };

            var feature = json.feature && json.feature.data ? json.feature.data : {};
            if (feature.domain_counts) {
                patterns.domains = Object.keys(feature.domain_counts);
            }

            var blob = new Blob([JSON.stringify(patterns, null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'ad_patterns_' + Date.now() + '.json';
            a.click();
            URL.revokeObjectURL(url);
            showNotification('✅ 广告特征已导出！');
        } catch (e) {
            showNotification('导出失败');
        }
    }

    function formatFeatureSummary(feature) {
        if (!feature || !feature.success) return '<div style="color:#ef4444; padding: 12px; background: #fee2e2; border-radius: 8px;">❌ 特征提取失败</div>';
        const data = feature.data || {};
        let html = '<div style="display: grid; gap: 12px;">';
        if (data.md5_signatures && data.md5_signatures.length) {
            html += '<div style="background: #f0fdf4; padding: 12px; border-radius: 8px;">';
            html += '<div style="font-weight: 600; color: #166534; margin-bottom: 8px;">🎯 MD5指纹</div>';
            html += '<div style="font-size: 13px;">数量: <strong>' + data.md5_signatures.length + '</strong></div>';
            if (data.md5_signatures.length <= 3) {
                data.md5_signatures.forEach(function(md5) {
                    html += '<div style="font-size: 11px; font-family: monospace; margin-top: 4px; color: #666;">' + md5.substring(0, 32) + '...</div>';
                });
            }
            html += '</div>';
        }
        if (data.domain_counts) {
            var domains = Object.keys(data.domain_counts);
            html += '<div style="background: #eff6ff; padding: 12px; border-radius: 8px;">';
            html += '<div style="font-weight: 600; color: #1e40af; margin-bottom: 8px;">🌐 域名统计</div>';
            html += '<div style="font-size: 13px;">唯一域名: <strong>' + domains.length + '</strong></div>';
            if (domains.length > 0) {
                html += '<div style="font-size: 12px; color: #666; margin-top: 8px;">示例: ' + domains.slice(0, 3).join(', ') + '</div>';
            }
            html += '</div>';
        }
        if (data.global_signature) {
            html += '<div style="background: #f5f3ff; padding: 12px; border-radius: 8px;">';
            html += '<div style="font-weight: 600; color: #6b21a8; margin-bottom: 8px;">🔐 全局签名</div>';
            html += '<div style="font-size: 11px; font-family: monospace; word-break: break-all;">' + data.global_signature + '</div>';
            html += '</div>';
        }
        html += '</div>';
        return html || '<div style="color:#666;">暂无数据</div>';
    }

    function formatAdSummary(adClean) {
        if (!adClean || !adClean.success) return '<div style="color:#ef4444; padding: 12px; background: #fee2e2; border-radius: 8px;">❌ 去广告失败</div>';
        const data = adClean.data || {};
        let html = '<div style="display: grid; gap: 12px;">';
        if (data.total !== undefined) {
            html += '<div style="background: #f0fdf4; padding: 12px; border-radius: 8px;">';
            html += '<div style="font-weight: 600; color: #166534; margin-bottom: 8px;">📊 统计信息</div>';
            html += '<div>总片段: <strong>' + data.total + '</strong></div>';
            html += '<div>移除广告: <strong style="color:#dc2626;">' + (data.removed || 0) + '</strong></div>';
            html += '<div>保留正片: <strong style="color:#16a34a;">' + (data.kept || 0) + '</strong></div>';
            if (data.total > 0) {
                var rate = ((data.removed || 0) / data.total * 100).toFixed(1);
                html += '<div style="margin-top: 8px;">广告占比: <strong>' + rate + '%</strong></div>';
            }
            html += '</div>';
        }
        if (data.removed_reasons && data.removed_reasons.length) {
            html += '<div style="background: #fef2f2; padding: 12px; border-radius: 8px;">';
            html += '<div style="font-weight: 600; color: #991b1b; margin-bottom: 8px;">🚫 移除原因</div>';
            data.removed_reasons.slice(0, 5).forEach(function(reason) {
                html += '<div style="font-size: 12px; margin-top: 4px;">• ' + reason + '</div>';
            });
            if (data.removed_reasons.length > 5) {
                html += '<div style="font-size: 12px; color: #666; margin-top: 4px;">...等 ' + data.removed_reasons.length + ' 种原因</div>';
            }
            html += '</div>';
        }
        html += '</div>';
        return html || '<div style="color:#666;">暂无数据</div>';
    }

    function htmlEscape(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('tab-tools') || document.getElementById('tab-system')) {
            loadToolsList();
        }
    });
    </script>

    <?php
    // ===== 17. 配置备份 =====
    ?>
    <div class="tab-panel <?php echo $page==='backup'?'active':''; ?>" id="tab-backup">
        <h2>💾 配置备份</h2>
        <div class="panel">
            <form method="post">
                <input type="hidden" name="action" value="create_backup">
                <p>一键备份所有配置文件到 cache/backup_时间戳/ 目录。包括：API / 平台 / 开关 / 后台 / NoAd / ZJK.txt。</p>
                <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">📦 立即备份</button>
            </form>
        </div>
        <?php if (!empty($msg) && strpos($msg, '备份') !== false): ?>
        <div class="panel">
            <h3>✅ 备份提示</h3>
            <p style="color:#28a745"><?php echo htmlspecialchars($msg); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // ===== 17. 系统管理 ===== (合并备份、密码、设置)
    ?>
    <div class="tab-panel <?php echo $page==='system_mgmt'?'active':''; ?>" id="tab-system_mgmt">
        <h2>🛠️ 系统管理</h2>

        <!-- 子标签导航 -->
        <div class="tabs-nav" id="systemSubNav" style="margin-bottom:20px;background:linear-gradient(135deg,#4facfe,#00f2fe);padding:8px">
            <button data-subtab="sys_backup" class="active" style="color:white;background:rgba(255,255,255,0.15);border:none;padding:10px 18px;border-radius:10px;font-size:14px">💾 配置备份</button>
            <button data-subtab="sys_password" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">🔐 修改密码</button>
            <button data-subtab="sys_setting" style="color:white;background:transparent;border:none;padding:10px 18px;border-radius:10px;font-size:14px">⚙️ 后台设置</button>
        </div>

        <!-- 子标签 1: 配置备份 -->
        <div class="sub-tab-panel active" id="subtab-sys_backup">
            <div class="panel">
                <h3>💾 配置备份与恢复</h3>
                <p style="color:#666;font-size:13px">点击下方按钮创建配置文件的备份，用于恢复或迁移。</p>
                <form method="post" style="margin-top:12px">
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">📦 创建配置备份</button>
                </form>
                <?php
                $backupDir = __DIR__ . '/cache';
                $backups = [];
                if (is_dir($backupDir)) {
                    foreach (glob($backupDir . '/backup_*.php') as $f) {
                        $backups[] = $f;
                    }
                }
                if (!empty($backups)):
                ?>
                <div style="margin-top:20px">
                    <h4>📂 已有备份（<?php echo count($backups); ?> 个）</h4>
                    <table class="data-table">
                        <thead><tr><th>文件名</th><th>大小</th><th>创建时间</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($backups) as $bk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(basename($bk)); ?></td>
                                <td><?php echo round(filesize($bk)/1024, 2); ?> KB</td>
                                <td><?php echo date('Y-m-d H:i:s', filemtime($bk)); ?></td>
                                <td><form method="post" style="display:inline" onsubmit="return confirm('确认恢复此备份？将覆盖当前配置！')"><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="backup_file" value="<?php echo htmlspecialchars(basename($bk)); ?>"><button type="submit" class="btn-secondary-sm">恢复</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 子标签 2: 修改密码 -->
        <div class="sub-tab-panel" id="subtab-sys_password" style="display:none">
            <div class="panel" style="max-width:500px">
                <form method="post">
                    <input type="hidden" name="action" value="change_password">
                    <label style="display:block;margin:10px 0">当前密码<br><input type="password" name="old_password" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <label style="display:block;margin:10px 0">新密码（至少 6 位）<br><input type="password" name="new_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <label style="display:block;margin:10px 0">确认密码<br><input type="password" name="confirm_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;margin-top:10px">🔐 修改密码</button>
                </form>
            </div>
        </div>

        <!-- 子标签 3: 后台设置 -->
        <div class="sub-tab-panel" id="subtab-sys_setting" style="display:none">
            <form method="post">
                <input type="hidden" name="action" value="save_admin_config">
                <div class="panel">
                    <h3>🎛️ 基础设置</h3>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="admin_enabled" <?php if (!empty($adminConfig['admin_enabled'])) echo 'checked'; ?>> ✅ 启用后台管理</label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enforce_port" <?php if (!empty($adminConfig['enforce_port'])) echo 'checked'; ?>> ⚠️ 强制端口校验</label>
                    <label style="display:block;margin:10px 0">允许访问的端口（逗号分隔）：<br><input type="text" name="allowed_ports" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ports'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                    <label style="display:block;margin:10px 0">允许访问的 IP（逗号分隔）：<br><input type="text" name="allowed_ips" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ips'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                </div>
                <div class="panel">
                    <h3>🔐 安全设置</h3>
                    <label style="display:block;margin:10px 0">会话有效期（秒）：<input type="number" name="session_lifetime" value="<?php echo max(60, (int)($adminConfig['session_lifetime'] ?? 7200)); ?>" min="60" max="864000" style="width:120px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">最大登录失败次数：<input type="number" name="max_login_attempts" value="<?php echo max(1, (int)($adminConfig['max_login_attempts'] ?? 5)); ?>" min="1" max="999" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0">失败锁定时长（秒）：<input type="number" name="lockout_duration" value="<?php echo max(60, (int)($adminConfig['lockout_duration'] ?? 300)); ?>" min="60" max="86400" style="width:100px;margin-left:10px"></label>
                    <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_log" <?php if (!empty($adminConfig['enable_log'])) echo 'checked'; ?>> 📒 启用操作日志</label>
                    <div style="margin-top:16px">
                        <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存后台设置</button>
                        <button type="button" class="btn-secondary-sm" onclick="restoreFromAutoSave('save_admin_config')" style="font-size:14px;padding:10px 24px;margin-left:10px">↩ 还原上次自动保存</button>
                    </div>
                </div>
            </form>

            <form method="post" style="margin-top:16px" onsubmit="return confirm('确认要修改后台入口文件名？请确保你能记住新文件名！');">
                <input type="hidden" name="action" value="change_path">
                <div class="panel">
                    <h3>🔄 修改后台入口路径</h3>
                    <p style="color:#666;font-size:13px">当前路径：<strong><?php echo htmlspecialchars($currentScript); ?></strong></p>
                    <label style="display:block;margin:10px 0">新后台文件名：<input type="text" name="new_path" placeholder="例：my_console.php" pattern="[A-Za-z0-9_\-]+\.php$" required style="width:260px;margin-left:10px"></label>
                    <button type="submit" class="btn-danger-sm" style="font-size:14px;padding:10px 24px">🔄 重命名后台入口</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    // ===== 17. 修改管理员密码 ===== (旧页面，保留但不显示在导航)
    ?>
    <div class="tab-panel" id="tab-password" style="display:none">
        <h2>🔐 修改管理员密码</h2>
        <div class="panel" style="max-width:500px">
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <label style="display:block;margin:10px 0">当前密码<br><input type="password" name="old_password" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                <label style="display:block;margin:10px 0">新密码（至少 6 位）<br><input type="password" name="new_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                <label style="display:block;margin:10px 0">确认密码<br><input type="password" name="confirm_password" minlength="6" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></label>
                <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px;margin-top:10px">🔐 修改密码</button>
            </form>
        </div>
    </div>

    <?php
    // ===== 18. 后台设置 =====
    ?>
    <div class="tab-panel" id="tab-setting" style="display:none">
        <h2>🛠️ 后台设置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_admin_config">
            <div class="panel">
                <label style="display:block;margin:10px 0"><input type="checkbox" name="admin_enabled" <?php if (!empty($adminConfig['admin_enabled'])) echo 'checked'; ?>> ✅ 启用后台管理</label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enforce_port" <?php if (!empty($adminConfig['enforce_port'])) echo 'checked'; ?>> ⚠️ 强制端口校验</label>
                <label style="display:block;margin:10px 0">允许访问的端口（逗号分隔）：<br><input type="text" name="allowed_ports" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ports'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                <label style="display:block;margin:10px 0">允许访问的 IP（逗号分隔）：<br><input type="text" name="allowed_ips" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ips'] ?? [])); ?>" style="width:300px;margin-top:4px"></label>
                <label style="display:block;margin:10px 0">会话有效期（秒）：<input type="number" name="session_lifetime" value="<?php echo max(60, (int)($adminConfig['session_lifetime'] ?? 7200)); ?>" min="60" max="864000" style="width:120px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0">最大登录失败次数：<input type="number" name="max_login_attempts" value="<?php echo max(1, (int)($adminConfig['max_login_attempts'] ?? 5)); ?>" min="1" max="999" style="width:100px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0">失败锁定时长（秒）：<input type="number" name="lockout_duration" value="<?php echo max(60, (int)($adminConfig['lockout_duration'] ?? 300)); ?>" min="60" max="86400" style="width:100px;margin-left:10px"></label>
                <label style="display:block;margin:10px 0"><input type="checkbox" name="enable_log" <?php if (!empty($adminConfig['enable_log'])) echo 'checked'; ?>> 📒 启用操作日志</label>
                <div style="margin-top:16px">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px;padding:10px 24px">💾 保存后台设置</button>
                </div>
            </div>
        </form>

        <form method="post" style="margin-top:16px" onsubmit="return confirm('确认要修改后台入口文件名？请确保你能记住新文件名！');">
            <input type="hidden" name="action" value="change_path">
            <div class="panel">
                <h3>🔄 修改后台入口路径</h3>
                <p style="color:#666;font-size:13px">当前路径：<strong><?php echo htmlspecialchars($currentScript); ?></strong></p>
                <label style="display:block;margin:10px 0">新后台文件名：<input type="text" name="new_path" placeholder="例：my_console.php" pattern="[A-Za-z0-9_\-]+\.php$" required style="width:260px;margin-left:10px"></label>
                <button type="submit" class="btn-danger-sm" style="font-size:14px;padding:10px 24px">🔄 重命名后台入口</button>
            </div>
        </form>
    </div>

</div>

<script>
// ===== 标签页切换 =====
(function() {
    var nav = document.getElementById('tabsNav');
    if (!nav) return;
    var tabs = nav.querySelectorAll('button[data-tab]');
    var panels = document.querySelectorAll('.tab-panel');
    tabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tab = this.getAttribute('data-tab');
            tabs.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            panels.forEach(function(p) {
                if (p.id === 'tab-' + tab) p.classList.add('active');
                else p.classList.remove('active');
            });
        });
    });
})();

// ===== 子标签页切换（智能去广告中心 + 系统管理）
(function() {
    // 处理所有带有 data-subtab 的按钮
    var subNavs = document.querySelectorAll('[id$="SubNav"]');
    subNavs.forEach(function(nav) {
        var btns = nav.querySelectorAll('button[data-subtab]');
        btns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var subtab = this.getAttribute('data-subtab');
                // 在当前 tab-panel 范围内查找所有 sub-tab-panel
                var parentPanel = nav.closest('.tab-panel');
                if (!parentPanel) return; // 找不到父面板时跳过
                var subPanels = parentPanel.querySelectorAll('.sub-tab-panel');
                btns.forEach(function(b) {
                    b.classList.remove('active');
                    b.style.background = 'transparent';
                    b.style.background = '';
                });
                this.classList.add('active');
                this.style.background = 'rgba(255,255,255,0.15)';
                subPanels.forEach(function(sp) {
                    if (sp.id === 'subtab-' + subtab) {
                        sp.classList.add('active');
                        sp.style.display = 'block';
                    } else {
                            sp.classList.remove('active');
                            sp.style.display = 'none';
                    }
                });
            });
        });
    });
})();

// ===== M3U8 解析 =====
function parseM3u8() {
    var url = document.getElementById('m3u8Url').value.trim();
    var analysisMode = document.getElementById('analysisMode').value;
    if (!url) { alert('请输入 M3U8 链接或内容'); return; }

    var statusEl = document.getElementById('parseStatus');
    var parseStats = document.getElementById('parseStats');
    var parseLoading = document.getElementById('parseLoading');

    // 显示加载状态
    parseStats.style.display = 'none';
    parseLoading.style.display = 'block';
    statusEl.textContent = '正在解析中...';

    // 模拟进度
    var progress = 0;
    var progressInterval = setInterval(function() {
        progress = Math.min(progress + Math.random() * 15, 90);
        document.getElementById('parseProgress').style.width = progress + '%';
        document.getElementById('parseProgressText').textContent = Math.round(progress) + '%';
    }, 300);

    var formData = new FormData();
    formData.append('action', 'ajax_parse_m3u8');
    formData.append('m3u8_url', url);
    formData.append('analysis_mode', analysisMode);

    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            clearInterval(progressInterval);
            document.getElementById('parseProgress').style.width = '100%';
            document.getElementById('parseProgressText').textContent = '100%';

            setTimeout(function() {
                parseLoading.style.display = 'none';
                parseStats.style.display = 'block';

                if (data.code !== 200) {
                    statusEl.textContent = '❌ 解析失败：' + (data.msg || '未知错误');
                    statusEl.style.color = '#ef4444';
                    return;
                }

                // 更新统计信息
                document.getElementById('sTotal').textContent = data.total || 0;
                document.getElementById('sAd').textContent = data.ad_count || 0;
                document.getElementById('sKeep').textContent = data.keep_count || 0;

                var totalDuration = data.total_duration || 0;
                var adDuration = data.ad_duration || 0;
                document.getElementById('sDuration').textContent = formatDuration(totalDuration);
                document.getElementById('sAdDuration').textContent = formatDuration(adDuration);

                var adRate = data.total > 0 ? ((data.ad_count / data.total) * 100).toFixed(1) : 0;
                document.getElementById('sAdRate').textContent = adRate + '%';

                // 显示原始内容
                document.getElementById('rawContent').textContent = data.raw_content || '（无法获取原始内容）';

                // 显示清洗后内容
                document.getElementById('cleanContent').textContent = data.cleaned_content || '（无法获取清洗内容）';

                // 显示片段列表
                var segs = document.getElementById('segmentList');
                var html = '';
                if (data.segments && data.segments.length) {
                    data.segments.forEach(function(seg, index) {
                        var segClass = seg.is_ad ? 'ad' : 'keep';
                        var reason = seg.is_ad ? '广告片段' : '正片';
                        if (seg.ad_reason) {
                            reason += ' - ' + seg.ad_reason;
                        }

                        html += '<div class="segment-item ' + segClass + '">';
                        html += '<div>';
                        html += '<span class="segment-index">#' + (index + 1) + '</span>';
                        html += '<span class="segment-duration">' + seg.duration + 's</span>';
                        html += '<span style="margin-left: 12px; font-size: 12px; padding: 4px 8px; border-radius: 6px; background: ' + (seg.is_ad ? '#fee2e2' : '#dcfce7') + '; color: ' + (seg.is_ad ? '#dc2626' : '#16a34a') + '; font-weight: 600;">' + reason + '</span>';
                        html += '</div>';
                        html += '<div class="segment-uri">' + seg.uri + '</div>';
                        if (seg.is_ad && seg.ad_reason) {
                            html += '<div class="segment-reason">原因：' + seg.ad_reason + '</div>';
                        }
                        html += '</div>';
                    });
                } else {
                    html = '<div style="text-align: center; padding: 40px; color: #888;">暂无片段数据</div>';
                }
                segs.innerHTML = html;

                statusEl.textContent = '✅ 解析完成 - 站点：' + (data.site_name || '未知') + ' | 模式：' + analysisMode;
                statusEl.style.color = '#fff';
            }, 500);
        })
        .catch(function(e) {
            clearInterval(progressInterval);
            parseLoading.style.display = 'none';
            parseStats.style.display = 'block';
            statusEl.textContent = '❌ 请求失败：' + e.message;
            statusEl.style.color = '#ef4444';
        });
}

// 格式化时长
function formatDuration(seconds) {
    if (!seconds) return '0s';
    if (seconds < 60) return seconds.toFixed(1) + 's';
    var mins = Math.floor(seconds / 60);
    var secs = Math.floor(seconds % 60);
    return mins + 'm ' + secs + 's';
}

// 加载示例M3U8
function loadSampleM3u8() {
    var sampleM3u8 = `#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-MEDIA-SEQUENCE:1
#EXTINF:5.0,
https://example.com/video/segment1.ts
#EXTINF:5.0,
https://ad.example.com/ad1.ts
#EXTINF:5.0,
https://example.com/video/segment2.ts
#EXTINF:5.0,
https://ads.example.com/sponsor.ts
#EXTINF:5.0,
https://example.com/video/segment3.ts
#EXTINF:5.0,
https://ad.doubleclick.net/pre_roll.ts
#EXTINF:5.0,
https://example.com/video/segment4.ts
#EXT-X-ENDLIST`;

    document.getElementById('m3u8Url').value = sampleM3u8;
    document.getElementById('parseStatus').textContent = '✅ 已加载示例M3U8，点击"开始解析"进行分析';
    document.getElementById('parseStatus').style.color = '#10b981';
}

// 切换原始内容显示
function toggleRawContent() {
    var panel = document.getElementById('rawContentPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

// 复制清洗后内容
function copyCleanContent() {
    var content = document.getElementById('cleanContent').textContent;
    if (!content) {
        alert('没有可复制的内容');
        return;
    }
    navigator.clipboard.writeText(content).then(function() {
        showNotification('✅ 已复制到剪贴板！');
    }).catch(function() {
        alert('复制失败，请手动复制');
    });
}

// 下载清洗后文件
function downloadCleanM3u8() {
    var content = document.getElementById('cleanContent').textContent;
    if (!content) {
        alert('没有可下载的内容');
        return;
    }
    var blob = new Blob([content], { type: 'text/plain' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'cleaned_' + Date.now() + '.m3u8';
    a.click();
    URL.revokeObjectURL(url);
    showNotification('✅ 文件下载已开始！');
}

// 显示广告详情
function showAdDetails() {
    var segments = document.querySelectorAll('.segment-item.ad');
    if (segments.length === 0) {
        showNotification('没有检测到广告片段');
        return;
    }
    var details = '检测到的广告片段：\n\n';
    segments.forEach(function(seg, index) {
        var idx = seg.querySelector('.segment-index');
        var uri = seg.querySelector('.segment-uri');
        var reason = seg.querySelector('.segment-reason');
        details += '片段 #' + (index + 1) + '\n';
        details += 'URL: ' + (uri ? uri.textContent : '未知') + '\n';
        details += '原因: ' + (reason ? reason.textContent : '未知') + '\n\n';
    });
    alert(details);
}

// 加入黑名单
function addToBlacklist() {
    var segments = document.querySelectorAll('.segment-item.ad');
    if (segments.length === 0) {
        showNotification('没有检测到广告片段');
        return;
    }
    var uris = [];
    segments.forEach(function(seg) {
        var uri = seg.querySelector('.segment-uri');
        if (uri) {
            uris.push(uri.textContent);
        }
    });
    navigator.clipboard.writeText(uris.join('\n')).then(function() {
        showNotification('✅ 已复制 ' + uris.length + ' 个广告URL到剪贴板，可添加到黑名单！');
    });
}

// 片段过滤
var currentFilter = 'all';
function filterSegments(filter) {
    currentFilter = filter;
    var items = document.querySelectorAll('.segment-item');
    items.forEach(function(item) {
        if (filter === 'all') {
            item.style.display = 'block';
        } else if (filter === 'ad') {
            item.style.display = item.classList.contains('ad') ? 'block' : 'none';
        } else if (filter === 'keep') {
            item.style.display = item.classList.contains('keep') ? 'block' : 'none';
        }
    });

    // 更新按钮状态
    document.querySelectorAll('#segmentFilters button').forEach(function(btn) {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
}

// 显示通知
function showNotification(message) {
    var notification = document.createElement('div');
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 16px 24px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 12px; font-size: 14px; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: slideIn 0.3s ease;';
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(function() {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(function() { notification.remove(); }, 300);
    }, 3000);
}

// 添加CSS动画
var style = document.createElement('style');
style.textContent = '@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } } @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }';
document.head.appendChild(style);

// ===== 接口添加/编辑辅助函数 =====
function addApiRow() {
    var tbody = document.getElementById('apiTbody');
    if (tbody.querySelector('tr')) {
        // 如果已有占位行且内容为"暂无数据"，不清除（因为我们使用的是正常行结构）
    }
    var tr = document.createElement('tr');
    tr.innerHTML = '<td style="color:#999;text-align:center">+</td><td><input type="text" name="api_name[]" placeholder="接口名称" style="width:100%"></td><td><input type="text" name="api_url[]" placeholder="https://jx.example.com/?url=" style="width:100%"></td><td><input type="number" name="api_timeout[]" value="5" min="1" max="120" style="width:100%"></td><td><button type="button" class="btn-danger-sm" onclick="this.closest(\'tr\').remove()">删除</button></td>';
    tbody.appendChild(tr);
}
function addPlatformRow() {
    var tbody = document.getElementById('platTbody');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="platform_name[]" placeholder="平台名" style="width:100%"></td><td><input type="text" name="platform_rule[]" placeholder="域名关键字|接口名" style="width:100%"></td><td><button type="button" class="btn-danger-sm" onclick="this.closest(\'tr\').remove()">删除</button></td>';
    tbody.appendChild(tr);
}

// ===== 站点编辑辅助 =====
function editSite(id, name, code, url, match, remark, enabled) {
    document.getElementById('siteIdInput').value = id;
    document.getElementById('siteName').value = name;
    document.getElementById('siteCode').value = code;
    document.getElementById('siteUrl').value = url;
    document.getElementById('sitePattern').value = match;
    document.getElementById('siteRemark').value = remark;
    document.getElementById('siteEnabled').checked = enabled;
    window.scrollTo({top:0, behavior:'smooth'});
}
function resetSiteForm() {
    document.getElementById('siteIdInput').value = 0;
    document.getElementById('siteForm').reset();
}

// ===== 解析源编辑辅助 =====
function editSource(id, name, url, type, timeout, order, match, remark) {
    document.getElementById('srcId').value = id;
    document.getElementById('srcName').value = name;
    document.getElementById('srcUrl').value = url;
    document.getElementById('srcType').value = type;
    document.getElementById('srcTimeout').value = timeout;
    document.getElementById('srcOrder').value = order;
    document.getElementById('srcMatch').value = match || '';
    document.getElementById('srcRemark').value = remark || '';
    window.scrollTo({top:0, behavior:'smooth'});
}
function resetSourceForm() {
    document.getElementById('srcId').value = 0;
    document.getElementById('srcForm').reset();
}

// ===== 算法管理 =====
function reloadAlgorithms() {
    var statusEl = document.getElementById('algoStatus');
    if (statusEl) statusEl.textContent = '扫描中...';
    var statusEl2 = document.getElementById('algoStatus_algo');
    if (statusEl2) statusEl2.textContent = '扫描中...';
    var formData = new FormData();
    formData.append('action', 'ajax_list_algorithms');
    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var tableBody = document.querySelector('#algoTable tbody');
            var tableBody2 = document.querySelector('#algoTable_algo tbody');
            if (data.code === 200 && data.algorithms && data.algorithms.length) {
                var html = '';
                data.algorithms.forEach(function(a) {
                    html += '<tr><td>' + (a.id || '-') + '</td><td>' + (a.name || '-') + '</td>';
                    html += '<td>' + (a.description || '-') + '</td>';
                    html += '<td>' + (a.enabled ? '<span class="badge badge-green">启用</span>' : '<span class="badge badge-red">禁用</span>') + '</td>';
                    html += '<td><button type="button" class="btn-secondary-sm" onclick="toggleAlgo(\'' + a.id + '\', ' + (a.enabled ? 0 : 1) + ')">切换</button></td></tr>';
                });
                if (tableBody) tableBody.innerHTML = html;
                if (tableBody2) tableBody2.innerHTML = html;
                var totalEl = document.getElementById('algoTotal');
                if (totalEl) totalEl.textContent = data.algorithms.length;
                var totalEl2 = document.getElementById('algoTotal_algo');
                if (totalEl2) totalEl2.textContent = data.algorithms.length;
                var enabledCount = data.algorithms.filter(function(a) { return a.enabled; }).length;
                var enabledEl = document.getElementById('algoEnabled');
                if (enabledEl) enabledEl.textContent = enabledCount;
                var enabledEl2 = document.getElementById('algoEnabled_algo');
                if (enabledEl2) enabledEl2.textContent = enabledCount;
                if (statusEl) statusEl.textContent = '扫描完成';
                if (statusEl2) statusEl2.textContent = '扫描完成';
            } else {
                var emptyHtml = '<tr><td colspan="5" style="text-align:center;color:#999">未找到自定义算法</td></tr>';
                if (tableBody) tableBody.innerHTML = emptyHtml;
                if (tableBody2) tableBody2.innerHTML = emptyHtml;
                if (statusEl) statusEl.textContent = '无可用算法';
                if (statusEl2) statusEl2.textContent = '无可用算法';
            }
        })
        .catch(function(e) {
            if (statusEl) statusEl.textContent = '失败：' + e.message;
            if (statusEl2) statusEl2.textContent = '失败：' + e.message;
        });
}
function toggleAlgo(id, enabled) {
    var formData = new FormData();
    formData.append('action', 'ajax_toggle_algo');
    formData.append('algo_id', id);
    formData.append('enabled', enabled);
    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) reloadAlgorithms();
            else alert('切换失败：' + (data.msg || '未知错误'));
        });
}
function testAlgorithms() {
    var inputEl = document.getElementById('algoTestInput');
    var scopeEl = document.getElementById('algoTestScope');
    var resultEl = document.getElementById('algoTestResult');
    var resultEl2 = document.getElementById('algoTestResult_algo');
    var input = inputEl ? inputEl.value : '';
    var scope = scopeEl ? scopeEl.value : 'all';
    if (!input.trim()) { alert('请输入测试内容'); return; }
    if (resultEl) resultEl.textContent = '测试中...';
    if (resultEl2) resultEl2.textContent = '测试中...';
    var formData = new FormData();
    formData.append('action', 'ajax_test_algorithms');
    formData.append('input', input);
    formData.append('scope', scope);
    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var resultText = '';
            if (data.code === 200) {
                resultText = '原始：' + data.original + '\n结果：' + data.result + '\n变化：' + (data.changed ? '是' : '否') + '\n应用：' + (data.applied && data.applied.join ? data.applied.join(', ') : '-');
            } else {
                resultText = '测试失败：' + (data.msg || '未知错误');
            }
            if (resultEl) resultEl.textContent = resultText;
            if (resultEl2) resultEl2.textContent = resultText;
        })
        .catch(function(e) {
            var errText = '请求失败：' + e.message;
            if (resultEl) resultEl.textContent = errText;
            if (resultEl2) resultEl2.textContent = errText;
        });
}
// ===== 如意算法：测试当前参数 =====
function ruyiTestCurrent() {
    var resultEl = document.getElementById('ruyiResult');
    resultEl.textContent = '🔍 正在运行如意测试...（下载示例 M3U8 → 应用算法 → 输出结果）';
    var formData = new FormData();
    formData.append('action', 'ajax_ruyi_test');
    var sampleUrl = document.querySelector('input[name="ruyi_auto_optimize_sample_url"]').value.trim();
    if (sampleUrl) formData.append('sample_url', sampleUrl);
    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                resultEl.textContent = data.report || '测试完成';
            } else {
                resultEl.textContent = '❌ 测试失败：' + (data.msg || '未知错误');
            }
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 如意算法：一键自动优化 =====
function ruyiAutoOptimize() {
    var resultEl = document.getElementById('ruyiResult');
    resultEl.textContent = '🤖 正在运行自动优化...（多参数扫描 → 选出最优 → 自动保存）';
    var formData = new FormData();
    formData.append('action', 'ajax_ruyi_auto_optimize');
    fetch('<?php echo htmlspecialchars($currentScript); ?>', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                resultEl.textContent = data.report || '优化完成';
                if (data.saved) alert('✅ 已自动保存最优参数！下次解析视频将使用新参数');
                setTimeout(function(){ if (typeof reloadAlgorithms === 'function') reloadAlgorithms(); }, 500);
            } else {
                resultEl.textContent = '❌ 优化失败：' + (data.msg || '未知错误');
            }
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 如意算法：恢复默认参数（写入配置值） =====
function ruyiResetDefault() {
    if (!confirm('确认恢复如意算法的默认参数吗？\n当前修改将被覆盖。')) return;
    var defaults = {
        ruyi_score_threshold: 4,
        ruyi_baseline_sec: 4.00,
        ruyi_baseline_tolerance: 0.10,
        ruyi_min_cluster_len: 3,
        ruyi_max_cluster_len: 15,
        ruyi_min_cluster_sum: 15,
        ruyi_max_cluster_sum: 35,
        ruyi_short_seg_threshold: 3.0,
        ruyi_very_short_threshold: 1.5,
        ruyi_auto_optimize_hour: 3,
        ruyi_auto_optimize_interval_hours: 24
    };
    for (var key in defaults) {
        var input = document.querySelector('input[name="' + key + '"]');
        if (input) {
            if (input.type === 'checkbox') input.checked = (defaults[key] === true || defaults[key] === 1);
            else input.value = defaults[key];
        }
    }
    // 开关类
    ['ruyi_enabled', 'ruyi_enable_discontinuity', 'ruyi_auto_optimize_enabled'].forEach(function(key) {
        var el = document.querySelector('input[name="' + key + '"]');
        if (el) el.checked = true;
    });
    document.getElementById('ruyiResult').textContent = '↩ 已填充默认值，请点击「保存如意参数」来生效';
}
// ===== 如意算法：页面访问时触发每日自动检测 =====
(function() {
    <?php if (!empty($noadConfig['ruyi_auto_optimize_enabled'])): ?>
    // 每天仅触发一次，避免每次访问都运行
    var lastKey = 'ruyi_last_optimize_' + '<?php echo date('Y-m-d'); ?>';
    var now = new Date();
    var targetHour = <?php echo (int)($noadConfig['ruyi_auto_optimize_hour'] ?? 3); ?>;
    // 当访问时间 >= 目标小时，且当日未运行过 → 自动触发
    if (now.getHours() >= targetHour && !localStorage.getItem(lastKey)) {
        localStorage.setItem(lastKey, '1');
        setTimeout(function() {
            try { ruyiAutoOptimize(); } catch (e) {}  // 静默失败，不影响用户
        }, 2000);
    }
    <?php endif; ?>
})();
// ===== MD5 指纹去广告：测试当前参数 =====
function md5TestCurrent() {
    var resultEl = document.getElementById('md5Result');
    var resultEl2 = document.getElementById('md5Result_ac');
    var resultEl3 = document.getElementById('md5Result_algo');
    if (resultEl) resultEl.textContent = '🧪 正在下载并分析测试视频...';
    if (resultEl2) resultEl2.textContent = '🧪 正在下载并分析测试视频...';
    if (resultEl3) resultEl3.textContent = '🧪 正在下载并分析测试视频...';
    var sampleInput = document.querySelector('input[name="ruyi_auto_optimize_sample_url"]');
    var sampleUrl = sampleInput ? sampleInput.value.trim() : '';
    var formData = new FormData();
    formData.append('action', 'ajax_md5_test');
    if (sampleUrl) formData.append('sample_url', sampleUrl);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var text = data.code === 200 ? data.report : '❌ ' + (data.error || '未知错误');
            if (resultEl) resultEl.textContent = text;
            if (resultEl2) resultEl2.textContent = text;
            if (resultEl3) resultEl3.textContent = text;
        })
        .catch(function(e) {
            var errText = '❌ 请求失败：' + e.message;
            if (resultEl) resultEl.textContent = errText;
            if (resultEl2) resultEl2.textContent = errText;
            if (resultEl3) resultEl3.textContent = errText;
        });
}
// ===== MD5 指纹去广告：加载统计信息 =====
function md5LoadStats() {
    var resultEl = document.getElementById('md5Result');
    var resultEl2 = document.getElementById('md5Result_ac');
    var resultEl3 = document.getElementById('md5Result_algo');
    var panelEl = document.getElementById('md5StatsPanel');
    var panelEl2 = document.getElementById('md5StatsPanel_ac');
    var panelEl3 = document.getElementById('md5StatsPanel_algo');
    if (resultEl) resultEl.textContent = '📊 正在加载数据库统计...';
    if (resultEl2) resultEl2.textContent = '📊 正在加载数据库统计...';
    if (resultEl3) resultEl3.textContent = '📊 正在加载数据库统计...';
    var formData = new FormData();
    formData.append('action', 'ajax_md5_stats');
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                var stats = data.stats || {};
                var top = data.top || [];
                var blacklist = data.blacklist || [];
                var whitelist = data.whitelist || [];
                var txt = '📊 MD5 指纹库统计\n\n'
                    + '总指纹数：' + (stats.total || 0) + '\n'
                    + '今日新增：' + (stats.today_segments || 0) + '\n'
                    + '黑名单：' + (stats.ad_count || 0) + ' 条\n'
                    + '白名单：' + (stats.whitelist_count || 0) + ' 条\n'
                    + '数据库大小：' + (data.db_size || 0) + ' KB\n\n';
                if (top && top.length > 0) {
                    txt += '🔥 高频出现的 MD5 Top ' + Math.min(top.length, 10) + '：\n';
                    for (var i = 0; i < Math.min(top.length, 10); i++) {
                        var t = top[i];
                        txt += '  ' + (i + 1) + '. ' + t.md5.substring(0, 16) + '...  ' + t.count + ' 次\n';
                    }
                }
                if (blacklist && blacklist.length > 0) {
                    txt += '\n🚫 最近加入黑名单的：\n';
                    for (var j = 0; j < Math.min(blacklist.length, 5); j++) {
                        txt += '  ' + blacklist[j].md5.substring(0, 16) + '...\n';
                    }
                }
                if (resultEl) resultEl.textContent = txt;
                if (resultEl2) resultEl2.textContent = txt;
                if (resultEl3) resultEl3.textContent = txt;
                var panelText = '总指纹：' + (stats.total || 0) + ' · 今日新增：' + (stats.today_segments || 0) + ' · 数据库：' + (data.db_size || 0) + ' KB';
                if (panelEl) panelEl.textContent = panelText;
                if (panelEl2) panelEl2.textContent = panelText;
                if (panelEl3) panelEl3.textContent = panelText;
            }
            else {
                var errText = '❌ ' + (data.error || '未知错误');
                if (resultEl) resultEl.textContent = errText;
                if (resultEl2) resultEl2.textContent = errText;
                if (resultEl3) resultEl3.textContent = errText;
            }
        })
        .catch(function(e) {
            var errText = '❌ 请求失败：' + e.message;
            if (resultEl) resultEl.textContent = errText;
            if (resultEl2) resultEl2.textContent = errText;
            if (resultEl3) resultEl3.textContent = errText;
        });
}
// ===== MD5 指纹去广告：恢复默认参数 =====
function md5ResetDefault() {
    if (!confirm('确认恢复 MD5 指纹去广告的默认参数吗？\n当前修改将被覆盖。')) return;
    var defaults = {
        md5_repeat_threshold: 3,
        md5_max_concurrency: 6,
        md5_segment_timeout: 15,
        md5_total_timeout: 60,
        md5_max_segment_kb: 5000,
        md5_min_interval_ms: 100,
        md5_db_cleanup_days: 30
    };
    for (var key in defaults) {
        var inputs = document.querySelectorAll('input[name="' + key + '"]');
        inputs.forEach(function(input) { input.value = defaults[key]; });
    }
    ['md5_enabled', 'md5_auto_learn', 'md5_use_proxy'].forEach(function(key) {
        var els = document.querySelectorAll('input[name="' + key + '"]');
        els.forEach(function(el) { el.checked = true; });
    });
    var resultText = '↩ 已填充默认值，请点击「保存 MD5 参数」来生效';
    var resultEl = document.getElementById('md5Result');
    var resultEl2 = document.getElementById('md5Result_ac');
    var resultEl3 = document.getElementById('md5Result_algo');
    if (resultEl) resultEl.textContent = resultText;
    if (resultEl2) resultEl2.textContent = resultText;
    if (resultEl3) resultEl3.textContent = resultText;
}
// ===== MD5 深度分析：实时检测 TS 内容（分片加载，避免超时） =====
var _deepAnalyzeRunning = false;
var _deepAllSegments = [];
var _deepCacheKey = '';
var _deepVideoUrl = '';
var _deepM3u8Url = '';
var _deepM3u8Content = '';
var _deepDomain = '';
var _deepTotal = 0;
var _deepDiscontinuityCount = 0;
var _deepDiscontinuityIndices = [];
var _deepAnalysisResult = null;

function md5DeepAnalyze() {
    if (_deepAnalyzeRunning) return;

    var videoUrl = document.getElementById('deep_video_url').value.trim();
    var m3u8Url = document.getElementById('deep_m3u8_url').value.trim();
    var scanMode = document.getElementById('deep_scan_mode').value;
    var btn = document.getElementById('deep_analyze_btn');

    var errorDiv = document.getElementById('deep_error');
    var resultDiv = document.getElementById('deep_analysis_result');
    var loadingDiv = document.getElementById('deep_loading');
    var progressDiv = document.getElementById('deep_progress');

    errorDiv.style.display = 'none';
    errorDiv.textContent = '';

    if (!videoUrl && !m3u8Url) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = '❌ 请输入视频 URL 或 M3U8 地址';
        return;
    }

    _deepAnalyzeRunning = true;
    _deepAllSegments = [];
    _deepCacheKey = '';
    _deepVideoUrl = videoUrl;
    _deepM3u8Url = '';
    _deepM3u8Content = '';
    _deepDomain = '';
    _deepTotal = 0;

    resultDiv.style.display = 'none';
    loadingDiv.style.display = 'none';
    progressDiv.style.display = 'block';
    updateProgress(0, 0, '准备中...');

    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.textContent = '⏳ 解析 M3U8...';

    deepParseM3U8(videoUrl, m3u8Url, scanMode);
}

function deepParseM3U8(videoUrl, m3u8Url, scanMode) {
    var errorDiv = document.getElementById('deep_error');
    var progressDiv = document.getElementById('deep_progress');

    var formData = new FormData();
    formData.append('action', 'ajax_md5_parse_m3u8');
    if (videoUrl) formData.append('video_url', videoUrl);
    if (m3u8Url) formData.append('m3u8_url', m3u8Url);

    var controller = new AbortController();
    var timeoutId = setTimeout(function() { controller.abort(); }, 45000);

    fetch(window.location.href, { method: 'POST', body: formData, signal: controller.signal })
        .then(function(r) {
            clearTimeout(timeoutId);
            var contentType = r.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') === -1) {
                return r.text().then(function(text) {
                    throw new Error('服务器返回了非 JSON 内容（' + (text.substring(0, 100) || '空') + '）');
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.code !== 200) {
                throw new Error(data.error || data.msg || '未知错误');
            }

            _deepCacheKey = data.cache_key || '';
            _deepM3u8Url = data.m3u8_url || '';
            _deepM3u8Content = data.m3u8_content || '';
            _deepTotal = data.segment_count || 0;
            _deepDiscontinuityCount = data.discontinuity_count || 0;
            _deepDiscontinuityIndices = data.discontinuity_indices || [];

            var btn = document.getElementById('deep_analyze_btn');
            btn.textContent = '⏳ 分析中... (0/' + _deepTotal + ')';

            updateProgress(0, _deepTotal, 'M3U8 解析成功，共 ' + _deepTotal + ' 段，开始分析...');

            setTimeout(function() {
                deepAnalyzeNextBatch(videoUrl, m3u8Url, scanMode, 0, _deepCacheKey);
            }, 300);
        })
        .catch(function(e) {
            clearTimeout(timeoutId);
            handleDeepAnalyzeError('M3U8 解析失败：' + e.message);
        });
}

function deepAnalyzeNextBatch(videoUrl, m3u8Url, scanMode, offset, cacheKey) {
    var errorDiv = document.getElementById('deep_error');
    var btn = document.getElementById('deep_analyze_btn');
    var resultDiv = document.getElementById('deep_analysis_result');
    var progressDiv = document.getElementById('deep_progress');
    var loadingDiv = document.getElementById('deep_loading');

    var formData = new FormData();
    formData.append('action', 'ajax_md5_deep_analyze');
    if (videoUrl) formData.append('video_url', videoUrl);
    if (m3u8Url) formData.append('m3u8_url', m3u8Url);
    formData.append('scan_mode', scanMode);
    formData.append('offset', offset);
    if (cacheKey) formData.append('cache_key', cacheKey);

    deepFetchWithRetry(formData, 3, 180000)
        .then(function(data) {
            if (data.code !== 200) {
                throw new Error(data.error || data.msg || '未知错误');
            }

            // 累积片段
            _deepAllSegments = _deepAllSegments.concat(data.segments || []);
            _deepCacheKey = data.cache_key || cacheKey;
            _deepM3u8Url = data.m3u8_url || _deepM3u8Url;
            _deepDomain = data.domain || _deepDomain;
            _deepTotal = data.total_segments || 0;

            var analyzed = _deepAllSegments.length;
            var total = data.is_quick_mode ? (data.quick_limit || _deepTotal) : (data.is_sample_mode ? (data.sample_total || _deepTotal) : _deepTotal);
            var percent = total > 0 ? Math.min(100, Math.round(analyzed / total * 100)) : 0;

            // 更新进度
            var modeText = { quick: '极速', sample: '智能抽样', standard: '标准', slow: '稳定' }[scanMode] || scanMode;
            var modeSuffix = data.is_sample_mode ? ' (抽样' + analyzed + '/' + total + '·总' + _deepTotal + '段)' : '模式 · 已分析 ' + analyzed + '/' + total + ' 段';
            updateProgress(percent, analyzed, modeText + modeSuffix);

            // 实时更新页面显示（增量）
            updateDeepResultDisplay(data.is_quick_mode, total);

            if (data.has_more && data.next_offset !== undefined) {
                // 继续下一批
                setTimeout(function() {
                    deepAnalyzeNextBatch(videoUrl, m3u8Url, scanMode, data.next_offset, data.cache_key);
                }, 200);
            } else {
                // 全部完成
                finishDeepAnalyze(data);
            }
        })
        .catch(function(e) {
            handleDeepAnalyzeError('分析失败：' + e.message);
        });
}

function deepFetchWithRetry(formData, maxRetries, timeoutMs) {
    return new Promise(function(resolve, reject) {
        var attempt = 0;

        function tryFetch() {
            var controller = new AbortController();
            var timeoutId = setTimeout(function() { controller.abort(); }, timeoutMs);

            fetch(window.location.href, { method: 'POST', body: formData, signal: controller.signal })
                .then(function(r) {
                    clearTimeout(timeoutId);
                    var contentType = r.headers.get('content-type') || '';
                    if (contentType.indexOf('application/json') === -1) {
                        return r.text().then(function(text) {
                            throw new Error('服务器返回了非 JSON 内容（upstream request timeout）');
                        });
                    }
                    return r.json();
                })
                .then(function(data) {
                    resolve(data);
                })
                .catch(function(e) {
                    clearTimeout(timeoutId);
                    attempt++;
                    if (attempt < maxRetries) {
                        setTimeout(tryFetch, 1000 * attempt);
                    } else {
                        reject(e);
                    }
                });
        }

        tryFetch();
    });
}

function handleDeepAnalyzeError(errorMsg) {
    _deepAnalyzeRunning = false;
    var errorDiv = document.getElementById('deep_error');
    var progressDiv = document.getElementById('deep_progress');
    var loadingDiv = document.getElementById('deep_loading');
    var btn = document.getElementById('deep_analyze_btn');

    if (progressDiv) progressDiv.style.display = 'none';
    if (loadingDiv) loadingDiv.style.display = 'none';
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.textContent = '🚀 开始深度分析';
    }
    if (errorDiv) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = '❌ ' + errorMsg;
    }
}

function updateProgress(percent, analyzed, text) {
    var bar = document.getElementById('deep_progress_bar');
    var pct = document.getElementById('deep_progress_percent');
    var txt = document.getElementById('deep_progress_text');
    if (bar) bar.style.width = percent + '%';
    if (pct) pct.textContent = percent + '%';
    if (txt) txt.textContent = text;
}

function updateDeepResultDisplay(isQuickMode, total) {
    var resultDiv = document.getElementById('deep_analysis_result');
    resultDiv.style.display = 'block';

    var adCount = _deepAllSegments.filter(function(s) { return s.is_ad; }).length;
    var contentCount = _deepAllSegments.length - adCount;
    var analyzed = _deepAllSegments.length;

    // 更新统计
    var statsDiv = document.getElementById('deep_stats');
    var quickHint = isQuickMode ? '<div style="padding:8px 16px;background:#fffbeb;border-radius:6px;color:#d97706"><b>模式：</b>⚡ 极速抽样（' + analyzed + '/' + total + '段）</div>' : '';
    var sampleHint = _deepAllSegments.length > 0 && _deepAllSegments[0].is_sample ? '<div style="padding:8px 16px;background:#ecfdf5;border-radius:6px;color:#059669"><b>模式：</b>🎯 智能抽样（' + analyzed + '/' + total + '·总' + _deepTotal + '段）</div>' : '';
    statsDiv.innerHTML = ''
        + '<div style="padding:8px 16px;background:#f3f4f6;border-radius:6px"><b>总片段：</b>' + _deepTotal + '</div>'
        + '<div style="padding:8px 16px;background:#fef2f2;border-radius:6px;color:#dc2626"><b>广告片段：</b>' + adCount + '</div>'
        + '<div style="padding:8px 16px;background:#f0fdf4;border-radius:6px;color:#059669"><b>正片片段：</b>' + contentCount + '</div>'
        + quickHint + sampleHint;

    // 更新域名
    document.getElementById('deep_domain').textContent = _deepDomain || '';

    // 更新广告片段列表
    var adSegs = _deepAllSegments.filter(function(s) { return s.is_ad; });
    var adSegsDiv = document.getElementById('deep_ad_segments');
    if (adSegs.length === 0) {
        adSegsDiv.innerHTML = '<div style="color:#888;font-size:13px">未检测到广告片段</div>';
    } else {
        var adTable = '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            + '<tr style="background:#f3f4f6"><th style="padding:6px 8px;text-align:left">#</th><th style="padding:6px 8px;text-align:left">时长</th><th style="padding:6px 8px;text-align:left">大小</th><th style="padding:6px 8px;text-align:left">MD5</th><th style="padding:6px 8px;text-align:left">原因</th><th style="padding:6px 8px;text-align:left">操作</th></tr>';
        adSegs.forEach(function(s) {
            var sizeStr = s.size ? (s.size > 1024 ? (s.size/1024).toFixed(1) + 'KB' : s.size + 'B') : '-';
            var markBtn = s.md5 ? '<button onclick="manualMarkSeg(\'' + s.md5 + '\', false, \'' + s.index + '\')" style="padding:3px 8px;font-size:11px;background:#059669;color:white;border:none;border-radius:3px;cursor:pointer">设为正片</button>' : '-';
            adTable += '<tr style="border-bottom:1px solid #e5e7eb">'
                + '<td style="padding:6px 8px">' + s.index + '</td>'
                + '<td style="padding:6px 8px">' + s.duration.toFixed(2) + 's</td>'
                + '<td style="padding:6px 8px">' + sizeStr + '</td>'
                + '<td style="padding:6px 8px;font-family:monospace;font-size:11px;word-break:break-all">' + (s.md5 || 'N/A') + '</td>'
                + '<td style="padding:6px 8px;color:#dc2626">' + (s.reason || '') + '</td>'
                + '<td style="padding:6px 8px">' + markBtn + '</td>'
                + '</tr>';
        });
        adTable += '</table>';
        adSegsDiv.innerHTML = adTable;
    }

    // 更新正片片段列表
    var contentSegs = _deepAllSegments.filter(function(s) { return !s.is_ad; });
    var contentSegsDiv = document.getElementById('deep_content_segments');
    if (contentSegs.length === 0) {
        contentSegsDiv.innerHTML = '<div style="color:#888;font-size:13px">无正片片段</div>';
    } else {
        var contentTable = '<table style="width:100%;border-collapse:collapse;font-size:13px">'
            + '<tr style="background:#f3f4f6"><th style="padding:6px 8px;text-align:left">#</th><th style="padding:6px 8px;text-align:left">时长</th><th style="padding:6px 8px;text-align:left">大小</th><th style="padding:6px 8px;text-align:left">MD5</th><th style="padding:6px 8px;text-align:left">状态</th><th style="padding:6px 8px;text-align:left">操作</th></tr>';
        contentSegs.forEach(function(s) {
            var sizeStr = s.size ? (s.size > 1024 ? (s.size/1024).toFixed(1) + 'KB' : s.size + 'B') : '-';
            var markBtn = s.md5 ? '<button onclick="manualMarkSeg(\'' + s.md5 + '\', true, \'' + s.index + '\')" style="padding:3px 8px;font-size:11px;background:#dc2626;color:white;border:none;border-radius:3px;cursor:pointer">设为广告</button>' : '-';
            contentTable += '<tr style="border-bottom:1px solid #e5e7eb">'
                + '<td style="padding:6px 8px">' + s.index + '</td>'
                + '<td style="padding:6px 8px">' + s.duration.toFixed(2) + 's</td>'
                + '<td style="padding:6px 8px">' + sizeStr + '</td>'
                + '<td style="padding:6px 8px;font-family:monospace;font-size:11px;word-break:break-all">' + (s.md5 || 'N/A') + '</td>'
                + '<td style="padding:6px 8px;color:#059669">' + (s.reason || s.status || '') + '</td>'
                + '<td style="padding:6px 8px">' + markBtn + '</td>'
                + '</tr>';
        });
        contentTable += '</table>';
        contentSegsDiv.innerHTML = contentTable;
    }
}

function finishDeepAnalyze(data) {
    _deepAnalyzeRunning = false;

    var btn = document.getElementById('deep_analyze_btn');
    var progressDiv = document.getElementById('deep_progress');

    btn.disabled = false;
    btn.style.opacity = '1';
    btn.textContent = '🚀 开始深度分析';
    progressDiv.style.display = 'none';

    document.getElementById('deep_clean_m3u8').value = data.clean_m3u8 || '';

    runDeepClusterAnalysis();
}

function runDeepClusterAnalysis() {
    var clusterStatusDiv = document.getElementById('deep_cluster_status');
    var clusterSummaryDiv = document.getElementById('deep_cluster_summary');

    if (clusterStatusDiv) clusterStatusDiv.style.display = 'block';
    if (clusterStatusDiv) clusterStatusDiv.innerHTML = '🔬 正在进行深度聚类分析（广告集群识别 + 比特率异常检测）...';

    var formData = new FormData();
    formData.append('action', 'ajax_md5_deep_cluster');
    formData.append('segments', JSON.stringify(_deepAllSegments));
    formData.append('total_segments', _deepTotal || 0);
    formData.append('m3u8_content', _deepM3u8Content || '');
    formData.append('m3u8_url', _deepM3u8Url || '');

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(t) {
                    throw new Error('聚类分析返回非JSON');
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.code !== 200) {
                throw new Error(data.error || '聚类分析失败');
            }

            _deepAllSegments = data.refined_segments || _deepAllSegments;
            _deepAnalysisResult = data.deep_analysis || null;

            if (data.clean_m3u8) {
                document.getElementById('deep_clean_m3u8').value = data.clean_m3u8;
            }

            if (clusterStatusDiv) {
                clusterStatusDiv.innerHTML = '✅ 深度聚类分析完成！' + (data.analysis_summary || '');
                clusterStatusDiv.style.background = '#f0fdf4';
                clusterStatusDiv.style.color = '#059669';
            }

            if (clusterSummaryDiv && data.ad_clusters && data.ad_clusters.length > 0) {
                var clusterHtml = '<div style="margin-top:10px"><b>🎯 广告集群识别：</b><ul style="margin:5px 0;padding-left:20px">';
                data.ad_clusters.forEach(function(c) {
                    clusterHtml += '<li>第 ' + c.start + '-' + c.end + ' 段，共 ' + c.count + ' 段（' + (c.total_duration || 0).toFixed(1) + '秒）</li>';
                });
                clusterHtml += '</ul></div>';
                clusterSummaryDiv.innerHTML = clusterHtml;
            }

            if (data.deep_analysis) {
                renderCommercialPanel(data.deep_analysis);
                renderSignaturePanel(data.deep_analysis);
            }

            updateDeepResultDisplay(false, _deepTotal);
        })
        .catch(function(e) {
            if (clusterStatusDiv) {
                clusterStatusDiv.innerHTML = '⚠️ 深度聚类分析未执行：' + e.message;
                clusterStatusDiv.style.background = '#fffbeb';
                clusterStatusDiv.style.color = '#d97706';
            }
        });
}

function renderCommercialPanel(deepAnalysis) {
    var panel = document.getElementById('deep_commercial_panel');
    if (!panel) return;
    panel.style.display = 'block';

    var statsDiv = document.getElementById('deep_commercial_stats');
    var breaksDiv = document.getElementById('deep_commercial_breaks');

    var commercialBreaks = deepAnalysis.commercial_breaks || [];
    var discontinuityBreaks = deepAnalysis.discontinuity_breaks || [];
    var adCount = deepAnalysis.ad_count || 0;
    var adDuration = deepAnalysis.ad_duration || 0;
    var adPercentage = deepAnalysis.ad_percentage || 0;

    var adSuspectedDiscontinuities = discontinuityBreaks.filter(function(d) { return d.has_ad_suspected; }).length;

    statsDiv.innerHTML = ''
        + '<div style="padding:6px 12px;background:#fee2e2;border-radius:6px;color:#dc2626"><b>广告片段：</b>' + adCount + ' 段 (' + adDuration.toFixed(1) + 's)</div>'
        + '<div style="padding:6px 12px;background:#fed7aa;border-radius:6px;color:#ea580c"><b>广告插播段：</b>' + commercialBreaks.length + ' 个</div>'
        + '<div style="padding:6px 12px;background:#fde68a;border-radius:6px;color:#b45309"><b>DISCONTINUITY：</b>' + discontinuityBreaks.length + ' 个</div>'
        + '<div style="padding:6px 12px;background:#fecaca;border-radius:6px;color:#991b1b"><b>疑似广告插播：</b>' + adSuspectedDiscontinuities + ' 个</div>'
        + '<div style="padding:6px 12px;background:#f3f4f6;border-radius:6px;color:#4b5563"><b>广告占比：</b>' + adPercentage + '%</div>';

    var html = '';

    if (commercialBreaks.length > 0) {
        html += '<div style="margin-bottom:12px"><b style="color:#dc2626;font-size:13px">🚫 广告插播段列表：</b>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px">';
        html += '<tr style="background:#f3f4f6"><th style="padding:5px 8px;text-align:left">#</th><th style="padding:5px 8px;text-align:left">起始段</th><th style="padding:5px 8px;text-align:left">结束段</th><th style="padding:5px 8px;text-align:left">片段数</th><th style="padding:5px 8px;text-align:left">时长</th></tr>';
        commercialBreaks.forEach(function(c, i) {
            html += '<tr style="border-bottom:1px solid #fecaca">'
                + '<td style="padding:5px 8px">' + (i+1) + '</td>'
                + '<td style="padding:5px 8px">' + c.start + '</td>'
                + '<td style="padding:5px 8px">' + c.end + '</td>'
                + '<td style="padding:5px 8px">' + c.count + '</td>'
                + '<td style="padding:5px 8px">' + (c.total_duration || 0).toFixed(1) + 's</td>'
                + '</tr>';
        });
        html += '</table></div>';
    }

    if (discontinuityBreaks.length > 0) {
        html += '<div><b style="color:#b45309;font-size:13px">📍 DISCONTINUITY 插播点：</b>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px">';
        html += '<tr style="background:#f3f4f6"><th style="padding:5px 8px;text-align:left">#</th><th style="padding:5px 8px;text-align:left">位置段号</th><th style="padding:5px 8px;text-align:left">类型</th></tr>';
        discontinuityBreaks.forEach(function(d, i) {
            var typeClass = d.has_ad_suspected ? 'color:#dc2626' : 'color:#6b7280';
            var typeLabel = d.has_ad_suspected ? '⚠️ 疑似广告插播' : '🔄 场景切换';
            html += '<tr style="border-bottom:1px solid #e5e7eb">'
                + '<td style="padding:5px 8px">' + (i+1) + '</td>'
                + '<td style="padding:5px 8px;font-family:monospace">' + d.segment_index + '</td>'
                + '<td style="padding:5px 8px;' + typeClass + '">' + typeLabel + '</td>'
                + '</tr>';
        });
        html += '</table></div>';
    }

    if (commercialBreaks.length === 0 && discontinuityBreaks.length === 0) {
        html = '<div style="color:#6b7280;font-size:13px;padding:10px;text-align:center">未检测到广告插播或 DISCONTINUITY 切换点</div>';
    }

    breaksDiv.innerHTML = html;
}

function renderSignaturePanel(deepAnalysis) {
    var panel = document.getElementById('deep_signature_panel');
    if (!panel) return;
    panel.style.display = 'block';

    var adSigs = deepAnalysis.ad_signatures || [];
    var signatures = deepAnalysis.signatures || {};

    var adSigDiv = document.getElementById('deep_ad_signatures');
    if (adSigs.length === 0) {
        adSigDiv.innerHTML = '<div style="color:#6b7280;font-size:12px;padding:8px;text-align:center">暂无已识别的广告特征码（需要更多样本分析）</div>';
    } else {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:11px">';
        html += '<tr style="background:#f3f4f6"><th style="padding:4px 6px;text-align:left">#</th><th style="padding:4px 6px;text-align:left">MD5 特征码</th><th style="padding:4px 6px;text-align:left">大小</th><th style="padding:4px 6px;text-align:left">时长</th><th style="padding:4px 6px;text-align:left">识别原因</th></tr>';
        adSigs.forEach(function(s, i) {
            var sizeStr = s.size ? (s.size > 1024 ? (s.size/1024).toFixed(1) + 'KB' : s.size + 'B') : '-';
            html += '<tr style="border-bottom:1px solid #e9d5ff">'
                + '<td style="padding:4px 6px">' + (i+1) + '</td>'
                + '<td style="padding:4px 6px;font-family:monospace;color:#7c3aed;word-break:break-all">' + s.md5 + '</td>'
                + '<td style="padding:4px 6px">' + sizeStr + '</td>'
                + '<td style="padding:4px 6px">' + (s.duration || 0).toFixed(2) + 's</td>'
                + '<td style="padding:4px 6px;color:#6b7280">' + (s.reason || '') + '</td>'
                + '</tr>';
        });
        html += '</table>';
        adSigDiv.innerHTML = html;
    }

    var fnDiv = document.getElementById('deep_filename_patterns');
    if (signatures.filename_patterns && Object.keys(signatures.filename_patterns).length > 0) {
        var fnHtml = '';
        var count = 0;
        for (var prefix in signatures.filename_patterns) {
            if (count >= 5) break;
            fnHtml += '<div style="padding:2px 0"><span style="color:#7c3aed">' + prefix + '</span> : ' + signatures.filename_patterns[prefix] + ' 段</div>';
            count++;
        }
        fnDiv.innerHTML = fnHtml;
    }

    var md5Div = document.getElementById('deep_md5_prefixes');
    if (signatures.md5_prefixes && Object.keys(signatures.md5_prefixes).length > 0) {
        var md5Html = '';
        var count = 0;
        for (var pfx in signatures.md5_prefixes) {
            if (count >= 5) break;
            md5Html += '<div style="padding:2px 0"><span style="color:#7c3aed">' + pfx + '...</span> : ' + signatures.md5_prefixes[pfx] + ' 段</div>';
            count++;
        }
        md5Div.innerHTML = md5Html;
    }

    var sizeDiv = document.getElementById('deep_size_distribution');
    if (signatures.size_distribution && Object.keys(signatures.size_distribution).length > 0) {
        var sizeHtml = '';
        var count = 0;
        for (var bucket in signatures.size_distribution) {
            if (count >= 5) break;
            sizeHtml += '<div style="padding:2px 0"><span style="color:#7c3aed">' + bucket + '-' + (parseInt(bucket)+100) + 'KB</span> : ' + signatures.size_distribution[bucket] + ' 段</div>';
            count++;
        }
        sizeDiv.innerHTML = sizeHtml;
    }
}

function exportAdSignatures() {
    if (!_deepAnalysisResult || !_deepAnalysisResult.ad_signatures) {
        alert('暂无广告特征码数据');
        return;
    }

    var exportData = {
        export_time: new Date().toISOString(),
        source_url: _deepM3u8Url,
        total_segments: _deepAnalysisResult.total_segments,
        ad_count: _deepAnalysisResult.ad_count,
        ad_duration: _deepAnalysisResult.ad_duration,
        ad_signatures: _deepAnalysisResult.ad_signatures,
        commercial_breaks: _deepAnalysisResult.commercial_breaks,
        signatures: _deepAnalysisResult.signatures,
    };

    var jsonStr = JSON.stringify(exportData, null, 2);
    var blob = new Blob([jsonStr], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'ad_signatures_' + Date.now() + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function manualMarkSeg(md5, isAd, index) {
    var reason = isAd ? '后台人工标记(深度分析)' : '后台人工标记(正片)';
    var actionText = isAd ? '设为广告' : '设为正片';

    if (!confirm('确定将第 ' + index + ' 段 ' + actionText + ' 吗？')) return;

    var formData = new FormData();
    formData.append('action', 'ajax_md5_manual_mark');
    formData.append('md5', md5);
    formData.append('is_ad', isAd ? '1' : '0');
    formData.append('reason', reason);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code !== 200) {
                alert('标记失败：' + (data.error || '未知错误'));
                return;
            }

            for (var i = 0; i < _deepAllSegments.length; i++) {
                if (_deepAllSegments[i].md5 === md5) {
                    _deepAllSegments[i].is_ad = isAd;
                    _deepAllSegments[i].reason = isAd ? '人工标记-广告' : '人工标记-正片';
                    _deepAllSegments[i].status = isAd ? 'manual_ad' : 'manual_content';
                }
            }

            updateDeepResultDisplay(false, _deepTotal);
            alert('✅ 标记成功！');
        })
        .catch(function(e) {
            alert('标记失败：' + e.message);
        });
}
// ===== MD5 诊断工具 JS =====
var _diagCurrentM3u8Url = '';
var _diagSegments = [];
var _diagSegmentIndex = 0;

function diagM3u8() {
    var url = document.getElementById('diag_url').value.trim();
    if (!url) {
        alert('请输入 M3U8 URL');
        return;
    }

    var resultDiv = document.getElementById('diag_step1_result');
    var rawDiv = document.getElementById('diag_raw_content');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding:8px;color:#666">⏳ 正在解析 M3U8...</div>';
    rawDiv.style.display = 'none';

    var formData = new FormData();
    formData.append('action', 'ajax_md5_diagnose');
    formData.append('url', url);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(t) {
                    throw new Error('服务器返回非JSON内容：' + (t.substring(0, 80) || '空'));
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.code !== 200) {
                resultDiv.innerHTML = '<div style="padding:8px;color:#dc2626">❌ 解析失败：' + (data.error || '未知错误') + '</div>';
                return;
            }

            _diagCurrentM3u8Url = data.url;
            _diagSegments = data.segments_preview || [];
            var total = data.total_segments || 0;

            var segList = '';
            if (_diagSegments.length > 0) {
                segList = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:8px">'
                    + '<tr style="background:#f3f4f6"><th style="padding:4px 6px;text-align:left">#</th><th style="padding:4px 6px;text-align:left">时长</th><th style="padding:4px 6px;text-align:left">URI</th></tr>';
                _diagSegments.forEach(function(s, i) {
                    segList += '<tr><td style="padding:4px 6px;border-bottom:1px solid #eee">' + i + '</td>'
                        + '<td style="padding:4px 6px;border-bottom:1px solid #eee">' + parseFloat(s.duration).toFixed(2) + 's</td>'
                        + '<td style="padding:4px 6px;border-bottom:1px solid #eee;font-size:11px;word-break:break-all;font-family:monospace">' + s.uri + '</td></tr>';
                });
                if (total > _diagSegments.length) {
                    segList += '<tr><td colspan="3" style="padding:4px 6px;color:#888;font-size:12px">... 还有 ' + (total - _diagSegments.length) + ' 个片段未显示</td></tr>';
                }
                segList += '</table>';
            }

            resultDiv.innerHTML = ''
                + '<div style="padding:8px 12px;background:#f0fdf4;border-radius:6px;font-size:13px">'
                + '<b>✅ M3U8 解析成功！</b><br>'
                + '地址：<code style="word-break:break-all">' + data.url + '</code><br>'
                + '域名：' + data.domain + '<br>'
                + '片段总数：<b>' + total + '</b><br>'
                + 'Header 行数：<code>' + data.header.split('\\n').length + '</code><br>'
                + '有 ENDLIST：' + (data.has_endlist ? '✅ 是' : '❌ 否') + '</div>'
                + segList;

            // 显示原始内容
            rawDiv.style.display = 'block';
            document.getElementById('diag_raw_pre').textContent = data.raw_first_2000 || '';

            // 初始化片段索引
            if (_diagSegments.length > 0) {
                _diagSegmentIndex = 0;
            }
        })
        .catch(function(e) {
            resultDiv.innerHTML = '<div style="padding:8px;color:#dc2626">❌ 请求失败：' + e.message + '</div>';
        });
}

function diagFetchSegment() {
    var segIndex = _diagSegmentIndex;
    var seg = _diagSegments[segIndex];

    var resultDiv = document.getElementById('diag_step2_result');
    var step3Div = document.getElementById('diag_step3');
    var m3u8Url = _diagCurrentM3u8Url || document.getElementById('diag_url').value.trim();

    if (!m3u8Url || !seg) {
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="padding:8px;color:#dc2626">❌ 请先执行"1️⃣ 解析M3U8"，确保片段列表已加载</div>';
        return;
    }

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="padding:8px;color:#666">⏳ 正在下载片段 #' + segIndex + '...</div>';
    step3Div.style.display = 'none';

    var formData = new FormData();
    formData.append('action', 'ajax_md5_fetch_segment');
    formData.append('m3u8_url', m3u8Url);
    formData.append('segment_index', segIndex);
    formData.append('segment_uri', seg.uri);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(t) {
                    throw new Error('服务器返回非JSON内容：' + (t.substring(0, 80) || '空'));
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.code !== 200) {
                resultDiv.innerHTML = '<div style="padding:8px;color:#dc2626">❌ 下载失败：' + (data.error || '未知错误') + '</div>';
                return;
            }

            var dbStatus = data.db_status;
            var dbInfo = '';
            if (dbStatus) {
                dbInfo = '<br>数据库状态：<b style="color:' + (dbStatus.is_ad || dbStatus.in_blacklist ? '#dc2626' : '#059669') + '">'
                    + (dbStatus.is_ad ? '🚫 广告(' + dbStatus.count + '次)' :
                       dbStatus.in_blacklist ? '🚫 黑名单' :
                       dbStatus.is_whitelist ? '✅ 白名单(正片)' :
                       dbStatus.count + '次出现')
                    + '</b>';
            } else {
                dbInfo = '<br>数据库状态：<span style="color:#d97706">⚠️ 数据库中无记录（新片段）</span>';
            }

            var httpColor = data.http_code >= 200 && data.http_code < 300 ? '#059669' : '#dc2626';
            var sizeKB = (data.size / 1024).toFixed(1);
            var md5Color = data.md5 ? '#059669' : '#dc2626';

            resultDiv.innerHTML = ''
                + '<div style="padding:10px 12px;background:#f0fdf4;border-radius:6px;font-size:13px">'
                + '<b>✅ 片段下载成功！</b>' + dbInfo + '<br>'
                + 'HTTP 状态：<b style="color:' + httpColor + '">' + data.http_code + '</b><br>'
                + '片段大小：<b>' + sizeKB + ' KB</b><br>'
                + 'MD5：<code style="font-size:12px;word-break:break-all;color:' + md5Color + '">' + (data.md5 || 'N/A') + '</code><br>'
                + '片段URI：<code style="font-size:11px;word-break:break-all">' + data.uri + '</code><br>'
                + '完整URL：<a href="' + data.full_url + '" target="_blank" style="font-size:11px;word-break:break-all">' + data.full_url + '</a><br>'
                + 'CURL错误：' + (data.curl_error || '无') + '</div>';

            // 填充手动标记表单
            if (data.md5) {
                step3Div.style.display = 'block';
                document.getElementById('diag_mark_md5').value = data.md5;
                document.getElementById('diag_mark_result').textContent = '';
            }
        })
        .catch(function(e) {
            resultDiv.innerHTML = '<div style="padding:8px;color:#dc2626">❌ 请求失败：' + e.message + '</div>';
        });
}

function diagNextSegment() {
    if (_diagSegments.length === 0) {
        alert('请先执行"1️⃣ 解析M3U8"');
        return;
    }
    _diagSegmentIndex = (_diagSegmentIndex + 1) % _diagSegments.length;
    diagFetchSegment();
}

function diagManualMark() {
    var md5 = document.getElementById('diag_mark_md5').value.trim();
    var isAd = document.getElementById('diag_mark_type').value === 'ad';
    var reason = document.getElementById('diag_mark_reason').value.trim() || (isAd ? '人工标记广告' : '人工标记正片');

    if (!md5) {
        alert('请先获取片段 MD5');
        return;
    }

    var resultDiv = document.getElementById('diag_mark_result');
    resultDiv.textContent = '⏳ 正在标记...';

    var formData = new FormData();
    formData.append('action', 'ajax_md5_manual_mark');
    formData.append('md5', md5);
    formData.append('is_ad', isAd ? '1' : '0');
    formData.append('reason', reason);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(t) {
                    throw new Error('服务器返回非JSON内容：' + (t.substring(0, 80) || '空'));
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.code === 200) {
                var tag = isAd ? '🚫 已标记为广告' : '✅ 已标记为正片';
                resultDiv.innerHTML = '<b style="color:#059669">' + tag + '</b> — ' + reason + '<br>MD5: ' + md5;
            } else {
                resultDiv.innerHTML = '<span style="color:#dc2626">❌ 标记失败：' + (data.error || '未知错误') + '</span>';
            }
        })
        .catch(function(e) {
            resultDiv.innerHTML = '<span style="color:#dc2626">❌ 请求失败：' + e.message + '</span>';
        });
}

// 复制清理后的 M3U8
function copyDeepM3u8() {
    var m3u8Content = document.getElementById('deep_clean_m3u8').value;
    if (!m3u8Content) {
        var errorDiv = document.getElementById('deep_error');
        errorDiv.style.display = 'block';
        errorDiv.textContent = '⚠️ 没有可复制的内容';
        return;
    }
    var btn = event.target;
    var origText = btn.textContent;
    navigator.clipboard.writeText(m3u8Content).then(function() {
        btn.textContent = '✅ 已复制';
        setTimeout(function() { btn.textContent = origText; }, 2000);
    }).catch(function() {
        // 降级方案
        var textarea = document.getElementById('deep_clean_m3u8');
        textarea.select();
        document.execCommand('copy');
        btn.textContent = '✅ 已复制';
        setTimeout(function() { btn.textContent = origText; }, 2000);
    });
}
// ===== MD5 指纹去广告：页面访问时触发每日检测 =====
(function() {
    var md5Enabled = <?php echo !empty($noadConfig['md5_enabled']) ? 'true' : 'false'; ?>;
    if (!md5Enabled) return;
    var md5LastKey = 'md5_last_test_' + '<?php echo date('Y-m-d'); ?>';
    if (!localStorage.getItem(md5LastKey)) {
        localStorage.setItem(md5LastKey, '1');
        setTimeout(function() {
            try { md5LoadStats(); } catch (e) {}
        }, 3000);
    }
})();
// ===== 万能规则2 - 测试当前参数 =====
function featTestCurrent() {
    var resultEl = document.getElementById('featResult');
    resultEl.textContent = '🧪 正在批量调用解析接口分析视频...';
    var sampleUrl = document.querySelector('input[name="ruyi_auto_optimize_sample_url"]').value.trim();
    var formData = new FormData();
    formData.append('action', 'ajax_feat_test');
    if (sampleUrl) formData.append('test_url', sampleUrl);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) resultEl.textContent = data.report;
            else resultEl.textContent = '❌ ' + (data.error || '未知错误');
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 万能规则2 - 加载特征库统计 =====
function featLoadStats() {
    var resultEl = document.getElementById('featResult');
    var panelEl = document.getElementById('featStatsPanel');
    resultEl.textContent = '📊 正在加载特征库统计...';
    var formData = new FormData();
    formData.append('action', 'ajax_feat_stats');
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                resultEl.textContent = data.report;
                if (panelEl && data.report) {
                    var lines = data.report.split('\n');
                    if (lines.length > 0) panelEl.textContent = lines[0];
                }
            }
            else resultEl.textContent = '❌ ' + (data.error || '未知错误');
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 万能规则2 - 学习特征 =====
function featLearnCurrent() {
    var resultEl = document.getElementById('featResult');
    resultEl.textContent = '📚 正在批量学习视频特征...';
    var sampleUrl = document.querySelector('input[name="ruyi_auto_optimize_sample_url"]').value.trim();
    var formData = new FormData();
    formData.append('action', 'ajax_feat_learn');
    if (sampleUrl) formData.append('test_url', sampleUrl);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) resultEl.textContent = data.report;
            else resultEl.textContent = '❌ ' + (data.error || '未知错误');
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 万能规则2 - 手动标记域名 =====
function featMarkDomain() {
    var resultEl = document.getElementById('featResult');
    var domain = document.querySelector('input[name="mark_domain"]').value.trim();
    var type = document.querySelector('select[name="mark_type"]').value;
    if (!domain) { alert('请输入要标记的域名'); return; }
    resultEl.textContent = '➕ 正在标记 ' + domain + ' 为 ' + (type === 'content' ? '正片' : (type === 'ad' ? '广告' : '未知')) + '...';
    var formData = new FormData();
    formData.append('action', 'ajax_feat_mark');
    formData.append('domain', domain);
    formData.append('type', type);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) resultEl.textContent = '✅ 已标记 ' + data.domain + ' 为 ' + data.type_name;
            else resultEl.textContent = '❌ ' + (data.error || '未知错误');
        })
        .catch(function(e) { resultEl.textContent = '❌ 请求失败：' + e.message; });
}
// ===== 万能规则2 - 恢复默认参数 =====
function featResetDefault() {
    if (!confirm('确认恢复万能规则2的默认参数吗？\n当前修改将被覆盖。')) return;
    var defaults = {
        feat_max_sources: 3,
        feat_source_timeout: 15,
        feat_total_timeout: 60,
        feat_min_votes: 2,
        feat_max_concurrency: 2,
        feat_sample_count: 15
    };
    for (var key in defaults) {
        var input = document.querySelector('input[name="' + key + '"]');
        if (input) input.value = defaults[key];
    }
    ['feat_enabled', 'feat_learn_enabled', 'feat_low_resource_mode', 'feat_use_proxy'].forEach(function(key) {
        var el = document.querySelector('input[name="' + key + '"]');
        if (el) el.checked = true;
    });
    document.getElementById('featResult').textContent = '↩ 已填充默认值，请点击「保存规则2参数」来生效';
}
// ===== 万能规则2 - 页面访问时自动加载特征库统计 =====
(function() {
    var featEnabled = <?php echo !empty($noadConfig['feat_enabled']) ? 'true' : 'false'; ?>;
    if (!featEnabled) return;
    var featLastKey = 'feat_last_stats_' + '<?php echo date('Y-m-d'); ?>';
    if (!localStorage.getItem(featLastKey)) {
        localStorage.setItem(featLastKey, '1');
        setTimeout(function() {
            try { featLoadStats(); } catch (e) {}
        }, 3500);
    }
})();

// ===== 增强的子标签切换 - 处理所有 SubNav 容器（冗余但安全） =====
(function() {
    var subNavs = document.querySelectorAll('[id$="SubNav"]');
    subNavs.forEach(function(nav) {
        var btns = nav.querySelectorAll('button[data-subtab]');
        btns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                var subtab = this.getAttribute('data-subtab');
                var parentPanel = nav.closest('.tab-panel');
                if (!parentPanel) return;
                var subPanels = parentPanel.querySelectorAll('.sub-tab-panel');
                btns.forEach(function(b) {
                    b.classList.remove('active');
                    b.style.background = 'transparent';
                });
                this.classList.add('active');
                this.style.background = 'rgba(255,255,255,0.15)';
                subPanels.forEach(function(sp) {
                    if (sp.id === 'subtab-' + subtab) {
                        sp.classList.add('active');
                        sp.style.display = 'block';
                    } else {
                        sp.classList.remove('active');
                        sp.style.display = 'none';
                    }
                });
            });
        });
    });
})();

// ===== 自动保存功能（点击空白区域时，保存当前激活子标签内的表单到 localStorage） =====
(function() {
    var saveCooldown = 3000; // 3 秒冷却，避免频繁保存
    var lastSaveTime = 0;

    document.addEventListener('click', function(e) {
        // 如果点击的是交互元素（按钮、输入框、链接、下拉等），不触发自动保存
        var interactive = e.target.closest('button, input, select, textarea, a, label, [data-tab], [data-subtab]');
        if (interactive) return;

        var now = Date.now();
        if (now - lastSaveTime < saveCooldown) return;

        // 找当前激活的 tab-panel
        var activePanel = document.querySelector('.tab-panel.active');
        if (!activePanel) return;

        // 检查是否有子标签，如果有，优先取激活的子标签
        var subActive = activePanel.querySelector('.sub-tab-panel.active');
        var targetPanel = subActive || activePanel;

        var form = targetPanel.querySelector('form[method="post"]');
        if (!form) return;

        // 检查表单是否有可提交内容（输入框）
        var inputs = form.querySelectorAll('input[type="text"], input[type="number"], input[type="checkbox"], input[type="email"], input[type="url"], textarea, select');
        if (inputs.length === 0) return;

        // 取得动作（action 隐藏字段）
        var actionInput = form.querySelector('input[name="action"]');
        var actionName = actionInput ? actionInput.value : 'unknown';

        // 保存当前状态到 localStorage
        var formData = {};
        inputs.forEach(function(inp) {
            var key = inp.name || '';
            if (!key) return;
            if (inp.type === 'checkbox') {
                formData[key] = inp.checked;
            } else {
                formData[key] = inp.value;
            }
        });
        try {
            localStorage.setItem('adminAutoSave_' + actionName, JSON.stringify({time: now, data: formData}));
        } catch (err) { /* localStorage 可能禁用，静默失败 */ }

        showAutoSaveNotification('✓ 已自动保存当前设置');
        lastSaveTime = now;
    });

    function showAutoSaveNotification(msg) {
        var oldNotice = document.getElementById('autoSaveNotice');
        if (oldNotice) oldNotice.remove();

        var notice = document.createElement('div');
        notice.id = 'autoSaveNotice';
        notice.style.cssText = 'position:fixed;top:20px;right:20px;background:linear-gradient(135deg,#11998e,#38ef7d);color:white;padding:12px 24px;border-radius:12px;font-weight:600;font-size:14px;box-shadow:0 4px 20px rgba(17,153,142,0.3);z-index:99999;animation:slideInRight 0.4s ease;';
        notice.textContent = msg;
        document.body.appendChild(notice);

        setTimeout(function() {
            notice.style.opacity = '0';
            notice.style.transition = 'opacity 0.4s';
            setTimeout(function() { notice.remove(); }, 400);
        }, 2000);
    }

    // 添加动画（如果还没加过）
    if (!document.getElementById('autoSaveStyle')) {
        var style = document.createElement('style');
        style.id = 'autoSaveStyle';
        style.textContent = '@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}';
        document.head.appendChild(style);
    }
})();

// ===== 还原功能 - 从 localStorage 恢复指定动作的表单值 =====
function restoreFromAutoSave(actionName) {
    try {
        var saved = localStorage.getItem('adminAutoSave_' + actionName);
        if (!saved) {
            alert('没有找到自动保存记录 (action: ' + actionName + ')');
            return;
        }
        var parsed = JSON.parse(saved);
        var timeStr = new Date(parsed.time).toLocaleString();
        if (!confirm('确认还原 ' + timeStr + ' 的保存内容？\n当前页面上未保存的修改将会被覆盖。')) return;

        // 查找当前激活面板内的对应表单
        var activePanel = document.querySelector('.tab-panel.active');
        if (!activePanel) { alert('找不到当前面板'); return; }

        var forms = activePanel.querySelectorAll('form[method="post"]');
        var targetForm = null;
        for (var fi = 0; fi < forms.length; fi++) {
            var a = forms[fi].querySelector('input[name="action"]');
            if (a && a.value === actionName) {
                targetForm = forms[fi];
                break;
            }
        }
        // 回退：取第一个表单
        if (!targetForm && forms.length > 0) targetForm = forms[0];
        if (!targetForm) { alert('找不到可还原的表单'); return; }

        // 恢复输入值
        var restoredCount = 0;
        Object.keys(parsed.data).forEach(function(key) {
            var input = targetForm.querySelector('[name="' + key + '"]');
            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = !!parsed.data[key];
                } else {
                    input.value = parsed.data[key];
                }
                restoredCount++;
            }
        });
        alert('✓ 已还原上次自动保存（共 ' + restoredCount + ' 项）');
    } catch (e) {
        alert('还原失败：' + e.message);
    }
}
</script>
</body>
</html>
<?php
}
?>