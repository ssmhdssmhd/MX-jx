<?php
/**
 * 沫兮万能解析 - 后台管理系统 v4.0.0
 * 架构：控制器 + 模板分离
 *   - 本文件：登录校验 + 请求分发 + 数据库操作
 *   - admin_tpl.php：UI 模板渲染
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
date_default_timezone_set('Asia/Shanghai');
session_start();

$adminConfig  = require __DIR__ . '/config/admin.php';
$apiConfig    = require __DIR__ . '/config/api.php';
$platformCfg  = require __DIR__ . '/config/platform.php';
$switchConfig = require __DIR__ . '/config/switch.php';
$noadConfig   = file_exists(__DIR__ . '/config/noad.php')
              ? require __DIR__ . '/config/noad.php'
              : array('noad_enabled' => false, 'resource_types' => array(),
                      'stats_enabled' => false);
$currentScript = basename($_SERVER['SCRIPT_NAME']);

$msg = '';
$msgType = 'success';

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

// ========== 登录 ==========
$loginError = '';
$isLoggedIn = (!empty($_SESSION['admin_logged_in']) &&
               ($_SESSION['admin_login_time'] + $adminConfig['session_lifetime'] > time()));

if (!$isLoggedIn && ($_POST['action'] ?? '') === 'login') {
    $attemptFile = __DIR__ . '/cache/_login_attempts.php';
    $attemptData = array();
    if (file_exists($attemptFile)) {
        $attemptData = @include $attemptFile;
        if (!is_array($attemptData)) $attemptData = array();
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
        $attemptData[$clientIp] = array('count' => ($attemptData[$clientIp]['count'] ?? 0) + 1,
                                        'last_time' => $now);
        writePhpFile($attemptFile, '<?php return ' . var_export($attemptData, true) . ';');
        $loginError = '密码错误';
    }
}

if ($isLoggedIn && ($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
    exit;
}

function writePhpFile($file, $content) {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return false; }
    return rename($tmp, $file);
}

if (!$isLoggedIn) {
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
    <title>登录 - 沫兮万能解析后台 v4.0.0</title>
    <link rel="stylesheet" href="admin_style.css"></head><body>
    <div class="login-wrap"><div class="login-box"><div class="login-icon">🎬</div>
    <h1>沫兮万能解析管理后台</h1>
    <div class="sub">PHP 智能线路切换 + NoAd 去广告解析 v4.0.0</div>
    <?php if ($loginError): ?><div class="err"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="请输入管理员密码（默认 admin123）" required autofocus>
    <button type="submit" class="btn-primary">登录后台</button></form>
    <div class="info"><strong>默认密码:</strong> admin123（登录后请立即修改）<br>
    <strong>当前路径:</strong> /<?php echo htmlspecialchars($currentScript); ?></div>
    <div class="ft">MX-射手沫蝴蝶</div></div></div></body></html><?php
    exit;
}

// ========== 登录后：数据库 ==========
$db = null;
if (!empty($noadConfig['noad_enabled']) && !empty($noadConfig['stats_enabled']) &&
    extension_loaded('pdo_sqlite')) {
    try {
        require_once __DIR__ . '/core/Database.php';
        $db = Database::getInstance($noadConfig['sqlite_path']);
    } catch (Exception $e) { $db = null; }
}

// ========== 动作处理 ==========
$action = $_POST['action'] ?? '';
$page = $_GET['page'] ?? 'dashboard';

// --- v3 原功能 ---
if ($action === 'save_api') {
    $newApis = array();
    $names = $_POST['api_name'] ?? array();
    $urls = $_POST['api_url'] ?? array();
    $timeouts = $_POST['api_timeout'] ?? array();
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
    $newPlats = array();
    $names = $_POST['platform_name'] ?? array();
    $rules = $_POST['platform_rule'] ?? array();
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
    $newSw = array(
        'enable_global_api' => isset($_POST['enable_global_api']),
        'zjk_file_path'     => trim($_POST['zjk_file_path'] ?? 'ZJK.txt'),
        'global_api_timeout'=> max(1, (int)($_POST['global_api_timeout'] ?? 8)),
        'global_api_count'  => max(0, (int)($_POST['global_api_count'] ?? 6)),
        'enable_zjk_apis'   => isset($_POST['enable_zjk_apis']),
        'enable_m3u8_direct'=> isset($_POST['enable_m3u8_direct']),
        'enable_unified_display' => isset($_POST['enable_unified_display']),
    );
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
    if ($newPath === '' || !preg_match('/^[a-zA-Z0-9_\\-]+\\.php$/', $newPath)) {
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
    $ports = array();
    foreach (explode(',', trim($_POST['allowed_ports'] ?? '')) as $p) {
        $p = (int)trim($p);
        if ($p > 0 && $p < 65536) $ports[] = $p;
    }
    $newAdmin['allowed_ports'] = array_values(array_unique($ports));
    $ips = array();
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

// --- v4.0 NoAd 新增动作 ---
elseif ($action === 'save_noad_source' && $db) {
    $data = array(
        'name'    => trim($_POST['source_name'] ?? ''),
        'url'     => trim($_POST['source_url'] ?? ''),
        'type_id' => (int)($_POST['source_type'] ?? 1),
        'timeout' => max(1, (int)($_POST['source_timeout'] ?? 8)),
        'enabled' => isset($_POST['source_enabled']) ? 1 : 0,
        'sort_order' => (int)($_POST['source_order'] ?? 0),
        'match_rules' => trim($_POST['source_match'] ?? ''),
        'remark'  => trim($_POST['source_remark'] ?? ''),
    );
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

// --- 辅助函数 ---
function buildPhpReturnArray($arr) {
    $content = "<?php\n/** 配置文件 */\n\nreturn array(\n";
    foreach ($arr as $k => $v) {
        $content .= "    \"" . addslashes($k) . "\" => \"" . addslashes((string)$v) . "\",\n";
    }
    $content .= ");\n";
    return $content;
}
function buildNoadConfig($c) {
    $kw = var_export($c['ad_keywords'] ?? array('ad','广告'), true);
    $wl = var_export($c['whitelist_keywords'] ?? array('main','正片','hd'), true);
    $rt = var_export($c['resource_types'] ?? defaultTypes(), true);
    $ds = var_export($c['default_sources'] ?? array(), true);
    return "<?php\n/** Noad 去广告系统 v4.0.0 配置 */\n\nreturn array(\n" .
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
    return array(
        1 => array('key'=>'movie','name'=>'🎬 电影资源','icon'=>'🎬'),
        2 => array('key'=>'tv','name'=>'📺 电视剧集','icon'=>'📺'),
        3 => array('key'=>'variety','name'=>'🎤 综艺娱乐','icon'=>'🎤'),
        4 => array('key'=>'anime','name'=>'🎭 动漫动画','icon'=>'🎭'),
        5 => array('key'=>'document','name'=>'📚 纪录片','icon'=>'📚'),
        6 => array('key'=>'sports','name'=>'⚽ 体育赛事','icon'=>'⚽'),
        7 => array('key'=>'short','name'=>'📱 短视频','icon'=>'📱'),
    );
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

// ========== 查询数据供模板使用 ==========
$overview = $db ? $db->getOverviewStats() : array(
    'total_requests' => 0, 'today_requests' => 0, 'total_ad_removed' => 0,
    'cache_hit_rate' => 0, 'avg_response_time' => 0, 'source_count' => 0,
);
$dailyStats = $db ? $db->getDailyStats(7) : array();
$topSources = $db ? $db->getTopSources(10) : array();
$recentLogs = $db ? $db->getRecentLogs(30) : array();
$noadSources = $db ? $db->getSources(0, false) : array();
$adRules = $db ? $db->getAdRules(false) : array();

// 引入 UI 模板
include __DIR__ . '/admin_tpl.php';
