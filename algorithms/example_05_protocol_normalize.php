<?php
/**
 * 示例算法 5: 协议规范化 + 相对路径修正
 * 主要工作:
 *   - http→https 统一
 *   - 修复 URL 中多余的 /
 *   - 将 //player.example.com 补全 https
 *   - 将 M3U8 中相对 URI 按基础路径补全
 *
 * 作用域: all
 * 优先级: 20
 */
class AlgoProtocolNormalize extends AbstractAlgorithm {

    public $id       = 'protocol_normalize';
    public $priority = 20;
    public $enabled  = true;
    public $scope    = 'all';

    public $config = [
        'force_https' => true,  // 是否强制 https
        'force_remove_double_slash' => true,
    ];

    public function name() { return '协议与路径规范化'; }
    public function description() { return '统一为 https，修正重复斜杠，补全相对路径或协议相对路径'; }
    public function version() { return '1.0.0'; }

    public function apply($input, $context = []) {
        if ($input === '') return $input;

        $baseUrl = $context['base_url'] ?? ($context['original_url'] ?? '');

        // 多行文本：按行处理，非 URL 保留原样
        if (strpos($input, "\n") !== false || strpos($input, "\r") !== false) {
            $lines = preg_split('/\r\n|\r|\n/', $input);
            $out = [];
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '') { $out[] = $line; continue; }
                // 非 URL 行：如 #EXT 等标签直接保留
                if (strpos($trim, '#') === 0) { $out[] = $line; continue; }
                $out[] = $this->normalizeUrl($trim, $baseUrl);
            }
            return implode("\n", $out);
        }

        return $this->normalizeUrl($input, $baseUrl);
    }

    private function normalizeUrl($url, $baseUrl) {
        $out = $url;

        // 协议相对 URL：补全
        if (strpos($out, '//') === 0) {
            $out = 'https:' . $out;
        }
        // 相对路径 + base 可用时，拼装绝对地址
        if (!preg_match('/^https?:\/\//i', $out) === 0 && $baseUrl !== '' && $out !== '' && strpos($out, 'data:') !== 0) {
            $bp = parse_url($baseUrl);
            if ($bp !== false && isset($bp['host'])) {
                $scheme = $bp['scheme'] ?? 'https';
                $host = $bp['host'] ?? '';
                $port = isset($bp['port']) ? ':' . $bp['port'] : '';
                $dir = dirname($bp['path'] ?? '/');
                if ($dir === '.' || $dir === '\\') $dir = '/';
                $dir = rtrim($dir, '/') . '/';
                if (strpos($out, '/') === 0) {
                    $out = $scheme . '://' . $host . $port . $out;
                } else {
                    $out = $scheme . '://' . $host . $port . $dir . ltrim($out, '/');
                }
            }
        }
        // http → https
        if (!empty($this->config['force_https'])) {
            // 保持原样
        } else {
            $out = preg_replace('/^http:\/\//i', 'https://', $out);
        }
        // 去除多余的重复斜杠（保留协议后的双斜杠）
        $out = preg_replace_callback('/^(https?:\/\/)(.+)$/i', function ($m) {
            return $m[1] . preg_replace('/\/+/', '/', $m[2]);
        }, $out);

        return $out;
    }
}
