<?php
/**
 * Noad 播放列表代理入口
 * 功能:
 *   ?mode=m3u8&src=https://.../play.m3u8   -> 返回去广告后的 M3U8
 *   ?mode=ts&src=https://.../seg01.ts       -> 代理转发 TS 片段（解决跨域）
 *   ?mode=api&url=https://v.qq.com/...      -> 直接返回 JSON 解析结果
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/NoAdParser.php';

$noadCfg = require __DIR__ . '/config/noad.php';
if (empty($noadCfg['noad_enabled'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => 503, 'msg' => 'Noad 去广告系统已关闭'));
    exit;
}

$mode = $_GET['mode'] ?? 'api';
$src  = $_GET['src'] ?? '';
$url  = $_GET['url'] ?? '';
$parser = new NoAdParser();

switch ($mode) {

    case 'm3u8':
        if ($src === '') { http_response_code(400); echo 'missing src'; exit; }
        $parser->serveCleanM3u8('', $src);
        exit;

    case 'ts':
        if ($src === '') { http_response_code(400); echo 'missing src'; exit; }
        $parser->serveTs($src);
        exit;

    case 'api':
    default:
        if ($url === '') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 400, 'msg' => 'missing url param'));
            exit;
        }
        $videoType = $_GET['type'] ?? '';
        $result = $parser->parse($url, $videoType);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
}
