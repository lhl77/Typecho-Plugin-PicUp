<div align="center">

# PicUp for Typecho

**A multi-backend image & attachment upload plugin for Typecho — supports 18+ cloud storage services, multiple profiles, and server-side image processing.**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb3?logo=php&logoColor=white)](https://www.php.net/)
[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-4a90d9)](https://typecho.org/)
[![GitHub release](https://img.shields.io/github/v/release/lhl77/Typecho-Plugin-PicUp?color=brightgreen&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
[![License](https://img.shields.io/github/license/lhl77/Typecho-Plugin-PicUp?color=blue)](LICENSE)
[![Stars](https://img.shields.io/github/stars/lhl77/Typecho-Plugin-PicUp?style=flat&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/stargazers)

</div>

<p align="center">
  <a href="https://blog.lhl.one/artical/1026.html">中文文档</a> ·
  <a href="https://github.com/lhl77/Typecho-Plugin-PicUp/releases">Releases</a> ·
  <a href="https://github.com/lhl77/Typecho-Plugin-PicUp/issues">Bug Reports</a>
</p>

---

## Features

| Feature | Description |
| ------- | ----------- |
| **18+ Storage Backends** | Switch between cloud storage services and image hosts at any time |
| **Multiple Profiles** | Save and switch between multiple configuration sets with one click |
| **Image Processing** | Server-side compression, WebP conversion, and watermarking — toggle per profile |
| **Extensible Architecture** | Drivers and extensions are auto-discovered; drop a file into the right directory and it works |
| **Responsive Config UI** | Mobile-friendly settings panel with dark mode support |
| **Upload Progress Toast** | Real-time toast notifications showing upload status |

---

## Storage Drivers

| Driver | ID | Description |
| ------ | -- | ----------- |
| **Local** | `local` | Follows Typecho's native logic, stores to `usr/uploads/` |
| **Lsky Pro** | `lsky` | Supports v1 / v2 API |
| **AWS S3 / Compatible** | `s3` | AWS S3, MinIO, Cloudflare R2, Alibaba Cloud OSS (S3-compatible), etc. |
| **WebDAV** | `webdav` | Standard WebDAV protocol |
| **GitHub Repository** | `github` | Stores via GitHub Contents API, supports CDN acceleration |
| **S.EE (SM.MS)** | `smms` | S.EE free image host |
| **Alibaba Cloud OSS** | `aliyunoss` | Alibaba Cloud Object Storage Service (native V1 signature) |
| **Tencent Cloud COS** | `tencentcos` | Tencent Cloud Object Storage (COS V5 signature) |
| **Qiniu Cloud KODO** | `qiniukodo` | Qiniu Cloud object storage |
| **Upyun USS** | `upyun` | Upyun cloud storage |
| **EasyImage** | `easyimage` | Self-hosted EasyImage |
| **Cloudflare ImgBed** | `cfimgbed` | Image host based on Cloudflare Workers |
| **NodeImage** | `nodeimage` | NodeImage host, authenticated via `X-API-Key` |
| **Chevereto V4** | `cheveretoV4` | Self-hosted Chevereto V4, supports albums |
| **Imgur** | `imgur` | Supports anonymous and account-based uploads |
| **OneImg** | `oneimg` | Bearer Token authentication |
| **Telegram ImgBed** | `tgimagebed` | tg-imagebed project, supports anonymous and token uploads |
| **Zpic** | `zpic` | Zpic / ImgURL Pro, supports V2/V3 API |

---

## Image Processing Extensions

Extensions live in the `extensions/` directory and can be enabled or disabled independently per profile.

| Extension | ID | Requires | Description |
| --------- | -- | -------- | ----------- |
| **Compression** | `compress` | PHP `gd` | Lossy/lossless compression for JPEG/PNG/WebP with configurable quality |
| **WebP Conversion** | `webp` | PHP `gd` + WebP support | Converts JPEG/PNG/GIF/BMP to WebP before upload |
| **Watermark** | `watermark` | PHP `gd` | Text watermark (TTF font) or image watermark with position and opacity control |

> **Note:** Extensions process files server-side before they are sent to cloud storage. The original file is never modified.

---

## Requirements

| Item | Minimum | Notes |
| ---- | ------- | ----- |
| PHP | 7.4+ | 8.0+ recommended; `curl`, `json`, and `fileinfo` extensions required |
| Typecho | 1.3.0+ | Namespace-based version required |
| OpenSSL | **1.1.0+** | Older versions may cause TLS handshake failures with Cloudflare and similar services |

---

## Installation

### Option 1 — AB-Store (Recommended)

Install the [AdminBeautify](https://github.com/lhl77/Typecho-Plugin-AdminBeautify) plugin, then open **AB-Store** in the admin panel, search for **PicUp**, and install with one click. Updates are delivered the same way.

### Option 2 — Manual

1. Download the latest ZIP from [GitHub Releases](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
2. Extract the archive and rename the folder to `PicUp`
3. Upload it to `usr/plugins/` in your Typecho installation
4. Go to **Admin** → **Console** → **Plugins** → enable **PicUp**

### Option 3 — Git Clone

```bash
cd /path/to/typecho/usr/plugins/
git clone https://github.com/lhl77/Typecho-Plugin-PicUp.git PicUp
```

---

## Setup

### 1. Configure a Profile

After activating the plugin, go to **Admin** → **Settings** → **PicUp**.

Profiles are stored as JSON. Each top-level key is a profile name. Choose a driver with `"driver"` and fill in the required fields for that driver. Add a `"_extensions"` block to configure image processing per profile.

**Example — Alibaba Cloud OSS with compression and WebP:**

```json
{
  "my-oss": {
    "driver": "aliyunoss",
    "endpoint": "oss-cn-hangzhou.aliyuncs.com",
    "bucket": "my-bucket",
    "accessKeyId": "YOUR_KEY_ID",
    "accessKeySecret": "YOUR_KEY_SECRET",
    "prefix": "images",
    "urlPrefix": "https://cdn.example.com",
    "_extensions": {
      "compress": { "enabled": true, "quality": "82" },
      "webp":     { "enabled": true, "quality": "85" },
      "watermark": { "enabled": false }
    }
  }
}
```

> **Keep your credentials private.** Never commit your JSON config to a public repository.

### 2. Select the Active Profile

After saving, pick the profile you want to activate from the dropdown and save again. All subsequent uploads will use that profile.

---

## Driver Reference

<details>
<summary><b>Local Storage</b></summary>

| Field | Description | Example |
| ----- | ----------- | ------- |
| `uploadDir` | Upload directory relative to the Typecho root | `usr/uploads` |
| `urlPrefix` | File URL prefix; leave blank to use the site URL automatically | `https://example.com` |

</details>

<details>
<summary><b>Lsky Pro</b></summary>

| Field | Description |
| ----- | ----------- |
| `server` | Host URL, e.g. `https://pic.example.com` |
| `token` | API Token (without the `Bearer` prefix) |
| `strategy_id` | Storage strategy ID (optional) |
| `album_id` | Album ID (optional) |
| `api_version` | API version: `v1` or `v2` |

</details>

<details>
<summary><b>Alibaba Cloud OSS</b></summary>

| Field | Description | Example |
| ----- | ----------- | ------- |
| `endpoint` | Region endpoint | `oss-cn-hangzhou.aliyuncs.com` |
| `bucket` | Bucket name | `my-bucket` |
| `accessKeyId` | Access Key ID | |
| `accessKeySecret` | Access Key Secret | |
| `prefix` | Path prefix (optional) | `images` |
| `urlPrefix` | Custom CDN domain (optional) | `https://cdn.example.com` |

</details>

<details>
<summary><b>Tencent Cloud COS</b></summary>

| Field | Description | Example |
| ----- | ----------- | ------- |
| `region` | Region | `ap-guangzhou` |
| `bucket` | Bucket name including AppId | `my-bucket-1250000000` |
| `secretId` | SecretId | |
| `secretKey` | SecretKey | |
| `prefix` | Path prefix (optional) | `images` |
| `urlPrefix` | Custom domain (optional) | `https://cdn.example.com` |

</details>

<details>
<summary><b>Qiniu Cloud KODO</b></summary>

| Field | Description |
| ----- | ----------- |
| `accessKey` | Access Key |
| `secretKey` | Secret Key |
| `bucket` | Bucket name |
| `zone` | Storage zone (`z0`=East China, `z1`=North China, `z2`=South China, `na0`=North America, `as0`=Southeast Asia) |
| `urlPrefix` | Bound custom domain (**required** — Qiniu does not provide free test domains) |
| `prefix` | Path prefix (optional) |

</details>

<details>
<summary><b>Upyun USS</b></summary>

| Field | Description |
| ----- | ----------- |
| `service` | Service (bucket) name |
| `operator` | Operator account |
| `password` | Operator password |
| `urlPrefix` | Bound custom domain |
| `prefix` | Path prefix (optional) |

</details>

<details>
<summary><b>GitHub Repository</b></summary>

| Field | Description | Example |
| ----- | ----------- | ------- |
| `token` | Personal Access Token with `repo` scope | |
| `repo` | Repository (`owner/repo`) | `lhl77/images` |
| `branch` | Branch | `main` |
| `prefix` | Path prefix (optional) | `images` |
| `cdn` | CDN acceleration URL (optional) | `https://cdn.jsdelivr.net/gh/lhl77/images` |

</details>

<details>
<summary><b>S3-Compatible (AWS S3 / MinIO / R2 / etc.)</b></summary>

| Field | Description |
| ----- | ----------- |
| `endpoint` | Endpoint URL |
| `region` | Region |
| `bucket` | Bucket name |
| `accessKey` | Access Key |
| `secretKey` | Secret Key |
| `pathStyle` | Use path-style URLs (required for MinIO) |
| `urlPrefix` | Custom domain (optional) |
| `prefix` | Path prefix (optional) |

</details>

<details>
<summary><b>WebDAV</b></summary>

| Field | Description | Example |
| ----- | ----------- | ------- |
| `server` | WebDAV server URL | `https://dav.example.com/path` |
| `username` | Username | |
| `password` | Password | |
| `urlPrefix` | Public-facing domain for file URLs | `https://cdn.example.com` |
| `prefix` | Path prefix (optional) | `images` |

</details>

<details>
<summary><b>NodeImage</b></summary>

| Field | Description |
| ----- | ----------- |
| `api_key` | API Key obtained from the NodeImage admin panel (sent as `X-API-Key` header) |

> **Note:** NodeImage uses Cloudflare as its CDN. Your server's OpenSSL must be ≥ 1.1.0, otherwise the TLS handshake will be rejected.

</details>

<details>
<summary><b>Chevereto V4</b></summary>

| Field | Description |
| ----- | ----------- |
| `server` | Chevereto V4 site URL, e.g. `https://pic.example.com` |
| `api_key` | API v1 Key from **Dashboard → API** in Chevereto admin |
| `album_id` | Album ID (optional) |

</details>

<details>
<summary><b>Imgur</b></summary>

| Field | Description |
| ----- | ----------- |
| `client_id` | Client ID obtained from [Imgur API](https://api.imgur.com/oauth2/addclient) |
| `access_token` | Access Token (optional) — omit for anonymous uploads |
| `album_hash` | Album deletehash (optional, requires `access_token`) |
| `cdn` | CDN replacement domain (optional) — replaces `https://i.imgur.com` |

</details>

<details>
<summary><b>OneImg</b></summary>

| Field | Description |
| ----- | ----------- |
| `server` | Host URL, e.g. `https://img.example.com` |
| `token` | Bearer Token |
| `bucket_id` | Bucket ID (optional) |
| `url_prefix` | URL prefix (optional) — prepended when the host returns a relative path |

</details>

<details>
<summary><b>Telegram ImgBed (tg-imagebed)</b></summary>

| Field | Description |
| ----- | ----------- |
| `server` | Host URL, e.g. `https://img.example.com` |
| `token` | Token (optional) — omit for anonymous uploads with lower rate limits |

</details>

<details>
<summary><b>Zpic</b></summary>

| Field | Description |
| ----- | ----------- |
| `server` | Host domain, e.g. `https://zpic.example.com` |
| `api_version` | API version: `v3` (Bearer Token, recommended) or `v2` (uid + token, ImgURL Pro compatible) |
| `token` | V3 token (format `sk-xxx`) or V2 token |
| `uid` | UID (V2 only) |
| `album_id` | Album ID (optional) |

</details>

---

## Image Processing Reference

### Compression

```json
"compress": {
  "enabled": true,
  "quality": "80"
}
```

`quality` is 1–100. For JPEG/WebP it maps directly to lossy quality; for PNG it maps to compression level as `(100 - quality) / 10`.

### WebP Conversion

```json
"webp": {
  "enabled": true,
  "quality": "85"
}
```

When enabled, JPEG/PNG/GIF files are converted to `.webp` before upload. Requires PHP GD compiled with WebP support (`--with-webp`).

### Watermark

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

| Field | Description |
| ----- | ----------- |
| `type` | `text` (text watermark) or `image` (image watermark) |
| `text` | Watermark text content |
| `font_size` | Font size in pixels |
| `font_color` | Font color in hex |
| `opacity` | Opacity 0–100 |
| `position` | `top-left` / `top-right` / `bottom-left` / `bottom-right` / `center` |
| `font_path` | Path to a TTF font file (required for CJK characters) |
| `image_path` | Path to the watermark image (`type=image` only) |
| `image_scale` | Watermark image width as a percentage of the original image width |

> The plugin bundles Noto Sans for text watermarks. For CJK text watermarks, provide a TTF/OTF font containing CJK characters (e.g. `NotoSansCJK-Regular.ttc`) or rely on a system font that the plugin auto-detects.

---

## Directory Structure

```
PicUp/
├── Plugin.php        # Main plugin file
├── README.md
├── vendor/           # Storage drivers
└── extensions/       # Image processing extensions
```

---

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/lhl77/Typecho-Plugin-PicUp).
