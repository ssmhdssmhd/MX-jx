<?php
/**
 * 更简单的端到端测试：直接用 shell 注入 $_GET/$_POST，让 php -r 直接跑目标脚本。
 * 每个测试都是独立进程。
 */

$root = __DIR__;
$phpBin = PHP_BINARY;

$cases = array(
    '1. index.php (no args -> 400 error)' => function () use ($root) {
        return runIsolated($root, 'index.php', array(), null);
    },
    '2. index.php (legacy m3u8 url -> should go legacy mode)' => function () use ($root) {
        return runIsolated($root, 'index.php', array('mode' => 'legacy', 'url' => 'https://example.com/play.m3u8'), null);
    },
    '3. noad_proxy.php?mode=algorithms&a=list' => function () use ($root) {
        return runIsolated($root, 'noad_proxy.php', array('mode' => 'algorithms', 'a' => 'list'), null);
    },
    '4. noad_proxy.php?mode=algorithms&a=test' => function () use ($root) {
        return runIsolated($root, 'noad_proxy.php', array('mode' => 'algorithms', 'a' => 'test', 'input' => 'https://example.com/play.m3u8?ad_slot=1&track=xyz&utm_source=fb'), null);
    },
    '5. noad_proxy.php?mode=algorithms&a=toggle' => function () use ($root) {
        return runIsolated($root, 'noad_proxy.php', array('mode' => 'algorithms', 'a' => 'toggle', 'id' => 'url_tracker_strip', 'enabled' => '0'), null);
    },
    '6. noad_proxy.php?mode=m3u8 (clean local m3u8)' => function () use ($root) {
        $m3u8 = "$root/cache/_e2e.m3u8";
        @mkdir("$root/cache");
        file_put_contents($m3u8, "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\nad/seg01.ts\n#EXTINF:5.0,\nad/seg02.ts\n#EXTINF:10.0,\nplay/seg03.ts\n#EXTINF:10.0,\nplay/seg04.ts\n#EXT-X-ENDLIST\n");
        $r = runIsolated($root, 'noad_proxy.php', array('mode' => 'm3u8', 'src' => 'file://' . $m3u8), null);
        @unlink($m3u8);
        return $r;
    },
    '7. noad_proxy.php?mode=ts' => function () use ($root) {
        $ts = "$root/cache/_e2e.ts";
        @mkdir("$root/cache");
        file_put_contents($ts, str_repeat('FAKETS', 100));
        $r = runIsolated($root, 'noad_proxy.php', array('mode' => 'ts', 'src' => 'file://' . $ts), null);
        @unlink($ts);
        return $r;
    },
    '8. admin.php (GET page, no login session)' => function () use ($root) {
        return runIsolated($root, 'admin.php', array(), null);
    },
    '9. admin.php (POST action=ajax_list_algorithms)' => function () use ($root) {
        return runIsolated($root, 'admin.php', array(), array('action' => 'ajax_list_algorithms'));
    },
    '10. admin.php (POST action=ajax_get_sites)' => function () use ($root) {
        return runIsolated($root, 'admin.php', array(), array('action' => 'ajax_get_sites'));
    },
    '11. admin.php (POST action=ajax_parse_m3u8)' => function () use ($root) {
        $m3u8 = "$root/cache/_e2e.m3u8";
        @mkdir("$root/cache");
        file_put_contents($m3u8, "#EXTM3U\n#EXTINF:10.0,\n/ad/seg01.ts\n#EXTINF:10.0,\n/play/seg02.ts\n#EXT-X-ENDLIST\n");
        $r = runIsolated($root, 'admin.php', array(), array('action' => 'ajax_parse_m3u8', 'm3u8_url' => 'file://' . $m3u8));
        @unlink($m3u8);
        return $r;
    },
    '12. html/index.html readable' => function () use ($root) {
        return array('out' => (string)@filesize("$root/html/index.html"), 'status' => 0);
    },
    '13. html/player.html readable' => function () use ($root) {
        return array('out' => (string)@filesize("$root/html/player.html"), 'status' => 0);
    },
    '14. config/switch.php valid php' => function () use ($root) {
        return lintPhp("$root/config/switch.php");
    },
    '15. config/admin.php valid php' => function () use ($root) {
        return lintPhp("$root/config/admin.php");
    },
    '16. algorithms/ 全部可加载' => function () use ($root) {
        $files = glob("$root/algorithms/*.php");
        $out = array();
        $bad = 0;
        foreach ($files as $f) {
            $check = lintPhp($f);
            if (!$check['ok']) $bad++;
            $out[] = basename($f) . '=' . ($check['ok'] ? 'OK' : 'BAD');
        }
        return array('out' => 'count=' . count($files) . ' bad=' . $bad . ' ' . implode(',', $out), 'status' => $bad === 0 ? 0 : 1);
    },
);

function lintPhp($path) {
    global $phpBin;
    $cmd = $phpBin . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($cmd, $lines, $ret);
    $txt = implode("\n", $lines);
    return array('out' => $txt, 'status' => (int)!preg_match('/No syntax errors/i', $txt), 'ok' => preg_match('/No syntax errors/i', $txt));
}

function runIsolated($root, $file, $get, $post) {
    global $phpBin;
    // 生成小脚本
    $stub = tempnam(sys_get_temp_dir(), 'e2e_') . '.php';
    $php = "<?php\n";
    $php .= '$_GET = ' . var_export($get ?: array(), true) . ";\n";
    $php .= '$_POST = ' . var_export($post ?: array(), true) . ";\n";
    $php .= '$_SERVER["REQUEST_METHOD"] = ' . var_export($post ? 'POST' : 'GET', true) . ";\n";
    $php .= '$_SERVER["HTTP_HOST"] = "localhost";' . "\n";
    $php .= '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";' . "\n";
    $php .= '$_SERVER["REQUEST_URI"] = "/" . ' . var_export($file, true) . ';' . "\n";
    $php .= 'chdir(' . var_export($root, true) . ');' . "\n";
    $php .= 'require ' . var_export($root . '/' . $file, true) . ';' . "\n";
    file_put_contents($stub, $php);
    $cmd = $phpBin . ' -d display_errors=stderr -d log_errors=0 -d variables_order=EGPCS -d session.use_cookies=0 -d session.use_trans_sid=0 -f ' . escapeshellarg($stub) . ' 2>&1';
    exec($cmd, $lines, $ret);
    $out = implode("\n", $lines);
    @unlink($stub);
    return array('out' => $out, 'status' => $ret);
}

$passed = 0; $failed = 0;
$issues = array();
foreach ($cases as $name => $fn) {
    try {
        $result = $fn();
    } catch (Throwable $e) {
        $result = array('out' => 'EXCEPTION: ' . $e->getMessage(), 'status' => 1);
    }
    $out = $result['out'] ?? '';
    $status = $result['status'] ?? 1;
    $hasFatal = (stripos($out, 'Fatal error') !== false) || (stripos($out, 'Uncaught') !== false) || strpos($out, 'Parse error') !== false;
    $hasWarning = stripos($out, 'Warning:') !== false || stripos($out, 'Notice:') !== false || stripos($out, 'Deprecated:') !== false;

    $ok = !$hasFatal && $status === 0;
    $flag = $ok ? 'OK' : 'FAIL';
    if ($ok && $hasWarning) $flag .= ' (warn)';
    echo str_pad($name, 65) . $flag . "  (" . strlen($out) . " bytes)\n";
    if (!$ok || $hasWarning) {
        $issues[] = array($name, $flag, substr($out, 0, 1200));
        echo "---- detail ----\n" . substr($out, 0, 1200) . "\n-----------------\n";
    }
    if ($ok) $passed++; else $failed++;
}

echo "\n===== Summary =====\nTotal: " . ($passed + $failed) . "  Passed: $passed  Failed: $failed\n";
if (!empty($issues)) {
    echo "\nKnown issues (" . count($issues) . "):\n";
    foreach ($issues as $i) {
        echo " - " . $i[0] . " -> " . $i[1] . "\n";
    }
}
exit($failed > 0 ? 1 : 0);
