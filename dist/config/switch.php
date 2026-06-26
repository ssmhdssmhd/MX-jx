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
];