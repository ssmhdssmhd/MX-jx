<?php
/**
 * API线路配置文件
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

return [
    /**
     * api线路配置 - 支持标准json格式的数据接口
     * 
     * 格式: '接口备注' => "解析接口|请求超时时间"
     * 示例: 'MyJson1-VIP' => "https://jx.98big.com/?url=|5"
     * 
     * 说明: 系统会并发请求所有可用接口，优先返回最快成功的接口
     * 超时时间表示接口超过指定秒数未响应则自动放弃
     */
    'MyJson1-VIP' => "https://jx.jsonplayer.com/player/?url=|5",
    'MyJson2-VIP' => "https://jx.m3u8.tv/jiexi/?url=|5", 
    'MyJson3-VIP' => "https://jx.bozrc.com:4433/player/?url=|5",
    'MyJson4-VIP' => "https://jx.xmflv.com:4433/player/?url=|5",
    'MyJson5-VIP' => "https://jx.ivitoa.com/player/?url=|5",
    'MyJson6-VIP' => "https://jx.we-vip.com:4433/player/?url=|5",
    
    // 新增的7条API线路
    'MyJson7-VIP' => "https://jx.lache.me/cc/?url=|5",
    'MyJson8-VIP' => "https://jx.yangshipin.cn/?url=|5",
    'MyJson9-VIP' => "https://jx.ppflv.com/?url=|5",
    'MyJson10-VIP' => "https://jx.m3u8.tv/jiexi/?url=|5",
    'MyJson11-VIP' => "https://jx.618g.com/?url=|5",
    'MyJson12-VIP' => "https://jx.quankan.app/?url=|5",
    'MyJson13-VIP' => "https://jx.blbo.cc:4433/?url=|5",
    
    // 备用线路
    'Backup1' => "https://jx.jsonplayer.com/player/?url=|8",
    'Backup2' => "https://jx.m3u8.tv/jiexi/?url=|8",
];