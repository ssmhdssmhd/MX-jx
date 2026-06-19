<?php
/**
 * 沫兮万能解析 - 后台管理系统
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 3.1.0
 */

error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
date_default_timezone_set('Asia/Shanghai');
session_start();

$adminConfig = require 'config/admin.php';
$apiConfig = require 'config/api.php';
$platformConfig = require 'config/platform.php';
$switchConfig = require 'config/switch.php';
$currentScript = basename($_SERVER['SCRIPT_NAME']);

if (empty($adminConfig['admin_enabled'])) {
    http_response_code(403);
    exit('<div style="text-align:center;padding:50px;"><h2>后台已禁用</h2></div>');
}

if (!empty($adminConfig['enforce_port']) && !empty($adminConfig['allowed_ports'])) {
    $curPort = (int)($_SERVER['SERVER_PORT'] ?? 80);
    if (!in_array($curPort, $adminConfig['allowed_ports'], true)) {
        http_response_code(403);
        $pStr = implode(',', $adminConfig['allowed_ports']);
        echo '<div style="text-align:center;padding:50px;"><h2>端口访问受限</h2><p>允许端口: ' . $pStr . ' / 当前: ' . $curPort . '</p></div>';
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

$isLoggedIn = (!empty($_SESSION['admin_logged_in']) && ($_SESSION['admin_login_time'] + $adminConfig['session_lifetime'] > time()));

$loginError = '';
if (!$isLoggedIn && isset($_POST['action']) && $_POST['action'] === 'login') {
    $password = $_POST['password'] ?? '';
    $attemptFile = __DIR__ . '/cache/_login_attempts.php';
    $attemptData = [];
    if (file_exists($attemptFile)) {
        $attemptData = include $attemptFile;
    }
    if (!is_array($attemptData)) $attemptData = [];
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    if (!empty($attemptData[$clientIp]) && $attemptData[$clientIp]['count'] >= $adminConfig['max_login_attempts'] && $now - $attemptData[$clientIp]['last_time'] < $adminConfig['lockout_duration']) {
        $loginError = '登录失败次数过多，已锁定';
    } elseif (md5($password) === $adminConfig['admin_password']) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = $now;
        unset($attemptData[$clientIp]);
        writePhpFile($attemptFile, '<?php return ' . var_export($attemptData, true) . ';');
        header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    } else {
        $attemptData[$clientIp] = array('count' => ($attemptData[$clientIp]['count'] ?? 0) + 1, 'last_time' => $now);
        writePhpFile($attemptFile, '<?php return ' . var_export($attemptData, true) . ';');
        $loginError = '密码错误';
    }
}

if ($isLoggedIn && isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
    exit;
}

function writePhpFile($file, $content) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return false; }
    return rename($tmp, $file);
}

if (!$isLoggedIn) {
    renderLoginPage($loginError);
    exit;
}

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'save_api':
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
            if (saveConfig('config/api.php', $newApis)) { $apiConfig = require 'config/api.php'; $message = 'API配置已保存'; }
            else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            break;
        case 'save_platform':
            $newPlats = array();
            $names = $_POST['platform_name'] ?? array();
            $rules = $_POST['platform_rule'] ?? array();
            for ($i = 0; $i < count($names); $i++) {
                $n = trim($names[$i] ?? '');
                $r = trim($rules[$i] ?? '');
                if ($n !== '' && $r !== '') $newPlats[$n] = $r;
            }
            if (saveConfig('config/platform.php', $newPlats)) { $platformConfig = require 'config/platform.php'; $message = '平台规则已保存'; }
            else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            break;
        case 'save_switch':
            $newSw = array(
                'enable_global_api' => isset($_POST['enable_global_api']),
                'zjk_file_path' => trim($_POST['zjk_file_path'] ?? 'ZJK.txt'),
                'global_api_timeout' => max(1, (int)($_POST['global_api_timeout'] ?? 8)),
                'global_api_count' => max(0, (int)($_POST['global_api_count'] ?? 6)),
                'enable_zjk_apis' => isset($_POST['enable_zjk_apis']),
                'enable_m3u8_direct' => isset($_POST['enable_m3u8_direct']),
                'enable_unified_display' => isset($_POST['enable_unified_display']),
            );
            if (saveConfig('config/switch.php', $newSw, true)) { $switchConfig = require 'config/switch.php'; $message = '系统开关已保存'; }
            else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            break;
        case 'save_zjk':
            $zjkContent = $_POST['zjk_content'] ?? '';
            $zjkPath = __DIR__ . '/' . trim($switchConfig['zjk_file_path'] ?? 'ZJK.txt');
            if (file_put_contents($zjkPath, $zjkContent) !== false) $message = 'ZJK.txt已保存';
            else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            break;
        case 'change_password':
            $old = $_POST['old_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $conf = $_POST['confirm_password'] ?? '';
            if (md5($old) !== $adminConfig['admin_password']) { $message = '原密码错误'; $messageType = 'error'; }
            elseif (strlen($new) < 6) { $message = '新密码至少6位'; $messageType = 'error'; }
            elseif ($new !== $conf) { $message = '两次密码不一致'; $messageType = 'error'; }
            else {
                $newAdmin = $adminConfig;
                $newAdmin['admin_password'] = md5($new);
                if (saveConfig('config/admin.php', $newAdmin, true)) $message = '密码修改成功';
                else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            }
            break;
        case 'change_path':
            $newPath = trim($_POST['new_path'] ?? '');
            if ($newPath === '' || !preg_match('/^[a-zA-Z0-9_\\-]+\\.php$/', $newPath)) { $message = '路径格式不正确'; $messageType = 'error'; }
            elseif ($newPath === 'index.php') { $message = '不能命名为 index.php'; $messageType = 'error'; }
            elseif (file_exists(__DIR__ . '/' . $newPath) && $newPath !== $currentScript) { $message = '文件已存在'; $messageType = 'error'; }
            else {
                $newAdmin = $adminConfig;
                $newAdmin['admin_path'] = $newPath;
                $ok1 = saveConfig('config/admin.php', $newAdmin, true);
                $ok2 = true;
                if ($newPath !== $currentScript) {
                    if (!rename(__DIR__ . '/' . $currentScript, __DIR__ . '/' . $newPath)) $ok2 = false;
                }
                if ($ok1 && $ok2) { header('Location: ' . htmlspecialchars($newPath)); exit; }
                else { $message = '修改失败，请检查文件权限'; $messageType = 'error'; }
            }
            break;
        case 'save_admin_config':
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
            if (saveConfig('config/admin.php', $newAdmin, true)) { $adminConfig = $newAdmin; $message = '后台设置已保存'; }
            else { $message = '保存失败，请检查文件权限'; $messageType = 'error'; }
            break;
        case 'create_backup':
            $backupDir = __DIR__ . '/cache/backup_' . date('Ymd_His');
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            @copy(__DIR__ . '/config/api.php', $backupDir . '/api.php');
            @copy(__DIR__ . '/config/platform.php', $backupDir . '/platform.php');
            @copy(__DIR__ . '/config/switch.php', $backupDir . '/switch.php');
            @copy(__DIR__ . '/config/admin.php', $backupDir . '/admin.php');
            if (file_exists(__DIR__ . '/ZJK.txt')) @copy(__DIR__ . '/ZJK.txt', $backupDir . '/ZJK.txt');
            $message = '备份已创建: ' . basename($backupDir);
            break;
        case 'clear_log':
            if (file_exists(__DIR__ . '/cache/admin_log.txt')) unlink(__DIR__ . '/cache/admin_log.txt');
            $message = '操作日志已清空';
            break;
    }
}

function saveConfig($file, $data, $isAdmin = false) {
    $full = __DIR__ . '/' . $file;
    if ($isAdmin) {
        $content = "<?php\n/**\n * " . basename($file) . "\n */\n\nreturn array(\n";
        foreach ($data as $k => $v) {
            if (is_bool($v)) $vStr = $v ? 'true' : 'false';
            elseif (is_numeric($v) && !is_string($v)) $vStr = (string)$v;
            elseif (is_array($v)) $vStr = var_export($v, true);
            else $vStr = '"' . addslashes((string)$v) . '"';
            $content .= "    \"" . $k . "\" => " . $vStr . ",\n";
        }
        $content .= ");\n";
    } else {
        $content = "<?php\n/**\n * " . basename($file) . "\n */\n\nreturn array(\n";
        foreach ($data as $k => $v) {
            $content .= "    \"" . addslashes($k) . "\" => \"" . addslashes((string)$v) . "\",\n";
        }
        $content .= ");\n";
    }
    return writePhpFile($full, $content);
}

function renderLoginPage($error = '') {
    global $currentScript;
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>登录 - 沫兮万能解析后台</title>
<style><?php include __DIR__ . '/admin_style.css'; ?></style></head><body>
<div class="login-wrap"><div class="login-box"><div class="login-icon">&#127916;</div>
<h1>沫兮万能解析管理后台</h1>
<div class="sub">PHP 智能线路切换系统 v3.1.0</div>
<?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="login">
<input type="password" name="password" placeholder="请输入管理员密码（默认 admin123）" required autofocus>
<button type="submit" class="btn-primary">登录后台</button>
</form>
<div class="info"><strong>默认密码:</strong> admin123（登录后请立即修改）<br><strong>当前路径:</strong> /<?php echo htmlspecialchars($currentScript); ?></div>
<div class="ft">MX-射手沫蝴蝶</div>
</div></div></body></html><?php
}

include __DIR__ . '/admin_main.php';
