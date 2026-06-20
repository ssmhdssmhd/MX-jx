<div align="center">

# 🎬 沫兮万能解析

**PHP 智能线路切换系统 · NoAd 去广告 v4.0.0**

> 多线路并发请求 · 智能选优 · M3U8 直链检测 · 全平台视频解析 · 可视化后台管理 · M3U8 广告片段智能过滤

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
├── index.php                  # 🎯 主入口文件（解析接口）
├── admin.php                  # 🔐 后台管理入口（密码登录）
├── admin_main.php             # 📊 后台页面模板（9 个功能页）
├── admin_style.css            # 🎨 后台页面样式
├── README.md                  # 📖 项目说明文档
├── INSTALL.md                 # 🚀 新手安装指南（推荐阅读）
├── disclaimer.php             # 📝 免责声明类
├── ZJK.txt                    # ⚙️ 自定义接口配置
│
├── config/                    # 📂 配置层
│   ├── api.php                # API 线路配置（13主 + 2备）
│   ├── platform.php           # 平台规则配置（17个视频平台）
│   ├── switch.php             # 系统开关配置
│   └── admin.php              # 后台配置（密码/端口/权限等）
│
├── core/                      # 📂 核心层
│   ├── strategy.php           # 🧠 Strategy - 智能选择策略
│   ├── requester.php          # 🌐 Requester - 并发请求引擎
│   └── cache.php              # 💾 SmartCache - 文件缓存
│
├── handlers/                  # 📂 处理器层
│   └── M3U8Handler.php        # 🎬 M3U8 直链检测与响应
│
├── html/                      # 📂 前端页面（v4.1.0 新增）
│   ├── index.html             # 🏠 首页 / 演示页（输入视频链接进行解析）
│   ├── player.html            # ▶️ M3U8 播放器（HLS.js 播放解析后的视频）
│   ├── api-test.html          # 🧪 API 调试页（查看原始响应 / JSON 字段）
│   └── m3u8-test.html         # 📺 M3U8 测试页（内置公开测试流）
│
├── docs/                      # 📂 文档目录（v4.1.0 新增）
│   ├── 配置说明.md            # 配置文件详细说明
│   ├── 核心说明.md            # 核心模块设计说明
│   └── M3U8处理器规则.md      # M3U8 处理器规则说明
│
├── cache/                     # 📂 缓存目录（运行时自动生成）
│   └── .gitkeep               # 空目录占位
│
└── backup/                    # 📂 历史备份（归档压缩包）
    └── *.zip                  # 各版本代码备份
```

### 📊 项目文件统计（v4.1.0）

| 类型 | 数量 | 说明 |
|------|------|------|
| **PHP 文件** | 10 个 | 入口 2 个 + 配置 4 个 + 核心 3 个 + 处理器 1 个 |
| **HTML 页面** | 4 个 | 首页 / 播放器 / API调试 / M3U8测试 |
| **CSS 文件** | 1 个 | 后台管理页面样式 |
| **文本文档** | 6 个 | README、INSTALL、ZJK.txt、docs/ 3 个说明 |
| **总文件数** | ~21 个 | |

### 核心类/文件说明

| 文件 | 类型 | 职责 |
|------|------|------|
| `Strategy` | [core/strategy.php](core/strategy.php) | 策略决策引擎：平台匹配、API 聚合、响应选优 |
| `Requester` | [core/requester.php](core/requester.php) | 请求执行引擎：`curl_multi` 并发请求、结果解析、格式统一 |
| `SmartCache` | [core/cache.php](core/cache.php) | 缓存管理器：文件系统缓存、过期自动清理 |
| `M3U8Handler` | [handlers/M3U8Handler.php](handlers/M3U8Handler.php) | M3U8 处理器：直链检测、快速响应 |

### 后台管理文件（v3.1.0 新增）

| 文件 | 类型 | 职责 |
|------|------|------|
| `admin.php` | [admin.php](admin.php) | 后台入口：密码登录验证 / 权限控制 / 操作分发 |
| `admin_tpl.php` | [admin_tpl.php](admin_tpl.php) | 后台页面模板：12 个功能 Tab（含 NoAd 数据统计 / 解析源 / 广告规则） |
| `admin_style.css` | [admin_style.css](admin_style.css) | 后台样式：渐变配色 / 开关组件 / 响应式布局 |
| `admin 配置` | [config/admin.php](config/admin.php) | 后台配置：密码哈希 / 端口限制 / IP 白名单 / 会话时长 |
| `noad_proxy.php` | [noad_proxy.php](noad_proxy.php) | NoAd 代理入口：M3U8 清洗代理 / TS 跨域代理 / JSON API |

### NoAd 去广告核心（v4.0.0 新增）

| 文件 | 类型 | 职责 |
|------|------|------|
| `NoAdParser` | [core/NoAdParser.php](core/NoAdParser.php) | M3U8 广告片段识别过滤 / 多源并发匹配 / 缓存加速 |
| `Database` | [core/Database.php](core/Database.php) | SQLite 轻量持久层：解析源 / 广告规则 / 访问统计 / 日志 |
| `NoAd 配置` | [config/noad.php](config/noad.php) | 去广告系统参数：阈值 / 超时 / 资源类型 / 默认规则 |

### 前端页面（v4.1.0 新增）

| 文件 | 类型 | 作用 |
|------|------|------|
| `index.html` | [html/index.html](html/index.html) | 🏠 首页 / 演示页：输入视频链接，调用解析接口，一键播放 |
| `player.html` | [html/player.html](html/player.html) | ▶️ M3U8 播放器：基于 HLS.js，支持点播与直播 |
| `api-test.html` | [html/api-test.html](html/api-test.html) | 🧪 API 调试页：GET/POST 请求，JSON 响应可视化 |
| `m3u8-test.html` | [html/m3u8-test.html](html/m3u8-test.html) | 📺 M3U8 测试页：内置 Apple 公开测试流，快速验证播放 |

---

## 🔧 后台管理系统（v3.1.0 新增）

从 v3.1.0 版本开始，项目支持可视化后台管理，无需手动编辑配置文件。

### 📂 后台文件

| 文件 | 作用 |
|------|------|
| [admin.php](admin.php) | 后台入口文件（默认登录地址） |
| [admin_main.php](admin_main.php) | 后台页面模板：仪表盘/API管理/平台规则等 9 个功能页 |
| [admin_style.css](admin_style.css) | 后台页面样式（渐变配色、响应式布局） |
| [config/admin.php](config/admin.php) | 后台核心配置：密码、路径、端口、安全策略等 |

### 🚀 快速开始

```
默认地址: http://你的域名/admin.php
默认密码: admin123
```

**首次登录推荐操作：**

1. 🔐 进入「修改密码」页面，修改默认密码
2. ⚙️ 进入「后台设置」修改访问路径（如改为 `dashboard.php`）
3. 🔌 进入「API 线路」确认或添加解析接口
4. 🧪 进入「接口测试」验证所有接口连通性
5. 📦 进入「备份日志」创建首次配置备份

### 🎯 后台功能一览

| 模块 | 功能说明 |
|------|----------|
| 📊 **仪表盘** | PHP版本/cURL状态/API数量/文件权限 快速检查 |
| 🔌 **API 线路** | 增删改查所有解析接口，可视化编辑无需改代码 |
| 📺 **平台规则** | 为特定视频平台指定优先的解析接口 |
| 🎛️ **系统开关** | 总接口模式/超时时间/M3U8直链检测 等开关 |
| 📝 **自定义接口** | 在线编辑 ZJK.txt 文件 |
| 🧪 **接口测试** | 并发测试所有接口对指定视频链接的解析效果 |
| 💾 **备份日志** | 一键备份所有配置 + 查看管理员操作日志 |
| 🔐 **修改密码** | 修改管理员登录密码（MD5 加密存储） |
| ⚙️ **后台设置** | 访问路径修改、端口限制、IP白名单、会话时长等高级配置 |

### 🔒 安全特性

- **密码加密存储**: 管理员密码以 MD5 哈希形式保存在配置文件中
- **失败锁定**: 连续登录失败超过 5 次自动锁定 300 秒
- **端口限制**: 可配置仅允许特定端口（如 8899）访问后台
- **IP 白名单**: 可配置允许访问后台的 IP 列表
- **会话超时**: 登录会话自动过期（默认 2 小时）
- **操作日志**: 记录管理员每一次修改操作

### 💡 端口 8899 配置指南

如果希望通过 `http://你的域名:8899/admin.php` 访问后台：

**宝塔面板：**
1. 进入「网站」→ 点击对应网站「设置」
2. 打开「配置文件」，在 `server` 块内添加一行:
   ```
   listen 8899;
   ```
3. 保存，重载 Nginx 配置
4. 确认服务器防火墙/安全组开放 8899 端口
5. 在后台「设置」中开启「强制端口限制」并添加 8899 到白名单

**Nginx 原生：** 在网站的 server{} 配置块中增加 `listen 8899;`

---

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

### v4.0.0 (2026-06-19) — 重大版本：NoAd 去广告解析系统

- ✨ **M3U8 广告片段智能过滤**：自动识别 `#EXTINF` 片段中的广告关键词（中文「片头广告 / 片中广告 / 片尾广告 / 推广」+ 英文 `ad/advert/promo/tracker` 等），自动从播放列表中剔除
- ✨ **7 种资源类型分类管理**：电影 / 电视剧 / 综艺 / 动漫 / 纪录片 / 体育 / 短视频，可独立配置解析源
- ✨ **多源自动匹配**：并发请求多个解析源，自动选择最快有效响应；可按 URL 关键词为特定平台分配专用解析源
- ✨ **SQLite 轻量数据统计**：自动记录每次解析请求（时间 / IP / 来源 / 耗时 / 移除广告片段数），后台面板呈现近 7 天柱状图、Top 10 解析源、实时访问日志
- ✨ **缓存加速**：30 分钟内相同 URL 请求直接返回缓存结果，显著降低延迟和外部请求量
- ✨ **代理入口 `noad_proxy.php`**：支持 `mode=m3u8`（代理并清洗远程 M3U8）、`mode=ts`（TS 片段跨域代理）、`mode=api`（直接 JSON 响应）
- ✨ **后台可视化规则管理**：广告规则库可动态添加 / 启用 / 删除，独立于默认内置规则
- ✨ **全新后台 `admin_tpl.php`**：从 v3.1 升级到 12 个 Tab（总览 / NoAd 数据统计 / 去广告解析源 / 广告规则库 / NoAd 设置 / API 线路 / 平台规则 / 系统开关 / 自定义接口 / 备份日志 / 修改密码 / 后台设置）
- ✨ **全兼容 PHP 7.4 ~ 8.x**，零依赖（仅需 pdo_sqlite 扩展即可启用统计，无 sqlite 时核心解析照常运行）

### v3.1.0 (2026-06-19)

- ✨ 新增可视化后台管理系统（admin.php / admin_main.php / admin_style.css）
- ✨ 后台支持 9 个功能模块：仪表盘、API线路、平台规则、系统开关、自定义接口、接口测试、备份日志、修改密码、后台设置
- ✨ 管理员密码登录（MD5加密存储）+ 失败次数锁定（防暴力破解）
- ✨ 支持自定义后台访问路径（可改为任意文件名，如 dashboard.php）
- ✨ 支持端口访问限制（如 8899 端口）+ IP 白名单
- ✨ 支持会话自动过期、操作日志记录、配置一键备份
- ✨ 支持在线并发测试所有接口，展示响应时间和有效性
- ✨ 所有配置文件均可在后台可视化编辑，无需手动写代码
- 📚 新增 INSTALL.md 新手安装指南（全平台图文教程）
- 📱 后台页面响应式设计，支持手机端访问

### v3.0.1 (2026-06-19) — 稳定性修复

- 🔧 修复 core/cache.php 中 `file_exists()` 缺少右括号导致的语法错误
- 🔧 修复 SmartCache 读写 unserialize 缓存损坏时的报错问题
- 🔧 修复 Strategy::getPriorityApi 平台配置缺少分隔符时的空指针问题
- 🔧 修复 Strategy 默认开关值与配置文件不一致的问题
- 🔒 在 index.php 中增加 SSRF 防护，限制仅 http/https 协议
- 🔒 生产环境关闭 display_errors，启用日志记录
- ✅ 全部 PHP 文件语法检查通过，运行时逻辑测试通过

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
| **当前版本** | v4.0.0（含 NoAd 去广告系统） |
| **更新日期** | 2026-06-19 |

---

<div align="center">

**Made with ❤️ by MX-射手沫蝴蝶**

</div>
