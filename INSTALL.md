<div align="center">

# 🚀 沫兮万能解析 — 新手安装指南 v3.1.0

> 从零开始，一步步带你把这个解析系统跑起来！
>
> **阅读时间约 10 分钟** · **最快 5 分钟完成部署** · **含可视化后台管理系统**

[![查看 README](https://img.shields.io/badge/📖-README_项目说明-blue.svg)](README.md)
[![PHP](https://img.shields.io/badge/PHP-7.4%20~%208.x-777BB4.svg)](https://www.php.net/)
[![cURL](https://img.shields.io/badge/cURL-必须启用-orange.svg)]()

</div>

---

## 📖 目录

- [**第 0 章**：这是什么程序？](#0-这是什么程序)
- [**第 1 章**：环境要求](#1-环境要求)
- [**第 2 章**：获取代码（3 种方式任选一种）](#2-获取代码)
- [**第 3 章**：本地运行（Windows / macOS）](#3-本地运行)
- [**第 4 章**：部署到服务器（宝塔 / cPanel / 虚拟主机）](#4-部署到服务器)
- [**第 5 章**：使用方法](#5-使用方法)
- [**第 6 章**：配置详解](#6-配置详解)
- [**第 7 章**：常见问题 FAQ](#7-常见问题-faq)
- [**第 8 章**：快速参考速查表](#8-快速参考速查表)

---

## 0️⃣ 这是什么程序？

**沫兮万能解析** 是一个**视频线路智能切换系统**，它的作用很简单：

> 你给它一个**视频播放页链接**（如腾讯视频、爱奇艺的链接），它会**同时请求多个备用解析接口**，从中挑选**响应最快且能成功播放**的那条线路返回给你。

```
  你 ───► ?url=https://v.qq.com/x/cover/xxx.html
                     │
          ┌──────────┼──────────┐
          ▼          ▼          ▼
       接口A       接口B       接口C
          │          │          │
          └──────────┼──────────┘
                     ▼
              ⚡ 选最快的一条返回
```

### 它能做什么

- ✅ 解析各大视频平台的播放链接
- ✅ 自动切换备用线路，提高解析成功率
- ✅ 支持 M3U8 直链快速通道
- ✅ 自定义解析接口（通过 `ZJK.txt`）

### 它不是什么

- ❌ 不是视频存储服务（不保存任何视频文件）
- ❌ 不提供视频内容（仅转发公开的解析接口结果）
- ❌ 不是播放器（需要配合支持 M3U8 的播放器使用）

---

## 1️⃣ 环境要求

### ✅ 最低要求

| 组件 | 要求版本 | 说明 |
|------|----------|------|
| **PHP** | 7.4 或更高 | 推荐 8.0+，本项目实测 7.4 / 8.1 / 8.2 正常 |
| **cURL 扩展** | 必须启用 | 用于并发请求接口，核心依赖 |
| **allow_url_fopen** | `On` | PHP 配置项，用于读取远程文件（部分功能需要） |
| **Web 服务器** | Apache / Nginx / IIS 任意一种 | 只要能跑 PHP 就行 |

### ⚙️ 各平台推荐环境

| 你的平台 | 推荐方案 | 安装难度 |
|----------|----------|----------|
| Windows | **phpStudy** 或 **XAMPP** | ⭐ 最简单 |
| macOS | **MAMP** 或 自带 Apache | ⭐⭐ 简单 |
| Linux 服务器 | **宝塔面板**（BT） | ⭐⭐ 简单 |
| 虚拟主机 | 只要支持 PHP + cURL 即可 | ⭐ 最简单 |

---

## 2️⃣ 获取代码

### 📦 方式一：直接下载 ZIP（新手推荐 ⭐）

1. 打开仓库主页：https://github.com/ssmhdssmhd/MX-jx
2. 点击绿色 **<> Code** 按钮
3. 选择 **Download ZIP**
4. 解压到任意文件夹

```
下载完成的文件: MX-jx-main.zip
解压后目录:  MX-jx-main/
```

### 🌿 方式二：Git 克隆（推荐，方便更新）

```bash
# 在命令行/PowerShell 中执行
git clone https://github.com/ssmhdssmhd/MX-jx.git
```

> 更新时只需进入目录执行 `git pull` 即可

### 📥 方式三：SSH 克隆

```bash
git clone git@github.com:ssmhdssmhd/MX-jx.git
```

### 📂 你会得到的文件（共 14 个文件）

```
MX-jx/
├── index.php              ← 🎯 主入口文件（解析接口，浏览器访问这个）
├── admin.php              ← 🔐 后台管理入口（默认密码: admin123）
├── admin_main.php         ← 📊 后台页面模板（9个功能模块）
├── admin_style.css        ← 🎨 后台样式文件
├── disclaimer.php         ← 📝 免责声明类（自动加载，无需管）
├── ZJK.txt               ← ⚙️ 自定义接口（可选）
├── README.md             ← 📖 项目说明文档
├── INSTALL.md            ← 🚀 本文件（新手安装指南）
│
├── config/               ← 📂 配置文件文件夹
│   ├── api.php           │   API 解析接口列表
│   ├── platform.php      │   视频平台规则
│   ├── switch.php        │   系统开关配置
│   └── admin.php         │   后台配置（密码/端口/权限等）
│
├── core/                 ← 📂 核心程序文件夹
│   ├── strategy.php      │   智能选优策略引擎
│   ├── requester.php     │   并发请求引擎
│   └── cache.php         │   文件缓存模块
│
└── handlers/             ← 📂 处理器文件夹
    └── M3U8Handler.php   │   M3U8 直链处理
```

> 💡 **作为新手你只需要关心三个入口**：
> - 🔗 [index.php](index.php) — 解析接口（对外使用）
> - 🔐 [admin.php](admin.php) — 后台管理（内部使用，默认密码 admin123）
> - ⚙️ [config/api.php](config/api.php) + [config/switch.php](config/switch.php) — 配置文件（可在后台中可视化修改）

---

## 3️⃣ 本地运行

> 这一步让你**先在自己的电脑上测试**，确认没问题再部署到服务器。

### 🪟 Windows 用户（phpStudy 方案，推荐 ✅）

#### 第一步：下载安装 phpStudy

网址：https://www.xp.cn/download.html

- 下载 "phpStudy 小皮面板"（免费版本即可）
- 一路 **下一步** 安装完成

#### 第二步：启动环境

1. 打开 phpStudy 面板
2. 点击左侧 **软件管理** → 安装 **PHP 8.1**（或任意 7.4+ 版本）
3. 点击左侧 **网站** → 点击 **创建网站**
4. 填写：
   - **域名**：`localhost`（或随便写，如 `mx.test`）
   - **根目录**：选择你解压代码的文件夹
   - **PHP 版本**：选择 8.1 或 7.4+
   - **FTP**：不创建
   - **数据库**：不需要
5. 点击 **创建**

#### 第三步：确认 cURL 已启用

1. 在网站根目录新建文件 `phpinfo.php`，写入：
   ```php
   <?php phpinfo(); ?>
   ```
2. 浏览器访问 `http://localhost/phpinfo.php`
3. 按 `Ctrl + F` 搜索 **curl**
4. ✅ 如果看到 **cURL support → enabled** 就可以了
5. ❌ 如果没启用：phpStudy → 软件管理 → 找到对应 PHP 版本 → 设置 → 配置文件 → 搜索 `;extension=curl` → 去掉前面的分号 `;` → 保存重启

#### 第四步：测试访问

浏览器打开：`http://localhost/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html`

如果看到返回一段 JSON 数据，说明部署成功！🎉

#### 第五步：清理测试文件

删除 `phpinfo.php`（安全起见，不要在生产环境保留）。

---

### 🍎 macOS 用户（MAMP 方案）

#### 第一步：下载 MAMP

网址：https://www.mamp.info/en/downloads/

下载免费的 **MAMP**（不是 MAMP Pro），安装。

#### 第二步：配置

1. 打开 MAMP
2. 点击 **Preferences...** → **PHP** → 选择 7.4+ 版本
3. 点击 **Web Server** → 选择 **Apache**
4. **Document Root** → 选择你的代码目录
5. 点击 **OK**

#### 第三步：启动

1. 点击 **Start Servers**
2. 访问 `http://localhost:8888/index.php`（端口默认 8888）

---

### 🐧 Linux 用户（命令行方案）

#### Debian / Ubuntu

```bash
# 1. 安装基础环境
sudo apt update
sudo apt install php php-curl php-cli

# 2. 验证
php -v
php -m | grep curl   # 看到 curl 就行

# 3. 进入项目目录启动内置服务器
cd /path/to/MX-jx
php -S 0.0.0.0:8080

# 4. 浏览器访问
# http://localhost:8080/index.php
```

#### CentOS / RHEL

```bash
# 1. 安装
sudo yum install epel-release
sudo yum install php php-curl

# 2. 启动内置服务器
cd /path/to/MX-jx
php -S 0.0.0.0:8080
```

---

## 4️⃣ 部署到服务器

> 本地测试通过后，就可以上传到**公网服务器**让别人访问了。

### 🛡️ 方案一：宝塔面板（国内服务器推荐）

#### 第一步：在服务器上安装宝塔

```bash
# CentOS 系统（阿里云/腾讯云/华为云通用）
yum install -y wget && wget -O install.sh https://download.bt.cn/install/install_6.0.sh && sh install.sh

# Ubuntu/Debian 系统
wget -O install.sh https://download.bt.cn/install/install-ubuntu_6.0.sh && sudo bash install.sh
```

> 安装完成后，控制台会输出**面板地址、用户名、密码**，请保存好！

#### 第二步：登录面板配置环境

1. 浏览器打开 `http://你的服务器IP:8888`
2. 首次登录会弹窗推荐套件，选择 LNMP：
   - **Nginx** 1.18+
   - **MySQL**：**不需要**，选择不安装
   - **PHP**：8.1 或 7.4+
3. 等待安装完成（大约 5-10 分钟）

#### 第三步：创建网站

1. 左侧菜单点击 **网站** → **添加站点**
2. 填写：
   - **域名**：你的域名（如 `jx.example.com`）
   - **根目录**：默认即可，记下来路径
   - **FTP**：不创建
   - **数据库**：不创建
   - **PHP 版本**：选择 7.4+
3. 点击 **提交**

#### 第四步：上传代码

1. 左侧菜单点击 **文件**
2. 进入刚才创建的网站目录（如 `/www/wwwroot/jx.example.com/`）
3. 删除里面默认的 `index.html` 和 `.htaccess` 等文件
4. 点击 **上传** → 把项目所有文件拖进去
5. 或者：点击 **远程下载**，输入：
   ```
   https://github.com/ssmhdssmhd/MX-jx/archive/refs/heads/main.zip
   ```
   然后解压，把里面的文件移到根目录

#### 第五步：设置权限

1. 在文件管理器中，右键网站根目录 → **权限**
2. 设置为 `755`，所有者 `www`
3. ✅ 确认所有 PHP 文件有读取权限

#### 第六步：测试访问

浏览器访问：`http://你的域名/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html`

看到 JSON 响应即成功！🎉

---

### 🌐 方案二：cPanel / 虚拟主机（海外服务器）

#### 第一步：登录 cPanel

#### 第二步：上传文件

1. 打开 **File Manager**（文件管理器）
2. 进入 `public_html` 目录
3. 点击 **上传** → 上传 ZIP 文件 → 解压

#### 第三步：选择 PHP 版本

1. 打开 **Select PHP Version**（选择 PHP 版本）
2. 选择 7.4 或 8.x
3. 确保勾选了 **curl** 扩展 ✅
4. 保存

#### 第四步：测试

访问：`http://你的域名/index.php`

---

### 🏷️ 方案三：二级目录部署（子目录）

如果你想放在 `https://你的域名/jx/` 下，而不是根目录：

1. 在网站根目录创建 `jx` 文件夹
2. 把所有文件上传到 `jx/` 里面
3. 访问：`https://你的域名/jx/index.php?url=xxx`

✅ 本项目**不依赖域名路径**，放到任何子目录都可以直接运行，无需修改配置。

---

## 5️⃣ 使用方法

### 🧪 最简单的测试

在浏览器中访问：

```
你的域名/index.php?url=视频播放页链接
```

**示例**：

```
https://jx.example.com/index.php?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html
```

你会看到类似这样的 JSON 响应：

```json
{
    "code": 200,
    "msg": "https://player.example.com/video.m3u8",
    "url": "https://player.example.com/video.m3u8",
    "strategy": "MyJson1-VIP-Global-Fastest",
    "developer": "MX-射手沫蝴蝶",
    "contact": "QQ: 2094332348",
    "timestamp": 1718768000,
    "switch_status": {
        "global_api_enabled": true,
        "global_api_count": 6,
        "zjk_apis_enabled": true,
        "zjk_file_exists": true,
        "unified_display_enabled": true,
        "m3u8_direct_enabled": true
    }
}
```

**关键字段说明**：

| 字段 | 含义 |
|------|------|
| `code` | `200` = 成功，其他 = 失败 |
| `url` | **最关键**！这是解析后的播放地址，粘贴到播放器即可观看 |
| `msg` | 通常与 `url` 相同（统一显示模式） |
| `strategy` | 本次使用的是哪条线路（方便你调试接口质量） |

---

### 📱 配合播放器使用

解析得到的是 `.m3u8` 链接，需要配合支持 M3U8 的播放器：

| 平台 | 推荐播放器 | 说明 |
|------|-----------|------|
| **手机（安卓）** | MX Player / Reex / 超级直播 | 复制 m3u8 链接粘贴播放 |
| **手机（iOS）** | nPlayer / Infuse / Fileball | Safari 可直接播放部分 m3u8 |
| **电脑** | PotPlayer / VLC | VLC: 媒体 → 打开网络串流 |
| **电视盒子** | Kodi / 当贝播放器 | 通过接口接入 IPTV 系统 |

---

### 🔌 作为接口接入其他系统

如果你的系统有自己的 APP/网页，你可以把这个当作**解析接口**来调用：

```javascript
// 前端调用示例（JavaScript / fetch）
async function parseVideo(url) {
    const api = 'https://jx.example.com/index.php?url=';
    const resp = await fetch(api + encodeURIComponent(url));
    const data = await resp.json();
    if (data.code === 200) {
        console.log('播放地址:', data.url);
        // 把 data.url 送入播放器
        return data.url;
    }
    throw new Error(data.msg || '解析失败');
}
```

```python
# Python 调用示例
import requests

def parse_video(url):
    api = 'https://jx.example.com/index.php?url='
    resp = requests.get(api + url, timeout=15)
    data = resp.json()
    if data.get('code') == 200:
        return data['url']
    raise Exception(data.get('msg', '解析失败'))
```

---

## 6️⃣ 配置详解

> 你可以通过修改以下文件来**定制**解析行为，无需动代码。

### 🎛️ 系统开关 — [config/switch.php](config/switch.php)

```php
return [
    'enable_global_api' => true,          // ✅ 是否启用"总接口"模式
    'global_api_count' => 6,              //   前 N 条 API 作为总接口
    'global_api_timeout' => 8,            //   总接口超时时间（秒）
    'enable_zjk_apis' => true,            // ✅ 是否加载 ZJK.txt 自定义接口
    'zjk_file_path' => 'ZJK.txt',         //   自定义接口文件路径
    'enable_m3u8_direct' => true,         // ✅ 检测到 m3u8 链接直接返回
    'enable_unified_display' => true,     // ✅ msg 与 url 字段显示相同内容
];
```

**开关建议**：

| 场景 | enable_global_api | global_api_count | enable_zjk_apis | enable_m3u8_direct |
|------|-------------------|------------------|-----------------|--------------------|
| 新手默认 | `true` | `6` | `true` | `true` |
| 仅用自己的接口 | `true` | `0` | `true` | `true` |
| 服务器性能较差 | `true` | `3` | `false` | `true` |
| 不需要并发 | `false` | `0` | `true` | `true` |

---

### 🔗 API 接口列表 — [config/api.php](config/api.php)

```php
return [
    // 格式: '接口名称' => '接口URL|超时秒数'
    'MyJson1-VIP' => 'https://jx.jsonplayer.com/player/?url=|5',
    'MyJson2-VIP' => 'https://jx.m3u8.tv/jiexi/?url=|5',
    //  ... 更多接口
    'Backup1' => 'https://jx.jsonplayer.com/player/?url=|8',
];
```

**如何新增自己的接口**：

1. 找到一个可用的解析接口（例如 `https://api.example.com/jx/?url=`）
2. 在 `config/api.php` 中增加一行：
   ```php
   'MyCustom-API' => 'https://api.example.com/jx/?url=|8',
   ```
3. `|8` 表示 8 秒超时，可自行调整

> 💡 **接口格式要求**：接口必须接受 `?url=` 参数，并返回包含 `url`/`code`/`data` 字段的 JSON 响应。

---

### 📋 自定义接口文件 — [ZJK.txt](ZJK.txt)

如果你不想改 PHP 文件，可以把自定义接口写在纯文本里：

```
# 每行一个，格式: 接口URL|超时秒数
# # 开头是注释，会被忽略
https://api.example.com/jx/?url=|8
https://api2.example.com/parse/?url=|10
```

特点：
- 改动即时生效，无需重启
- 方便批量管理
- 接口会按**顺序**被并发请求

---

### 🎬 平台规则 — [config/platform.php](config/platform.php)

```php
return [
    // 格式: '平台名称' => '域名关键字|优先使用的API名'
    'qq'     => 'v.qq.com|MyJson1-VIP',
    'iqiyi'  => 'iqiyi.com|MyJson2-VIP',
    'douyin' => 'douyin.com|MyJson4-VIP',
    // ... 更多平台
];
```

**工作原理**：当请求的 URL 中包含 `v.qq.com` 时，系统会**优先**让 `MyJson1-VIP` 这条线路去解析，如果它失败了，才会退回给其他线路。

> 💡 这个功能的意义在于：某些接口对特定平台支持特别好，可以给它"优先权"。如果你不懂怎么调，保持默认即可。

---

## 7️⃣ 常见问题 FAQ

### ❓ Q1: 浏览器访问显示空白页 / 500 错误

**原因**：PHP 代码执行出错。

**排查步骤**：
1. 检查 PHP 版本是否 ≥ 7.4：在网站根目录创建 `phpinfo.php`，内容 `<?php phpinfo();`，访问查看
2. 检查 cURL 是否已启用：在 phpinfo 页面搜索 `curl`，必须显示 enabled
3. 临时打开错误显示看具体报错：
   - 修改 `index.php` 第 11-12 行为：
     ```php
     ini_set('display_errors', 1);
     error_reporting(E_ALL);
     ```
   - 访问看具体报错
   - **解决问题后记得改回来**，避免暴露敏感信息

---

### ❓ Q2: 返回 `code: 404` 或 "所有解析接口均失败"

**原因**：所有 API 接口都请求失败了。

**可能的问题**：

| 可能性 | 检查方法 | 解决 |
|--------|---------|------|
| 服务器不能访问外网 | 用 SSH 执行 `curl https://www.baidu.com` | 找服务商开放出站访问 |
| API 接口已失效 | 浏览器直接访问接口 URL 测试 | 更换 `config/api.php` 中的接口 |
| 请求超时 | 接口响应慢 | 把 `|5` 改大，如 `|15` |
| 接口被限流 | 同 IP 请求过多被拦截 | 更换接口或增加间隔 |

**最简单的测试**：

```
# 直接测试某条接口
https://jx.jsonplayer.com/player/?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html
```

如果在浏览器里能返回 JSON，但你的服务器请求不到，**就是服务器网络问题**。

---

### ❓ Q3: M3U8 链接没有被识别

检查 [config/switch.php](config/switch.php) 中：

```php
'enable_m3u8_direct' => true,   // 必须是 true
```

同时链接 URL 中需要包含 `.m3u8` 或路径/参数里有 `m3u8` 关键字。

---

### ❓ Q4: 怎么增加接口线路

打开 `config/api.php`，按已有格式在数组末尾添加一行：

```php
'MyNewAPI' => 'https://你的接口地址/?url=|10',
```

保存即可，**无需重启任何服务**。

---

### ❓ Q5: 响应太慢怎么办

1. **减少并发数量**：把 `global_api_count` 从 6 改到 3
2. **降低超时时间**：如 `|5` 改到 `|3`
3. **筛选出稳定接口**：测试每条接口，删掉总是失败的
4. **启用缓存**（高级）：接入 `core/cache.php` 的缓存功能

---

### ❓ Q6: 解析后的播放链接无法播放

原因可能有：
1. **接口返回的是假/失效链接** → 换一条接口试试
2. **播放器不支持该编码格式** → 换 VLC / MX Player 测试
3. **接口需要特定 Referer** → 某些接口对 Referer 有校验，需要在播放器设置 Referer

**最简单的测试**：把返回的 `url` 直接用 VLC 播放器"打开网络串流"粘贴播放。

---

### ❓ Q7: 部署后提示文件权限问题

Linux 服务器执行：
```bash
cd /www/wwwroot/你的网站目录
chmod -R 755 .
chown -R www.www .
```

Windows 虚拟主机通常不需要设置权限。

---

### ❓ Q8: 如何升级到新版本

```bash
cd /path/to/MX-jx
git pull origin main
```

如果你是用 ZIP 下载的：**备份 `config/api.php`、`config/switch.php`、`ZJK.txt`**，然后重新下载新版本覆盖，再把备份的配置文件放回去。

---

### ❓ Q9: 想在一个目录部署多份

```
wwwroot/
├── jx1/   ← 第一份（使用默认接口）
├── jx2/   ← 第二份（自定义接口组A）
└── jx3/   ← 第三份（自定义接口组B）
```

每个目录独立的配置，互不干扰。

---

## 8️⃣ 快速参考速查表

### 🔑 关键文件速查

| 目标 | 文件 | 要不要改 |
|------|------|----------|
| 解析入口 | `index.php` | ❌ 不要改 |
| **后台入口** | `admin.php` | ✅ **首次登录请立即修改密码** |
| 增减接口 | `config/api.php` | ✅ 可以改（后台也能可视化编辑） |
| 设置开关 | `config/switch.php` | ✅ 可以改（后台也能可视化编辑） |
| 平台匹配 | `config/platform.php` | ✅ 可以改（后台也能可视化编辑） |
| 后台权限 | `config/admin.php` | ⚠️ 谨慎修改（存储密码哈希） |
| 自定义接口 | `ZJK.txt` | ✅ 可以改 |
| 核心逻辑 | `core/*.php` | ❌ 除非你懂 PHP |

### 🧭 两个快速访问入口

```
👉 解析接口:   http://你的域名/index.php?url=视频链接
👉 管理后台:   http://你的域名/admin.php  (默认密码: admin123)
```

### 🛠️ 常用命令速查

| 操作 | 命令 |
|------|------|
| 查看 PHP 版本 | `php -v` |
| 查看已启用扩展 | `php -m` |
| 检查 cURL | `php -m \| grep curl` |
| 本地启动服务器 | `php -S 0.0.0.0:8080` |
| 更新代码 | `git pull origin main` |
| 查看修改的文件 | `git status` |
| 查看差异 | `git diff` |

### 📡 测试接口是否可用

在服务器命令行执行：

```bash
curl -I "https://jx.jsonplayer.com/player/?url=https://v.qq.com/x/cover/mzc00200mp8vo9l/r00421m6n7k.html"
```

如果返回 `HTTP/1.1 200 OK` 开头，说明服务器网络没问题。

---

### 🆘 还是搞不定？

1. **回看步骤**：从第 0 章重新来过，不要跳过任何一步
2. **确认 PHP 版本**：`<?php phpinfo(); ?>` 是新手最好的调试工具
3. **确认 cURL 启用**：90% 的问题都是扩展没开
4. **确认网络连通**：服务器能不能访问外网接口

---

<div align="center">

---

**🎉 看到这里，你应该已经能独立部署和使用了！**

**如果本项目对你有帮助，欢迎在 GitHub 点个 Star ⭐**

[⬅️ 返回 README](README.md) · [🌐 项目仓库](https://github.com/ssmhdssmhd/MX-jx)

</div>
