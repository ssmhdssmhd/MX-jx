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
</script>
</body>
</html>
