<?php

/**
 * PicUp for Typecho - 本地存储驱动
 *
 * 遵循 Typecho 原生上传逻辑，将文件存储到服务器本地目录（默认 usr/uploads/）。
 * 适合不需要云存储、只想使用 PicUp 扩展模块（压缩/WebP/水印）的场景。
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class LocalDriver implements DriverInterface
{
    /** @var array 当前配置 */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return '本地存储';
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'uploadDir' => [
                'label'       => '上传目录',
                'type'        => 'text',
                'default'     => 'usr/uploads',
                'description' => '相对于 Typecho 根目录的上传路径，默认 usr/uploads（即 Typecho 原生上传目录）',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => 'URL 前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件访问 URL 前缀，留空则自动使用站点地址（如 https://example.com）。最终 URL = 前缀 + / + 上传目录 + / + 文件路径',
                'required'    => false,
            ],
        ];
    }

    /**
     * 上传文件到本地目录
     *
     * @param string $localFile  本地临时文件路径
     * @param string $remotePath 相对路径（如 2026/03/abc123.jpg）
     * @param string $mimeType   MIME 类型
     * @return string|false 成功返回存储路径（如 usr/uploads/2026/03/abc123.jpg），失败返回 false
     */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $uploadDir = trim($this->config['uploadDir'] ?? 'usr/uploads', '/');
        $rootDir   = rtrim(defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : '', '/');

        // 目标文件完整路径
        $destRelative = $uploadDir . '/' . ltrim($remotePath, '/');
        $destAbsolute = $rootDir . '/' . $destRelative;
        $destDirAbs   = dirname($destAbsolute);

        // 递归创建目录
        if (!is_dir($destDirAbs) && !@mkdir($destDirAbs, 0755, true)) {
            error_log('[PicUp] LocalDriver: 无法创建目录 ' . $destDirAbs);
            return false;
        }

        // 优先使用 move_uploaded_file（正式上传文件），其次用 copy（扩展处理后的临时文件）
        if (is_uploaded_file($localFile)) {
            if (!@move_uploaded_file($localFile, $destAbsolute)) {
                error_log('[PicUp] LocalDriver: move_uploaded_file 失败，src=' . $localFile . '，dest=' . $destAbsolute);
                return false;
            }
        } else {
            if (!@copy($localFile, $destAbsolute)) {
                error_log('[PicUp] LocalDriver: copy 失败，src=' . $localFile . '，dest=' . $destAbsolute);
                return false;
            }
        }

        // 返回存储到数据库的相对路径
        return $destRelative;
    }

    /**
     * 删除本地文件
     *
     * @param string $remotePath 数据库中存储的路径（如 usr/uploads/2026/03/abc123.jpg）
     * @return bool
     */
    public function delete(string $remotePath): bool
    {
        $rootDir      = rtrim(defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : '', '/');
        $absolutePath = $rootDir . '/' . ltrim($remotePath, '/');

        if (!file_exists($absolutePath)) {
            return true; // 文件不存在，视为删除成功
        }

        return @unlink($absolutePath);
    }

    /**
     * 获取公开访问 URL
     *
     * @param string $remotePath 数据库中存储的路径（如 usr/uploads/2026/03/abc123.jpg）
     * @return string
     */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');

        if (empty($urlPrefix)) {
            // 尝试从 Typecho Options 获取站点 URL
            try {
                $siteUrl   = \Widget\Options::alloc()->siteUrl;
                $urlPrefix = rtrim($siteUrl, '/');
            } catch (\Exception $e) {
                $urlPrefix = '';
            }
        }

        return $urlPrefix . '/' . ltrim($remotePath, '/');
    }

    /**
     * {@inheritdoc}
     * 本地驱动直接存储 upload() 返回的相对路径
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // uploadedUrl 即 upload() 返回的相对路径，如 usr/uploads/2026/03/abc.jpg
        return $uploadedUrl;
    }

    /**
     * {@inheritdoc}
     * 本地存储支持路径复用（覆盖写），无需强制新路径
     */
    public function alwaysNewPath(): bool
    {
        return false;
    }
}
