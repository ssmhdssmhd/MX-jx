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
    'qq' => 'v.qq.com|MyJson1-VIP',
    'Iqiyi' => 'iqiyi.com|MyJson2-VIP',
    'Hupu' => 'hoopchina.com.cn|MyJson3-VIP',
    'Douyin' => 'douyin.com|MyJson4-VIP',
    'MGTV' => 'mgtv.com|MyJson5-VIP',
    'Youku' => 'youku.com|MyJson6-VIP',
    'PPTV' => 'pptv.com|MyJson1-VIP',
    'Le' => 'le.com|MyJson2-VIP',
    'Sohu' => 'sohu.com|MyJson3-VIP',
    'M1905' => '1905.com|MyJson4-VIP',
    'bilibili' => 'bilibili.com|MyJson5-VIP',
    'XiGua' => 'ixigua.com|MyJson6-VIP',
    'Acfun' => 'acfun.cn|MyJson1-VIP',
    '1075' => '1075_|MyJson2-VIP',
    '1098' => '1098_|MyJson3-VIP',
    '1097' => '1097_|MyJson4-VIP',
    'maituitui' => 'ff80|MyJson5-VIP',
];