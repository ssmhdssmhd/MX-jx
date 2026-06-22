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
        // TS 片段代理：解决跨域和防盗链问题
        $tsUrl = urldecode($src);
        $ch = curl_init($tsUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_REFERER        => parse_url($tsUrl, PHP_URL_SCHEME) . '://' . parse_url($tsUrl, PHP_URL_HOST),
            CURLOPT_HTTPHEADER     => array(
                'Accept: video/mp2t,video/*,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ),
        ));
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $code !== 200) {
            http_response_code(502);
            header('Access-Control-Allow-Origin: *');
            echo 'TS proxy failed: ' . ($err ?: 'HTTP ' . $code);
            exit;
        }
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        header('Cache-Control: public, max-age=86400');
        
        if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $m)) {
            header('Content-Type: ' . trim($m[1]));
        } else {
            header('Content-Type: video/mp2t');
        }
        
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
            header('Content-Length: ' . $m[1]);
        }
        
        echo $body;
        exit;

    case 'rules':
        // 返回当前去广告规则（供前端展示）
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: max-age=300');
        echo json_encode(array(
            'code' => 200,
            'ad_keywords' => $noadCfg['ad_keywords'] ?? array(),
            'whitelist_keywords' => $noadCfg['whitelist_keywords'] ?? array(),
            'ad_keyword_threshold' => $noadCfg['ad_keyword_threshold'] ?? 1,
            'ad_filter_enabled' => !empty($noadCfg['enable_ad_filter']),
            'ts_proxy_enabled' => !empty($noadCfg['enable_ts_proxy']),
        ), JSON_UNESCAPED_UNICODE);
        exit;

    // ========== v4.2 自定义算法 API ==========
    case 'algorithms':
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        $action = $_GET['a'] ?? 'list';
        if ($action === 'list') {
            echo json_encode(array(
                'code' => 200,
                'algorithms' => $parser->listCustomAlgorithms(),
                'enabled_count' => count(array_filter($parser->listCustomAlgorithms(), function ($x) {
                    return !empty($x['enabled']);
                })),
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'toggle') {
            $id = $_POST['id'] ?? $_GET['id'] ?? '';
            $enabled = (bool)($_POST['enabled'] ?? $_GET['enabled'] ?? 1);
            if ($id === '') { echo json_encode(['code' => 400, 'msg' => 'missing id']); exit; }
            $parser->setCustomAlgorithmEnabled($id, $enabled);
            echo json_encode(['code' => 200, 'id' => $id, 'enabled' => $enabled]);
            exit;
        }
        if ($action === 'reload') {
            $algos = $parser->reloadCustomAlgorithms();
            echo json_encode(['code' => 200, 'algorithms' => $algos], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'test') {
            $input = $_POST['input'] ?? $_GET['input'] ?? '';
            $scope = $_POST['scope'] ?? $_GET['scope'] ?? 'all';
            if ($input === '') { echo json_encode(['code' => 400, 'msg' => 'missing input']); exit; }
            $result = $parser->applyCustomAlgorithms($input, $scope, ['original_url' => 'test://local']);
            echo json_encode(array(
                'code'     => 200,
                'original' => $result['original'] ?? $input,
                'result'   => $result['data'] ?? $input,
                'applied'  => $result['applied'] ?? [],
                'changed'  => ($result['data'] ?? $input) !== $input,
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['code' => 400, 'msg' => 'unknown action: ' . $action], JSON_UNESCAPED_UNICODE);
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

        // ============================================================
        // 资源站去广告：对返回的 URL / m3u8 内容应用 suanfa 算法
        // ============================================================
        $noadCfg = require __DIR__ . '/config/noad.php';
        if (!empty($noadCfg['enable_resource_site_cleaning'])) {
            $cleanTarget = '';
            if (!empty($result['url']))              $cleanTarget = $result['url'];
            else if (!empty($result['noad_url']))    $cleanTarget = $result['noad_url'];

            if ($cleanTarget !== '') {
                $cleanResult = $parser->cleanByResourceSite($url, $cleanTarget);
                if (!empty($cleanResult['data']) && $cleanResult['data'] !== $cleanTarget) {
                    $result['noad_url'] = $cleanResult['data'];
                    $result['url']      = $cleanResult['data'];
                }
                $result['resource_site'] = array(
                    'matched'   => $cleanResult['matched_site'],
                    'algorithms'=> $cleanResult['algorithms_applied'],
                    'ad_tokens' => $cleanResult['ad_tokens_removed'],
                );
            }

            // 若返回中有 raw_m3u8/clean_m3u8 字段，则对每个 URI 也应用清理
            if (!empty($result['clean_m3u8'])) {
                $cleanM3u8 = $parser->cleanByResourceSite($url, $result['clean_m3u8']);
                if (!empty($cleanM3u8['data'])) {
                    $result['clean_m3u8'] = $cleanM3u8['data'];
                }
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
}
