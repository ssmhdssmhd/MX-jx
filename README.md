<div align="center">

# 🎬 沫兮万能解析

**PHP 智能线路切换系统 · NoAd 去广告 · 单文件后台 v4.3.0**

> 多线路并发请求 · 智能选优 · M3U8 直链检测 · 全平台视频解析 · 18 功能可视化后台 · M3U8 广告片段智能过滤 · 代理/速率限制反封禁

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Status](https://img.shields.io/badge/Status-✅%20Active-brightgreen.svg)]()
[![安装指南](https://img.shields.io/badge/📖-新手安装指南-ff69b4.svg)](INSTALL.md)
[![快速上手](https://img.shields.io/badge/🚀-5%20%E5%88%86%E9%92%9F%E9%83%A8%E7%BD%B2-orange.svg)](INSTALL.md#3%EF%B8%8F%E2%83%A3%E6%9C%AC%E8%BF%90%E8%A1%8C%E7%A8%8B)

> 💡 **新手看这里**：第一次使用？👉 **[点击查看完整安装指南](INSTALL.md)**（中文，从零开始手把手教学）

</div>

---

## 🏗️ 项目整体架构图

```
                        ┌─────────────────────┐
                        │   👤 外部用户请求    │
                        │  (浏览器/APP/脚本)  │
                        └───────┬─────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐
│  🎯 index.php   │   │  🔐 admin.php   │   │  🔄 noad_proxy.php│
│  (解析接口入口)  │   │  (后台管理入口)  │   │  (代理/清洗入口)  │
└──────┬──────────┘   └──────┬──────────┘   └──────┬──────────┘
       │                      │                      │
       │                      ▼                      │
       │              ┌───────────────────┐         │
       │              │ 🔑 密码校验 +      │         │
       │              │ 🔒 权限/IP/端口校验 │         │
       │              └────────┬──────────┘         │
       │                       │                    │
       ▼                       ▼                    ▼
┌───────────────────────────────────────────────────────────────┐
│                      🏗️  核心层 (core/)                          │
├───────────────────────────────────────────────────────────────┤
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐            │
│  │🧠 Strategy   │ │🌐 Requester  │ │💾 SmartCache │            │
│  │ 智能选优     │ │ 并发请求     │ │ 文件缓存     │            │
│  └──────┬───────┘ └──────┬───────┘ └──────┬───────┘            │
│         │                 │                 │                   │
│         ▼                 ▼                 ▼                   │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐            │
│  │✂️ NoAdParser │ │🗄️ Database   │ │📜 cache 目录  │            │
│  │ 广告过滤     │ │ SQLite 持久   │ │ 自动生成     │            │
│  └──────┬───────┘ └──────┬───────┘ └───────────────┘            │
│         │                │                                       │
│         └────────┬───────┘                                       │
│                  ▼                                               │
│         ┌─────────────────────┐                                 │
│         │ 🎬 M3U8Handler      │                                 │
│         │ (handlers/)         │                                 │
│         │ 直链检测 + 快速响应  │                                 │
│         └────────────┬────────┘                                 │
│                      ▼                                          │
│         ┌───────────────────────────┐                           │
│         │ 🧩 algorithms/ 自定义算法  │                           │
│         │ (AbstractAlgorithm + 注册表) │                           │
│         └────────────┬──────────────┘                           │
│                      ▼                                          │
│         ┌───────────────────────────┐                           │
│         │ 📂 config/ 配置层          │                           │
│         │ (api/platform/switch/     │                           │
│         │  admin/noad)              │                           │
│         └────────────┬──────────────┘                           │
└──────────────────────┼───────────────────────────────────────────┘
                       ▼
         ┌────────────────────────────┐
         │  📄 解析后 JSON 响应        │
         │  /  📼 清洗后的 M3U8        │
         │  /  💾 缓存数据             │
         └────────────────────────────┘
```

## 📖 项目简介

**沫兮万能解析** 是一个基于 PHP cURL 的多线路视频解析系统，采用**并发请求 + 智能选择策略**，自动从多条 API 线路中选择响应最快的有效结果，同时支持 M3U8 直链快速通道。

### ✨ 核心特性

| 功能 | 说明 |
|------|------|
| ⚡ **并发请求** | 同时向所有 API 线路发起请求，优先返回最快响应 |
| 🎯 **智能选优** | 三级选择算法：平台优先级 → 总接口最快 → 全局最快 |
| 📺 **M3U8 直链** | 自动识别 m3u8 链接，无需走解析流程 |
| ✂️ **NoAd 去广告** | 自动识别 M3U8 中的广告片段并移除，可配置解析源 |
| 🧩 **自定义算法** | 可扩展的算法注册表，支持 m3u8/url 多作用域清洗 |
| 🔧 **动态配置** | 通过配置文件增减 API，无需修改代码 |
| 🌐 **全平台适配** | 支持 17+ 视频平台智能匹配 |
| 🎛️ **多开关控制** | 支持自定义接口 / 统一格式 / 总接口 等开关 |
| 💾 **智能缓存** | 内置文件缓存系统，减少重复请求 |
| 🛡️ **代理/速率限制** | 支持多代理轮换 + 请求速率限制，避免 IP 被封禁 |
| 🔐 **可视化后台** | 单文件 `admin.php`，18 个功能标签页，密码登录保护 |

---

## 🚀 快速开始

> 💡 **新手建议**：这里是最简操作步骤。如果看不懂或遇到问题，请阅读
> **[📖 完整新手安装指南](INSTALL.md)**（包含 Windows/macOS/Linux/宝塔面板/虚拟主机
> 全平台图文教程 + 常见问题 FAQ + 配置详解）。

### 环境要求

- **PHP** >= 7.4（兼容 7.4 ~ 8.5，推荐 8.0+）
- **cURL** 扩展（`curl_multi` 支持，核心依赖，必须启用）
- **pdo_sqlite** 扩展（可选，用于 NoAd 数据统计；未启用时核心解析照常工作）
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
# http://你的域名/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421n6n7k.html
```

> 🪟 **Windows 用户** 推荐使用 **phpStudy**（小皮面板），详见 **[INSTALL.md · Windows 教程](INSTALL.md#%EF%B8%8F-windows-%E7%94%A8%E6%88%B7phpstudy-%E6%96%B9%E6%A1%88%E6%8E%A8%E8%8D%90-)**
>
> 🐧 **Linux 服务器** 推荐使用 **宝塔面板**，详见 **[INSTALL.md · 宝塔面板教程](INSTALL.md#%EF%B8%8F-%E6%96%B9%E6%A1%88%E4%B8%80%E5%AE%9D%E5%A1%94%E9%9D%A2%E6%9D%BF%E5%9B%BD%E5%86%85%E6%9C%8D%E5%8A%A1%E5%99%A8%E6%8E%A8%E8%8D%90)**

### 调用方式

```
GET  /index.php?url=<视频播放页地址>
POST /index.php  (body: url=<视频播放页地址>)
```

**示例（复制到浏览器测试）**：

```
https://你的域名/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421n6n7k.html
https://你的域名/index.php?url=https://www.iqiyi.com/v_xxx.html
https://你的域名/index.php?url=https://www.douyin.com/video/xxx
```

> 📖 **更多使用场景**（配合播放器使用 / 作为 API 接入 / 自定义接口管理）见 **[INSTALL.md · 使用方法](INSTALL.md#5%EF%B8%8F%E2%83%A3%E4%BD%BF%E7%94%A8%E6%96%B9%E6%B3%95)**

---

## 📁 项目结构

```
MX-jx/
├── index.php                  # 🎯 主入口文件（解析接口，对外 API）
├── noad_proxy.php             # 🔄 NoAd 代理（M3U8 清洗 / TS 跨域 / 自定义算法 API）
├── admin.php                  # 🔐 后台管理入口（单文件，含登录校验 + 18 个功能 Tab）
├── disclaimer.php             # 📝 免责声明类（自动加载，无需手动管理）
├── ZJK.txt                    # ⚙️ 自定义接口配置（纯文本，每行一条）
├── tests_smoke.php            # 🧪 冒烟测试脚本（快速验证所有功能）
├── README.md                  # 📖 项目说明文档（本文）
├── INSTALL.md                 # 🚀 新手安装指南（从零开始的完整教程）
│
├── config/                    # 📂 配置层（所有可配置项集中在这里）
│   ├── api.php                # API 线路配置（多条主线路 + 备用线路）
│   ├── platform.php           # 平台规则配置（视频平台 → 优先解析接口的映射）
│   ├── switch.php             # 系统开关配置（总接口 / 缓存 / M3U8 直链 / 自定义接口 等）
│   ├── admin.php              # 后台配置（密码哈希 / 端口限制 / IP 白名单 / 会话有效期）
│   └── noad.php               # NoAd 去广告配置（解析源 / 关键词 / 缓存 / 代理 / 算法）
│
├── core/                      # 📂 核心层（核心业务逻辑，通常不需要修改）
│   ├── strategy.php           # 🧠 Strategy - 智能选择策略（平台匹配 + 选优算法）
│   ├── requester.php          # 🌐 Requester - 并发请求引擎（curl_multi 并发 + 结果聚合）
│   ├── cache.php              # 💾 SmartCache - 文件缓存（过期自动清理）
│   ├── Database.php           # 🗄️ SQLite 持久层（解析源 / 规则 / 统计 / 日志）
│   └── NoAdParser.php         # ✂️ M3U8 去广告 + 资源站清理 + 自定义算法入口
│
├── handlers/                  # 📂 处理器层（特殊格式的快速处理）
│   └── M3U8Handler.php        # 🎬 M3U8 直链检测与快速响应
│
├── algorithms/                # 📂 自定义算法扩展层（v4.2 新增，可插拔）
│   ├── AbstractAlgorithm.php  # 算法基类（priority / scope / matchPatterns 统一接口）
│   ├── AlgorithmRegistry.php  # 算法注册表（自动扫描 / 排序 / 按作用域应用）
│   ├── example_01_url_tracker_strip.php  # 示例：URL 跟踪参数清洗
│   ├── example_02_ad_keyword_filter.php  # 示例：广告关键词过滤
│   ├── example_03_domain_rewrite.php     # 示例：广告域名重写
│   ├── example_04_m3u8_segment_cleaner.php # 示例：M3U8 片段级清洗
│   ├── example_05_protocol_normalize.php # 示例：协议 / 路径规范化
│   └── custom_template.php    # 用户自定义算法模板（复制后改写即可）
│
├── html/                      # 📂 前端页面（纯静态，可独立使用）
│   ├── index.html             # 🏠 首页 / 演示页（输入视频链接一键解析播放）
│   ├── player.html            # ▶️ M3U8 播放器（基于 HLS.js，点播 + 直播）
│   ├── api-test.html          # 🧪 API 调试页（GET/POST 测试 + JSON 响应可视化）
│   └── m3u8-test.html         # 📺 M3U8 测试页（内置公开测试流）
│
├── docs/                      # 📂 文档目录（开发 / 运维参考）
│   ├── 配置说明.md            # 配置文件详细说明（各字段含义 + 取值范围）
│   ├── 核心说明.md            # 核心模块设计说明（架构 + 流程 + 扩展点）
│   └── M3U8处理器规则.md      # M3U8 处理器规则说明（匹配条件 + 响应格式）
│
├── cache/                     # 📂 缓存目录（运行时自动生成，默认不提交）
│   └── .gitkeep               # 空目录占位符
│
└── backup/                    # 📂 后台「一键备份」输出目录（默认不提交）
    └── .gitkeep               # 空目录占位符
```

### 📊 项目文件统计（v4.3.0）

| 类型 | 数量 | 说明 |
|------|------|------|
| **入口文件** | 3 个 | `index.php` / `admin.php` / `noad_proxy.php` |
| **后台文件** | 1 个 | `admin.php`（单文件整合，CSS + 模板 + 逻辑全部内嵌） |
| **配置文件** | 5 个 | `config/` 目录下 5 个 PHP 配置文件 |
| **核心模块** | 5 个 | `core/` 目录下 5 个核心类 |
| **处理器** | 1 个 | `handlers/M3U8Handler.php` |
| **自定义算法** | 7 个 | `algorithms/` 目录下（5 个示例 + 1 个基类 + 1 个注册表 + 1 个模板） |
| **前端页面** | 4 个 | `html/` 目录下 4 个纯静态 HTML |
| **文档** | 4 个 | README / INSTALL / docs/ 3 个说明 |
| **总文件数** | ~30 个 | |

### 核心类 / 文件说明

| 文件 | 类型 | 职责 |
|------|------|------|
| `Strategy` | [core/strategy.php](core/strategy.php) | 策略决策引擎：平台匹配、API 聚合、响应选优 |
| `Requester` | [core/requester.php](core/requester.php) | 请求执行引擎：`curl_multi` 并发请求、结果解析、格式统一 |
| `SmartCache` | [core/cache.php](core/cache.php) | 缓存管理器：文件系统缓存、过期自动清理 |
| `M3U8Handler` | [handlers/M3U8Handler.php](handlers/M3U8Handler.php) | M3U8 处理器：直链检测、快速响应 |
| `NoAdParser` | [core/NoAdParser.php](core/NoAdParser.php) | M3U8 广告片段识别过滤 / 多源并发匹配 / 资源站清理 / 自定义算法入口 |
| `Database` | [core/Database.php](core/Database.php) | SQLite 轻量持久层：解析源 / 广告规则 / 访问统计 / 日志 |

### 后台管理（单文件 admin.php，18 个功能 Tab）

| Tab | 功能说明 |
|-----|----------|
| 📊 **仪表盘** | PHP 版本 / cURL 状态 / API 数量 / 环境检查 / 操作日志 |
| 📈 **NoAd 数据统计** | 近 7 天请求柱图 / 广告片段移除数 / 缓存命中率 / 访问日志 |
| 🔌 **去广告解析源** | 可视化添加 / 启用 / 禁用 / 编辑 NoAd 解析接口 |
| 🚫 **广告规则库** | 管理自定义广告关键词（优先级 + 启用状态 + 命中次数） |
| ⚙️ **NoAd 设置** | 缓存有效期 / 超时 / 并发上限 / 关键词阈值 / 代理池 / 速率限制 |
| 📺 **M3U8 解析** | 输入远程 M3U8 URL，自动解析片段、标记广告、对比清洗前后 |
| 🎬 **资源站点** | 管理 dytt / 西瓜 / 如意 / 爱奇艺 / 腾讯视频 / 优酷 / 芒果 TV 等规则 |
| 📄 **解析日志** | 记录每次手动 M3U8 解析的时间 / 站点 / 片段数，便于排错 |
| 💽 **缓存管理** | 查看缓存目录 / 设置有效期 / 一键清理 |
| 📡 **API 线路** | 管理所有解析接口线路（添加 / 删除 / 测试） |
| 🎯 **平台规则** | 视频平台 → 优先接口映射规则 |
| 🎛️ **系统开关** | 总接口模式 / 缓存 / M3U8 直链 / 自定义接口 等开关 |
| 📝 **自定义接口** | `ZJK.txt` 接口文件在线编辑 |
| 🧪 **接口测试** | 在线并发测试所有接口，展示响应时间和有效性 |
| 🧩 **自定义算法** | 算法列表 / 启用禁用 / 重新扫描 / 本地测试 / 开发模板 |
| 💾 **备份日志** | 一键备份所有配置文件，生成时间戳压缩包 |
| 🔐 **修改密码** | 管理员密码修改（MD5 加密存储） |
| ⚙️ **后台设置** | 端口限制 / IP 白名单 / 会话有效期 / 文件权限等安全配置 |

### 🔒 后台安全特性

- **密码加密存储**：管理员密码以 MD5 哈希形式保存在配置文件中
- **失败锁定**：连续登录失败超过 5 次自动锁定 300 秒
- **端口限制**：可配置仅允许特定端口访问后台
- **IP 白名单**：可配置允许访问后台的 IP 列表
- **会话超时**：登录会话自动过期（默认 2 小时）
- **操作日志**：记录管理员每一次修改操作

### 🛡️ 代理与反封禁（v4.3 新增）

在 `config/noad.php` 中可配置：

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `enable_proxy` | `false` | 是否启用 HTTP 代理池 |
| `proxies` | `[]` | 代理列表，支持 `ip:port` 格式，请求时自动轮换 |
| `rate_limit_min_interval_ms` | `350` | 同一域名请求最小间隔（毫秒） |
| `rate_limit_jitter_ms` | `150` | 随机抖动范围（毫秒），避免固定频率被识别 |
| `rate_limit_max_concurrent` | `3` | 同一域名最大并发请求数 |
| `user_agents` | `[...]` | 随机 User-Agent 池，每次请求随机选择 |

> 💡 如果你的解析接口对 IP 有频率限制或容易封禁，启用代理池 + 设置合理的速率限制可以有效降低被拦截的概率。

---

### 请求处理流程

```
                     HTTP 请求
                        │
                        ▼
          ┌──────────────────────────┐
          │   index.php  参数校验      │
          │   (URL 格式 + 非空校验 +   │
          │    SSRF 协议校验)          │
          └─────────────┬──────────────┘
                        │
              ┌─────────┴────────────┐
              ▼                      ▼
      是 M3U8 链接？            否 → 进入解析流程
              │                      │
              ▼                      ▼
     直接返回 JSON        ┌─────────────────────────┐
                          │ 模式判断 (mode 参数)       │
                          │  auto / noad / legacy     │
                          └──────────┬────────────────┘
                                     │
                    ┌────────────────┴───────────────┐
                    ▼                                ▼
        ┌───────────────────────┐        ┌───────────────────────┐
        │ NoAd 去广告模式         │        │ 旧版并发解析模式        │
        │ (noad + 自定义算法)     │        │ (Strategy + Requester)│
        └──────────┬─────────────┘        └──────────┬─────────────┘
                   ▼                                ▼
        ┌─────────────────────────────────────────────────────────┐
        │                    Strategy 决策引擎                      │
        │  · 匹配平台规则 · 聚合所有 API · 选最优线路                │
        └───────────────────────────┬─────────────────────────────┘
                                    ▼
                    ┌───────────────────────────┐
                    │   Requester 请求引擎       │
                    │   · curl_multi 并发请求    │
                    │   · 收集有效响应           │
                    │   · 代理池轮换             │
                    │   · 速率限制 + UA 随机     │
                    │   · Strategy 选最优        │
                    │   · 统一响应格式           │
                    └─────────────┬─────────────┘
                                  ▼
                          返回 JSON 响应
```

### 智能选优算法

```
                     所有并发响应
                          │
          ┌───────────────┴────────────────┐
          ▼                                ▼
   匹配平台规则？                      未匹配平台
     ├─► 返回 Priority API 响应         │
     └─► 失败则继续                      │
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

- 系统内置 **多条主线路** + **备用线路**
- 格式：`接口URL|超时时间`（秒）
- 可自由增减，无需修改核心代码
- 可通过后台可视化编辑

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
| `enable_global_api` | `true` | 启用"总接口"模式，前 N 条 API 兼容全平台 |
| `global_api_count` | `6` | 作为总接口的 API 数量（前 N 条） |
| `global_api_timeout` | `8` | 总接口请求超时（秒） |
| `enable_zjk_apis` | `true` | 是否加载 `ZJK.txt` 自定义接口 |
| `enable_m3u8_direct` | `true` | M3U8 直链直接输出（不走解析） |
| `enable_unified_display` | `true` | msg 与 url 字段统一显示 |

### NoAd 去广告配置 — [config/noad.php](config/noad.php)

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `noad_enabled` | `true` | 是否启用 NoAd 去广告系统 |
| `ad_keywords` | `[...]` | 广告关键词列表（中英文均可） |
| `cache_ttl_seconds` | `1800` | 缓存有效期（秒，默认 30 分钟） |
| `request_timeout` | `10` | 请求超时时间（秒） |
| `max_concurrent_sources` | `3` | 最大并发解析源数 |
| `enable_proxy` | `false` | 是否启用 HTTP 代理池 |
| `proxies` | `[]` | 代理列表（ip:port 格式） |
| `rate_limit_min_interval_ms` | `350` | 同一域名最小请求间隔 |
| `user_agents` | `[...]` | User-Agent 随机池 |
| `enable_custom_algorithms` | `true` | 是否启用自定义算法扩展 |
| `custom_algorithms_scope` | `'m3u8'` | 自定义算法作用域（m3u8/url/all） |

### 自定义接口 — [ZJK.txt](ZJK.txt)

```
# 每行一个接口，格式: URL|超时秒数
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

**A**：检查以下几点：
1. 服务器是否能访问外部网络（部分主机商禁用出站）
2. PHP `curl` 扩展是否启用：`php -m | grep curl`
3. 目标 API 线路是否仍有效（可手动测试单个接口）
4. `ZJK.txt` 中的自定义接口格式是否正确

### Q2: 响应很慢？

**A**：可以尝试：
1. 减少 API 线路数量（保留 5-8 条即可）
2. 在 `switch.php` 中调低 `global_api_timeout`
3. 启用缓存功能（修改 `index.php` 接入 `SmartCache`）
4. 启用代理池 + 合理设置速率限制，避免被接口限流

### Q3: 如何添加新的视频平台？

**A**：编辑 `config/platform.php`，新增一行：
```php
'平台标识' => '域名关键字|优先API名称',
```

### Q4: M3U8 链接没有被识别？

**A**：确认 `switch.php` 中 `enable_m3u8_direct = true`，URL 需满足：
- 路径包含 `.m3u8` 扩展名，或
- 查询参数包含 `m3u8` 关键字，或
- 路径中包含 `m3u8` 关键字

### Q5: 接口频繁被封禁？

**A**：在 `config/noad.php` 中启用代理池 + 设置合理的速率限制：
1. 设置 `enable_proxy = true`
2. 在 `proxies` 数组中填入可用代理（`ip:port`）
3. 设置 `rate_limit_min_interval_ms` 为 300-500 毫秒
4. 设置 `rate_limit_max_concurrent` 为 2-3

> 💡 **更多问题解答**：以上为精简 FAQ。关于 500 错误 / 404 接口失败 / 权限问题 / 升级版本 / 多份部署等完整解答，请访问
> **[📖 INSTALL.md · 完整常见问题](INSTALL.md#7%EF%B8%8F%E2%83%A3%E5%B8%B8%E8%A7%81%E9%97%AE%E9%A2%98-faq)**。

---

## 📝 更新日志

### v4.3.0 (2026-06-21) — 单文件后台整合 + 代理/速率限制反封禁

- ✨ **后台文件整合（3 → 1）**：将原先分散的 `admin.php`（入口逻辑）、`admin_tpl.php`（模板）、`admin_main.php`（旧模板）、`admin_style.css`（样式）**整合为单个 `admin.php`**
  - CSS 样式代码内联渲染，无需额外请求样式文件
  - 18 个功能 Tab 的 HTML 模板通过 PHP 函数动态生成
  - 登录校验、权限控制、表单处理、AJAX 响应全部统一管理
  - 部署更简单（只需上传一个文件），版本升级更方便
- ✨ **代理与反封禁**：新增 `config/noad.php` 中的代理池轮换配置、请求速率限制（最小间隔 + 随机抖动）、最大并发数控制、随机 User-Agent 池
- ✨ **admin.php 语法检查通过**：`php -l admin.php` 零错误
- ✨ **冒烟测试通过**：`tests_smoke.php` 16/16 全部通过（入口响应 / AJAX 接口 / 算法扫描 / M3U8 解析）
- 📖 **README 与项目结构说明** 更新到 v4.3.0，新增整体架构图

### v4.2.0 (2026-06-21) — 自定义算法扩展 + 项目结构整理

- ✨ **`algorithms/` 目录体系**：新增 `AbstractAlgorithm` 基类 + `AlgorithmRegistry` 注册表，所有算法自动扫描加载，按优先级排序，按作用域应用
- ✨ **5 个内置示例算法**：URL 跟踪参数清洗、广告关键词过滤、域名重写、M3U8 片段级清洗、协议规范化
- ✨ **用户自定义算法模板**：`algorithms/custom_template.php` 可复制、改名，快速编写专属过滤规则
- ✨ **`noad_proxy.php` 新增 `mode=algorithms`**：提供 `list` / `toggle` / `reload` / `test` 四个 JSON API，便于后台管理或被第三方系统调用
- ✨ **后台新增「🧩 自定义算法」Tab**：列表展示算法信息 / 一键启用禁用 / 重新扫描目录 / 本地测试算法链 / 附开发示例代码
- ✨ **`NoAdParser::listCustomAlgorithms()` / `setCustomAlgorithmEnabled()` / `applyCustomAlgorithms()` / `reloadCustomAlgorithms()`**：供 PHP 内部或 AJAX 直接调用
- ⚙️ **`config/noad.php` 新增 `enable_custom_algorithms` + `custom_algorithms_scope`**
- 🧹 **项目结构整理**：剔除历史版本 `.zip`、旧版备份目录、临时 `test_playlist.m3u8`、`cache/*.db` 等大体积/运行时文件；完善 `.gitignore`，仅保留 `backup/.gitkeep` 与 `cache/.gitkeep`

### v4.1.0 (2026-06-20) — M3U8 解析页 + 资源站去广告

- ✨ **M3U8 解析页（双栏对比）**：输入远程 M3U8 URL，左侧展示原始内容，右侧展示清洗后的内容，包含时间戳 / 片段时长 / 广告标记信息
- ✨ **资源站去广告**：针对 dytt（电影天堂）、西瓜、如意、爱奇艺、腾讯视频、优酷、芒果 TV 等常见资源站域名，内置专门清理算法
- ✨ **`NoAdParser::cleanByResourceSite()`**：根据 URL 自动识别资源站，应用对应算法
- ✨ **后台新增 Tab**：M3U8 解析 / 资源站点 / 解析日志 / 缓存管理
- ✨ **`admin.php` 新增 AJAX 接口**：`ajax_parse_m3u8` / `ajax_get_sites` / `ajax_list_algorithms`
- 🧪 **`html/m3u8-test.html` / `player.html` / `api-test.html`**：内置公开测试流

### v4.0.0 (2026-06-19) — NoAd 去广告解析系统

- ✨ **M3U8 广告片段智能过滤**：自动识别 `#EXTINF` 片段中的广告关键词
- ✨ **7 种资源类型分类管理**：电影 / 电视剧 / 综艺 / 动漫 / 纪录片 / 体育 / 短视频
- ✨ **多源自动匹配**：并发请求多个解析源，自动选择最快有效响应
- ✨ **SQLite 轻量数据统计**：自动记录每次解析请求（时间 / IP / 来源 / 耗时 / 移除广告片段数）
- ✨ **缓存加速**：30 分钟内相同 URL 请求直接返回缓存结果
- ✨ **代理入口 `noad_proxy.php`**：支持 `mode=m3u8`（代理并清洗远程 M3U8）、`mode=ts`（TS 片段跨域代理）、`mode=api`（直接 JSON 响应）
- ✨ **全新后台管理可视化**：从 v3.1 升级到 12+ 个 Tab

---

## 👤 开发者信息

| 项目 | 内容 |
|------|------|
| **开发者** | MX-射手沫蝴蝶 |
| **联系方式** | QQ: 2094332348 |
| **当前版本** | v4.3.0（单文件后台 + NoAd 去广告 + 自定义算法 + 代理/速率限制） |
| **更新日期** | 2026-06-21 |

---

<div align="center">

**Made with ❤️ by MX-射手沫蝴蝶**

</div>
