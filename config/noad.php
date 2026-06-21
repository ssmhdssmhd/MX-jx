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
        // ========== A. 通用广告标识 ==========
        "ad", "ads", "advert", "advertisement", "pre-roll", "mid-roll", "post-roll",
        "adbreak", "adslot", "adzone", "adbreak", "sponsor",

        // ========== B. 中文广告标识 ==========
        "广告", "插播", "片头广告", "片中广告", "片尾广告",
        "推广", "推广位", "商业广告", "贴片广告", "暂停广告",
        "角标广告", "浮动广告", "前置广告", "中插广告", "后插广告",

        // ========== C. 视频平台广告相关 ==========
        "ad.qq.com", "adnet", "adnxs", "adserver", "adsrv",
        "adsame", "adwo", "adview", "admob", "admob",
        "inmobi", "appodeal", "mopub", "unityads", "vungle",
        "applovin", "chartboost", "ironsrc", "mintegral", "穿山甲",
        "广点通", "百青藤", "芒果广告", "优酷广告", "腾讯广告",

        // ========== D. CDN/域名广告关键词 ==========
        "adcdn", "adpush", "adp", "adstatic", "adcdn",
        "adapi", "adfile", "adimg", "adpic", "adpicx",
        "adv.", "adv-", "-adv", "/adv/", "ads/",
        "click", "clicktrack", "clickid", "clickTAG",
        "track", "tracking", "tracker", "analytics", "stat.",
        "tongji", "cnzz", "baidu", "hm.baidu", "hm.js",
        "51.la", "metric", "pixel", "beacon",

        // ========== E. 播放器/解析广告 ==========
        "tvod", "txvideo_ad", "aliyun_ad", "qiyi_ad", "youku_ad",
        "vip.live.com", "vip video", "vip.ad", "vipad",
        "playerad", "player_ad", "preroll", "postroll", "midroll",
        "videoad", "video_ad", "clipad", "clip_ad",
        "mp4ad", "ad.mp4", "ad.m3u8", "ad_",

        // ========== F. 推广/营销关键词 ==========
        "promo", "promotion", "sponsor", "sponsored",
        "featured", "recommend_ad", "hotspot", "hot_ad",
        "banner", "bannerad", "floatad", "float_ad",
        "logo", "overlay", "overlay_ad", "watermark_ad",
        "skip", "skipad", "skip_ad", "jumpad",
        "countdown", "countdown_ad", "adcountdown",

        // ========== G. 短片段广告（时长 < 3秒 且含以下关键词）==========
        "logo_", "_logo", "logo.", "corner_ad",
        "preroll_", "_preroll", "postroll_", "_postroll",
        "short_", "_short", "teaser", "teaser_ad",
        "trailer_ad", "_trailer", "intro_ad", "outro_ad",
        "bumper", "bumper_ad",

        // ========== H. M3U8 广告片段常见路径 ==========
        "commercial", "commercials", "ad_content", "ad_clip",
        "adsegment", "ad_seg", "adv_seg", "adpiece",
        "breakpoint", "ad_break", "adblock", "block_ad",
        "noncontent", "non_content", "placeholder_ad",
    ),

    // ========== 广告白名单（防止误伤） ==========
    // 说明: 命中以下关键词的片段无论是否命中广告规则都将保留
    "whitelist_keywords" => array(
        // ========== 正片/内容相关 ==========
        "main", "content", "video", "正片", "正集", "正片高清",
        "movie", "film", "episode", "ep", "clip", "clips",
        "part", "chapter", "segment", "seg",
        "trailer", "预告", "preview", "preview",
        "opening", "op", "ending", "ed", "片头", "片尾",

        // ========== 分辨率/画质 ==========
        "hd", "fhd", "4k", "uhd", "1080", "1080p", "720", "720p",
        "540", "480", "360", "br", "bitrate", "h264", "h265",
        "hevc", "avc", "av1",

        // ========== 音频/字幕 ==========
        "audio", "audio_", "_audio", "aac", "mp3",
        "subtitle", "subtitle_", "_subtitle", "subtitle",
        "caption", "caption_", "chn", "cn", "eng", "zho",

        // ========== 播放列表相关 ==========
        "playlist", "index", "master", "variant",
        "chunklist", "seg", "segment", "stream",
        "live", "vod", "ll-hls", "dvr",

        // ========== CDN/源站白名单 ==========
        "aliyun", "aliyuncs", "alibaba", "alibabacdn",
        "tencent", "cdntc", "myqcloud", "qcloud",
        "bytedance", "bytedns", "bdydns", "bytecdn",
        "kuaishou", "ksurl", "kscdn",
        "bilibili", "bili", "hdslb",
        "iQiyi", "iqiyi", "qiyi",
        "youku", "ykimg", "img", "ykcdn",
        "mgtv", "hunantv", "imgv",
        "sohu", "sohuv", "vms",
        "letv", "lespark", "lescdn",
        "migu", "cmcc", "cmvod",
        "pps", "pptv", "pplive",
        "wasu", "wasucloud",
        "xunlei", "xlwebcloud",
        "baiducloud", "bcehost",

        // ========== 通用安全词 ==========
        "stream", "live", "onair", "on_air",
        "source", "src", "origin",
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

    // ========== 资源站去广告开关（v4.1.0 新增）==========
    "enable_resource_site_cleaning" => true,   // 启用资源站域名识别 + suanfa 算法清理
    "resource_site_debug" => false,            // 是否返回资源站识别信息（供调试）

    // ========== 自定义算法扩展（v4.2 新增）==========
    "enable_custom_algorithms" => true,        // 启用 /algorithms 目录下的自定义算法扩展
    "custom_algorithms_scope" => "all",        // 默认作用域: all / url / m3u8

    // ========== 预置资源站（后台会从配置 + 数据库合并）==========
    "resource_sites" => array(
        // 电影天堂 (dytt)
        array(
            "name"          => "电影天堂",
            "short_code"    => "dytt",
            "base_url"      => "https://www.dytt8.com",
            "patterns"      => array("dytt", "电影天堂", "dy8", "dianyingtiantang"),
            "algorithms"    => array("suanfasmall", "suanfa3", "suanfadyt"),
            "priority"      => 1,
            "enabled"       => true,
        ),
        // 西瓜视频 (xigua / xiguang)
        array(
            "name"          => "西瓜资源",
            "short_code"    => "xg",
            "base_url"      => "https://www.360kan.com",
            "patterns"      => array("xigua", "xiguang", "西瓜", "360kan", "ixigua", "ixg"),
            "algorithms"    => array("suanfaxiguang", "suanfa5", "suanfa9"),
            "priority"      => 2,
            "enabled"       => true,
        ),
        // 如意资源站
        array(
            "name"          => "如意资源",
            "short_code"    => "ry",
            "base_url"      => "https://jx.ruyi.com",
            "patterns"      => array("ruyi", "如意", "ry.jx", "ryplayer"),
            "algorithms"    => array("suanfa8", "suanfa4", "suanfa5"),
            "priority"      => 3,
            "enabled"       => true,
        ),
        // 爱奇艺
        array(
            "name"          => "爱奇艺去广告",
            "short_code"    => "iqiyi",
            "base_url"      => "https://www.iqiyi.com",
            "patterns"      => array("iqiyi", "爱奇艺", "qiyi"),
            "algorithms"    => array("suanfa9", "suanfa5", "suanfa4"),
            "priority"      => 5,
            "enabled"       => true,
        ),
        // 腾讯视频
        array(
            "name"          => "腾讯视频去广告",
            "short_code"    => "qq",
            "base_url"      => "https://v.qq.com",
            "patterns"      => array("qq.com", "腾讯", "v.qq", "video.qq"),
            "algorithms"    => array("suanfa7", "suanfa4", "suanfa5", "suanfa1"),
            "priority"      => 5,
            "enabled"       => true,
        ),
        // 优酷
        array(
            "name"          => "优酷去广告",
            "short_code"    => "youku",
            "base_url"      => "https://www.youku.com",
            "patterns"      => array("youku", "优酷", "yk", "v.youku"),
            "algorithms"    => array("suanfa7", "suanfa4", "suanfa5", "suanfa1"),
            "priority"      => 5,
            "enabled"       => true,
        ),
        // 芒果 TV
        array(
            "name"          => "芒果 TV",
            "short_code"    => "mgtv",
            "base_url"      => "https://www.mgtv.com",
            "patterns"      => array("mgtv", "芒果", "hunantv", "mgtv.com"),
            "algorithms"    => array("suanfa7", "suanfa4", "suanfa5"),
            "priority"      => 6,
            "enabled"       => true,
        ),
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
