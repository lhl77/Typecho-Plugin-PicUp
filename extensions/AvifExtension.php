<?php

/**
 * PicUp for Typecho - 自动转 AVIF 扩展
 *
 * 在文件上传到云存储前，将 JPEG / PNG / GIF / BMP / WebP 自动转换为 AVIF 格式。
 *
 * 支持 GD 与 Imagick 两种驱动，可在方案配置中选择。
 *
 * @package PicUp
 * @author LHL
 * @version 1.1.0
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
        return '上传前将 JPEG/PNG/GIF/BMP/WebP 转为 AVIF 格式，支持 GD 或 Imagick 驱动。';
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
     * 无硬性 PHP 扩展要求，任一驱动可用即可。
     */
    public static function getRequiredPhpExtensions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     * GD（需 AVIF 支持）或 Imagick（需 AVIF 格式支持）任一可用即为当前环境可用。
     */
    public static function isAvailable(): bool
    {
        return self::isGdAvifAvailable() || self::isImagickAvifAvailable();
    }

    /**
     * 检测 GD 是否支持 AVIF 编码。
     */
    public static function isGdAvifAvailable(): bool
    {
        if (!extension_loaded('gd') || !function_exists('imageavif')) {
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
     * 检测 Imagick 扩展是否可用且支持 AVIF 格式。
     */
    public static function isImagickAvifAvailable(): bool
    {
        return self::isImagickFormatAvailable('AVIF');
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        $driverOptions = [];
        if (self::isGdAvifAvailable()) {
            $driverOptions['gd'] = 'GD';
        }
        if (self::isImagickAvifAvailable()) {
            $driverOptions['imagick'] = 'Imagick';
        }
        if (empty($driverOptions)) {
            $driverOptions['gd'] = 'GD';
        }

        $firstKey = array_key_first($driverOptions);

        return [
            'driver' => [
                'label'       => '转换驱动',
                'type'        => 'select',
                'default'     => $firstKey,
                'description' => 'Imagick 对调色板 PNG 等特殊格式兼容性更好；仅当 PHP Imagick 扩展安装且编译 AVIF 支持时可选。',
                'required'    => false,
                'options'     => $driverOptions,
            ],
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

        $driver = (string)($config['driver'] ?? 'gd');

        // 优先按用户选择的驱动处理；不可用时回退到另一驱动
        if ($driver === 'imagick' && self::isImagickAvifAvailable()) {
            $result = $this->convertWithImagick($localFile, $quality);
            if ($result !== null) {
                return [$result, 'image/avif'];
            }
        }

        if ($driver !== 'imagick' || !self::isImagickAvifAvailable()) {
            if (self::isGdAvifAvailable()) {
                $result = $this->convertWithGd($localFile, $mimeType, $quality);
                if ($result !== null) {
                    return [$result, 'image/avif'];
                }
            }
        }

        // GD 失败且未尝试过 Imagick，最后用 Imagick 兜底
        if ($driver === 'gd' && self::isImagickAvifAvailable()) {
            $result = $this->convertWithImagick($localFile, $quality);
            if ($result !== null) {
                return [$result, 'image/avif'];
            }
        }

        return [$localFile, $mimeType];
    }

    /* ------------------------------------------------------------------ */

    /**
     * 使用 GD 将图片转为 AVIF（含调色板 PNG 转 truecolor 修复）。
     */
    private function convertWithGd(string $localFile, string $mimeType, int $quality): ?string
    {
        if (!self::isGdAvifAvailable()) {
            return null;
        }

        $img = $this->createGdImage($localFile, $mimeType);
        if (!$img) {
            return null;
        }

        // 修复：调色板（索引色）PNG/GIF 在部分 GD 版本下直接
        // imageavif 会输出 0 字节文件，先转为 truecolor 再编码。
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $this->handleTransparency($img);

        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_avif_');
        if (!$tmpFile) {
            imagedestroy($img);
            return null;
        }

        $ok = @imageavif($img, $tmpFile, $quality);
        imagedestroy($img);

        if (!$ok || !is_file($tmpFile) || @filesize($tmpFile) <= 0) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }

    /**
     * 使用 Imagick 将图片转为 AVIF。
     */
    private function convertWithImagick(string $localFile, int $quality): ?string
    {
        if (!self::isImagickAvifAvailable()) {
            return null;
        }

        try {
            if (!class_exists('Imagick')) {
                return null;
            }
            $imagick = new \Imagick($localFile);
            $imagick->setImageFormat('avif');
            $imagick->setImageCompressionQuality($quality);

            if ($imagick->getImageAlphaChannel()) {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            }

            $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_avif_');
            if (!$tmpFile) {
                $imagick->destroy();
                return null;
            }

            $imagick->writeImage($tmpFile);
            $imagick->destroy();

            if (!is_file($tmpFile) || @filesize($tmpFile) <= 0) {
                @unlink($tmpFile);
                return null;
            }

            return $tmpFile;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * 根据 MIME 类型创建 GD 图像资源
     */
    private function createGdImage(string $localFile, string $mimeType)
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

    /* ------------------------------------------------------------------ */

    /**
     * 检测 Imagick 是否支持指定格式
     */
    private static function isImagickFormatAvailable(string $format): bool
    {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return false;
        }
        try {
            $formats = \Imagick::queryFormats();
            return is_array($formats) && in_array(strtoupper($format), $formats, true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}