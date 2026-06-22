<?php
/**
 * 示例算法 3: 广告域名重写
 * 将常见的广告/跟踪/第三方 CDN 域名替换为主站真实 CDN
 *
 * 作用域: all
 * 优先级: 35
 */
class AlgoDomainRewrite extends AbstractAlgorithm {

    public $id       = 'domain_rewrite';
    public $priority = 35;
    public $enabled  = true;
    public $scope    = 'all';

    /** 域名映射: key(旧) => value(新)，大小写不敏感 */
    public $config = [
        'map' => [
            // 常见广告 CDN
            'adcdn.'                 => 'cdn.',
            'cdn-ad.'                => 'cdn.',
            'video-ad.'              => 'video.',
            'ad-video.'              => 'video.',
            'ad-player.'             => 'player.',
            'player-ad.'             => 'player.',
            'ads-video.'             => 'video.',
            'advertising.'           => 'video.',
            'tracker.'               => 'api.',
            'clicktrack.'            => 'api.',

            // 常见平台广告子域
            'ad.qq.com'              => 'v.qq.com',
            'adservice.google.com'   => 'video.google.com',
            'ad.video.iqiyi.com'     => 'www.iqiyi.com',
            'x.ad.youku.com'         => 'player.youku.com',
            'ad.dytt.com'            => 'www.dytt.com',
            'ad.xigua.com'           => 'www.ixigua.com',

            // 第三方跟踪域名
            'google-analytics.com'   => '127.0.0.1',
            'googletagmanager.com'   => '127.0.0.1',
            'doubleclick.net'        => '127.0.0.1',
            'stats.wp.com'           => '127.0.0.1',
            'cdn.taboola.com'        => '127.0.0.1',
            'adservice.yes.qq.com'   => 'v.qq.com',

            // 常见 http→https
            'http://'                => 'https://',
        ],
    ];

    public function name() { return '广告域名重写'; }
    public function description() { return '将已知广告/跟踪/第三方 CDN 域名替换为主站真实 CDN 或 127.0.0.1，阻止广告请求'; }
    public function version() { return '1.0.0'; }

    public function apply($input, $context = []) {
        if ($input === '') return $input;

        $result = $input;
        foreach ($this->config['map'] as $bad => $good) {
            // 大小写不敏感替换
            $result = str_ireplace($bad, $good, $result);
        }
        // 去除 "https://https://" 等多次替换产生的畸形
        $result = preg_replace('/(https?:\/\/)+/i', 'https://', $result);

        return $result;
    }
}
