<?php
/**
 * 简单直连测试：验证各模块 noad_url 字段
 */

require_once __DIR__ . '/handlers/M3U8Handler.php';

echo "=== 测试 1: M3U8Handler 直链响应 ===\n";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$resp = M3U8Handler::generateM3U8Response('https://example.com/play.m3u8', true);
if (isset($resp['noad_url']) && strpos($resp['noad_url'], 'noad_proxy.php?mode=m3u8') !== false) {
    echo "[PASS] noad_url = " . $resp['noad_url'] . "\n";
} else {
    echo "[FAIL] noad_url 不正确: " . json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

echo "\n=== 测试 2: M3U8 带 query 参数 ===\n";
$resp2 = M3U8Handler::generateM3U8Response('https://example.com/api?video=1&format=m3u8', true);
if (isset($resp2['noad_url']) && strpos($resp2['noad_url'], 'noad_proxy.php') !== false) {
    echo "[PASS] noad_url = " . $resp2['noad_url'] . "\n";
} else {
    echo "[FAIL] noad_url 不正确\n";
    exit(1);
}

echo "\n=== 测试 3: 检查 index.php 中 M3U8 直链路径输出 ===\n";
$phpCode = '
$_GET = array("mode" => "legacy", "url" => "https://example.com/play.m3u8");
$_POST = array();
$_SERVER = array_merge($_SERVER ?: array(), array(
    "SCRIPT_NAME" => "/index.php",
    "HTTP_HOST" => "localhost",
    "REQUEST_URI" => "/",
));
$_REQUEST = array_merge($_GET, $_POST);
ob_start();
require "' . __DIR__ . '/index.php";
$out = ob_get_clean();
echo $out;
';
$tmpFile = sys_get_temp_dir() . '/_noad_test_' . mt_rand() . '.php';
file_put_contents($tmpFile, '<?php ' . $phpCode);
$jsonOutput = shell_exec('php ' . escapeshellarg($tmpFile));
@unlink($tmpFile);

$data = json_decode($jsonOutput, true);
if (!is_array($data)) {
    echo "[FAIL] index.php 输出非 JSON: " . substr($jsonOutput, 0, 300) . "\n";
    exit(1);
}
if (isset($data['noad_url']) && strpos($data['noad_url'], 'noad_proxy.php') !== false) {
    echo "[PASS] noad_url = " . $data['noad_url'] . "\n";
} else {
    echo "[FAIL] 响应中无有效 noad_url 字段，完整响应: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

echo "\n=== 所有测试通过 ===\n";
exit(0);
