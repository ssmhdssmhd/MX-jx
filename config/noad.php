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

    // ========== 如意解析源专属去广告算法（如意 v2.0+ 配置）==========
    // 说明: 针对如意（ryiplay/如意资源站的 M3U8 去广告参数
    //       该算法会自动分析 M3U8 片段的广告片段进行清理。
    //       默认参数: 针对资源站通用，但可调节 50~60s 为视频测试
    "ruyi_enabled" => true,                    // 启用如意专属算法开关（默认
    "ruyi_score_threshold" => 4,                  // Score 阈值 = 0 表示不限制，数值越大越严格越保守）
    "ruyi_baseline_sec" => 4.00,                  // 资源站标准片段时长（秒）：通常是视频编码标准单元，用于判断哪些片段时长偏离正常值属于异常并由并非常规片段
    "ruyi_baseline_tolerance" => 0.10,             // 基准时长容差（秒）：±0.10s 内都算正常片段
    "ruyi_min_cluster_len" => 3,                     // 广告簇最小长度：连续多少个非基准时长片段为广告
    "ruyi_max_cluster_len" => 15,                    // 广告簇最大长度（超过视为正片内容
    "ruyi_min_cluster_sum" => 15.0,                  // 广告簇最小总时长（秒）：广告块总时长阈值
    "ruyi_max_cluster_sum" => 35.0,                  // 广告簇最大总时长（秒）：广告块总时长上限
    "ruyi_short_seg_threshold" => 3.0,                  // 短片段阈值（秒）：时长 < 此值的独立片段标记为广告候选
    "ruyi_very_short_threshold" => 1.5,               // 极短片段阈值（秒）： < 此值直接判定为广告过渡/推广
    "ruyi_enable_discontinuity" => true,              // 启用 DISCONTINUITY 标记辅助判断
    "ruyi_auto_optimize_enabled" => true,            // 每天自动检测和自动优化算法参数
    "ruyi_auto_optimize_hour" => 3,              // 自动检测的时间：3点进行检测 服务器空闲时间执行，单位：小时（0~23)
    "ruyi_auto_optimize_interval_hours" => 24,        // 自动检测的间隔（小时），24=每天一次
    "ruyi_auto_optimize_sample_url" => "",           // 自动检测用的示例视频 URL（用于自动检测优化算法去广告效率（为空会自动选择随机选择个视频测试
    "ruyi_debug_mode" => false,                         // 调试模式：返回详细的识别信息（调试专用

    // ==================================================================
    // ========== 🎯 万能规则1 - MD5 指纹去广告（v5.0 新增）==============
    // ==================================================================
    // 核心原理：广告片段会在不同视频/不同集数中重复出现 → MD5 指纹具有高频率重复特征
    //          而正片内容是独一无二的 → 通过统计 MD5 出现次数自动识别广告
    // 优点：与内容无关、自动学习、跨资源站通用
    // 缺点：首次播放需要积累样本（通常 2-3 次相同广告后才会生效）
    "md5_enabled" => true,                      // 启用 MD5 指纹去广告（万能规则1 主开关）
    "md5_repeat_threshold" => 3,                 // MD5 重复次数阈值：相同 MD5 出现在不同视频中 >= 此值 → 判定为广告（默认3，越小越激进）
    "md5_max_concurrency" => 6,                  // 最大并发下载数（会被服务器健康度动态调整：CPU高时自动降低）
    "md5_segment_timeout" => 15,                  // 单个片段下载超时（秒），防止卡死
    "md5_total_timeout" => 60,                    // 总处理超时（秒），整视频处理超过此时间立即停止（保护服务器）
    "md5_max_segment_kb" => 5000,                // 单个片段最大大小（KB），超过则跳过（防止下载超大文件）
    "md5_use_proxy" => true,                     // 启用代理池（降低 IP 被封禁风险）
    "md5_min_interval_ms" => 100,                // 最小请求间隔（毫秒），防止请求过快被识别
    "md5_proxy_pool" => array(                   // MD5 专用代理池（与如意共享，可写额外代理），格式: "http://ip:port" 或 "http://user:pass@ip:port"
        // "http://127.0.0.1:7890",
        // "http://127.0.0.1:10809",
    ),
    "md5_auto_learn" => true,                    // 启用自动学习：每次解析自动记录新 MD5 指纹（越用越准）
    "md5_db_cleanup_days" => 30,                 // 清理 N 天前的片段记录（控制数据库大小）
    "md5_debug" => false,                         // 调试模式：返回 MD5 指纹和识别详情

    // ========== HTTP 代理 & 反封禁（v4.3 新增）==========
    // 说明: 支持多代理轮换，可避免短时间内对同一源请求过多而被封 IP
    // 格式: "http://ip:port" 或 "http://user:pass@ip:port"
    //   示例: "http://127.0.0.1:7890", "http://user:pass@proxy.example.com:8080"
    "enable_proxy" => false,                   // 是否启用 HTTP 代理（默认关闭，自行填好代理再开）
    "proxies" => array(                        // 多代理轮换池（启用时随机选择一个，可写多个）
        // "http://127.0.0.1:7890",
        // "http://127.0.0.1:10809",
    ),
    "proxy_failover" => true,                  // 若代理请求失败，自动退回到直连（避免因某个代理挂掉整体不可用）
    "proxy_random_user_agent" => true,         // 随机化 UA（推荐开启，进一步降低被识别为爬虫）

    // ========== 速率限制（v4.3 新增）==========
    // 说明: 对同一个视频源 URL 的解析请求做最小间隔限制，避免过于频繁请求
    "enable_rate_limit" => true,               // 是否启用速率限制
    "rate_limit_min_interval_ms" => 350,       // 同域名最小请求间隔（毫秒），默认 350ms
    "rate_limit_burst_jitter_ms" => 250,       // 额外随机抖动（毫秒），让请求间隔不均匀，更难识别
    "rate_limit_concurrent_max" => 4,          // 同一时刻最大并发请求数（针对解析源，避免同时发太多）

    // ========== 请求伪装（v4.3 新增）==========
    "request_random_delay" => true,            // 顺序请求时每个请求之间加 50~250ms 随机延迟
    "request_retry_on_failure" => 1,           // 请求失败自动重试次数（默认 1 次，可设 0 关闭）
    "request_custom_headers" => array(         // 自定义请求头（可覆盖默认）
        // "X-Custom-Header" => "value",
    ),

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
