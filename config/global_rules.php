<?php
/**
 * 全局规则库 - 广告过滤核心配置
 *
 * 本文件包含所有去广告相关的规则：
 * - 域名黑名单
 * - 关键词库
 * - 文件名前缀
 * - 白名单
 * - MD5指纹库
 *
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 1.0.0
 */

return [
    /**
     * 广告域名黑名单
     * 包含常见广告域名，支持泛匹配
     */
    'ad_domains' => [
        // 国际广告域名
        'ad.', 'ads.', 'advert.', 'advertisement.', 'sponsor.',
        'doubleclick.net', 'googlesyndication.com', 'googleadservices.com',
        'googletagmanager.com', 'googletagservices.com',
        'amazon-adsystem.com', 'adsystem.amazon.',
        'facebook.com/tr', 'connect.facebook.net/en_US/fbevents.js',
        'analytics.', 'tracking.', 'pixel.', 'beacon.',
        'adnxs.com', 'criteo.com', 'taboola.com', 'outbrain.com',
        'moatads.com', 'chartbeat.com', 'mixpanel.com',

        // 国内广告域名
        'bdstatic.com', 'bdimg.com', 'baidu.com/bdh',
        'yunpian.com', 'emqtt.com',
        'miaozhen.com', 'Admaster.com',
        'talkingdata.com', 'dataeye.com',
        'growingio.com', '神策.com',

        // 视频广告域名
        'adservice.google.', 'ads.youtube.com',
        'adserver.yahoo.com', 'ad.doubleclick.net',
        'ad.youtube.com', 'adservice.google.com',

        // CDN广告
        'cdn-ad.', 'ad-cdn.', 'adcdn.',
        'static.ads.', 'media.ads.',
    ],

    /**
     * 广告关键词库
     * 匹配文件路径或URL中的关键词
     */
    'ad_keywords' => [
        // 英文关键词
        'ad_', '_ad', 'adver', 'advert', 'sponsor', 'sponsor_',
        'promo', 'banner', 'pre-roll', 'mid-roll', 'post-roll',
        'adbreak', 'ad_url', 'adlink', 'adslot', 'ad_unit',
        'advertisement', 'advertising', 'promotion',

        // 中文关键词
        '广告', '片头广告', '片尾广告', '贴片广告', '中插广告',
        '赞助商', '广告位', '广告域名', '广告片段', '广告链接',
        '滚动广告', '弹窗广告', '视频广告', '流广告',

        // 特殊标记
        'click', 'click_track', 'impression', 'conversion',
        'tracker', 'tracking', 'analytics', 'stats',
    ],

    /**
     * 文件名前缀黑名单
     * 用于匹配TS/MP4等片段文件名
     */
    'ad_prefixes' => [
        'ad_', 'adv_', 'sponsor_', 'promo_', 'banner_',
        'adver_', 'advert_', 'pre_roll_', 'mid_roll_',
    ],

    /**
     * 白名单（正片特征词）
     * 包含这些词的片段不会被误删
     */
    'whitelist' => [
        // 视频正片特征
        '正片', 'main', 'video', 'play', 'stream', 'vod',
        'hd', 'sd', '1080p', '720p', '480p', '360p',
        'ch0', 'ch1', 'chapter', 'episode', 'part',

        // 集数标记
        '第', '集', 'episode', 'ep_', 'ep-',

        // 质量标识
        'high', 'medium', 'low', 'auto', 'baseline',

        // 其他正片特征
        'content', 'media', 'segment', 'ts',
    ],

    /**
     * MD5指纹库
     * 存储已知的广告片段MD5指纹
     * 格式: 'md5指纹' => ['count' => 出现次数, 'type' => 'ad/whitelist']
     */
    'md5_signatures' => [
        // 示例（实际使用中会自动学习）
        // 'a1b2c3d4e5f678901234567890123456' => ['count' => 5, 'type' => 'ad'],
    ],

    /**
     * 规则更新时间
     */
    'updated_at' => '2026-06-22 22:00:00',

    /**
     * 规则来源
     */
    'source' => 'manual',
];
