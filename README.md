<div align="center">

# PicUp for Typecho

**多存储后端图片/附件上传插件，支持多种云存储服务，多 Profile 配置，图像处理扩展。**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb3?logo=php&logoColor=white)](https://www.php.net/)
[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-4a90d9)](https://typecho.org/)
[![GitHub release](https://img.shields.io/github/v/release/lhl77/Typecho-Plugin-PicUp?color=brightgreen&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
[![License](https://img.shields.io/github/license/lhl77/Typecho-Plugin-PicUp?color=blue)](LICENSE)
[![Stars](https://img.shields.io/github/stars/lhl77/Typecho-Plugin-PicUp?style=flat&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/stargazers)

</div>

---
## [截图、插件开发指南、SDK接入指南](https://blog.lhl.one/artical/1026.html)
---

## ✨ 功能特性

- 🗂️ **多存储后端** — 支持 12+ 种云存储 / 图床，可随时切换
- 📦 **多 Profile 配置** — 同时保存多套配置方案，一键应用切换
- 🖼️ **图像处理扩展** — 图片压缩、自动转 WebP、添加水印，可逐个开关
- 🔌 **扩展化架构** — 驱动和扩展均自动发现，放入对应目录即生效
- 📱 **响应式配置界面** — 移动端友好，支持深色模式
- ⚡ **上传进度提示** — Toast 通知，实时展示上传状态

---

## 📦 支持的存储驱动

| 驱动 | 标识 | 说明 |
|------|------|------|
| **本地存储** | `local` | 遵循 Typecho 原生逻辑，存储至 `usr/uploads/` |
| **Lsky Pro 兰空图床** | `lsky` | 支持 v1 / v2 API |
| **AWS S3 / 兼容** | `s3` | 支持 AWS S3、MinIO、Cloudflare R2、阿里云 OSS（S3 兼容）等 |
| **WebDAV** | `webdav` | 标准 WebDAV 协议 |
| **GitHub 仓库** | `github` | 通过 GitHub Contents API 存储，支持 CDN 加速 |
| **S.EE (SM.MS)** | `smms` | S.EE 免费图床 |
| **阿里云 OSS** | `aliyunoss` | 阿里云对象存储（原生 V1 签名） |
| **腾讯云 COS** | `tencentcos` | 腾讯云对象存储（COS V5 签名） |
| **七牛云 KODO** | `qiniukodo` | 七牛云对象存储 |
| **又拍云 USS** | `upyun` | 又拍云云存储 |
| **EasyImage 简单图床** | `easyimage` | EasyImage 自建图床 |
| **CloudFlare ImgBed** | `cfimgbed` | 基于 Cloudflare 的图床 |

---

## 🖼️ 图像处理扩展

扩展存放于 `extensions/` 目录，每个方案（Profile）可独立配置开启/关闭。

| 扩展 | 标识 | 依赖 | 说明 |
|------|------|------|------|
| **图片压缩** | `compress` | PHP `gd` 扩展 | 对 JPEG/PNG/WebP 进行有损/无损压缩，可设置质量百分比 |
| **自动转 WebP** | `webp` | PHP `gd` + WebP 支持 | 上传前将 JPEG/PNG/GIF/BMP 转换为 WebP 格式 |
| **添加水印** | `watermark` | PHP `gd` 扩展 | 支持文字水印（TTF 字体）和图片水印，可设置位置/透明度 |

> **提示**：扩展会在文件上传至云存储前在服务端处理，原文件不会被修改。

---

## 🚀 安装

### 方式一：AB-Store 一键安装（推荐）

安装 [AdminBeautify](https://github.com/lhl77/Typecho-Plugin-AdminBeautify) 插件后，进入后台 **AB-Store** 应用商店，搜索 **PicUp** 即可一键安装并获取后续更新。

### 方式二：手动安装

1. 下载最新 [Release](https://github.com/lhl77/Typecho-Plugin-PicUp/releases) 压缩包
2. 解压为 `PicUp` 文件夹
3. 上传至 Typecho 的 `usr/plugins/` 目录
4. 登录后台 → **控制台** → **插件管理** → 启用 **PicUp**

### 方式三：Git 克隆

```bash
cd /path/to/typecho/usr/plugins/
git clone https://github.com/lhl77/Typecho-Plugin-PicUp.git PicUp
```

---

## ⚙️ 配置

启用插件后，进入 **控制台 → 插件管理 → PicUp → 设置**。

### 配置编辑器

配置界面提供可视化编辑器，支持：

- **添加方案** — 创建新的配置 Profile
- **重命名方案** — 修改方案名称
- **应用此方案** — 将当前方案设为活跃方案
- **删除方案** — 删除当前方案

每个方案包含：
1. **驱动类型** — 选择存储后端
2. **驱动配置** — 各驱动专属配置字段
3. **插件扩展** — 为该方案独立配置图像处理扩展

### JSON 配置示例

```json
{
  "my-oss": {
    "driver": "aliyunoss",
    "endpoint": "oss-cn-hangzhou.aliyuncs.com",
    "bucket": "my-bucket",
    "accessKeyId": "xxx",
    "accessKeySecret": "xxx",
    "prefix": "images",
    "urlPrefix": "https://cdn.example.com",
    "_extensions": {
      "compress": { "enabled": true, "quality": "82" },
      "webp": { "enabled": true, "quality": "85" },
      "watermark": { "enabled": false }
    }
  }
}
```

---

## 🔧 驱动配置说明

<details>
<summary><b>本地存储</b></summary>

| 字段 | 说明 | 示例 |
|------|------|------|
| `uploadDir` | 相对于 Typecho 根目录的上传目录 | `usr/uploads` |
| `urlPrefix` | 文件 URL 前缀，留空自动使用站点地址 | `https://example.com` |

</details>

<details>
<summary><b>Lsky Pro 兰空图床</b></summary>

| 字段 | 说明 |
|------|------|
| `server` | 图床地址，如 `https://pic.example.com` |
| `token` | API Token（不含 Bearer 前缀） |
| `strategy_id` | 储存策略 ID（可选） |
| `album_id` | 相册 ID（可选） |
| `api_version` | API 版本：`v1` 或 `v2` |

</details>

<details>
<summary><b>阿里云 OSS</b></summary>

| 字段 | 说明 | 示例 |
|------|------|------|
| `endpoint` | 地域节点 | `oss-cn-hangzhou.aliyuncs.com` |
| `bucket` | Bucket 名称 | `my-bucket` |
| `accessKeyId` | Access Key ID | |
| `accessKeySecret` | Access Key Secret | |
| `prefix` | 文件路径前缀（可选） | `images` |
| `urlPrefix` | 自定义 CDN 域名（可选） | `https://cdn.example.com` |

</details>

<details>
<summary><b>腾讯云 COS</b></summary>

| 字段 | 说明 | 示例 |
|------|------|------|
| `region` | 地域 | `ap-guangzhou` |
| `bucket` | Bucket（含 AppId） | `my-bucket-1250000000` |
| `secretId` | SecretId | |
| `secretKey` | SecretKey | |
| `prefix` | 路径前缀（可选） | `images` |
| `urlPrefix` | 自定义域名（可选） | `https://cdn.example.com` |

</details>

<details>
<summary><b>七牛云 KODO</b></summary>

| 字段 | 说明 |
|------|------|
| `accessKey` | Access Key |
| `secretKey` | Secret Key |
| `bucket` | Bucket 名称 |
| `zone` | 存储区域（z0=华东, z1=华北, z2=华南, na0=北美, as0=东南亚） |
| `urlPrefix` | 绑定的自定义域名（**必填**，七牛不提供免费测试域名） |
| `prefix` | 路径前缀（可选） |

</details>

<details>
<summary><b>又拍云 USS</b></summary>

| 字段 | 说明 |
|------|------|
| `service` | 服务名（Bucket） |
| `operator` | 操作员账号 |
| `password` | 操作员密码 |
| `urlPrefix` | 绑定的自定义域名 |
| `prefix` | 路径前缀（可选） |

</details>

<details>
<summary><b>GitHub 仓库</b></summary>

| 字段 | 说明 | 示例 |
|------|------|------|
| `token` | Personal Access Token（需 `repo` 权限） | |
| `repo` | 仓库名（`owner/repo`） | `lhl77/images` |
| `branch` | 分支 | `main` |
| `prefix` | 路径前缀（可选） | `images` |
| `cdn` | CDN 加速地址（可选） | `https://cdn.jsdelivr.net/gh/lhl77/images` |

</details>

<details>
<summary><b>S3 兼容（AWS S3 / MinIO / R2 等）</b></summary>

| 字段 | 说明 |
|------|------|
| `endpoint` | 端点地址 |
| `region` | 地域 |
| `bucket` | Bucket 名称 |
| `accessKey` | Access Key |
| `secretKey` | Secret Key |
| `pathStyle` | 路径风格（MinIO 需开启） |
| `urlPrefix` | 自定义域名（可选） |
| `prefix` | 路径前缀（可选） |

</details>

---

## 🖼️ 扩展配置说明

### 图片压缩

```json
"compress": {
  "enabled": true,
  "quality": "80"
}
```

- `quality`：1–100，JPEG/WebP 为有损质量，PNG 为压缩级别换算（`(100-quality)/10`）

### 自动转 WebP

```json
"webp": {
  "enabled": true,
  "quality": "85"
}
```

> 启用后，JPEG/PNG/GIF 上传时会自动转为 `.webp` 格式。服务端需要 PHP GD 扩展并编译了 WebP 支持（`--with-webp`）。

### 添加水印

```json
"watermark": {
  "enabled": true,
  "type": "text",
  "text": "© example.com",
  "font_size": "16",
  "font_color": "#ffffff",
  "opacity": "80",
  "position": "bottom-right",
  "font_path": "",
  "image_path": "",
  "image_scale": "20"
}
```

| 字段 | 说明 |
|------|------|
| `type` | `text`（文字水印）或 `image`（图片水印） |
| `text` | 水印文字内容 |
| `font_size` | 字体大小（像素） |
| `font_color` | 字体颜色（十六进制） |
| `opacity` | 透明度 0–100 |
| `position` | 位置：`top-left`/`top-right`/`bottom-left`/`bottom-right`/`center` |
| `font_path` | TTF 字体文件路径（支持中文水印需提供含 CJK 字符的字体） |
| `image_path` | 水印图片路径（`type=image` 时有效） |
| `image_scale` | 水印图片占原图宽度的百分比 |

> **中文水印**：需要提供含 CJK 字符的 TTF/OTF 字体文件（如 `NotoSansCJK-Regular.ttc`），或系统已安装常见中文字体（插件会自动检测）。

---

## 📁 目录结构

```
PicUp/
├── Plugin.php                  # 插件主文件
├── README.md
├── vendor/                     # 存储驱动
│   ├── DriverInterface.php     # 驱动接口
│   ├── LocalDriver.php         # 本地存储
│   ├── LskyDriver.php          # Lsky Pro
│   ├── S3Driver.php            # AWS S3 兼容
│   ├── WebDavDriver.php        # WebDAV
│   ├── GithubDriver.php        # GitHub 仓库
│   ├── SmmsDriver.php          # S.EE (SM.MS)
│   ├── AliyunOssDriver.php     # 阿里云 OSS
│   ├── TencentCosDriver.php    # 腾讯云 COS
│   ├── QiniuKodoDriver.php     # 七牛云 KODO
│   ├── UpyunDriver.php         # 又拍云
│   ├── EasyimageDriver.php     # EasyImage
│   └── CfimgbedDriver.php      # CF ImgBed
└── extensions/                 # 图像处理扩展
    ├── ExtensionInterface.php  # 扩展接口
    ├── CompressExtension.php   # 图片压缩
    ├── WebpExtension.php       # 自动转 WebP
    └── WatermarkExtension.php  # 添加水印
```

---

## 🤝 贡献

欢迎提交 [Issue](https://github.com/lhl77/Typecho-Plugin-PicUp/issues) 或 [Pull Request](https://github.com/lhl77/Typecho-Plugin-PicUp/pulls)。
