<div align="center">

# PicUp for Typecho

**多存储后端图片/附件上传插件，支持多种云存储服务，多 Profile 配置，图像处理扩展。**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb3?logo=php&logoColor=white)](https://www.php.net/)
[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-4a90d9)](https://typecho.org/)
[![GitHub release](https://img.shields.io/github/v/release/lhl77/Typecho-Plugin-PicUp?color=brightgreen&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/releases)
[![License](https://img.shields.io/github/license/lhl77/Typecho-Plugin-PicUp?color=blue)](LICENSE)
[![Stars](https://img.shields.io/github/stars/lhl77/Typecho-Plugin-PicUp?style=flat&logo=github)](https://github.com/lhl77/Typecho-Plugin-PicUp/stargazers)

**作者：[LHL](https://blog.lhl.one)　|　[📖 使用文档](https://blog.lhl.one/artical/1026.html)　|　[GitHub](https://github.com/lhl77/Typecho-Plugin-PicUp)**

</div>

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

启用插件后，进入 **控制台 → 插件管理 → PicUp → 设置**，详细配置说明见 [使用文档](https://blog.lhl.one/artical/1026.html)。

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

## 🔌 插件开发指南

> 所有驱动和扩展均**自动发现**——按规范命名放入对应目录即可，无需修改任何注册代码。

### 开发存储驱动

在 `vendor/` 目录新建 `XxxDriver.php`，实现 `DriverInterface` 接口。文件名规则：`大驼峰名称 + Driver.php`，插件以文件名去掉 `Driver` 后缀转小写作为驱动标识符（如 `MyS3Driver.php` → `mys3`）。

```php
<?php
namespace TypechoPlugin\PicUp\vendor;

class XxxDriver implements DriverInterface
{
    /** 驱动显示名称（后台设置中展示） */
    public static function getName(): string
    {
        return '我的存储服务';
    }

    /**
     * 驱动配置字段定义
     * type 支持：text | password | select
     * select 类型需提供 options: [['value'=>'v1','label'=>'V1'], ...]
     */
    public static function getConfigFields(): array
    {
        return [
            ['name' => 'endpoint',  'label' => '服务地址',    'type' => 'text',     'default' => ''],
            ['name' => 'bucket',    'label' => 'Bucket 名称', 'type' => 'text',     'default' => ''],
            ['name' => 'accessKey', 'label' => 'Access Key',  'type' => 'password', 'default' => ''],
            ['name' => 'secretKey', 'label' => 'Secret Key',  'type' => 'password', 'default' => ''],
            ['name' => 'region',    'label' => '地域',        'type' => 'select',   'default' => 'cn-east',
             'options' => [['value' => 'cn-east', 'label' => '华东'], ['value' => 'cn-north', 'label' => '华北']]],
        ];
    }

    public function __construct(array $config)
    {
        // 从 $config 读取配置字段，初始化 SDK / HTTP 客户端
        $this->endpoint  = rtrim($config['endpoint'] ?? '', '/');
        $this->bucket    = $config['bucket'] ?? '';
        $this->accessKey = $config['accessKey'] ?? '';
        $this->secretKey = $config['secretKey'] ?? '';
    }

    /**
     * 上传文件
     * @param string $localFile  本地临时文件绝对路径
     * @param string $remotePath 目标存储路径（含文件名），如 2024/01/photo.jpg
     * @param string $mimeType   文件 MIME 类型
     * @return string|false      成功返回可访问的文件 URL，失败返回 false
     */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        // 调用存储服务 SDK / HTTP API 上传文件
        // 失败时 return false，插件会自动弹出错误提示
        return 'https://cdn.example.com/' . $remotePath;
    }

    /**
     * 删除文件
     * @param string $remotePath 存储路径（由 getStoredPath() 返回的值）
     */
    public function delete(string $remotePath): bool
    {
        return true;
    }

    /**
     * 根据存储路径构造可访问的 URL
     */
    public function getUrl(string $remotePath): string
    {
        return 'https://cdn.example.com/' . $remotePath;
    }

    /**
     * 从上传结果反推存储路径，用于后续删除文件
     * 若 URL 即为存储路径，直接返回 $remotePath 即可
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $remotePath;
    }

    /**
     * 是否每次上传都生成新路径（某些图床会自动重命名）
     * 返回 true 时，插件不会复用已有路径
     */
    public function alwaysNewPath(): bool
    {
        return false;
    }
}
```

### 开发图像处理扩展

在 `extensions/` 目录新建 `XxxExtension.php`，实现 `ExtensionInterface` 接口。扩展标识符为文件名去掉 `Extension` 后缀转小写。

```php
<?php
namespace TypechoPlugin\PicUp\extensions;

class XxxExtension implements ExtensionInterface
{
    public static function getName(): string        { return '我的扩展'; }
    public static function getDescription(): string { return '对上传图片做 XX 处理'; }

    /**
     * 执行顺序，数字越小越先执行
     * 内置顺序参考：compress=10, webp=20, watermark=30
     */
    public static function getOrder(): int { return 50; }

    /** 依赖的 PHP 扩展，缺失时在后台显示"不可用"警告 */
    public static function getRequiredPhpExtensions(): array { return ['gd']; }
    public static function isAvailable(): bool { return extension_loaded('gd'); }

    /** 配置字段（格式同驱动配置字段） */
    public static function getConfigFields(): array
    {
        return [
            ['name' => 'quality', 'label' => '处理质量', 'type' => 'text', 'default' => '80'],
        ];
    }

    /**
     * 处理文件（核心方法）
     *
     * @param string $localFile 输入文件路径（只读，不要直接修改原文件）
     * @param string $mimeType  输入文件 MIME 类型
     * @param array  $config    当前扩展的配置项（来自方案的 _extensions.xxx）
     * @return array            [输出文件路径, 输出MIME类型]
     *                          若输出了新的临时文件，插件核心会统一清理
     */
    public function process(string $localFile, string $mimeType, array $config): array
    {
        // 创建临时文件承载处理结果
        $tmpFile = tempnam(sys_get_temp_dir(), 'picup_');

        // 示例：用 GD 处理图像
        $img = imagecreatefromjpeg($localFile);
        // ... 处理逻辑 ...
        imagejpeg($img, $tmpFile, (int)($config['quality'] ?? 80));
        imagedestroy($img);

        return [$tmpFile, $mimeType];
    }
}
```

#### 开发注意事项

- `process()` 接收的 `$localFile` 是**只读**的，输出结果请写入新临时文件后返回新路径。
- 多个扩展按 `getOrder()` 串行执行，上一个扩展的输出是下一个扩展的输入。
- 临时文件由插件核心在上传完成后统一清理，无需在扩展内手动 `unlink`。
- 错误建议通过 `error_log('[PicUp][XxxExtension] ...')` 记录，不要直接 `echo`（会污染上传响应）。

---

## 🖼️ 图床 SDK 接入指南

本节面向希望将自有图床服务接入 PicUp 或为第三方图床编写驱动的开发者。

### 接口规范

驱动须实现 `vendor/DriverInterface.php` 中定义的所有方法：

```php
interface DriverInterface
{
    // 元信息（静态）
    public static function getName(): string;
    public static function getConfigFields(): array;

    // 构造（接收解析后的配置数组）
    public function __construct(array $config);

    // 核心操作
    public function upload(string $localFile, string $remotePath, string $mimeType): string|false;
    public function delete(string $remotePath): bool;
    public function getUrl(string $remotePath): string;
    public function getStoredPath(string $remotePath, string $uploadedUrl): string;
    public function alwaysNewPath(): bool;
}
```

### 上传调用流程

```
用户上传文件
     │
     ▼
Plugin::uploadHandle()
     │
     ├─► 文件安全检查（扩展名白名单）
     │
     ├─► applyExtensions()       // 依次调用各 Extension::process()
     │       compress(10) → webp(20) → watermark(30) → 自定义(50) → ...
     │
     ├─► 实例化活跃方案的 Driver（传入方案配置数组）
     │
     ├─► Driver::upload($localFile, $remotePath, $mimeType)
     │       └─ 返回文件 URL（失败返回 false）
     │
     └─► 清理临时文件，返回 Typecho 附件数组
```

### `getConfigFields()` 字段属性说明

| 属性 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | ✅ | 字段 key，`$config['name']` 读取 |
| `label` | string | ✅ | 前端显示标签 |
| `type` | string | ✅ | `text` \| `password` \| `select` |
| `default` | string | ✅ | 默认值 |
| `options` | array | select 时必填 | `[['value'=>'v1','label'=>'V1'], ...]` |
| `placeholder` | string | ❌ | 输入框占位符 |

### 调试技巧

- 驱动内部错误统一使用 `error_log('[PicUp][XxxDriver] 说明 ' . $detail)` 记录。
- `upload()` 返回 `false` 时，插件自动弹出错误 Toast，无需驱动内部处理 UI。
- `__construct()` 中可做配置校验，抛出 `\Exception` 时插件会捕获并写入日志。
- 开发时可临时在 `upload()` 里加 `file_put_contents('/tmp/picup_debug.log', ...)` 辅助调试。

---

## 🤝 贡献

欢迎提交 [Issue](https://github.com/lhl77/Typecho-Plugin-PicUp/issues) 或 [Pull Request](https://github.com/lhl77/Typecho-Plugin-PicUp/pulls)。
