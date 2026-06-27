<?php
/**
 * 平台规则配置文件
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

return [
    /**
     * 指定平台规则配置
     * 
     * 格式: '平台备注' => "域名或标识|优先使用的API线路"
     * 示例: 'qq' => 'v.qq.com|MyJson1-VIP'
     * 
     * 说明: 当请求URL包含指定域名时，优先使用对应的API线路
     * 系统仍会并发请求其他接口作为备用
     */
    
    // ===== 五大官方平台（大官解） =====
    '腾讯视频' => 'v.qq.com|MyJson1-VIP',
    '爱奇艺' => 'iqiyi.com|MyJson2-VIP',
    '优酷' => 'youku.com|MyJson6-VIP',
    '芒果TV' => 'mgtv.com|MyJson5-VIP',
    '哔哩哔哩' => 'bilibili.com|MyJson5-VIP',
    
    // ===== 其他平台 =====
    'PPTV' => 'pptv.com|MyJson1-VIP',
    '乐视' => 'le.com|MyJson2-VIP',
    '搜狐视频' => 'sohu.com|MyJson3-VIP',
    'M1905电影网' => '1905.com|MyJson4-VIP',
    '西瓜视频' => 'ixigua.com|MyJson6-VIP',
    'AcFun' => 'acfun.cn|MyJson1-VIP',
    '抖音' => 'douyin.com|MyJson4-VIP',
    '虎扑' => 'hoopchina.com.cn|MyJson3-VIP',
    '1075' => '1075_|MyJson2-VIP',
    '1098' => '1098_|MyJson3-VIP',
    '1097' => '1097_|MyJson4-VIP',
    'maituitui' => 'ff80|MyJson5-VIP',
];