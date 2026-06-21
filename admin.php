<?php
/**
 * 沫兮万能解析 - 管理后台（单文件集成版 v4.3）
 * ------------------------------------------------------------
 * 原文件：admin.php + admin_tpl.php + admin_main.php + admin_style.css
 * 现全部整合到此文件中，便于部署与维护
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
    return "<?php\n/** NoAd 去广告系统 v4 配置 */\n\nreturn array(\n" .
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
            "    'concurrent_limit' => 8,\n" .
            "    'ad_keywords' => " . $kw . ",\n" .
            "    'whitelist_keywords' => " . $wl . ",\n" .
            "    'resource_types' => " . $rt . ",\n" .
            "    'default_sources' => " . $ds . ",\n" .
            "    'stats_enabled' => " . ($c['stats_enabled'] ? 'true' : 'false') . ",\n" .
            "    'stats_log_request' => true,\n" .
            "    'stats_top_limit' => 20,\n" .
            "    'sqlite_path' => __DIR__ . '/../cache/noad.db',\n" .
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
    writePhpFile(__DIR__ . '/config/noad.php', buildNoadConfig($newCfg));
    $noadConfig = require __DIR__ . '/config/noad.php';
    $msg = 'Noad 配置已保存';
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
        <div class="sub">PHP 智能线路切换 + NoAd 去广告解析 v4.3</div>
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
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",Arial,sans-serif;background:#f5f6fa;color:#333;min-height:100vh}
.login-wrap{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-box{background:white;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:100%;max-width:400px;padding:40px 30px}
.login-box h1{text-align:center;font-size:22px;margin-bottom:8px;color:#333}
.login-box .sub{text-align:center;color:#999;font-size:13px;margin-bottom:30px}
.login-box .login-icon{text-align:center;font-size:56px;margin-bottom:15px}
.login-box input[type="password"]{width:100%;padding:12px 15px;border:2px solid #e0e0e0;border-radius:8px;font-size:15px;margin-bottom:15px}
.login-box input[type="password"]:focus{border-color:#667eea;outline:none}
.login-box .err{background:#fef;border:1px solid #fbb;color:#c33;padding:12px;border-radius:8px;margin-bottom:20px;font-size:14px;text-align:center}
.login-box .info{background:#f4f4f9;border-left:4px solid #667eea;padding:12px 15px;border-radius:4px;margin-top:25px;font-size:12px;color:#666;line-height:1.7}
.login-box .ft{text-align:center;margin-top:25px;color:#bbb;font-size:12px}
.btn-primary{background:#667eea;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px}
.btn-primary:hover{background:#5568d3}

/* ===== 后台页面样式 ===== */
.admin-wrap{max-width:1400px;margin:0 auto;padding:20px}
.admin-header{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:12px;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.admin-header h1{margin:0;font-size:22px}
.admin-header .sub{font-size:13px;opacity:0.9;margin-top:4px}
.admin-header a{color:#fff;background:rgba(255,255,255,0.18);padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px}

.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px}
.badge-green{background:#d4edda;color:#155724}
.badge-red{background:#f8d7da;color:#721c24}
.badge-blue{background:#d1ecf1;color:#0c5460}
.badge-yellow{background:#fff3cd;color:#856404}

.stat-card{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1)}
.stat-card .num{font-size:32px;font-weight:700;margin:6px 0}
.stat-card .label{font-size:13px;opacity:0.9}
.stat-card.v2{background:linear-gradient(135deg,#f093fb,#f5576c)}
.stat-card.v3{background:linear-gradient(135deg,#4facfe,#00f2fe)}
.stat-card.v4{background:linear-gradient(135deg,#43e97b,#38f9d7)}
.stat-card.v5{background:linear-gradient(135deg,#fa709a,#fee140)}
.stat-card.v6{background:linear-gradient(135deg,#30cfd0,#330867)}

.chart-bar{height:20px;background:#e9ecef;border-radius:10px;overflow:hidden;margin:6px 0}
.chart-fill{height:100%;background:linear-gradient(90deg,#667eea,#764ba2);transition:width 0.4s}

.tabs-nav{display:flex;flex-wrap:wrap;gap:2px;padding:0;margin:0 0 18px;border-bottom:2px solid #e9ecef}
.tabs-nav button{background:none;border:none;padding:10px 16px;cursor:pointer;font-size:13px;color:#555;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all 0.2s;border-radius:6px 6px 0 0}
.tabs-nav button.active{color:#764ba2;border-bottom-color:#764ba2;font-weight:600}
.tabs-nav button:hover{background:#f5f5fa}

.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadein 0.3s}
@keyframes fadein{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

.panel{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,0.06)}
.panel h2,.panel h3{margin-top:0;margin-bottom:12px}
.panel p{line-height:1.8;font-size:14px;margin:8px 0;color:#555}

.grid-flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:14px}
.row-flex{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px}

table.data-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
table.data-table th{background:#f5f5fa;padding:10px 12px;text-align:left;font-weight:600;color:#555;border-bottom:2px solid #e0e0e0}
table.data-table td{padding:10px 12px;border-bottom:1px solid #f0f0f5;vertical-align:top}
table.data-table input[type="text"],table.data-table input[type="number"]{width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px}
table.data-table input[type="text"]:focus,table.data-table input[type="number"]:focus{border-color:#667eea;outline:none}

input[type="text"],input[type="number"],input[type="password"],select,textarea{padding:8px 12px;border:1px solid #d0d0d8;border-radius:6px;font-size:13px;background:#fff;outline:none;transition:border-color 0.2s}
input[type="text"]:focus,input[type="number"]:focus,input[type="password"]:focus,select:focus,textarea:focus{border-color:#667eea}
textarea{width:100%;min-height:200px;font-family:Consolas,Monaco,monospace;line-height:1.6}

.btn-danger-sm{background:#dc3545;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin:2px}
.btn-secondary-sm{background:#6c757d;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin:2px}
.btn-primary-sm{background:#667eea;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;margin:2px}
.btn-danger-sm:hover{background:#c82333}
.btn-secondary-sm:hover{background:#5a6268}
.btn-primary-sm:hover{background:#5568d3}

.msg-box{padding:12px 18px;background:#d4edda;color:#155724;border-radius:8px;margin-bottom:16px;border:1px solid #a5d6a7}
.msg-box.err{background:#fbe9e7;color:#c62828;border-color:#ef9a9a}

code.monocode{background:#f5f5fa;padding:2px 8px;border-radius:4px;font-size:12px;font-family:Consolas,Monaco,monospace}

.seg-row{padding:8px 12px;border-bottom:1px solid #f0f0f5;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.seg-row.ad{background:#ffebee;color:#b71c1c}
.seg-row .seg-idx{font-weight:600;min-width:40px;color:#666}
.seg-row .seg-dur{min-width:80px;color:#888}
.seg-row .seg-time{min-width:140px;color:#555}
.seg-row .seg-uri{flex:1;word-break:break-all;font-size:12px;color:#555;font-family:Consolas,monospace}
.seg-row .seg-reason{color:#d32f2f;font-size:11px;min-width:120px}

#parseStatus{color:#555;font-size:13px}
.parse-stat-box{background:#fff;border-radius:10px;padding:12px;margin-top:10px;border:1px solid #e9ecef}

@media(max-width:768px){
    .tabs-nav{overflow-x:auto;flex-wrap:nowrap}
    .grid-flow{grid-template-columns:1fr}
    .admin-header{flex-direction:column;align-items:flex-start}
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
]);

function renderAdminPanel($page, $msg, $msgType, $d) {
    extract($d);
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>沫兮万能解析 - 管理后台 v4.3</title>
<?php renderInlineStyles(); ?>
</head>
<body>
<div class="admin-wrap">
    <header class="admin-header">
        <div>
            <h1>🎬 沫兮万能解析管理后台</h1>
            <div class="sub">v4.3 · NoAd 去广告 + 智能线路切换（单文件版）</div>
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
        <button data-tab="dashboard" class="<?php echo $page==='dashboard'?'active':''; ?>">📊 总览</button>
        <button data-tab="m3u8_parse" class="<?php echo $page==='m3u8_parse'?'active':''; ?>">📼 M3U8 解析</button>
        <button data-tab="sites" class="<?php echo $page==='sites'?'active':''; ?>">🔗 资源站点</button>
        <button data-tab="parse_log" class="<?php echo $page==='parse_log'?'active':''; ?>">📄 解析日志</button>
        <button data-tab="cache_mgr" class="<?php echo $page==='cache_mgr'?'active':''; ?>">💽 缓存管理</button>
        <button data-tab="noad_stats" class="<?php echo $page==='noad_stats'?'active':''; ?>">📈 NoAd 统计</button>
        <button data-tab="noad_sources" class="<?php echo $page==='noad_sources'?'active':''; ?>">🔌 解析源</button>
        <button data-tab="noad_rules" class="<?php echo $page==='noad_rules'?'active':''; ?>">🚫 广告规则</button>
        <button data-tab="noad_config" class="<?php echo $page==='noad_config'?'active':''; ?>">⚙️ NoAd 设置</button>
        <button data-tab="api" class="<?php echo $page==='api'?'active':''; ?>">📡 API 线路</button>
        <button data-tab="platform" class="<?php echo $page==='platform'?'active':''; ?>">🎯 平台规则</button>
        <button data-tab="switch" class="<?php echo $page==='switch'?'active':''; ?>">🔀 系统开关</button>
        <button data-tab="zjk" class="<?php echo $page==='zjk'?'active':''; ?>">📝 自定义接口</button>
        <button data-tab="custom_algorithms" class="<?php echo $page==='custom_algorithms'?'active':''; ?>">🧩 算法</button>
        <button data-tab="backup" class="<?php echo $page==='backup'?'active':''; ?>">💾 备份</button>
        <button data-tab="password" class="<?php echo $page==='password'?'active':''; ?>">🔐 密码</button>
        <button data-tab="setting" class="<?php echo $page==='setting'?'active':''; ?>">🛠️ 后台设置</button>
    </div>

    <?php
    // ===== 1. 总览 =====
    ?>
    <div class="tab-panel <?php echo $page==='dashboard'?'active':''; ?>" id="tab-dashboard">
        <h2>📊 系统总览</h2>
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
    </div>

    <?php
    // ===== 2. M3U8 解析 =====
    ?>
    <div class="tab-panel <?php echo $page==='m3u8_parse'?'active':''; ?>" id="tab-m3u8_parse">
        <h2>📼 M3U8 解析（去广告分析）</h2>
        <div class="panel">
            <h3>🎯 输入 M3U8 链接</h3>
            <div class="row-flex">
                <input type="text" id="m3u8Url" placeholder="例如：https://example.com/video/playlist.m3u8" style="flex:1;min-width:300px">
                <select id="siteId"><option value="0">关联站点（可选）</option></select>
                <button type="button" class="btn-primary-sm" onclick="parseM3U8()" style="font-size:14px;padding:8px 18px">🔍 解析</button>
                <span id="parseStatus" style="color:#888;font-size:13px">等待解析...</span>
            </div>
            <div id="parseStats" style="margin-top:15px;display:none">
                <div class="grid-flow">
                    <div class="stat-card"><div class="label">总片段</div><div class="num" id="sTotal">0</div></div>
                    <div class="stat-card v2"><div class="label">广告片段</div><div class="num" id="sAd">0</div></div>
                    <div class="stat-card v3"><div class="label">保留片段</div><div class="num" id="sKeep">0</div></div>
                    <div class="stat-card v4"><div class="label">总时长</div><div class="num" id="sDuration">0</div></div>
                </div>
                <div style="margin-top:10px">
                    <button type="button" class="btn-secondary-sm" onclick="document.getElementById('rawContent').style.display=(document.getElementById('rawContent').style.display==='none'?'block':'none')">👁️ 原始内容</button>
                    <button type="button" class="btn-secondary-sm" onclick="copyCleanContent()">📋 复制清洗后的内容</button>
                </div>
            </div>
            <pre id="rawContent" style="margin-top:12px;padding:14px;background:#2b2b3c;color:#d0d0e0;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto;display:none"></pre>
            <div id="segmentList" style="margin-top:15px"></div>
        </div>
    </div>

    <?php
    // ===== 3. 资源站点 =====
    ?>
    <div class="tab-panel <?php echo $page==='sites'?'active':''; ?>" id="tab-sites">
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
                <thead><tr><th>ID</th><th>名称</th><th>短码</th><th>地址</th><th>匹配</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td><?php echo (int)$s['id']; ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($s['short_code'] ?? ''); ?></td>
                    <td style="font-size:12px;word-break:break-all;max-width:200px"><?php echo htmlspecialchars($s['base_url'] ?? ''); ?></td>
                    <td style="font-size:12px"><?php echo htmlspecialchars($s['match_pattern'] ?? ''); ?></td>
                    <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">切换</button></form>
                        <button type="button" class="btn-primary-sm" onclick="editSite(<?php echo (int)$s['id'].",'".htmlspecialchars(addslashes($s['name']))."','".htmlspecialchars(addslashes($s['short_code']??''))."','".htmlspecialchars(addslashes($s['base_url']??''))."','".htmlspecialchars(addslashes($s['match_pattern']??''))."','".htmlspecialchars(addslashes($s['algorithms']??''))."','".htmlspecialchars(addslashes($s['remark']??''))."',".(empty($s['enabled'])?'false':'true').")">✏️ 编辑</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认删除？')"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
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
    <div class="tab-panel <?php echo $page==='parse_log'?'active':''; ?>" id="tab-parse_log">
        <h2>📄 解析日志</h2>
        <div class="panel">
            <?php if (empty($parseLogs)): ?>
                <p style="color:#888">暂无解析记录。</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>ID</th><th>站点</th><th>总片段</th><th>广告</th><th>保留</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($parseLogs as $l): ?>
                <tr>
                    <td><?php echo (int)$l['id']; ?></td>
                    <td><?php echo htmlspecialchars($l['site_name'] ?? ''); ?></td>
                    <td><?php echo (int)($l['total_segments'] ?? 0); ?></td>
                    <td style="color:#dc3545"><?php echo (int)($l['ad_segments'] ?? 0); ?></td>
                    <td style="color:#28a745"><?php echo (int)($l['keep_segments'] ?? 0); ?></td>
                    <td><a href="?page=m3u8_parse" class="btn-primary-sm">查看</a></td>
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
    <div class="tab-panel <?php echo $page==='cache_mgr'?'active':''; ?>" id="tab-cache_mgr">
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
    // ===== 6. NoAd 数据统计 =====
    ?>
    <div class="tab-panel <?php echo $page==='noad_stats'?'active':''; ?>" id="tab-noad_stats">
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
                foreach ($dailyStats as $d) if (($d['total_requests'] ?? 0