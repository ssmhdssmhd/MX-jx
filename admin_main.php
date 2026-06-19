<?php
/**
 * 管理后台主页面模板
 * 由 admin.php 引入
 */
$tab = $_GET['tab'] ?? 'dashboard';
$zjkFile = $switchConfig['zjk_file_path'] ?? 'ZJK.txt';
$zjkContent = '';
$zjkFullPath = __DIR__ . '/' . $zjkFile;
if (file_exists($zjkFullPath)) {
    $zjkContent = file_get_contents($zjkFullPath);
}

$phpVer = PHP_VERSION;
$curlOk = extension_loaded('curl');
$writeCheck = array(
    'config/api.php' => is_writable(__DIR__ . '/config/api.php'),
    'config/platform.php' => is_writable(__DIR__ . '/config/platform.php'),
    'config/switch.php' => is_writable(__DIR__ . '/config/switch.php'),
    $zjkFile => is_writable($zjkFullPath),
);

$logLines = array();
$logFile = __DIR__ . '/cache/admin_log.txt';
if (file_exists($logFile)) {
    $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($allLines) $logLines = array_slice(array_reverse($allLines), 0, 50);
}

$parsedApis = array();
foreach ($apiConfig as $name => $val) {
    $parts = explode('|', $val);
    $parsedApis[] = array(
        'name' => $name,
        'url' => rtrim($parts[0], '|'),
        'timeout' => isset($parts[1]) ? (int)$parts[1] : 5,
    );
}

$testResults = array();
if ($tab === 'test' && !empty($_GET['test_url']) && $curlOk) {
    $testUrl = $_GET['test_url'];
    $apiConfigsTest = array();
    $apiCount = 0;
    foreach ($apiConfig as $name => $config) {
        $apiCount++;
        $parts = explode('|', $config);
        $url = str_replace('?url=', '?url={url}', $parts[0]);
        $to = (int)($parts[1] ?? 5);
        if (!empty($switchConfig['enable_global_api']) && $apiCount <= $switchConfig['global_api_count']) {
            $to = $switchConfig['global_api_timeout'];
        }
        $apiConfigsTest[] = array('name' => $name, 'url' => $url, 'timeout' => $to);
    }
    $mh = curl_multi_init();
    $handles = array();
    foreach ($apiConfigsTest as $cfg) {
        $reqUrl = str_replace('{url}', urlencode($testUrl), $cfg['url']);
        $ch = curl_init($reqUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_multi_add_handle($mh, $ch);
        $handles[] = array('ch' => $ch, 'name' => $cfg['name']);
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
        $testResults[] = array('name' => $h['name'], 'code' => $code, 'time' => $time, 'valid' => $valid);
    }
    curl_multi_close($mh);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>管理后台 - 沫兮万能解析</title>
<style><?php include __DIR__ . '/admin_style.css'; ?></style>
</head>
<body>

<div class="topbar">
<div class="logo">&#127916; 沫兮万能解析 <small>管理后台 v3.1.0</small></div>
<div class="actions">
<span><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '管理员'); ?></span>
<a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">刷新</a>
<a href="?action=logout" onclick="return confirm('确认退出登录？');">退出</a>
</div>
</div>

<div class="layout">
<div class="sidebar">
<a href="?tab=dashboard" class="menu <?php echo $tab==='dashboard' ? 'active' : ''; ?>">📊 仪表盘</a>
<a href="?tab=api" class="menu <?php echo $tab==='api' ? 'active' : ''; ?>">🔌 API 线路</a>
<a href="?tab=platform" class="menu <?php echo $tab==='platform' ? 'active' : ''; ?>">📺 平台规则</a>
<a href="?tab=switch" class="menu <?php echo $tab==='switch' ? 'active' : ''; ?>">🎛️ 系统开关</a>
<a href="?tab=zjk" class="menu <?php echo $tab==='zjk' ? 'active' : ''; ?>">📝 自定义接口</a>
<a href="?tab=test" class="menu <?php echo $tab==='test' ? 'active' : ''; ?>">🧪 接口测试</a>
<a href="?tab=backup" class="menu <?php echo $tab==='backup' ? 'active' : ''; ?>">💾 备份日志</a>
<a href="?tab=password" class="menu <?php echo $tab==='password' ? 'active' : ''; ?>">🔐 修改密码</a>
<a href="?tab=settings" class="menu <?php echo $tab==='settings' ? 'active' : ''; ?>">⚙️ 后台设置</a>
</div>

<div class="main">

<?php if ($message): ?>
<div class="alert <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($tab === 'dashboard'): ?>

<div class="page-header"><h2>📊 仪表盘</h2><p>系统运行状态概览</p></div>

<div class="info-grid">
<div class="info-box"><div class="label">PHP 版本</div><div class="value"><?php echo htmlspecialchars($phpVer); ?></div></div>
<div class="info-box <?php echo $curlOk ? 'green' : 'red'; ?>"><div class="label">cURL 扩展</div><div class="value"><?php echo $curlOk ? '✅ 已启用' : '❌ 未启用'; ?></div></div>
<div class="info-box"><div class="label">API 线路</div><div class="value"><?php echo count($apiConfig); ?> 条</div></div>
<div class="info-box"><div class="label">平台规则</div><div class="value"><?php echo count($platformConfig); ?> 条</div></div>
<div class="info-box orange"><div class="label">总接口模式</div><div class="value"><?php echo empty($switchConfig['enable_global_api']) ? '⭕ 关闭' : '✅ 开启'; ?></div></div>
<div class="info-box green"><div class="label">自定义接口</div><div class="value"><?php echo empty($switchConfig['enable_zjk_apis']) ? '⭕ 禁用' : '✅ 启用'; ?></div></div>
</div>

<div class="card"><h3>📁 文件权限检查</h3>
<?php foreach ($writeCheck as $f => $writable): ?>
<div style="padding:10px 0;border-bottom:1px dashed #eee;font-size:14px">
<?php echo htmlspecialchars($f); ?>
<span class="badge <?php echo $writable ? 'green' : 'red'; ?>" style="float:right"><?php echo $writable ? '可写' : '不可写'; ?></span>
</div>
<?php endforeach; ?>
</div>

<div class="card"><h3>🚀 快速导航</h3>
<div style="line-height:2;font-size:14px">
<p>👉 <a href="?tab=api">管理 API 线路</a> - 增删改解析接口</p>
<p>👉 <a href="?tab=switch">调整系统开关</a> - 超时时间/总接口数</p>
<p>👉 <a href="?tab=test">在线接口测试</a> - 测试所有接口效果</p>
<p>👉 <a href="?tab=password">修改管理员密码</a> - 推荐首次登录后修改</p>
<p>👉 <a href="?tab=settings">修改后台路径</a> - 重命名 admin.php 为自定义路径</p>
</div></div>

<?php elseif ($tab === 'api'): ?>

<div class="page-header"><h2>🔌 API 线路管理</h2><p>管理所有视频解析接口</p></div>
<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_api">
<table class="data-table">
<thead><tr><th>序号</th><th>接口名称</th><th>接口地址</th><th style="width:120px">超时(秒)</th><th style="width:100px" class="center">操作</th></tr></thead>
<tbody id="api-tbody">
<?php foreach ($parsedApis as $idx => $api): ?>
<tr>
<td style="color:#999;text-align:center"><?php echo $idx + 1; ?></td>
<td><input type="text" name="api_name[]" value="<?php echo htmlspecialchars($api['name']); ?>"></td>
<td><input type="text" name="api_url[]" value="<?php echo htmlspecialchars($api['url']); ?>"></td>
<td><input type="number" name="api_timeout[]" value="<?php echo (int)$api['timeout']; ?>" min="1" max="120"></td>
<td class="center"><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">删除</button></td>
</tr>
<?php endforeach; ?>
<?php if (count($parsedApis) === 0): ?>
<tr><td colspan="5" style="text-align:center;padding:30px;color:#999">暂无数据，点击下方"添加一行"按钮增加</td></tr>
<?php endif; ?>
</tbody>
</table>
<div class="btn-row">
<button type="button" class="btn btn-secondary" onclick="addApiRow()">➕ 添加一行</button>
<button type="submit" class="btn btn-primary">💾 保存全部</button>
</div>
</form>
</div>

<div class="card"><h3>💡 使用说明</h3>
<div style="font-size:14px;color:#555;line-height:2">
<p><strong>接口名称:</strong> 自定义标识符（如 MyJson1、BackupApi 等），不能重复</p>
<p><strong>接口地址:</strong> 完整的接口 URL，末尾带 <code style="background:#f5f6fa;padding:2px 6px;border-radius:3px">?url=</code> 即可</p>
<p><strong>超时:</strong> 超过这个时间未响应则视为失败，建议 5-15 秒</p>
</div></div>

<?php elseif ($tab === 'platform'): ?>

<div class="page-header"><h2>📺 平台规则管理</h2><p>为特定视频平台指定优先的解析接口</p></div>
<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_platform">
<table class="data-table">
<thead><tr><th>平台名</th><th>规则 (域名关键字 | 优先接口名)</th><th style="width:100px" class="center">操作</th></tr></thead>
<tbody id="plat-tbody">
<?php foreach ($platformConfig as $name => $rule): ?>
<tr>
<td><input type="text" name="platform_name[]" value="<?php echo htmlspecialchars($name); ?>"></td>
<td><input type="text" name="platform_rule[]" value="<?php echo htmlspecialchars($rule); ?>"></td>
<td class="center"><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">删除</button></td>
</tr>
<?php endforeach; ?>
<?php if (count($platformConfig) === 0): ?>
<tr><td colspan="3" style="text-align:center;padding:30px;color:#999">暂无数据</td></tr>
<?php endif; ?>
</tbody>
</table>
<div class="btn-row">
<button type="button" class="btn btn-secondary" onclick="addPlatRow()">➕ 添加一行</button>
<button type="submit" class="btn btn-primary">💾 保存全部</button>
</div>
</form></div>

<div class="card"><h3>💡 使用说明</h3>
<div style="font-size:14px;color:#555;line-height:2">
<p>格式: <code style="background:#f5f6fa;padding:2px 6px;border-radius:3px">域名关键字 | 接口名称</code></p>
<p>示例: <code style="background:#f5f6fa;padding:2px 6px;border-radius:3px">v.qq.com | MyJson1</code> 表示视频链接包含 v.qq.com 时优先使用 MyJson1 接口</p>
</div></div>

<?php elseif ($tab === 'switch'): ?>

<div class="page-header"><h2>🎛️ 系统开关配置</h2><p>调整解析引擎参数</p></div>
<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_switch">

<div class="form-row">
<label>启用总接口模式<small>开启后前 N 条 API 兼容所有平台</small></label>
<label class="switch"><input type="checkbox" name="enable_global_api" <?php echo empty($switchConfig['enable_global_api']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="form-row">
<label>总接口数量<small>前 N 条 API 作为总接口</small></label>
<input type="number" name="global_api_count" value="<?php echo (int)($switchConfig['global_api_count'] ?? 6); ?>" min="0" max="50">
</div>

<div class="form-row">
<label>总接口超时(秒)<small>总接口请求的超时时间</small></label>
<input type="number" name="global_api_timeout" value="<?php echo (int)($switchConfig['global_api_timeout'] ?? 8); ?>" min="1" max="120">
</div>

<div class="form-row">
<label>启用 ZJK 自定义接口<small>加载 ZJK.txt 中的接口</small></label>
<label class="switch"><input type="checkbox" name="enable_zjk_apis" <?php echo empty($switchConfig['enable_zjk_apis']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="form-row">
<label>ZJK 文件路径</label>
<input type="text" name="zjk_file_path" value="<?php echo htmlspecialchars($switchConfig['zjk_file_path'] ?? 'ZJK.txt'); ?>">
</div>

<div class="form-row">
<label>启用 M3U8 直链检测<small>识别 m3u8 链接直接返回</small></label>
<label class="switch"><input type="checkbox" name="enable_m3u8_direct" <?php echo empty($switchConfig['enable_m3u8_direct']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="form-row">
<label>统一显示格式<small>msg 和 url 字段显示相同内容</small></label>
<label class="switch"><input type="checkbox" name="enable_unified_display" <?php echo empty($switchConfig['enable_unified_display']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="btn-row right"><button type="submit" class="btn btn-primary">💾 保存设置</button></div>
</form></div>

<?php elseif ($tab === 'zjk'): ?>

<div class="page-header"><h2>📝 自定义接口 (ZJK.txt)</h2><p>通过文本文件管理额外的解析接口</p></div>
<div class="card">
<form method="post">
<input type="hidden" name="action" value="save_zjk">
<h3 style="margin-bottom:10px">📄 文件: <?php echo htmlspecialchars($zjkFile); ?></h3>
<p style="font-size:13px;color:#888;margin-bottom:15px">每行一条，格式: 接口URL|超时秒数。以 # 开头的行是注释。</p>
<textarea name="zjk_content" rows="15" placeholder="# 在这里编辑你的自定义接口&#10;https://jx1.example.com/?url=|8&#10;https://jx2.example.com/?url=|10"><?php echo htmlspecialchars($zjkContent); ?></textarea>
<div class="btn-row right"><button type="submit" class="btn btn-primary">💾 保存</button></div>
</form></div>

<?php elseif ($tab === 'test'): ?>

<div class="page-header"><h2>🧪 接口在线测试</h2><p>测试所有 API 线路对指定视频链接的解析效果</p></div>
<div class="card">
<form method="get"><input type="hidden" name="tab" value="test">
<div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap">
<label style="font-size:14px;color:#555">视频链接:</label>
<input type="text" name="test_url" value="<?php echo isset($_GET['test_url']) ? htmlspecialchars($_GET['test_url']) : 'https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html'; ?>" style="flex:1;min-width:300px;padding:10px;border:1px solid #ddd;border-radius:6px">
<button type="submit" class="btn btn-primary">开始测试</button>
</div></form></div>

<?php if (!empty($testResults)): ?>
<div class="card"><h3>📊 测试结果</h3>
<table class="data-table">
<thead><tr><th>接口名称</th><th>HTTP 状态</th><th>响应时间</th><th>有效响应</th></tr></thead>
<tbody>
<?php foreach ($testResults as $r): ?>
<tr>
<td><?php echo htmlspecialchars($r['name']); ?></td>
<td><span class="badge <?php echo $r['code'] == 200 ? 'green' : 'red'; ?>"><?php echo (int)$r['code']; ?></span></td>
<td><?php echo number_format($r['time'], 3); ?> 秒</td>
<td><span class="badge <?php echo $r['valid'] ? 'green' : 'red'; ?>"><?php echo $r['valid'] ? '✅ 成功' : '❌ 失败'; ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php elseif ($tab === 'backup'): ?>

<div class="page-header"><h2>💾 备份与日志</h2><p>管理配置备份和查看操作日志</p></div>

<div class="card"><h3>📦 快速备份</h3>
<p style="font-size:14px;color:#666;margin-bottom:15px">将当前所有配置文件备份到 cache/backup_时间戳/ 目录</p>
<form method="post"><input type="hidden" name="action" value="create_backup">
<button type="submit" class="btn btn-primary">📦 立即备份</button>
</form></div>

<div class="card"><h3>📋 操作日志 (最近 50 条)</h3>
<?php if (empty($logLines)): ?>
<div style="padding:20px;color:#999;text-align:center">暂无日志</div>
<?php else: ?>
<div class="log-area"><?php foreach ($logLines as $line): echo htmlspecialchars($line) . '<br>'; endforeach; ?></div>
<form method="post" onsubmit="return confirm('确认清空所有操作日志？');" style="margin-top:15px">
<input type="hidden" name="action" value="clear_log">
<button type="submit" class="btn btn-danger">🗑️ 清空日志</button>
</form>
<?php endif; ?></div>

<?php elseif ($tab === 'password'): ?>

<div class="page-header"><h2>🔐 修改管理员密码</h2><p>建议首次登录后立即修改</p></div>
<div class="card">
<form method="post" onsubmit="return checkPwdForm();">
<input type="hidden" name="action" value="change_password">
<div class="form-row"><label>当前密码</label><input type="password" name="old_password" id="old_password" required></div>
<div class="form-row"><label>新密码<small>至少 6 位</small></label><input type="password" name="new_password" id="new_password" required minlength="6"></div>
<div class="form-row"><label>确认新密码</label><input type="password" name="confirm_password" id="confirm_password" required minlength="6"></div>
<div class="btn-row right"><button type="submit" class="btn btn-primary">🔐 修改密码</button></div>
</form></div>

<?php elseif ($tab === 'settings'): ?>

<div class="page-header"><h2>⚙️ 后台设置</h2><p>调整访问权限、路径等高级配置</p></div>

<div class="card"><h3>🔧 权限与安全</h3>
<form method="post"><input type="hidden" name="action" value="save_admin_config">

<div class="form-row">
<label>启用后台<small>关闭后所有人无法登录</small></label>
<label class="switch"><input type="checkbox" name="admin_enabled" <?php echo empty($adminConfig['admin_enabled']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="form-row">
<label>强制端口限制<small>开启后仅允许指定端口访问</small></label>
<label class="switch"><input type="checkbox" name="enforce_port" <?php echo empty($adminConfig['enforce_port']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="form-row">
<label>允许的端口列表<small>多个端口用英文逗号分隔</small></label>
<input type="text" name="allowed_ports" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ports'] ?? array())); ?>">
</div>

<div class="form-row">
<label>允许的 IP 白名单<small>留空表示不限制；多个 IP 用逗号分隔</small></label>
<input type="text" name="allowed_ips" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ips'] ?? array())); ?>">
</div>

<div class="form-row">
<label>登录会话有效期(秒)<small>默认 7200 = 2 小时</small></label>
<input type="number" name="session_lifetime" value="<?php echo (int)($adminConfig['session_lifetime'] ?? 7200); ?>" min="60" max="86400">
</div>

<div class="form-row">
<label>最大失败尝试次数</label>
<input type="number" name="max_login_attempts" value="<?php echo (int)($adminConfig['max_login_attempts'] ?? 5); ?>" min="1" max="50">
</div>

<div class="form-row">
<label>失败锁定时长(秒)<small>超过最大尝试次数后的锁定时间</small></label>
<input type="number" name="lockout_duration" value="<?php echo (int)($adminConfig['lockout_duration'] ?? 300); ?>" min="60" max="86400">
</div>

<div class="form-row">
<label>启用操作日志</label>
<label class="switch"><input type="checkbox" name="enable_log" <?php echo empty($adminConfig['enable_log']) ? '' : 'checked'; ?>><span class="slider"></span></label>
</div>

<div class="btn-row right"><button type="submit" class="btn btn-primary">💾 保存设置</button></div>
</form></div>

<div class="card"><h3>📍 修改后台访问路径</h3>
<p style="font-size:14px;color:#666;margin-bottom:15px">当前路径: <strong><?php echo htmlspecialchars($currentScript); ?></strong></p>
<form method="post" onsubmit="return confirm('修改后将自动重命名文件并跳转，确认继续？');">
<input type="hidden" name="action" value="change_path">
<div class="form-row">
<label>新的后台文件名<small>必须以 .php 结尾</small></label>
<input type="text" name="new_path" value="<?php echo htmlspecialchars($currentScript); ?>" pattern="[a-zA-Z0-9_\-]+\.php" required>
</div>
<div class="btn-row right"><button type="submit" class="btn btn-success">✅ 修改路径</button></div>
</form>
<div class="hint">
💡 <strong>关于端口 8899 的说明:</strong><br>
如果你想通过 <code>域名:8899/admin.php</code> 访问后台，需要配置 Nginx/Apache 监听 8899 端口指向本项目目录。<br>
宝塔面板: 网站 → 设置 → 配置文件 → 添加 <code>listen 8899;</code> → 重载配置。<br>
同时确保服务器防火墙开放 8899 端口。本页"强制端口限制"选项开启后，非白名单端口访问会被拒绝。
</div></div>

<?php else: ?>

<div class="alert info">未知模块，返回 <a href="?tab=dashboard">仪表盘</a>。</div>

<?php endif; ?>

<div style="text-align:center;padding:25px;color:#aaa;font-size:12px;margin-top:30px">
MX-射手沫蝴蝶 · QQ: 2094332348 · 沫兮万能解析 v3.1.0
</div>

</div></div>

<script>
function removeRow(btn){ if(confirm('确认删除这一行？')){ btn.closest('tr').remove(); } }
function addApiRow(){
    var tb=document.getElementById('api-tbody');
    if(tb.querySelector('tr') && tb.querySelector('tr').style.textAlign==='center') tb.innerHTML='';
    var tr=document.createElement('tr');
    tr.innerHTML='<td style="color:#999;text-align:center">+</td><td><input type="text" name="api_name[]" placeholder="接口名称"></td><td><input type="text" name="api_url[]" placeholder="https://jx.example.com/?url="></td><td><input type="number" name="api_timeout[]" value="5" min="1" max="120"></td><td class="center"><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">删除</button></td>';
    tb.appendChild(tr);
}
function addPlatRow(){
    var tb=document.getElementById('plat-tbody');
    if(tb.querySelector('tr') && tb.querySelector('tr').style.textAlign==='center') tb.innerHTML='';
    var tr=document.createElement('tr');
    tr.innerHTML='<td><input type="text" name="platform_name[]" placeholder="平台名"></td><td><input type="text" name="platform_rule[]" placeholder="域名关键字|接口名"></td><td class="center"><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">删除</button></td>';
    tb.appendChild(tr);
}
function checkPwdForm(){
    var n=document.getElementById('new_password').value;
    var c=document.getElementById('confirm_password').value;
    if(n!==c){ alert('两次输入的新密码不一致！'); return false; }
    return true;
}
</script>
</body></html>
