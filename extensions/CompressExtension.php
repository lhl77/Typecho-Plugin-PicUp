<?php

/**
 * PicUp for Typecho - 图片压缩扩展
 *
 * 使用 PHP GD 扩展对上传图片进行压缩处理：
 * - JPEG：有损压缩，quality 即 JPEG 质量值（1-100）
 * - PNG：无损压缩，quality 换算为 GD 压缩级别 0-9（quality=100→level=0，quality=0→level=9）
 * - WebP：有损压缩（需 GD 编译 WebP 支持）
 * - 其他格式：直接跳过，不做处理
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

class CompressExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return '图片压缩';
    }

    /**
     * {@inheritdoc}
     */
    public static function getDescription(): string
    {
        return '上传前压缩图片体积，可自定义 JPEG/WebP 质量（1-100）或 PNG 压缩级别。';
    }

    /**
     * {@inheritdoc}
     * 压缩最先执行，方便后续扩展在已压缩图上叠加水印或转格式。
     */
    public static function getOrder(): int
    {
        return 10;
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
        return extension_loaded('gd');
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'quality' => [
                'label'       => '压缩质量',
                'type'        => 'number',
                'default'     => '80',
                'description' => '质量范围 1–100（默认 80）。JPEG/WebP 为有损质量；PNG 为压缩换算，值越高文件越大但质量越好。',
                'required'    => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * 处理 JPEG / PNG / WebP，其他格式原样返回。
     */
    public function process(string $localFile, string $mimeType, array $config): array
    {
        if (!self::isAvailable()) {
            return [$localFile, $mimeType];
        }

        $quality = isset($config['quality']) ? (int)$config['quality'] : 80;
        $quality = max(1, min(100, $quality));

        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return $this->processJpeg($localFile, $mimeType, $quality);

            case 'image/png':
                return $this->processPng($localFile, $mimeType, $quality);

            case 'image/webp':
                return $this->processWebp($localFile, $mimeType, $quality);

            default:
                return [$localFile, $mimeType];
        }
    }

    /* ------------------------------------------------------------------ */

    private function processJpeg(string $localFile, string $mimeType, int $quality): array
    {
        $img = @imagecreatefromjpeg($localFile);
        if (!$img) {
            return [$localFile, $mimeType];
        }

        $tmpFile = $this->createTempFile();
        if (!$tmpFile) {
            imagedestroy($img);
            return [$localFile, $mimeType];
        }

        imagejpeg($img, $tmpFile, $quality);
        imagedestroy($img);

        return [$tmpFile, $mimeType];
    }

    private function processPng(string $localFile, string $mimeType, int $quality): array
    {
        $img = @imagecreatefrompng($localFile);
        if (!$img) {
            return [$localFile, $mimeType];
        }

        // 保留 Alpha 通道
        imagesavealpha($img, true);

        $tmpFile = $this->createTempFile();
        if (!$tmpFile) {
            imagedestroy($img);
            return [$localFile, $mimeType];
        }

        // quality=100 → compression=0（不压缩），quality=0 → compression=9（最大压缩）
        $compression = (int)round((100 - $quality) * 9 / 100);
        $compression = max(0, min(9, $compression));

        imagepng($img, $tmpFile, $compression);
        imagedestroy($img);

        return [$tmpFile, $mimeType];
    }

    private function processWebp(string $localFile, string $mimeType, int $quality): array
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            return [$localFile, $mimeType];
        }

        $img = @imagecreatefromwebp($localFile);
        if (!$img) {
            return [$localFile, $mimeType];
        }

        $tmpFile = $this->createTempFile();
        if (!$tmpFile) {
            imagedestroy($img);
            return [$localFile, $mimeType];
        }

        imagewebp($img, $tmpFile, $quality);
        imagedestroy($img);

        return [$tmpFile, $mimeType];
    }

    private function createTempFile(): ?string
    {
        $f = @tempnam(sys_get_temp_dir(), 'picup_cmp_');
        return $f ?: null;
    }
}
