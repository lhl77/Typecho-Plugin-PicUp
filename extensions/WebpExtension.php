<?php

/**
 * PicUp for Typecho - 自动转 WebP 扩展
 *
 * 在文件上传到云存储前，将 JPEG / PNG / GIF / BMP 自动转换为 WebP 格式，
 * 有效减小文件体积（通常比 JPEG 小 25–35%）。
 *
 * 依赖：
 * - PHP GD 扩展，且须编译了 WebP 支持（--with-webp 或 --with-webp-dir）
 * - 检测方法：php -r "print_r(gd_info());" 查看 WebP Support 是否为 true
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

class WebpExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return '自动转 WebP';
    }

    /**
     * {@inheritdoc}
     */
    public static function getDescription(): string
    {
        return '上传前将 JPEG/PNG/GIF/BMP 转为 WebP 格式，需 PHP GD 扩展并编译 WebP 支持。';
    }

    /**
     * {@inheritdoc}
     * WebP 转换排在压缩和水印之后执行（order=30），确保先叠加水印再转格式。
     */
    public static function getOrder(): int
    {
        return 30;
    }

    /**
     * {@inheritdoc}
     */
    public static function getRequiredPhpExtensions(): array
    {
        return ['gd'];
    }

    /**
     * {@inheritdoc}
     * 除 GD 外，还需 GD 编译了 WebP 支持（imagewebp 函数存在）。
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        // 检测 WebP 编码支持
        if (!function_exists('imagewebp')) {
            return false;
        }

        // 进一步检查 gd_info()
        if (function_exists('gd_info')) {
            $info = gd_info();
            if (isset($info['WebP Support']) && !$info['WebP Support']) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'quality' => [
                'label'       => '转换质量',
                'type'        => 'number',
                'default'     => '85',
                'description' => 'WebP 输出质量，范围 1–100（默认 85）。值越高文件越大、画质越好。',
                'required'    => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * 将 JPEG/PNG/GIF/BMP 转换为 WebP，返回新临时文件和新 MIME 类型。
     * 已经是 WebP 或不支持的格式则原样返回。
     */
    public function process(string $localFile, string $mimeType, array $config): array
    {
        if (!self::isAvailable()) {
            return [$localFile, $mimeType];
        }

        // 不转换已经是 WebP 的文件
        if ($mimeType === 'image/webp') {
            return [$localFile, $mimeType];
        }

        $quality = isset($config['quality']) ? (int)$config['quality'] : 85;
        $quality = max(1, min(100, $quality));

        $img = $this->createImageResource($localFile, $mimeType);
        if (!$img) {
            return [$localFile, $mimeType];
        }

        // 处理透明通道（PNG/GIF 可能有 alpha）
        $this->handleTransparency($img);

        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_webp_');
        if (!$tmpFile) {
            imagedestroy($img);
            return [$localFile, $mimeType];
        }

        imagewebp($img, $tmpFile, $quality);
        imagedestroy($img);

        return [$tmpFile, 'image/webp'];
    }

    /* ------------------------------------------------------------------ */

    /**
     * 根据 MIME 类型创建 GD 图像资源
     *
     * @param string $localFile
     * @param string $mimeType
     * @return resource|false
     */
    private function createImageResource(string $localFile, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return @imagecreatefromjpeg($localFile);

            case 'image/png':
                $img = @imagecreatefrompng($localFile);
                if ($img) {
                    imagealphablending($img, true);
                    imagesavealpha($img, true);
                }
                return $img;

            case 'image/gif':
                return @imagecreatefromgif($localFile);

            case 'image/bmp':
            case 'image/x-bmp':
                if (function_exists('imagecreatefrombmp')) {
                    return @imagecreatefrombmp($localFile);
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * 对 GIF/PNG 透明通道进行 WebP 兼容处理
     *
     * @param resource $img
     */
    private function handleTransparency($img): void
    {
        // WebP 本身支持 alpha，直接保留即可
        imagealphablending($img, true);
        imagesavealpha($img, true);
    }
}
