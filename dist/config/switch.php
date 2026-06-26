<?php
/**
 * 系统开关配置
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

return [
    /**
     * 总接口模式开关
     * 开启后，前3条API线路将作为总接口，可调用所有平台规则
     */
    'enable_global_api' => true,
    
    /**
     * ZJK.txt文件路径
     */
    'zjk_file_path' => 'ZJK.txt',
    
    /**
     * 总接口并发超时时间（秒）
     */
    'global_api_timeout' => 8,
    
    /**
     * 启用前几条API作为总接口
     */
    'global_api_count' => 6,
    
    /**
     * 是否启用ZJK.txt中的接口
     */
    'enable_zjk_apis' => true,
    
    /**
     * M3U8直接输出开关
     * 开启后，如果检测到.m3u8链接将直接输出
     */
    'enable_m3u8_direct' => true,
    
    /**
     * 统一显示开关
     * 开启后，msg和url字段将显示相同的内容
     */
    'enable_unified_display' => true,

    /**
     * NoAd 去广告系统总开关
     * 开启后启用 M3U8 去广告、MD5 深度分析等功能
     */
    'noad_enabled' => true,

    /**
     * SQLite 数据库开关
     * 开启后使用 SQLite 存储广告指纹库、访问统计等数据
     * 关闭后使用内存缓存（重启后数据丢失）
     */
    'sqlite_enabled' => true,

    /**
     * 去插播广告开关
     * 开启后自动识别并过滤 M3U8 中的广告和插播片段
     * 可通过 /q?url= 接口调用去广告功能
     */
    'ad_remove_enabled' => true,

    /**
     * 视频解析接口开关
     * 开启后，/?url= 视频解析接口可用
     */
    'video_parse_enabled' => true,

    /**
     * 去广告链接接口开关
     * 开启后，/q.php?url= 去广告链接接口可用
     */
    'ad_remove_url_enabled' => true,

    /**
     * 解析结果集成去广告开关
     * 开启后，当视频解析和去广告功能都启用时，
     * 在 /?url= 的返回结果中自动集成去广告后的播放链接数组
     */
    'parse_integrate_noad' => true,

    /**
     * 调试模式开关
     * 开启后返回详细的调试信息
     */
    'debug_mode_enabled' => false,
];