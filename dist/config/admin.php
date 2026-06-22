<?php
/**
 * 后台管理配置文件
 * @author MX-射手沫蝴蝶
 * @contact QQ: 2094332348
 */

return [
    /**
     * 后台总开关
     * 设置为 false 将禁用整个后台，任何人（包括管理员）都无法登录
     */
    'admin_enabled' => true,

    /**
     * 后台访问路径（支持自定义）
     *
     * 默认为 admin.php，即通过以下地址访问：
     *   http://你的域名/admin.php
     *   http://你的域名:8899/admin.php
     *   http://服务器IP/admin.php
     *
     * 如需自定义，例如改为 control_panel.php，则访问地址为：
     *   http://你的域名/control_panel.php
     *
     * 注意：修改此配置后，需要同时重命名根目录下的 admin.php
     * （后台内部已提供"修改后台路径"功能，可一键完成）
     */
    'admin_path' => 'admin.php',

    /**
     * 管理员密码（MD5 加密后的密码）
     *
     * 默认密码：admin123
     * （首次登录后请务必在"后台设置"中修改密码）
     *
     * 如需手动修改：echo md5('你的密码'); 即可得到新的哈希值
     */
    'admin_password' => '0192023a7bbd73250516f069df18b500',

    /**
     * 允许访问后台的端口号（数组格式，可添加多个）
     *
     * 留空数组 [] 表示不限制端口（任何端口都可访问）
     * 例如：[80, 8899, 443] 表示仅允许通过 80/443/8899 端口访问
     *
     * 说明：端口限制属于服务器级别的设置（Nginx/Apache 监听端口），
     * 这里只是在 PHP 层面做二次校验。想要让 8899 端口能访问，
     * 还需要在 Nginx/Apache 配置中监听该端口并指向本项目目录。
     */
    'allowed_ports' => [80, 443, 8899, 8080],

    /**
     * 是否强制仅允许指定端口访问后台
     *   true  = 仅允许 allowed_ports 列表中的端口访问
     *   false = 任何端口都可访问（但仍需密码登录）
     */
    'enforce_port' => false,

    /**
     * 允许访问后台的 IP 白名单（数组格式）
     *
     * 留空数组 [] 表示不限制 IP（任何 IP 都可访问）
     * 例如：['127.0.0.1', '192.168.1.100', '你的公网IP']
     */
    'allowed_ips' => [],

    /**
     * 会话有效期（秒）
     * 登录后多久自动退出登录
     * 默认 2 小时 = 7200 秒
     */
    'session_lifetime' => 7200,

    /**
     * 登录失败最大次数（防止暴力破解）
     * 超过此次数后，该 IP 将被锁定 lockout_duration 秒
     */
    'max_login_attempts' => 5,

    /**
     * 登录失败锁定时长（秒）
     */
    'lockout_duration' => 300,

    /**
     * 是否启用操作日志
     * true  = 记录管理员的所有修改操作到 cache/admin_log.txt
     * false = 不记录
     */
    'enable_log' => true,
];
