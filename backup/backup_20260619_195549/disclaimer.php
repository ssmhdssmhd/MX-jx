<?php
/**
 * 免责声明
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

class Disclaimer {
    public static function getDisclaimer() {
        return [
            'disclaimer' => [
                'title' => '免责声明',
                'content' => '本程序仅供学习交流使用，严禁用于商业用途和非法目的。',
                'warning' => '使用者应对其行为承担全部法律责任，开发者不承担任何直接或间接的责任。',
                'notice' => '如不同意本声明，请立即停止使用并删除本程序。'
            ],
            'developer' => [
                'name' => 'MX-射手沫蝴蝶',
                'contact' => 'QQ: 2094332348',
                'version' => '3.0.0',
                'update_date' => '2024-01-01'
            ]
        ];
    }
    
    public static function addDisclaimerToResponse(&$response) {
        $disclaimer = self::getDisclaimer();
        $response['disclaimer'] = $disclaimer['disclaimer']['content'];
        $response['developer'] = $disclaimer['developer']['name'];
        $response['contact'] = $disclaimer['developer']['contact'];
    }
}