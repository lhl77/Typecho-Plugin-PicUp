<?php

/**
 * PicUp for Typecho - 自动转 AVIF 扩展
 *
 * 在文件上传到云存储前，将 JPEG / PNG / GIF / BMP / WebP 自动转换为 AVIF 格式。
 *
 * 依赖：
 * - PHP GD 扩展，且须编译了 AVIF 支持
 * - 检测方法：php -r "print_r(gd_info());" 查看 AVIF Support 是否为 true
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

class AvifExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return '自动转 AVIF';
    }

    /**
     * {@inheritdoc}
     */
    public static function getDescription(): string
    {
        return '上传前将 JPEG/PNG/GIF/BMP/WebP 转为 AVIF 格式，需 PHP GD 扩展并编译 AVIF 支持。';
    }

    /**
     * {@inheritdoc}
     * AVIF 转换排在水印之后、WebP 之前执行（order=25）。
     */
    public static function getOrder(): int
    {
        return 25;
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
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        if (!function_exists('imageavif')) {
            return false;
        }

        if (function_exists('gd_info')) {
            $info = gd_info();
            if (isset($info['AVIF Support']) && !$info['AVIF Support']) {
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
                'default'     => '60',
                'description' => 'AVIF 输出质量，范围 1–100（默认 60）。值越高文件越大、画质越好。',
                'required'    => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(string $localFile, string $mimeType, array $config): array
    {
        if (!self::isAvailable()) {
            return [$localFile, $mimeType];
        }

        if ($mimeType === 'image/avif') {
            return [$localFile, $mimeType];
        }

        $quality = isset($config['quality']) ? (int)$config['quality'] : 60;
        $quality = max(1, min(100, $quality));

        $img = $this->createImageResource($localFile, $mimeType);
        if (!$img) {
            return [$localFile, $mimeType];
        }

        $this->handleTransparency($img);

        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_avif_');
        if (!$tmpFile) {
            imagedestroy($img);
            return [$localFile, $mimeType];
        }

        $saved = @imageavif($img, $tmpFile, $quality);
        imagedestroy($img);

        if (!$saved || !is_file($tmpFile) || @filesize($tmpFile) <= 0) {
            @unlink($tmpFile);
            return [$localFile, $mimeType];
        }

        return [$tmpFile, 'image/avif'];
    }

    /**
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

            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($localFile);
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * @param resource $img
     */
    private function handleTransparency($img): void
    {
        imagealphablending($img, true);
        imagesavealpha($img, true);
    }
}