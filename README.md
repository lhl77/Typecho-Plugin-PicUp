<div align="center">

<img src="https://i.see.you/2026/07/26/5dnU/6cf4c80b939c47baefb00b66bd567b8a.jpg" width="30%" />

# PicUp for Typecho

**多存储后端图片/附件上传插件，支持多种云存储服务，多 Profile 配置，图像处理扩展。**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb3?logo=php&logoColor=white)](https://www.php.net/)
[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-4a90d9)](https://typecho.org/)
[![GitHub release](https://img.shields.io/github/v/release/lhl77/Typecho-Plugin-PicUp?color=brightgreen&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
[![License](https://img.shields.io/github/license/lhl77/Typecho-Plugin-PicUp?color=blue)](LICENSE)
[![Stars](https://img.shields.io/github/stars/lhl77/Typecho-Plugin-PicUp?style=flat&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/stargazers)

</div>

[文档](https://blog.lhl.one/artical/1026.html)

---

## 功能特性

- **多存储后端** — 支持 12+ 种云存储 / 图床，可随时切换
- **多 Profile 配置** — 同时保存多套配置方案，一键应用切换
- **图像处理扩展** — 图片压缩、自动转 WebP、自动转 AVIF、EXIF 信息抹除、添加水印，可逐个开关
- **自动备份（Beta）** — 同一张图上传后自动再传到多个方案，并记录每个方案的链接
- **AI 友好** — 内置 `Api.php` 接口层，安装 AB Admin 后可让内置 AI 助手直接开启自动备份、单文件/批量迁移与链接替换
- **扩展化架构** — 驱动和扩展均自动发现，放入对应目录即生效
- **响应式配置界面** — 移动端友好，支持深色模式
- **上传进度提示** — Toast 通知，实时展示上传状态

## 支持的存储驱动

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
| **CloudFlare ImgBed** | `cfimgbed` | 基于 Cloudflare Workers 的图床 |
| **NodeImage** | `nodeimage` | NodeImage 图床，通过 X-API-Key 认证 |
| **Chevereto V4** | `cheveretoV4` | Chevereto V4 自建图床，支持相册 |
| **Imgur** | `imgur` | Imgur 图床，支持匿名/账户上传 |
| **初春图床 (OneImg)** | `oneimg` | 初春图床，Bearer Token 认证 |
| **Telegram 图床** | `tgimagebed` | tg-imagebed 项目，支持匿名和 Token 上传 |
| **Zpic 图床** | `zpic` | Zpic / ImgURL Pro，支持 V2/V3 API |

---

## 图像处理扩展

扩展存放于 `extensions/` 目录，每个方案（Profile）可独立配置开启/关闭。

| 扩展 | 标识 | 依赖 | 说明 |
|------|------|------|------|
| **图片压缩** | `compress` | PHP `gd` 扩展 | 对 JPEG/PNG/WebP 进行有损/无损压缩，可设置质量百分比 |
| **自动转 WebP** | `webp` | PHP `gd` + WebP 支持 | 上传前将 JPEG/PNG/GIF/BMP 转换为 WebP 格式 |
| **自动转 AVIF** | `avif` | PHP `gd` + AVIF 支持 或 Imagick | 上传前将图片转换为 AVIF 格式（体积更小） |
| **EXIF 信息抹除** | `exif` | 无 | 抹除 EXIF / GPS / XMP / IPTC 元数据，避免泄露拍摄地点与设备信息 |
| **添加水印** | `watermark` | PHP `gd` 扩展 | 支持文字水印（TTF 字体）和图片水印，可设置位置/透明度 |

> **提示**：扩展会在文件上传至云存储前在服务端处理，原文件不会被修改。

---

## 自动备份（Beta）

> 🅱 **测试版**：功能已可用，但接口与界面细节仍可能调整，不建议作为唯一的数据保障手段。

同一张图片上传到主方案后，可以自动再上传到若干备份方案（本地、云存储、图床可混搭），
并把每个方案拿到的链接都记录下来。

- **在哪配置**：插件设置 → **自动备份**。复选框由 `configJson` 里现有的方案动态生成，
  **勾选即生效**（不需要再点「保存设置」）；主方案会被自动跳过，不会重复上传同一方案。
- **首次使用要先建表**：点击面板里的「**开启自动备份**」按钮创建 `{prefix}PicUpLinks`。
  从旧版直接升级上来的站点不会自动建表，所以做成手动按钮；建表成功会原地刷新面板，不跳页。
  **MySQL / MariaDB、SQLite、PostgreSQL 均已适配**（建表语句与表存在性检测按数据库类型自动切换；
  PostgreSQL 的建表不加引号，以保证与 Typecho Query Builder 生成的小写表名一致）。
- **存了什么**：每个文件一行 —— 文件名、主方案名、主链接，以及 `{"方案名": "链接"}` 形式的全部链接。
- **匹配规则（重要）**：附件与备份记录的对应关系**只按链接地址**判断。文件名不参与匹配 ——
  `image.png` 这类同名文件很常见，早期按文件名回退会把 A 文件的记录挂到 B 文件上，
  出现「没开启自动备份却已经有备份链接」。现在重新上传的同名文件**不会**继承旧记录；
  旧版本错误绑定的 `cid` 会在访问详情页时自动解绑。
- **怎么看**：面板内置链接查看器，支持按 **文件名 / 方案名 / 链接** 搜索，并分页浏览。
- **容错**：某个备份方案上传失败只会写错误日志，**不影响主上传结果**；主方案链接照常返回。

### 媒体库里的角标与单文件迁移

- 「管理 → 文件」的每张卡片右上角会显示 **「已备份 N」**（N = 该文件除当前链接外的备份数）。
- 点开任意文件进入详情页（`admin/media.php?cid=X`），在「文件地址」下方会出现
  **PicUp · 自动备份链接** 面板：
  - 列出该文件在 **每个方案下的链接**，当前使用的那条标「当前」；
  - 如果该文件**没有**自动备份记录，就只显示一条当前链接，方案名按各方案配置里的
    **域名 / 上传目录**反推（例如本地方案的 `usr/uploads` → `default`、`usr/uploads-bak` → `bak`；
    多个方案前缀重叠时取最长命中）。取不到时才显示中性标签「(当前)」。
  - 「迁移到」下拉会**默认选一个不是当前方案的方案**，避免一打开就是「迁移到自己」。
  - 点「设为当前」可直接切到已有链接（只改数据库指向，不搬文件）；
  - 选「迁移到 → 某方案 + 开始迁移」：**先备份、再替换** —— 若该方案还没有这个文件，会
    **先下载原文件 → 上传到目标方案**，确认拿到新链接后才改引用；
    **原方案的链接会保留在记录里**（迁移 `default → bak` 之后两条都在，可随时点「设为当前」切回）；
  - 默认勾选「同时替换文章与附件里的旧链接」，会把正文里对该文件的引用一并改成新地址
    （明文 `http://…` 与 JSON 转义 `http:\/\/…` 两种写法都能命中）。
- ⚠️ 详情页原有的「替换文件」上传面板已被移除：不同方案重新上传后返回的链接并不一致，
  替换文件会让链接与备份记录脱节，统一改用上面的「迁移」流程。

---

## 迁移与替换（Beta）

> 🅱 **测试版**：批量改写会同时改动链接表、附件地址与文章正文，**执行前请先「配置备份」**。

插件设置 → **迁移与替换** 折叠区，用于**批量**把文件从一个方案切到另一个方案：

- 选择「源方案 → 目标方案」，先点 **预览影响**：会统计受影响的 **链接记录 / 附件 / 文章** 数量（不写库）。
- 点 **执行替换**（二次确认）后，一次性改写三处：
  1. `{prefix}PicUpLinks`：`primary_profile` / `primary_url` 指向目标方案；
  2. 媒体库附件地址（`contents.text` 里的 `path`）；
  3. **文章正文**里出现的旧链接 —— 同时处理 JSON 转义形式（`http:\/\/…`）。
- 目标方案里没有该文件链接的记录会被跳过并计入「跳过」；执行前建议先「配置备份」。
- 单文件迁移不在这个区块做，而是按上面的说明在「管理 → 文件 → 详情页」操作。

---

## 服务器要求

| 项目 | 最低要求 | 说明 |
|------|---------|------|
| PHP | 7.4+ | **已适配到 PHP 8.5**；推荐 8.0+；需开启 `curl`、`json`、`fileinfo` 扩展 |
| Typecho | 1.3.0+ | 需命名空间版本 |
| OpenSSL | **1.1.0+** | 低于此版本可能导致连接 Cloudflare 等服务失败 |

> **PHP 8.5 兼容说明**：从 1.2.10 起，`curl_close()` / `imagedestroy()` /
> `finfo_close()` / `ReflectionProperty::setAccessible()` 这些在 PHP 8.5 中已废弃
> （前三个自 8.0/8.1 起就是空操作）的调用，都改为先判断 `PHP_VERSION_ID` 再执行 ——
> 7.4~8.4 上行为与以前一致（仍会真正释放句柄/资源），8.5+ 上不再刷 Deprecated 日志。


## 安装

### 方式一：AB-Store 一键安装（推荐）

安装 [AB Admin](https://ab.lhl.one) 插件后，进入后台 **管理** → **控制台** → **插件** → **AB-Store** 应用商店，搜索 **PicUp** 即可一键安装并获取后续更新。

### 方式二：手动安装

1. 下载最新 [Release](https://github.com/lhl77/Typecho-Plugin-PicUp/releases) 压缩包，或从 [AB Store 网页版](https://ab-store.lhl.one/plugin/414bab94) 下载
2. 解压为 `PicUp` 文件夹
3. 上传至 Typecho 的 `usr/plugins/` 目录
4. 登录后台 → **控制台** → **插件管理** → 启用 **PicUp**

### 方式三：Git 克隆

```bash
cd /path/to/typecho/usr/plugins/
git clone https://github.com/lhl77/Typecho-Plugin-PicUp.git PicUp
```

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

## 驱动配置说明

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

<details>
<summary><b>WebDAV</b></summary>

| 字段 | 说明 | 示例 |
|------|------|------|
| `server` | WebDAV 服务器地址 | `https://dav.example.com/path` |
| `username` | 用户名 | |
| `password` | 密码 | |
| `urlPrefix` | 文件公开访问域名 | `https://cdn.example.com` |
| `prefix` | 路径前缀（可选） | `images` |

</details>

<details>
<summary><b>NodeImage</b></summary>

| 字段 | 说明 |
|------|------|
| `api_key` | 在 NodeImage 后台获取的 API Key（通过 `X-API-Key` 请求头传递） |

> **注意**：NodeImage 使用 Cloudflare 作为 CDN，服务器 OpenSSL 须 ≥ 1.1.0，否则 TLS 握手会被拒绝。

</details>

<details>
<summary><b>Chevereto V4</b></summary>

| 字段 | 说明 |
|------|------|
| `server` | Chevereto V4 站点地址，如 `https://pic.example.com` |
| `api_key` | 在 Chevereto 后台「Dashboard → API」中获取的 API v1 Key |
| `album_id` | 相册 ID（可选），上传至指定相册 |

</details>

<details>
<summary><b>Imgur</b></summary>

| 字段 | 说明 |
|------|------|
| `client_id` | 在 [Imgur API](https://api.imgur.com/oauth2/addclient) 注册应用后获取的 Client ID |
| `access_token` | Access Token（可选），填写后上传到账户，留空则匿名上传 |
| `album_hash` | 相册 deletehash（可选），需配合 Access Token 使用 |
| `cdn` | CDN 替换域名（可选），将 `https://i.imgur.com` 替换为自定义域名 |

</details>

<details>
<summary><b>初春图床 (OneImg)</b></summary>

| 字段 | 说明 |
|------|------|
| `server` | 图床站点地址，如 `https://img.example.com` |
| `token` | Bearer Token |
| `bucket_id` | 存储桶 ID（可选） |
| `url_prefix` | URL 前缀（可选），图床返回相对路径时拼接为完整 URL |

</details>

<details>
<summary><b>Telegram 图床 (tg-imagebed)</b></summary>

| 字段 | 说明 |
|------|------|
| `server` | 图床站点地址，如 `https://img.example.com` |
| `token` | Token（可选），填写后使用认证上传（更高限额），留空则匿名上传 |

</details>

<details>
<summary><b>Zpic 图床</b></summary>

| 字段 | 说明 |
|------|------|
| `server` | 图床域名，如 `https://zpic.example.com` |
| `api_version` | API 版本：`v3`（Bearer Token，推荐）或 `v2`（uid + token，兼容 ImgURL Pro） |
| `token` | V3 的 Token（格式 `sk-xxx`）或 V2 的 Token |
| `uid` | UID（仅 V2 需要） |
| `album_id` | 相册 ID（可选） |

</details>

---

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

## 目录结构

```
PicUp/
├── Plugin.php                      # 插件主文件
├── README.md
├── vendor/                         # 存储驱动
└── extensions/                     # 图像处理扩展
```

## 贡献

欢迎提交 [Issue](https://github.com/lhl77/Typecho-Plugin-PicUp/issues) 或 [Pull Request](https://github.com/lhl77/Typecho-Plugin-PicUp/pulls)。