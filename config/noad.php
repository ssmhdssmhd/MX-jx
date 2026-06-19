<?php
/**
 * Noad 去广告解析系统配置
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 * @version 4.0.0
 */

return array(
    // ========== 系统总开关 ==========
    "noad_enabled" => true,                 // Noad 去广告系统总开关
    "debug_mode" => false,                  // 调试模式（生产环境建议关闭）

    // ========== M3U8 去广告核心配置 ==========
    "enable_ad_filter" => true,             // 启用广告片段智能过滤
    "enable_ts_proxy" => true,              // TS 片段代理（解决跨域问题）
    "ad_keyword_threshold" => 2,            // 广告关键词命中阈值（超过该阈值判定为广告）

    // ========== 缓存加速配置 ==========
    "cache_enabled" => true,                // 启用缓存加速
    "cache_ttl" => 1800,                    // 缓存有效期（秒）默认 30 分钟
    "cache_dir" => __DIR__ . "/../cache",   // 缓存目录

    // ========== 多源自动匹配 ==========
    "enable_multi_source" => true,          // 启用多源自动匹配
    "max_source_try" => 3,                  // 单视频最多尝试解析源数量
    "request_timeout" => 10,                // 单源请求超时时间（秒）
    "concurrent_limit" => 8,                // 并发请求上限

    // ========== 广告关键词库（自动识别流媒体规则）==========
    // 说明: 当 M3U8 片段 URI 或 EXTINF 描述中命中以下关键词之一，
    //       该片段将被标记为广告并从播放列表中移除。
    "ad_keywords" => array(
        // 通用广告标识
        "ad", "ads", "advert", "advertisement", "pre-roll", "mid-roll", "post-roll",
        // 中文广告标识
        "广告", "插播", "片头广告", "片中广告", "片尾广告", "推广", "推广位",
        // 视频平台广告
        "v.qq.com/ad", "ad.qq.com", "tvod", "aplayer", "aliyun_ad", "txvideo_ad",
        "vip.live.com", "adver", "promo", "promotion", "sponsor", "sponsored",
        // 广告域名关键词
        "adserver", "advertising", "adnetwork", "tracker", "tracking", "analytics",
        // 短片段疑似广告（片段时长 < 3 秒且路径含以下）
        "logo", "banner", "short_ad", "preroll",
    ),

    // ========== 广告白名单（防止误伤） ==========
    // 说明: 命中以下关键词的片段无论是否命中广告规则都将保留
    "whitelist_keywords" => array(
        "main", "content", "video", "正片", "movie", "film", "episode", "clip",
        "hd", "fhd", "4k", "1080", "720", "540", "360",
    ),

    // ========== 7 种资源类型分类 ==========
    "resource_types" => array(
        1 => array("key" => "movie",    "name" => "🎬 电影资源", "icon" => "🎬"),
        2 => array("key" => "tv",       "name" => "📺 电视剧集", "icon" => "📺"),
        3 => array("key" => "variety",  "name" => "🎤 综艺娱乐", "icon" => "🎤"),
        4 => array("key" => "anime",    "name" => "🎭 动漫动画", "icon" => "🎭"),
        5 => array("key" => "document", "name" => "📚 纪录片", "icon" => "📚"),
        6 => array("key" => "sports",   "name" => "⚽ 体育赛事", "icon" => "⚽"),
        7 => array("key" => "short",    "name" => "📱 短视频", "icon" => "📱"),
    ),

    // ========== 默认去广告解析接口（多源自动匹配）==========
    // 可在后台可视化管理，无需改代码；若接口返回的 M3U8 会自动清洗广告
    "default_sources" => array(
        array("name" => "Noad-主源",  "url" => "https://jx.playerjy.com/?url={url}", "timeout" => 8, "type" => 1),
        array("name" => "Noad-备源1", "url" => "https://www.yemu.xyz/?url={url}",     "timeout" => 8, "type" => 1),
        array("name" => "Noad-备源2", "url" => "https://jx.xmflv.com/?url={url}",    "timeout" => 8, "type" => 2),
        array("name" => "Noad-备源3", "url" => "https://jx.aidouer.net/?url={url}",  "timeout" => 10, "type" => 2),
    ),

    // ========== 数据统计 ==========
    "stats_enabled" => true,                // 启用访问数据统计
    "stats_log_request" => true,            // 记录每次解析请求（IP/时间/来源/耗时）
    "stats_top_limit" => 20,                // 热门统计展示条数

    // ========== SQLite 数据库路径 ==========
    "sqlite_path" => __DIR__ . "/../cache/noad.db",
);
