<div align="center">

# 🎬 沫兮万能解析

**PHP 智能线路切换系统 · 并发版 v3.0.0**

> 多线路并发请求 · 智能选优 · M3U8 直链检测 · 全平台视频解析

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-✅%20Active-brightgreen.svg)]()
[![安装指南](https://img.shields.io/badge/📖-新手安装指南-ff69b4.svg)](INSTALL.md)
[![快速上手](https://img.shields.io/badge/🚀-5%E5%88%86%E9%92%9F%E9%83%A8%E7%BD%B2-orange.svg)](INSTALL.md#3%EF%B8%8F%E2%83%A8%E6%9C%AC%E8%BF%90%E8%A1%8C%E7%A8%8B)

> 💡 **新手看这里**：第一次使用？👉 **[点击查看完整安装指南](INSTALL.md)**（中文，从零开始手把手教学）

</div>

---

## 📖 项目简介

**沫兮万能解析** 是一个基于 PHP cURL 的多线路视频解析系统，采用**并发请求 + 智能选择策略**，自动从多条 API 线路中选择响应最快的有效结果，同时支持 M3U8 直链快速通道。

### ✨ 核心特性

| 功能 | 说明 |
|------|------|
| ⚡ **并发请求** | 同时向所有 API 线路发起请求，优先返回最快响应 |
| 🎯 **智能选优** | 三级选择算法：平台优先级 → 总接口最快 → 全局最快 |
| 📺 **M3U8 直链** | 自动识别 m3u8 链接，无需走解析流程 |
| 🔧 **动态配置** | 通过配置文件增减 API，无需修改代码 |
| 🌐 **全平台适配** | 支持 17+ 视频平台智能匹配 |
| 🎛️ **多开关控制** | 支持自定义接口 / 统一格式 / 总接口 等开关 |
| 💾 **智能缓存** | 内置文件缓存系统，减少重复请求 |

---

## 🚀 快速开始

> 💡 **新手建议**：这里是最简操作步骤。如果看不懂或遇到问题，请阅读
> **[📖 完整新手安装指南](INSTALL.md)**（包含 Windows/macOS/Linux/宝塔面板/虚拟主机
> 全平台图文教程 + 常见问题 FAQ + 配置详解）。

### 环境要求

- **PHP** >= 7.4（支持 `??` 运算符）
- **cURL** 扩展（`curl_multi` 支持，核心依赖，必须启用）
- Web 服务器（Nginx / Apache 均可）

> 🔍 **如何确认环境？** 新建 `phpinfo.php` 写入 `<?php phpinfo(); ?>`，访问后搜索 `curl`，看到 `enabled` 即为通过。详细步骤见 **[INSTALL.md](INSTALL.md#1%EF%B8%8F%E2%83%A3%E7%8E%AF%E5%A2%83%E8%A6%81%E6%B1%82)**

### 部署步骤（3 步）

```bash
# 1. 下载代码
git clone https://github.com/ssmhdssmhd/MX-jx.git
cd MX-jx

# 2. 上传到 PHP 网站根目录（如宝塔的 /www/wwwroot/jx.example.com/）
#    或本地测试：php -S 0.0.0.0:8080

# 3. 浏览器测试（替换为你的域名或 localhost）
# http://你的域名/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html
```

> 🪟 **Windows 用户** 推荐使用 **phpStudy**（小皮面板），详见 **[INSTALL.md · Windows 教程](INSTALL.md#%EF%B8%8F-windows-%E7%94%A8%E6%88%B7phpstudy-%E6%96%B9%E6%A1%88%E6%8E%A8%E8%8D%90-)**
>
> 🐧 **Linux 服务器** 推荐使用 **宝塔面板**，详见 **[INSTALL.md · 宝塔面板教程](INSTALL.md#%EF%B8%8F-%E6%96%B9%E6%A1%88%E4%B8%80%E5%AE%9D%E5%A1%94%E9%9D%A2%E6%9D%BF%E5%9B%BD%E5%86%85%E6%9C%8D%E5%8A%A1%E5%99%A8%E6%8E%A8%E8%8D%90)**

### 调用方式

```
GET  /index.php?url=<视频播放页地址>
POST /index.php  (body: url=<视频播放页地址>)
```

**示例（复制到浏览器测试）：**

```
https://你的域名/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html
https://你的域名/index.php?url=https://www.iqiyi.com/v_xxx.html
https://你的域名/index.php?url=https://www.douyin.com/video/xxx
```

> 📖 **更多使用场景**（配合播放器使用 / 作为 API 接入 / 自定义接口管理）见 **[INSTALL.md · 使用方法](INSTALL.md#5%EF%B8%8F%E2%83%A8%E4%BD%BF%E7%94%A8%E6%96%B9%E6%B3%95)**

---

## 📁 项目结构

```
MX-jx/
├── index.php                  # 🎯 主入口文件（请求路由）
├── README.md                  # 📖 项目说明文档
├── INSTALL.md                 # 🚀 新手安装指南（推荐阅读）
├── disclaimer.php             # 📝 免责声明类
├── ZJK.txt                    # ⚙️ 自定义接口配置
│
├── config/                    # 📂 配置层
│   ├── api.php                # API 线路配置（13主 + 2备）
│   ├── platform.php           # 平台规则配置（17个视频平台）
│   └── switch.php             # 系统开关配置
│
├── core/                      # 📂 核心层
│   ├── strategy.php           # 🧠 Strategy - 智能选择策略
│   ├── requester.php          # 🌐 Requester - 并发请求引擎
│   └── cache.php              # 💾 SmartCache - 文件缓存
│
└── handlers/                  # 📂 处理器层
    └── M3U8Handler.php        # 🎬 M3U8 直链检测与响应
```

### 核心类说明

| 类名 | 文件 | 职责 |
|------|------|------|
| `Strategy` | [core/strategy.php](core/strategy.php) | 策略决策引擎：平台匹配、API 聚合、响应选优 |
| `Requester` | [core/requester.php](core/requester.php) | 请求执行引擎：`curl_multi` 并发请求、结果解析、格式统一 |
| `SmartCache` | [core/cache.php](core/cache.php) | 缓存管理器：文件系统缓存、过期自动清理 |
| `M3U8Handler` | [handlers/M3U8Handler.php](handlers/M3U8Handler.php) | M3U8 处理器：直链检测、快速响应 |

---

## 🏗️ 架构设计

### 请求处理流程

```
                     HTTP 请求
                        │
                        ▼
          ┌─────────────────────────────┐
          │   index.php  参数校验         │
          │   (URL 格式 + 非空校验)       │
          └─────────────┬───────────────┘
                        │
              ┌─────────┴──────────┐
              ▼                    ▼
       是 M3U8 链接？         否 → 进入解析流程
              │                    │
              ▼                    ▼
      直接返回 JSON        ┌───────────────────────┐
                          │ Strategy 决策引擎       │
                          │  · 匹配平台规则          │
                          │  · 聚合所有 API         │
                          └──────────┬─────────────┘
                                     │
                                     ▼
                          ┌───────────────────────┐
                          │ Requester 请求引擎      │
                          │  · curl_multi 并发请求  │
                          │  · 收集有效响应          │
                          │  · Strategy 选最优      │
                          │  · 统一响应格式          │
                          └──────────┬─────────────┘
                                     │
                                     ▼
                              返回 JSON 响应
```

### 智能选优算法

```
                      所有并发响应
                          │
          ┌───────────────┴───────────────┐
          ▼                               ▼
   匹配平台规则？                     未匹配平台
     ├─► 返回 Priority API 响应        │
     └─► 失败则继续                     │
                                       ▼
                              过滤 HTTP 200 + 非空响应
                                       │
                          ┌────────────┴────────────┐
                          ▼                         ▼
                   有总接口响应？               无总接口
                     ├─► 选其中响应最快的           │
                     └─► 无则继续                    │
                                               选全局最快响应
```

---

## ⚙️ 配置说明

### API 线路配置 — [config/api.php](config/api.php)

```php
return [
    '接口名称' => '接口URL|超时秒数',
    // 示例:
    'MyJson1-VIP' => 'https://jx.jsonplayer.com/player/?url=|5',
];
```

- 系统内置 **13 条主线路** + **2 条备用线路**
- 格式：`接口URL|超时时间`（秒）
- 可自由增减，无需修改核心代码

### 平台规则配置 — [config/platform.php](config/platform.php)

```php
return [
    '平台名称' => '域名标识|优先使用的API名',
    // 示例:
    'qq'     => 'v.qq.com|MyJson1-VIP',
    'iqiyi'  => 'iqiyi.com|MyJson2-VIP',
];
```

已支持平台：腾讯视频、爱奇艺、抖音、芒果TV、优酷、PPTV、乐视、搜狐、1905电影、Bilibili、西瓜视频、Acfun 等。

### 系统开关 — [config/switch.php](config/switch.php)

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enable_global_api` | `true` | 启用总接口模式，前 N 条 API 兼容全平台 |
| `global_api_count` | `6` | 作为总接口的 API 数量（前 N 条） |
| `global_api_timeout` | `8` | 总接口请求超时（秒） |
| `enable_zjk_apis` | `true` | 是否加载 `ZJK.txt` 自定义接口 |
| `enable_m3u8_direct` | `true` | M3U8 直链直接输出（不走解析） |
| `enable_unified_display` | `true` | msg 与 url 字段统一显示 |

### 自定义接口 — [ZJK.txt](ZJK.txt)

```
# 每行一个接口，格式：URL|超时秒数
# 注释行以 # 开头
https://jx.example.com/player/?url=|6
https://jx2.example.com/?url=|8
```

---

## 📤 响应格式

### 成功响应（JSON）

```json
{
    "code": 200,
    "msg": "https://player.example.com/video.m3u8",
    "url": "https://player.example.com/video.m3u8",
    "type": "m3u8",
    "MX": "检测到M3U8，直接输出",
    "strategy": "M3U8-Direct-Output",
    "developer": "MX-射手沫蝴蝶",
    "contact": "QQ: 2094332348",
    "timestamp": 1718768000,
    "switch_status": {
        "global_api_enabled": true,
        "global_api_count": 6,
        "zjk_apis_enabled": true,
        "zjk_file_exists": true,
        "unified_display_enabled": true
    }
}
```

### 策略标记说明

| strategy 值 | 说明 |
|-------------|------|
| `XXX-Priority[platform]` | 平台优先级 API 命中 |
| `XXX-Global-Fastest` | 总接口最快响应 |
| `XXX-Fastest` | 普通接口最快响应 |
| `XXX-Sequential` | 并发失败后顺序重试成功 |
| `M3U8-Direct-Output` | M3U8 直链直接输出 |

### 错误响应

```json
{
    "code": 400,
    "msg": "URL参数不能为空",
    "developer": "MX-射手沫蝴蝶",
    "contact": "QQ: 2094332348",
    "timestamp": 1718768000
}
```

---

## 🔒 安全声明

> ⚠️ **本程序仅供学习交流使用，严禁用于商业用途和非法目的。**
>
> 使用者应对其行为承担全部法律责任，开发者不承担任何直接或间接的责任。
>
> 如不同意本声明，请立即停止使用并删除本程序。

---

## 🛠️ 常见问题

### Q1: 所有接口都返回 404？

**A**: 检查以下几点：
1. 服务器是否能访问外部网络（部分主机商禁用出站）
2. PHP `curl` 扩展是否启用：`php -m | grep curl`
3. 目标 API 线路是否仍有效（可手动测试单个接口）
4. `ZJK.txt` 中的自定义接口格式是否正确

### Q2: 响应很慢？

**A**: 可以尝试：
1. 减少 API 线路数量（保留 5-8 条即可）
2. 在 `switch.php` 中调低 `global_api_timeout`
3. 启用缓存功能（修改 `index.php` 接入 `SmartCache`）

### Q3: 如何添加新的视频平台？

**A**: 编辑 `config/platform.php`，新增一行：
```php
'平台标识' => '域名关键字|优先API名称',
```

### Q4: M3U8 链接没有被识别？

**A**: 确认 `switch.php` 中 `enable_m3u8_direct = true`，URL 需满足：
- 路径包含 `.m3u8` 扩展名，或
- 查询参数包含 `m3u8` 关键字，或
- 路径中包含 `m3u8` 关键字

> 💡 **更多问题解答**：以上为精简 FAQ。关于 500 错误 / 404 接口失败 / 权限问题 / 升级版本 / 多份部署等完整解答，请访问
> **[📖 INSTALL.md · 完整常见问题](INSTALL.md#7%EF%B8%8F%E2%83%A8%E5%B8%B8%E8%A7%81%E9%97%AE%E9%A2%98-faq)**。

---

## 📝 更新日志

### v3.0.0 (2024-01-01)

- ✨ 全新并发请求架构，响应速度提升显著
- ✨ 新增三级智能选优算法
- ✨ 支持 M3U8 直链快速通道
- ✨ 支持 `ZJK.txt` 自定义接口动态加载
- ✨ 统一响应格式开关
- 🔧 修复并发请求下的若干稳定性问题

---

## 👤 开发者信息

| 项目 | 内容 |
|------|------|
| **开发者** | MX-射手沫蝴蝶 |
| **联系方式** | QQ: 2094332348 |
| **版本** | v3.0.0 |
| **更新日期** | 2024-01-01 |

---

<div align="center">

**Made with ❤️ by MX-射手沫蝴蝶**

</div>
