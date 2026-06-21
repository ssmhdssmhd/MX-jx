<?php
/**
 * 后台管理系统 UI 模板
 * 由 admin.php 引入，所有变量已在 admin.php 定义
 */
if (!defined('IN_ADMIN') && !isset($isLoggedIn)) { header('HTTP/1.1 403 Forbidden'); exit; }
$totalApis = is_array($apiConfig) ? count($apiConfig) : 0;
$totalPlats = is_array($platformCfg) ? count($platformCfg) : 0;
$rTypes = $noadConfig['resource_types'] ?? array();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>沫兮万能解析 - 管理后台 v4.0.0</title>
<link rel="stylesheet" href="admin_style.css">
<style>
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; }
.badge-green { background:#d4edda; color:#155724; }
.badge-red   { background:#f8d7da; color:#721c24; }
.badge-blue  { background:#d1ecf1; color:#0c5460; }
.badge-yellow{ background:#fff3cd; color:#856404; }
.stat-card { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff;
             padding:20px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
.stat-card .num { font-size:32px; font-weight:700; margin:6px 0; }
.stat-card .label { font-size:13px; opacity:0.9; }
.stat-card.v2 { background:linear-gradient(135deg,#f093fb,#f5576c); }
.stat-card.v3 { background:linear-gradient(135deg,#4facfe,#00f2fe); }
.stat-card.v4 { background:linear-gradient(135deg,#43e97b,#38f9d7); }
.stat-card.v5 { background:linear-gradient(135deg,#fa709a,#fee140); }
.stat-card.v6 { background:linear-gradient(135deg,#30cfd0,#330867); }
.chart-bar { height:20px; background:#e9ecef; border-radius:10px; overflow:hidden; margin:6px 0; }
.chart-fill { height:100%; background:linear-gradient(90deg,#667eea,#764ba2); transition:width 0.4s; }
.tabs-nav { display:flex; flex-wrap:wrap; gap:2px; padding:0; margin:0 0 18px;
            border-bottom:2px solid #e9ecef; }
.tabs-nav button { background:none; border:none; padding:10px 16px; cursor:pointer;
                   font-size:13px; color:#555; border-bottom:2px solid transparent;
                   margin-bottom:-2px; transition:all 0.2s; }
.tabs-nav button.active { color:#764ba2; border-bottom-color:#764ba2; font-weight:600; }
.tab-panel { display:none; }
.tab-panel.active { display:block; animation:fadein 0.3s; }
@keyframes fadein { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
.panel { background:#fff; border-radius:12px; padding:20px; margin-bottom:16px;
         box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.panel h2, .panel h3 { margin-top:0; }
table.data-table { width:100%; border-collapse:collapse; margin-top:10px; }
table.data-table th { background:#f5f5fa; padding:10px; text-align:left; font-size:13px;
                       border-bottom:2px solid #e9ecef; }
table.data-table td { padding:8px 10px; border-bottom:1px solid #f0f0f5; font-size:13px; }
input[type=text], input[type=number], select, textarea {
    padding:6px 10px; border:1px solid #d0d0d8; border-radius:6px; font-size:13px;
    background:#fff; outline:none; transition:border-color 0.2s;
}
input[type=text]:focus, input[type=number]:focus, select:focus, textarea:focus {
    border-color:#667eea;
}
.row-flex { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
.grid-flow { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px; }
.tabs-nav button { transition:color 0.2s, background 0.2s; border-radius:6px 6px 0 0; }
.tabs-nav button:hover { background:#f5f5fa; }
code.monocode { background:#f5f5fa; padding:2px 6px; border-radius:4px; font-size:12px; }
.msg-box { padding:12px 18px; background:#d4edda; color:#155724; border-radius:8px; margin-bottom:16px; }
.msg-box.err { background:#f8d7da; color:#721c24; }
.btn-danger-sm { background:#dc3545; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:12px; }
.btn-secondary-sm { background:#6c757d; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:12px; }
.btn-primary-sm { background:#667eea; color:#fff; border:none; padding:4px 10px; border-radius:6px; cursor:pointer; font-size:12px; }
</style>
</head>
<body>

<div class="admin-wrap">
    <header class="admin-header" style="display:flex; justify-content:space-between; align-items:center; padding:18px 20px; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; border-radius:12px; margin-bottom:18px;">
        <div>
            <h1 style="margin:0; font-size:22px;">🎬 沫兮万能解析管理后台</h1>
            <div style="font-size:13px; opacity:0.9; margin-top:4px;">v4.0.0 · NoAd 去广告 + 智能线路切换</div>
        </div>
        <div style="display:flex; gap:12px; align-items:center; font-size:13px;">
            <span>NoAd <?php echo !empty($noadConfig['noad_enabled']) ? '<span class="badge badge-green">已启用</span>' : '<span class="badge badge-red">已关闭</span>'; ?></span>
            <span>SQLite <?php echo extension_loaded('pdo_sqlite') ? '<span class="badge badge-green">可用</span>' : '<span class="badge badge-yellow">未加载</span>'; ?></span>
            <a href="?action=logout" style="color:#fff; background:rgba(255,255,255,0.18); padding:6px 14px; border-radius:6px; text-decoration:none;">退出登录</a>
        </div>
    </header>

    <?php if ($msg): ?>
        <div class="msg-box <?php echo $msgType === 'error' ? 'err' : ''; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="tabs-nav" id="tabsNav">
        <button data-tab="dashboard"  class="<?php echo $page==='dashboard'?'active':''; ?>">📊 总览</button>
        <button data-tab="m3u8_parse" class="<?php echo $page==='m3u8_parse'?'active':''; ?>">📼 M3U8 解析</button>
        <button data-tab="sites" class="<?php echo $page==='sites'?'active':''; ?>">🔗 资源站点</button>
        <button data-tab="parse_log" class="<?php echo $page==='parse_log'?'active':''; ?>">📄 解析日志</button>
        <button data-tab="cache_mgr" class="<?php echo $page==='cache_mgr'?'active':''; ?>">💽 缓存管理</button>
        <button data-tab="noad_stats" class="<?php echo $page==='noad_stats'?'active':''; ?>">📈 NoAd 数据统计</button>
        <button data-tab="noad_sources" class="<?php echo $page==='noad_sources'?'active':''; ?>">🔌 去广告解析源</button>
        <button data-tab="noad_rules" class="<?php echo $page==='noad_rules'?'active':''; ?>">🚫 广告规则库</button>
        <button data-tab="noad_config" class="<?php echo $page==='noad_config'?'active':''; ?>">⚙️ NoAd 设置</button>
        <button data-tab="api" class="<?php echo $page==='api'?'active':''; ?>">📡 API 线路</button>
        <button data-tab="platform" class="<?php echo $page==='platform'?'active':''; ?>">🎯 平台规则</button>
        <button data-tab="switch" class="<?php echo $page==='switch'?'active':''; ?>">🔀 系统开关</button>
        <button data-tab="zjk" class="<?php echo $page==='zjk'?'active':''; ?>">📝 自定义接口</button>
        <button data-tab="backup" class="<?php echo $page==='backup'?'active':''; ?>">💾 备份日志</button>
        <button data-tab="password" class="<?php echo $page==='password'?'active':''; ?>">🔐 修改密码</button>
        <button data-tab="setting" class="<?php echo $page==='setting'?'active':''; ?>">🛠️ 后台设置</button>
    </div>

    <?php
    // ========= 1. 总览 =========
    ?>
    <div class="tab-panel <?php echo $page==='dashboard'?'active':''; ?>" id="tab-dashboard">
        <h2>📊 系统总览</h2>
        <div class="grid-flow" style="margin-bottom:24px;">
            <div class="stat-card"><div class="label">累计解析请求</div><div class="num"><?php echo number_format($overview['total_requests'] ?? 0); ?></div></div>
            <div class="stat-card v2"><div class="label">今日解析</div><div class="num"><?php echo number_format($overview['today_requests'] ?? 0); ?></div></div>
            <div class="stat-card v3"><div class="label">已移除广告片段</div><div class="num"><?php echo number_format($overview['total_ad_removed'] ?? 0); ?></div></div>
            <div class="stat-card v4"><div class="label">缓存命中率</div><div class="num"><?php echo number_format($overview['cache_hit_rate'] ?? 1, 2); ?>%</div></div>
            <div class="stat-card v5"><div class="label">平均响应时间</div><div class="num"><?php echo number_format($overview['avg_response_time'] ?? 0, 1); ?>ms</div></div>
            <div class="stat-card v6"><div class="label">活跃解析源</div><div class="num"><?php echo (int)($overview['source_count'] ?? count($noadSources)); ?></div></div>
            <div class="stat-card v2" style="background:linear-gradient(135deg,#ff9a9e,#fad0c4); color:#333;"><div class="label">API 数 / 平台数</div><div class="num"><?php echo $totalApis; ?> / <?php echo $totalPlats; ?></div></div>
            <div class="stat-card v3" style="background:linear-gradient(135deg,#a8edea,#fed6e3); color:#333;"><div class="label">PHP 版本</div><div class="num" style="font-size:22px;"><?php echo htmlspecialchars(PHP_VERSION); ?></div></div>
        </div>

        <div class="panel">
            <h3>🚀 快速开始</h3>
            <p><strong>📌 NoAd 去广告解析：</strong><br>
            <code class="monocode">/index.php?url=视频播放地址&type=movie|tv|variety|anime|document|sports|short</code>
            </p>
            <p><strong>📌 直接 API：</strong><br>
            <code class="monocode">/noad_proxy.php?mode=api&url=视频播放地址</code>
            </p>
            <p><strong>📌 M3U8 代理：</strong><br>
            <code class="monocode">/noad_proxy.php?mode=m3u8&src=https://example.com/play.m3u8</code>
            </p>
            <p><strong>✅ 推荐步骤：</strong>在「🔌 去广告解析源」添加你的解析接口 → 在「🚫 广告规则库」自定义广告关键词 → 在「📈 NoAd 数据统计」查看效果</p>
        </div>
    </div>

    <?php
    // ========= 2. NoAd 数据统计 =========
    ?>
    <div class="tab-panel <?php echo $page==='noad_stats'?'active':''; ?>" id="tab-noad_stats">
        <h2>📈 NoAd 数据统计</h2>
        <?php if (!$db): ?>
            <div class="msg-box err">⚠️ SQLite 未加载或数据库不可用。请在 PHP 中启用 <code>pdo_sqlite</code> 扩展，并在「⚙️ NoAd 设置」中开启统计功能。</div>
        <?php else: ?>
            <div class="panel">
                <h3>📊 近 7 天请求趋势</h3>
                <?php if (empty($dailyStats)): ?>
                    <p style="color:#888;">暂无数据。先访问一下 /index.php?url=... 生成首次请求。</p>
                <?php else:
                    $maxVal = 1;
                    foreach ($dailyStats as $d) { if (($d['total_requests'] ?? 0) > $maxVal) $maxVal = $d['total_requests']; }
                    foreach ($dailyStats as $d):
                        $pct = round((($d['total_requests'] ?? 0) / $maxVal) * 100);
                        $date = substr($d['stat_date'] ?? '??', 5);
                ?>
                <div style="margin:12px 0;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
                        <span><?php echo htmlspecialchars($date); ?></span>
                        <span>共 <strong><?php echo (int)($d['total_requests'] ?? 0); ?></strong> · 移除广告 <strong><?php echo (int)($d['ad_removed_count'] ?? 0); ?></strong> · 缓存命中 <strong><?php echo (int)($d['cache_hit_count'] ?? 0); ?></strong></span>
                    </div>
                    <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="panel">
                <h3>🏆 最热门解析源（Top 10）</h3>
                <?php if (empty($topSources)): ?>
                    <p style="color:#888;">暂无数据。</p>
                <?php else:
                    $mTop = max(1, max(array_column($topSources, 'use_count')));
                    foreach ($topSources as $s):
                        $pct = round($s['use_count'] / $mTop * 100);
                ?>
                <div style="margin:12px 0;">
                    <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:4px;">
                        <span><?php echo htmlspecialchars($s['source_name']); ?></span>
                        <span>被使用 <strong><?php echo (int)$s['use_count']; ?></strong> 次</span>
                    </div>
                    <div class="chart-bar"><div class="chart-fill" style="width:<?php echo $pct; ?>%; background:linear-gradient(90deg,#f093fb,#f5576c);"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="panel">
                <h3>📝 最近 30 条访问日志</h3>
                <?php if (empty($recentLogs)): ?>
                    <p style="color:#888;">暂无日志。</p>
                <?php else: ?>
                <table class="data-table" style="width:100%;">
                    <thead><tr><th>时间</th><th>IP</th><th>来源</th><th>类型</th><th>移</th><th>耗时</th><th>缓存</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?php echo date('H:i:s', (int)$log['access_time']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                        <td><?php echo htmlspecialchars($log['source_name']); ?></td>
                        <td><?php echo htmlspecialchars($log['video_type'] ?: '-'); ?></td>
                        <td><?php echo (int)$log['ad_segments_removed']; ?></td>
                        <td><?php echo number_format((float)$log['response_time'], 1); ?>ms</td>
                        <td><?php echo !empty($log['is_from_cache']) ? '<span class="badge badge-green">命中</span>' : '<span class="badge badge-blue">未</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div style="margin-top:16px;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('确认清空所有 NoAd 统计和日志？');">
                        <input type="hidden" name="action" value="clear_log">
                        <button type="submit" class="btn-danger-sm">🗑️ 清空统计</button>
                    </form>
                    <form method="post" style="display:inline; margin-left:8px;" onsubmit="return confirm('确认清理所有 NoAd 缓存文件？');">
                        <input type="hidden" name="action" value="clear_noad_cache">
                        <button type="submit" class="btn-secondary-sm">🧹 清理缓存</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // ========= 3. 去广告解析源 =========
    ?>
    <div class="tab-panel <?php echo $page==='noad_sources'?'active':''; ?>" id="tab-noad_sources">
        <h2>🔌 去广告解析源（支持 7 种资源类型）</h2>

        <div class="panel">
            <h3>➕ 添加 / 编辑解析源</h3>
            <form method="post" id="srcForm">
                <input type="hidden" name="action" value="save_noad_source">
                <input type="hidden" name="source_id" id="srcId" value="0">
                <div class="grid-flow">
                    <label>名称<br>
                        <input type="text" name="source_name" id="srcName" placeholder="例：主源A" required style="width:100%;">
                    </label>
                    <label style="grid-column:span 2;">接口地址（URL 中请用 <code class="monocode">{url}</code> 作为播放页占位符）<br>
                        <input type="text" name="source_url" id="srcUrl" placeholder="https://jx.example.com/?url={url}" required style="width:100%;">
                    </label>
                    <label>资源类型<br>
                        <select name="source_type" id="srcType">
                            <?php foreach ($rTypes as $tid => $t): ?>
                                <option value="<?php echo (int)$tid; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>超时（秒）<br>
                        <input type="number" name="source_timeout" id="srcTimeout" value="8" min="1" max="60">
                    </label>
                    <label>排序<br>
                        <input type="number" name="source_order" id="srcOrder" value="0" min="0" max="9999">
                    </label>
                    <label style="grid-column:span 2;">匹配关键词（可选，为特定平台视频匹配此源）<br>
                        <input type="text" name="source_match" id="srcMatch" placeholder="例：v.qq.com / iqiyi" style="width:100%;">
                    </label>
                    <label style="grid-column:span 2;">备注<br>
                        <input type="text" name="source_remark" id="srcRemark" placeholder="备注" style="width:100%;">
                    </label>
                    <label style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="source_enabled" id="srcEnabled" checked>
                        <span>启用该解析源</span>
                    </label>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:8px 18px;">💾 保存解析源</button>
                    <button type="button" class="btn-secondary-sm" style="font-size:14px; padding:8px 18px;" onclick="resetSourceForm()">🔄 重置为新增</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h3>📋 现有解析源（共 <?php echo count($noadSources); ?> 个）</h3>
            <?php if (empty($noadSources)): ?>
                <p style="color:#888;">⚠️ 暂未添加任何解析源。请先添加，或查看 config/noad.php 中的 default_sources。</p>
            <?php else: ?>
            <table class="data-table" style="width:100%;">
                <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>接口地址</th><th>超时</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($noadSources as $s):
                    $tn = $rTypes[(int)$s['type_id']]['name'] ?? '未分类';
                ?>
                <tr>
                    <td><?php echo (int)$s['id']; ?></td>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td><?php echo htmlspecialchars($tn); ?></td>
                    <td style="word-break:break-all; max-width:260px; font-size:12px;"><?php echo htmlspecialchars($s['url']); ?></td>
                    <td><?php echo (int)$s['timeout']; ?>s</td>
                    <td><?php echo (int)$s['sort_order']; ?></td>
                    <td><?php echo empty($s['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td class="action-col" style="white-space:nowrap;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_noad_source">
                            <input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="btn-secondary-sm"><?php echo empty($s['enabled']) ? '启用' : '禁用'; ?></button>
                        </form>
                        <button type="button" class="btn-primary-sm"
                                onclick="editSource(<?php echo (int)$s['id']; ?>,
                                    '<?php echo htmlspecialchars(addslashes($s['name'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($s['url'])); ?>',
                                    <?php echo (int)$s['type_id']; ?>,
                                    <?php echo (int)$s['timeout']; ?>,
                                    <?php echo (int)$s['sort_order']; ?>,
                                    '<?php echo htmlspecialchars(addslashes($s['match_rules'] ?? '')); ?>',
                                    '<?php echo htmlspecialchars(addslashes($s['remark'] ?? '')); ?>',
                                    <?php echo empty($s['enabled']) ? 'false' : 'true'; ?>)">✏️ 编辑</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确认删除解析源 #<?php echo (int)$s['id']; ?>？');">
                            <input type="hidden" name="action" value="delete_noad_source">
                            <input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="btn-danger-sm">🗑️ 删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ========= 4. 广告规则库 =========
    ?>
    <div class="tab-panel <?php echo $page==='noad_rules'?'active':''; ?>" id="tab-noad_rules">
        <h2>🚫 广告片段识别规则库</h2>
        <p style="color:#666;">当 M3U8 文件中 <code class="monocode">#EXTINF</code> 描述或 TS 片段文件名中命中以下关键词时，该片段将被标记为广告并自动移除。当前阈值：<strong><?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?></strong></p>

        <div class="panel">
            <h3>➕ 添加自定义规则</h3>
            <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="action" value="add_ad_rule">
                <input type="text" name="keyword" placeholder="例：ad_roll / promo / 片头广告" required style="flex:1; min-width:220px;">
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:8px 18px;">➕ 添加规则</button>
            </form>
        </div>

        <div class="panel">
            <h3>📝 默认内置规则（来自 config/noad.php）</h3>
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                <?php foreach (($noadConfig['ad_keywords'] ?? array()) as $kw): ?>
                    <span class="badge badge-yellow" style="font-size:13px; padding:6px 12px;"><?php echo htmlspecialchars($kw); ?></span>
                <?php endforeach; ?>
            </div>
            <p style="color:#888; font-size:12px; margin-top:12px;">💡 修改默认规则：在「⚙️ NoAd 设置」中调整，或直接编辑 <code class="monocode">config/noad.php</code></p>
        </div>

        <div class="panel">
            <h3>🗃️ 自定义规则库（可动态增删，共 <?php echo count($adRules); ?> 条）</h3>
            <?php if (empty($adRules)): ?>
                <p style="color:#888;">暂无自定义规则。</p>
            <?php else: ?>
            <table class="data-table" style="width:100%;">
                <thead><tr><th>ID</th><th>关键词</th><th>命中次数</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($adRules as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><code class="monocode"><?php echo htmlspecialchars($r['keyword']); ?></code></td>
                    <td><?php echo (int)($r['hit_count'] ?? 0); ?></td>
                    <td><?php echo empty($r['enabled']) ? '<span class="badge badge-red">关闭</span>' : '<span class="badge badge-green">启用</span>'; ?></td>
                    <td><?php echo date('Y-m-d H:i', (int)($r['created_at'] ?? time())); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_ad_rule">
                            <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                            <button type="submit" class="btn-secondary-sm">切换</button>
                        </form>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确认删除规则 #<?php echo (int)$r['id']; ?>？');">
                            <input type="hidden" name="action" value="delete_ad_rule">
                            <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
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

    <?php
    // ========= 5. NoAd 系统设置 =========
    ?>
    <div class="tab-panel <?php echo $page==='noad_config'?'active':''; ?>" id="tab-noad_config">
        <h2>⚙️ NoAd 去广告系统设置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_noad_config">
            <div class="panel">
                <h3>总开关</h3>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="noad_enabled" <?php if (!empty($noadConfig['noad_enabled'])) echo 'checked'; ?>>
                    ✅ 启用 NoAd 去广告解析（v4 核心功能）
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_ad_filter" <?php if (!empty($noadConfig['enable_ad_filter'])) echo 'checked'; ?>>
                    🚫 启用 M3U8 广告片段智能过滤
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_multi_source" <?php if (!empty($noadConfig['enable_multi_source'])) echo 'checked'; ?>>
                    🔀 启用多源自动匹配（并发请求多个源，选最优）
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="cache_enabled" <?php if (!empty($noadConfig['cache_enabled'])) echo 'checked'; ?>>
                    🧹 启用缓存加速
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="stats_enabled" <?php if (!empty($noadConfig['stats_enabled'])) echo 'checked'; ?>>
                    📈 启用访问数据统计（SQLite）
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="debug_mode" <?php if (!empty($noadConfig['debug_mode'])) echo 'checked'; ?>>
                    🐛 调试模式（生产环境请关闭）
                </label>
            </div>

            <div class="panel">
                <h3>性能参数</h3>
                <div class="grid-flow">
                    <label>缓存有效期（秒）<br>
                        <input type="number" name="cache_ttl" value="<?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?>" min="60" max="86400" style="width:100%;">
                    </label>
                    <label>单次最多尝试解析源数<br>
                        <input type="number" name="max_source_try" value="<?php echo (int)($noadConfig['max_source_try'] ?? 3); ?>" min="1" max="20" style="width:100%;">
                    </label>
                    <label>单源请求超时（秒）<br>
                        <input type="number" name="request_timeout" value="<?php echo (int)($noadConfig['request_timeout'] ?? 10); ?>" min="1" max="120" style="width:100%;">
                    </label>
                    <label>广告关键词命中阈值<br>
                        <input type="number" name="ad_keyword_threshold" value="<?php echo (int)($noadConfig['ad_keyword_threshold'] ?? 2); ?>" min="1" max="20" style="width:100%;">
                    </label>
                </div>
                <p style="color:#888; font-size:12px; margin-top:12px;">💡 阈值说明：M3U8 片段描述或 URL 中命中规则关键词达到该数量时，该片段判定为广告。</p>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">💾 保存 NoAd 配置</button>
            </div>
        </form>
    </div>

    <?php
    // ========= 6. API 线路 =========
    ?>
    <div class="tab-panel <?php echo $page==='api'?'active':''; ?>" id="tab-api">
        <h2>📡 API 线路配置（v3 并发解析）</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_api">
            <table class="data-table" style="width:100%;">
                <thead><tr><th>接口名称</th><th>接口地址（URL 中请用 {url} 占位）</th><th>超时(秒)</th><th>操作</th></tr></thead>
                <tbody id="apiRows">
                <?php
                foreach ((array)$apiConfig as $name => $value) {
                    $parts = explode('|', $value);
                    $u = $parts[0] ?? '';
                    $t = (int)($parts[1] ?? 5);
                    echo '<tr>
                        <td><input type="text" name="api_name[]" value="' . htmlspecialchars($name) . '" style="width:100%;"></td>
                        <td><input type="text" name="api_url[]" value="' . htmlspecialchars($u) . '" style="width:100%;"></td>
                        <td><input type="number" name="api_timeout[]" value="' . $t . '" min="1" max="60" style="width:80px;"></td>
                        <td><button type="button" class="btn-danger-sm" onclick="this.closest(\'tr\').remove();">删除</button></td>
                    </tr>';
                }
                ?>
                </tbody>
            </table>
            <div style="margin-top:12px;">
                <button type="button" class="btn-secondary-sm" style="font-size:14px; padding:8px 18px;" onclick="addApiRow()">➕ 添加一行</button>
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:8px 18px;">💾 保存全部</button>
            </div>
        </form>
    </div>

    <?php
    // ========= 7. 平台规则 =========
    ?>
    <div class="tab-panel <?php echo $page==='platform'?'active':''; ?>" id="tab-platform">
        <h2>🎯 平台规则配置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_platform">
            <table class="data-table" style="width:100%;">
                <thead><tr><th>平台名称</th><th>匹配关键词</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ((array)$platformCfg as $name => $rule): ?>
                <tr>
                    <td><input type="text" name="platform_name[]" value="<?php echo htmlspecialchars($name); ?>" style="width:100%;"></td>
                    <td><input type="text" name="platform_rule[]" value="<?php echo htmlspecialchars($rule); ?>" style="width:100%;"></td>
                    <td><button type="button" class="btn-danger-sm" onclick="this.closest('tr').remove();">删除</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:12px;">
                <button type="button" class="btn-secondary-sm" style="font-size:14px; padding:8px 18px;" onclick="addPlatformRow()">➕ 添加一行</button>
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:8px 18px;">💾 保存全部</button>
            </div>
        </form>
    </div>

    <?php
    // ========= 8. 系统开关 =========
    ?>
    <div class="tab-panel <?php echo $page==='switch'?'active':''; ?>" id="tab-switch">
        <h2>🔀 系统开关</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_switch">
            <div class="panel">
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_global_api" <?php if (!empty($switchConfig['enable_global_api'])) echo 'checked'; ?>>
                    启用总接口并发请求
                </label>
                <label style="display:block; margin:10px 0;">
                    ZJK 文件路径：<input type="text" name="zjk_file_path" value="<?php echo htmlspecialchars($switchConfig['zjk_file_path'] ?? 'ZJK.txt'); ?>">
                </label>
                <label style="display:block; margin:10px 0;">
                    总接口超时（秒）：<input type="number" name="global_api_timeout" value="<?php echo (int)($switchConfig['global_api_timeout'] ?? 8); ?>" min="1">
                </label>
                <label style="display:block; margin:10px 0;">
                    总接口并发数：<input type="number" name="global_api_count" value="<?php echo (int)($switchConfig['global_api_count'] ?? 6); ?>" min="0">
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_zjk_apis" <?php if (!empty($switchConfig['enable_zjk_apis'])) echo 'checked'; ?>>
                    启用 ZJK.txt 自定义接口
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_m3u8_direct" <?php if (!empty($switchConfig['enable_m3u8_direct'])) echo 'checked'; ?>>
                    M3U8 直链快速通道
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_unified_display" <?php if (!empty($switchConfig['enable_unified_display'])) echo 'checked'; ?>>
                    统一响应格式
                </label>
                <div style="margin-top:12px;">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">💾 保存</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    // ========= 9. ZJK 自定义接口 =========
    ?>
    <div class="tab-panel <?php echo $page==='zjk'?'active':''; ?>" id="tab-zjk">
        <h2>📝 ZJK.txt 自定义接口</h2>
        <p style="color:#666;">格式：<code class="monocode">接口地址|超时时间（秒）</code>，每行一条。例如：<code class="monocode">https://jx.example.com/?url={url}|8</code></p>
        <form method="post">
            <input type="hidden" name="action" value="save_zjk">
            <textarea name="zjk_content" style="width:100%; min-height:220px; font-family:monospace; padding:12px; font-size:13px; border:1px solid #ddd; border-radius:8px;"><?php
                echo htmlspecialchars(file_exists(__DIR__ . '/ZJK.txt') ? file_get_contents(__DIR__ . '/ZJK.txt') : '');
            ?></textarea>
            <div style="margin-top:12px;"><button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">💾 保存</button></div>
        </form>
    </div>

    <?php
    // ========= 10. 备份 / 日志 =========
    ?>
    <div class="tab-panel <?php echo $page==='backup'?'active':''; ?>" id="tab-backup">
        <h2>💾 配置备份</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_backup">
            <div class="panel">
                <p>一键备份所有配置文件到 <code class="monocode">cache/backup_YYYYMMDD_HHMMSS/</code> 目录。包括：API / 平台 / 开关 / 后台 / NoAd / ZJK.txt。</p>
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">💾 立即备份</button>
            </div>
        </form>
        <div class="panel">
            <h3>🗃️ 现有备份目录</h3>
            <?php
            $backupDirs = glob(__DIR__ . '/cache/backup_*');
            if (empty($backupDirs)): ?>
                <p style="color:#888;">暂无备份。</p>
            <?php else: ?>
                <ul style="line-height:2; font-size:13px; font-family:monospace;">
                    <?php foreach ($backupDirs as $d): ?>
                        <li>📂 <?php echo htmlspecialchars(basename($d)); ?> （包含 <?php echo count(glob($dir = $d))?>) :
                            <?php
                            $files = glob($d . '/*');
                            echo implode(', ', array_map('basename', $files));
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ========= 11. 修改密码 =========
    ?>
    <div class="tab-panel <?php echo $page==='password'?'active':''; ?>" id="tab-password">
        <h2>🔐 修改管理员密码</h2>
        <form method="post">
            <input type="hidden" name="action" value="change_password">
            <div class="panel" style="max-width:500px;">
                <label style="display:block; margin:10px 0;">原密码<br><input type="password" name="old_password" required style="width:100%; padding:8px;"></label>
                <label style="display:block; margin:10px 0;">新密码（至少 6 位）<br><input type="password" name="new_password" minlength="6" required style="width:100%; padding:8px;"></label>
                <label style="display:block; margin:10px 0;">确认密码<br><input type="password" name="confirm_password" minlength="6" required style="width:100%; padding:8px;"></label>
                <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">🔐 修改密码</button>
            </div>
        </form>
    </div>

    <?php
    // ========= 12. 后台设置 =========
    ?>
    <div class="tab-panel <?php echo $page==='setting'?'active':''; ?>" id="tab-setting">
        <h2>🛠️ 后台设置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_admin_config">
            <div class="panel">
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="admin_enabled" <?php if (!empty($adminConfig['admin_enabled'])) echo 'checked'; ?>>
                    ✅ 启用后台管理
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enforce_port" <?php if (!empty($adminConfig['enforce_port'])) echo 'checked'; ?>>
                    ⚠️ 强制端口校验（仅允许指定端口访问，例如 8899）
                </label>
                <label style="display:block; margin:10px 0;">
                    允许访问的端口（逗号分隔，例：80, 443, 8899）：<br>
                    <input type="text" name="allowed_ports" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ports'] ?? array())); ?>" style="width:100%;">
                </label>
                <label style="display:block; margin:10px 0;">
                    允许访问的 IP（逗号分隔，留空则不限制）：<br>
                    <input type="text" name="allowed_ips" value="<?php echo htmlspecialchars(implode(',', $adminConfig['allowed_ips'] ?? array())); ?>" style="width:100%;">
                </label>
                <label style="display:block; margin:10px 0;">
                    会话有效期（秒）：<input type="number" name="session_lifetime" value="<?php echo max(60, (int)($adminConfig['session_lifetime'] ?? 7200)); ?>" min="60" max="864000">
                </label>
                <label style="display:block; margin:10px 0;">
                    最大登录失败次数：<input type="number" name="max_login_attempts" value="<?php echo max(1, (int)($adminConfig['max_login_attempts'] ?? 5)); ?>" min="1" max="999">
                </label>
                <label style="display:block; margin:10px 0;">
                    失败锁定时长（秒）：<input type="number" name="lockout_duration" value="<?php echo max(60, (int)($adminConfig['lockout_duration'] ?? 300)); ?>" min="60" max="86400">
                </label>
                <label style="display:block; margin:10px 0;">
                    <input type="checkbox" name="enable_log" <?php if (!empty($adminConfig['enable_log'])) echo 'checked'; ?>>
                    📒 启用操作日志
                </label>
                <div style="margin-top:16px;">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:10px 24px;">💾 保存后台设置</button>
                </div>
            </div>
        </form>

        <form method="post" style="margin-top:16px;" onsubmit="return confirm('确认要修改后台入口文件名？请确保你能记住新文件名！');">
            <input type="hidden" name="action" value="change_path">
            <div class="panel">
                <h3>🔄 修改后台入口路径</h3>
                <p style="color:#666; font-size:13px;">为增加安全性，可以把 <code class="monocode">admin.php</code> 改为自定义路径，例如 <code class="monocode">my_secret_panel.php</code></p>
                <label style="display:block; margin:10px 0;">
                    新后台文件名：<input type="text" name="new_path" placeholder="例：my_console.php" pattern="[A-Za-z0-9_\-]+\.php$" required style="width:260px;">
                </label>
                <button type="submit" class="btn-danger-sm" style="font-size:14px; padding:10px 24px;">🔄 重命名后台入口</button>
            </div>
        </form>
    </div>

    <?php
    // ========= v4.1. M3U8 解析（双栏对比 + 时间戳 + 广告片段高亮）=========
    ?>
    <div class="tab-panel <?php echo $page==='m3u8_parse'?'active':''; ?>" id="tab-m3u8_parse">
        <h2>📼 M3U8 解析（去插播广告分析）</h2>

        <div class="panel">
            <h3>🎯 输入 M3U8 链接</h3>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <input type="text" id="m3u8Url" placeholder="例如：https://example.com/video/playlist.m3u8"
                       style="flex:1; min-width:360px;" value="">
                <select id="siteId" style="min-width:180px;">
                    <option value="0">关联站点 / 资源来源（可选）</option>
                    <?php if (!empty($sites)): foreach ($sites as $s):
                        if (empty($s['enabled'])) continue; ?>
                        <option value="<?php echo (int)$s['id']; ?>">
                            <?php echo htmlspecialchars($s['name']); ?>
                            <?php if (!empty($s['short_code'])) echo '(' . htmlspecialchars($s['short_code']) . ')'; ?>
                        </option>
                    <?php endforeach; endif; ?>
                </select>
                <button type="button" onclick="parseM3U8()" class="btn-primary-sm" style="padding:8px 16px; font-size:14px; background:#667eea;">🔍 解析 M3U8</button>
                <span id="parseStatus" style="font-size:13px; color:#888;">等待解析...</span>
            </div>
            <p style="font-size:12px; color:#888; margin-top:8px;">💡 支持主播播放列表(多码率) 和媒体分片列表；解析后可对比过滤前后内容；并自动按累计时长计算时间戳。</p>
        </div>

        <div id="parseResultWrap" style="display:none;">
            <div class="panel">
                <h3>📊 解析统计</h3>
                <div class="grid-flow" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
                    <div class="stat-card" style="padding:14px;"><div class="label">总片段数</div><div class="num" id="sTotal" style="font-size:22px;">0</div></div>
                    <div class="stat-card v2" style="padding:14px; background:linear-gradient(135deg,#ef5350,#e53935);"><div class="label">广告片段</div><div class="num" id="sAd" style="font-size:22px;">0 <span style="font-size:12px;" id="sAdTime"></span></div></div>
                    <div class="stat-card v3" style="padding:14px; background:linear-gradient(135deg,#4caf50,#66bb6a);"><div class="label">保留片段</div><div class="num" id="sKeep" style="font-size:22px;">0 <span style="font-size:12px;" id="sKeepTime"></span></div></div>
                    <div class="stat-card v4" style="padding:14px;"><div class="label">总时长</div><div class="num" id="sDuration" style="font-size:22px;">0</div></div>
                    <div class="stat-card v5" style="padding:14px;"><div class="label">站点</div><div class="num" id="sSite" style="font-size:15px;">—</div></div>
                    <div class="stat-card v6" style="padding:14px;"><div class="label">规则命中</div><div class="num" id="sRules" style="font-size:22px;">0</div></div>
                </div>
                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="btn-primary-sm" onclick="copyToClipboard('rawContent','原始内容')" style="background:#ff9800; padding:8px 16px; font-size:13px;">📄 复制原始 M3U8 内容</button>
                    <button type="button" class="btn-primary-sm" onclick="copyToClipboard('cleanContent','过滤后内容')" style="background:#2196f3; padding:8px 16px; font-size:13px;">🧹 复制过滤后的 M3U8 内容</button>
                    <button type="button" class="btn-secondary-sm" onclick="document.getElementById('rawContent').style.display=(document.getElementById('rawContent').style.display==='none'?'block':'none');">👁️ 原始内容切换</button>
                    <button type="button" class="btn-secondary-sm" onclick="document.getElementById('segmentsWrap').style.display=(document.getElementById('segmentsWrap').style.display==='none'?'block':'none');">👁️ 片段详情切换</button>
                </div>
            </div>

            <div class="panel" id="segmentsWrap">
                <h3>📼 原始 M3U8 内容 <span style="font-size:12px; color:#888; font-weight:400;" id="rawLineCount">0 行</span></h3>
                <pre id="rawContent" style="background:#f0f0fa; padding:14px; border-radius:8px; font-size:12px; max-height:480px; overflow:auto; white-space:pre;"></pre>

                <h3 style="margin-top:24px;">⏱️ 带时间戳信息的 M3U8 内容 <span style="font-size:12px; color:#888; font-weight:400;" id="tsLineCount">0 行</span>
                    <span class="badge badge-red" style="margin-left:8px;">已删除的广告信息</span>
                </h3>
                <div id="timestampContent" style="background:#fff; padding:14px; border-radius:8px; border:1px solid #e9ecef; max-height:640px; overflow:auto; font-size:12px; line-height:1.7;"></div>
            </div>

            <div class="panel">
                <h3>🧹 过滤后纯净 M3U8（可直接播放）</h3>
                <pre id="cleanContent" style="background:#e8f5e9; padding:14px; border-radius:8px; font-size:12px; max-height:420px; overflow:auto; white-space:pre;"></pre>
            </div>
        </div>
    </div>

    <?php
    // ========= v4.1. 资源站点管理 =========
    ?>
    <div class="tab-panel <?php echo $page==='sites'?'active':''; ?>" id="tab-sites">
        <h2>🔗 资源站点（关联 M3U8 解析）</h2>
        <div class="panel">
            <h3>➕ 添加 / 编辑资源站点</h3>
            <form method="post" id="siteForm">
                <input type="hidden" name="action" value="save_site">
                <input type="hidden" name="site_id" id="siteIdInput" value="0">
                <table class="data-table">
                    <tr><td style="width:120px;">站点名称 *</td><td><input type="text" name="site_name" id="siteName" required style="width:100%; max-width:420px;" placeholder="例：优质资源聚合"></td></tr>
                    <tr><td>短代码</td><td><input type="text" name="site_code" id="siteCode" style="width:100%; max-width:240px;" placeholder="例：yzzy"></td></tr>
                    <tr><td>基础 URL</td><td><input type="text" name="site_url" id="siteUrl" style="width:100%; max-width:520px;" placeholder="https://..."></td></tr>
                    <tr><td>匹配规则（关键词，用逗号分隔）</td><td><input type="text" name="site_pattern" id="sitePattern" style="width:100%; max-width:520px;" placeholder="例：xigua,ixigua,西瓜"></td></tr>
                    <tr><td>去广告算法（逗号分隔，顺序执行）</td>
                        <td>
                            <input type="text" name="site_algorithms" id="siteAlgorithms" style="width:100%; max-width:520px;"
                                placeholder="suanfasmall,suanfa4,suanfa5,suanfaxiguang">
                            <div style="font-size:12px; color:#666; margin-top:4px;">
                                可用算法:
                                <strong>suanfa1</strong>(去跟踪参数) ·
                                <strong>suanfa3</strong>(路径截断) ·
                                <strong>suanfa4</strong>(协议规范化) ·
                                <strong>suanfa5</strong>(路径清理) ·
                                <strong>suanfa6</strong>(去缓存参数) ·
                                <strong>suanfa7</strong>(广告域名替换) ·
                                <strong>suanfa8</strong>(302重定向解包) ·
                                <strong>suanfa9</strong>(深度清理，组合suanfa7+4+5+1) ·
                                <strong>suanfasmall</strong>(广告特征检测) ·
                                <strong>suanfaxiguang</strong>(西瓜专用) ·
                                <strong>suanfadyt</strong>(电影天堂专用)
                            </div>
                        </td>
                    </tr>
                    <tr><td>备注</td><td><input type="text" name="site_remark" id="siteRemark" style="width:100%; max-width:520px;" placeholder="备注说明"></td></tr>
                    <tr><td>启用</td><td><input type="checkbox" name="site_enabled" id="siteEnabled" checked></td></tr>
                </table>
                <div style="margin-top:12px;">
                    <button type="submit" class="btn-primary-sm" style="font-size:14px; padding:8px 16px;">💾 保存站点</button>
                    <button type="button" class="btn-secondary-sm" onclick="resetSiteForm()" style="margin-left:8px; padding:8px 16px; font-size:14px;">🔄 重置表单</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h3>📋 已有资源站点（共 <?php echo count($sites); ?> 个）</h3>
            <?php if (empty($sites)): ?>
                <p style="color:#888;">暂无资源站点。请先添加一个。</p>
            <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>ID</th><th>名称</th><th>短代码</th><th>基础 URL</th><th>匹配规则</th><th>去广告算法</th><th>使用次数</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($sites as $s): ?>
                    <tr>
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['short_code'] ?? '-'); ?></td>
                        <td style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($s['base_url'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($s['match_pattern'] ?? '-'); ?></td>
                        <td style="max-width:260px; font-size:12px; color:#666;"><?php echo htmlspecialchars($s['algorithms'] ?? '-'); ?></td>
                        <td><?php echo (int)($s['parse_count'] ?? 0); ?></td>
                        <td><?php echo !empty($s['enabled']) ? '<span class="badge badge-green">启用</span>' : '<span class="badge badge-red">禁用</span>'; ?></td>
                        <td style="max-width:200px; font-size:12px; color:#666;"><?php echo htmlspecialchars($s['remark'] ?? ''); ?></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn-primary-sm" onclick="editSite(<?php echo (int)$s['id']; ?>,'<?php echo htmlspecialchars(addslashes($s['name'])); ?>','<?php echo htmlspecialchars(addslashes($s['short_code'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($s['base_url'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($s['match_pattern'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($s['algorithms'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($s['remark'] ?? '')); ?>',<?php echo (int)($s['enabled'] ?? 0); ?>)">✏️ 编辑</button>
                            <form method="post" style="display:inline; margin-left:4px;" onsubmit="return confirm('确认删除该站点？');"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-danger-sm">🗑️</button></form>
                            <form method="post" style="display:inline; margin-left:4px;"><input type="hidden" name="action" value="toggle_site"><input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>"><button type="submit" class="btn-secondary-sm">🔄</button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ========= v4.1. 解析日志 =========
    ?>
    <div class="tab-panel <?php echo $page==='parse_log'?'active':''; ?>" id="tab-parse_log">
        <h2>📄 解析日志（最近 50 条）</h2>
        <div class="panel">
            <h3>🕒 手动 M3U8 解析操作记录</h3>
            <?php if (empty($parseLogs)): ?>
                <p style="color:#888;">暂无解析记录。在「📼 M3U8 解析」页面解析一个 M3U8 链接后，日志将出现在这里。</p>
            <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>时间</th><th>站点</th><th>总片段</th><th>广告</th><th>保留</th><th>总时长</th><th>广告时长</th><th>IP</th><th>URL</th></tr></thead>
                    <tbody>
                    <?php foreach ($parseLogs as $l): ?>
                    <tr>
                        <td><?php echo date('Y-m-d H:i:s', (int)$l['parse_time']); ?></td>
                        <td><?php echo htmlspecialchars($l['site_name'] ?? '-'); ?></td>
                        <td><?php echo (int)$l['total_segments']; ?></td>
                        <td style="color:#e53935; font-weight:600;"><?php echo (int)$l['ad_segments']; ?></td>
                        <td style="color:#43a047; font-weight:600;"><?php echo (int)$l['keep_segments']; ?></td>
                        <td><?php echo number_format((float)($l['total_duration'] ?? 0), 1); ?>s</td>
                        <td><?php echo number_format((float)($l['ad_duration'] ?? 0), 1); ?>s</td>
                        <td><?php echo htmlspecialchars($l['ip'] ?? '-'); ?></td>
                        <td style="max-width:420px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; color:#666;"><?php echo htmlspecialchars($l['input_url'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px;">
                    <form method="post" onsubmit="return confirm('确认清空所有解析日志？');">
                        <input type="hidden" name="action" value="clear_parse_log">
                        <button type="submit" class="btn-danger-sm" style="padding:8px 16px; font-size:13px;">🗑️ 清空所有解析日志</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ========= v4.1. 缓存管理 =========
    ?>
    <div class="tab-panel <?php echo $page==='cache_mgr'?'active':''; ?>" id="tab-cache_mgr">
        <h2>💽 缓存管理</h2>
        <div class="panel">
            <h3>🧹 NoAd 缓存清理</h3>
            <p style="font-size:13px; color:#666;">所有 /cache/noad_*.cache 文件（包含解析结果缓存）都将被删除。下次解析将直接调用外部解析源，确保返回最新内容。</p>
            <form method="post" onsubmit="return confirm('确认清理所有 NoAd 缓存文件？');">
                <input type="hidden" name="action" value="clear_noad_cache">
                <button type="submit" class="btn-danger-sm" style="padding:10px 24px; font-size:14px;">🧹 清理 NoAd 缓存</button>
            </form>
        </div>
        <div class="panel">
            <h3>🔧 当前配置信息</h3>
            <table class="data-table">
                <tr><th style="width:180px;">项目</th><th>值</th></tr>
                <tr><td>NoAd 总开关</td><td><?php echo !empty($noadConfig['noad_enabled']) ? '<span class="badge badge-green">已启用</span>' : '<span class="badge badge-red">已关闭</span>'; ?></td></tr>
                <tr><td>资源类型数</td><td><?php echo count($resourceTypes); ?> 种</td></tr>
                <tr><td>解析源数</td><td><?php echo count($noadSources); ?> 个</td></tr>
                <tr><td>广告规则数</td><td><?php echo count($adRules); ?> 条</td></tr>
                <tr><td>资源站点数</td><td><?php echo count($sites); ?> 个</td></tr>
                <tr><td>缓存有效期</td><td><?php echo (int)($noadConfig['cache_ttl'] ?? 1800); ?> 秒</td></tr>
                <tr><td>单源超时</td><td><?php echo (int)($noadConfig['request_timeout'] ?? 10); ?> 秒</td></tr>
                <tr><td>PHP 版本</td><td><?php echo htmlspecialchars(PHP_VERSION); ?></td></tr>
                <tr><td>SQLite 扩展</td><td><?php echo extension_loaded('pdo_sqlite') ? '<span class="badge badge-green">可用</span>' : '<span class="badge badge-yellow">未加载</span>'; ?></td></tr>
            </table>
        </div>
    </div>

</div>

<script>
// ======== Tab 切换 ========
document.querySelectorAll('#tabsNav button').forEach(function(btn){
    btn.addEventListener('click', function(){
        var tab = this.getAttribute('data-tab');
        document.querySelectorAll('#tabsNav button').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
        this.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
        window.scrollTo(0, 0);
    });
});

// ======== API 线路 / 平台规则 添加行 ========
function addApiRow() {
    var tbody = document.getElementById('apiRows');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="api_name[]" style="width:100%;"></td>' +
                   '<td><input type="text" name="api_url[]" style="width:100%;"></td>' +
                   '<td><input type="number" name="api_timeout[]" value="5" min="1" max="60" style="width:80px;"></td>' +
                   '<td><button type="button" class="btn-danger-sm" onclick="this.closest(\'tr\').remove();">删除</button></td>';
    tbody.appendChild(tr);
}
function addPlatformRow() {
    var tbody = document.querySelector('#tab-platform tbody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="platform_name[]" style="width:100%;"></td>' +
                   '<td><input type="text" name="platform_rule[]" style="width:100%;"></td>' +
                   '<td><button type="button" class="btn-danger-sm" onclick="this.closest(\'tr\').remove();">删除</button></td>';
    tbody.appendChild(tr);
}

// ======== 解析源表单编辑 ========
function editSource(id, name, url, typeId, timeout, order, match, remark, enabled) {
    document.getElementById('srcId').value = id;
    document.getElementById('srcName').value = name;
    document.getElementById('srcUrl').value = url;
    document.getElementById('srcType').value = typeId;
    document.getElementById('srcTimeout').value = timeout;
    document.getElementById('srcOrder').value = order;
    document.getElementById('srcMatch').value = match || '';
    document.getElementById('srcRemark').value = remark || '';
    document.getElementById('srcEnabled').checked = !!enabled;
    document.querySelector('#tab-noad_sources h2').scrollIntoView({behavior: 'smooth'});
    document.querySelector('#tabsNav button[data-tab="noad_sources"]').click();
}
function resetSourceForm() {
    document.getElementById('srcForm').reset();
    document.getElementById('srcId').value = 0;
    document.getElementById('srcName').value = '';
    document.getElementById('srcUrl').value = '';
    document.getElementById('srcType').value = 1;
    document.getElementById('srcTimeout').value = 8;
    document.getElementById('srcOrder').value = 0;
    document.getElementById('srcMatch').value = '';
    document.getElementById('srcRemark').value = '';
    document.getElementById('srcEnabled').checked = true;
}

// ======== M3U8 解析逻辑（AJAX 调用 admin.php 的 action=ajax_parse_m3u8）========
function parseM3U8() {
    var url = document.getElementById('m3u8Url').value.trim();
    if (!url) { alert('请输入 M3U8 URL'); return; }
    var siteId = document.getElementById('siteId').value;
    var statusEl = document.getElementById('parseStatus');
    statusEl.textContent = '⏳ 正在解析，请稍候...';
    statusEl.style.color = '#2196f3';
    document.getElementById('parseResultWrap').style.display = 'block';

    // 构造 POST 请求
    var form = new FormData();
    form.append('action', 'ajax_parse_m3u8');
    form.append('m3u8_url', url);
    form.append('site_id', siteId);

    fetch(window.location.href, { method: 'POST', body: form })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data || data.code !== 200) {
                statusEl.textContent = '❌ ' + (data.msg || '解析失败');
                statusEl.style.color = '#e53935';
                return;
            }
            // 填充统计卡片
            document.getElementById('sTotal').textContent = data.total || 0;
            document.getElementById('sAd').childNodes[0].nodeValue = (data.ad_count || 0) + ' ';
            document.getElementById('sAdTime').textContent = '(' + (data.ad_duration || '00:00:00') + ')';
            document.getElementById('sKeep').childNodes[0].nodeValue = (data.keep_count || 0) + ' ';
            document.getElementById('sKeepTime').textContent = '(' + (data.keep_duration || '00:00:00') + ')';
            document.getElementById('sDuration').textContent = data.total_duration || '00:00:00';
            document.getElementById('sSite').textContent = data.site_name || '未关联';
            document.getElementById('sRules').textContent = data.rules || 0;

            // 填充原始内容
            var rawEl = document.getElementById('rawContent');
            rawEl.textContent = data.raw_content || '';
            var rawLines = (data.raw_content || '').split('\n').length;
            document.getElementById('rawLineCount').textContent = rawLines + ' 行';

            // 填充过滤后内容
            document.getElementById('cleanContent').textContent = data.clean_m3u8 || '';

            // 构造时间戳信息内容（广告片段红色高亮）
            var tsEl = document.getElementById('timestampContent');
            var html = '';
            var tsLineCount = 0;
            if (data.segments && data.segments.length) {
                data.segments.forEach(function(seg){
                    if (seg.is_ad) {
                        html += '<div style="background:#ffebee; padding:4px 8px; border-left:3px solid #e53935; margin:2px 0; border-radius:4px;">';
                        html += '<span style="color:#e53935; font-weight:600;">🚫 广告</span> ';
                        html += '<span style="color:#c62828;">⏱ 时间信息: ' + (seg.time_start || '?') + ' -- ' + (seg.time_end || '?') + '</span><br>';
                        html += '<span style="color:#c62828;">#EXTINF:' + (seg.duration ? seg.duration.toFixed(6) : '0') + ',</span><br>';
                        html += '<span style="color:#c62828; font-size:12px;">' + (seg.uri || '') + '</span>';
                        if (seg.reason) html += '<br><span style="font-size:11px; color:#e57373;">原因: ' + seg.reason + '</span>';
                        html += '</div>';
                        tsLineCount += 3;
                    } else {
                        html += '<div style="padding:4px 8px; margin:2px 0; border-radius:4px; background:#f5f5fa;">';
                        html += '<span style="color:#2e7d32; font-size:12px;">⏱ 时间信息: ' + (seg.time_start || '?') + ' -- ' + (seg.time_end || '?') + '</span><br>';
                        html += '<span style="color:#1565c0;">#EXTINF:' + (seg.duration ? seg.duration.toFixed(6) : '0') + ',</span><br>';
                        html += '<span style="font-size:12px;">' + (seg.uri || '') + '</span>';
                        html += '</div>';
                        tsLineCount += 3;
                    }
                });
            } else {
                html = '<span style="color:#888;">（无片段信息）</span>';
            }
            tsEl.innerHTML = html;
            document.getElementById('tsLineCount').textContent = tsLineCount + ' 行';

            statusEl.textContent = '✅ 解析完成';
            statusEl.style.color = '#43a047';
        })
        .catch(function(err){
            statusEl.textContent = '❌ 请求失败: ' + err;
            statusEl.style.color = '#e53935';
        });
}

// ======== 复制到剪贴板 ========
function copyToClipboard(elId, label){
    var el = document.getElementById(elId);
    if (!el) return;
    var text = el.textContent || '';
    if (!text) { alert('暂无' + label + '可复制'); return; }
    var temp = document.createElement('textarea');
    temp.value = text;
    document.body.appendChild(temp);
    temp.select();
    try {
        document.execCommand('copy');
        alert(label + ' 已复制到剪贴板（' + text.length + ' 字符）');
    } catch(e) { alert('复制失败，请手动选择'); }
    document.body.removeChild(temp);
}

// ======== 站点表单编辑/重置 ========
function editSite(id, name, code, url, pattern, algorithms, remark, enabled){
    document.getElementById('siteIdInput').value = id;
    document.getElementById('siteName').value = name || '';
    document.getElementById('siteCode').value = code || '';
    document.getElementById('siteUrl').value = url || '';
    document.getElementById('sitePattern').value = pattern || '';
    document.getElementById('siteAlgorithms').value = algorithms || '';
    document.getElementById('siteRemark').value = remark || '';
    document.getElementById('siteEnabled').checked = !!enabled;
    document.querySelector('#tab-sites h2').scrollIntoView({behavior:'smooth'});
    document.querySelector('#tabsNav button[data-tab="sites"]').click();
}
function resetSiteForm(){
    document.getElementById('siteForm').reset();
    document.getElementById('siteIdInput').value = 0;
}
</script>
</body>
</html>
