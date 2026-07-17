<?php

/**
 * PicUp for Typecho - 自动转 WebP 扩展
 *
 * 在文件上传到云存储前，将 JPEG / PNG / GIF / BMP 自动转换为 WebP 格式，
 * 有效减小文件体积（通常比 JPEG 小 25–35%）。
 *
 * 支持 GD 与 Imagick 两种驱动，可在方案配置中选择。
 *
 * @package PicUp
 * @author LHL
 * @version 1.1.0
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
        return '上传前将 JPEG/PNG/GIF/BMP 转为 WebP 格式，支持 GD 或 Imagick 驱动。';
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
     * 无硬性 PHP 扩展要求，任一驱动可用即可。
     */
    public static function getRequiredPhpExtensions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     * GD（需 WebP 支持）或 Imagick（需 WEBP 格式支持）任一可用即为当前环境可用。
     */
    public static function isAvailable(): bool
    {
        return self::isGdWebpAvailable() || self::isImagickWebpAvailable();
    }

    /**
     * 检测 GD 是否支持 WebP 编码。
     */
    public static function isGdWebpAvailable(): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return false;
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            if (isset($info['WebP Support']) && !$info['WebP Support']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 检测 Imagick 扩展是否可用且支持 WebP 格式。
     */
    public static function isImagickWebpAvailable(): bool
    {
        return self::isImagickFormatAvailable('WEBP');
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        $driverOptions = [];
        if (self::isGdWebpAvailable()) {
            $driverOptions['gd'] = 'GD';
        }
        if (self::isImagickWebpAvailable()) {
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
                'description' => 'Imagick 对调色板 PNG 等特殊格式兼容性更好；仅当 PHP Imagick 扩展安装且编译 WebP 支持时可选。',
                'required'    => false,
                'options'     => $driverOptions,
            ],
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

        $driver = (string)($config['driver'] ?? 'gd');

        // 优先按用户选择的驱动处理；不可用时回退到另一驱动
        if ($driver === 'imagick' && self::isImagickWebpAvailable()) {
            $result = $this->convertWithImagick($localFile, $quality);
            if ($result !== null) {
                return [$result, 'image/webp'];
            }
        }

        if ($driver !== 'imagick' || !self::isImagickWebpAvailable()) {
            if (self::isGdWebpAvailable()) {
                $result = $this->convertWithGd($localFile, $mimeType, $quality);
                if ($result !== null) {
                    return [$result, 'image/webp'];
                }
            }
        }

        // GD 失败且未尝试过 Imagick，最后用 Imagick 兜底
        if ($driver === 'gd' && self::isImagickWebpAvailable()) {
            $result = $this->convertWithImagick($localFile, $quality);
            if ($result !== null) {
                return [$result, 'image/webp'];
            }
        }

        return [$localFile, $mimeType];
    }

    /* ------------------------------------------------------------------ */

    /**
     * 使用 GD 将图片转为 WebP（含调色板 PNG 转 truecolor 修复）。
     */
    private function convertWithGd(string $localFile, string $mimeType, int $quality): ?string
    {
        if (!self::isGdWebpAvailable()) {
            return null;
        }

        $img = $this->createGdImage($localFile, $mimeType);
        if (!$img) {
            return null;
        }

        // 修复：调色板（索引色）PNG/GIF 在部分 GD 版本下直接
        // imagewebp 会输出 0 字节文件，先转为 truecolor 再编码。
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $this->handleTransparency($img);

        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_webp_');
        if (!$tmpFile) {
            imagedestroy($img);
            return null;
        }

        $ok = @imagewebp($img, $tmpFile, $quality);
        imagedestroy($img);

        if (!$ok || !is_file($tmpFile) || @filesize($tmpFile) <= 0) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }

    /**
     * 使用 Imagick 将图片转为 WebP。
     */
    private function convertWithImagick(string $localFile, int $quality): ?string
    {
        if (!self::isImagickWebpAvailable()) {
            return null;
        }

        try {
            if (!class_exists('Imagick')) {
                return null;
            }
            $imagick = new \Imagick($localFile);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);

            if ($imagick->getImageAlphaChannel()) {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            }

            $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_webp_');
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

            default:
                return false;
        }
    }

    /**
     * 对 GIF/PNG 透明通道进行 WebP 兼容处理
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
