<?php

/**
 * PicUp for Typecho - 水印扩展
 *
 * 支持两种水印模式：
 * 1. 文字水印：使用 imagettftext()（TTF 字体）或 imagestring()（内置字体，不支持中文）
 * 2. 图片水印：叠加半透明水印图片
 *
 * TTF 字体搜索顺序：
 *  1. 配置中的 font_path 字段
 *  2. 插件 extensions/ 目录下的 font.ttf / font.otf
 *  3. 常见系统字体路径（Linux / macOS / Windows）
 *  4. 降级使用 GD 内置字体（仅支持 ASCII，中文显示为乱码）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

class WatermarkExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return '添加水印';
    }

    /**
     * {@inheritdoc}
     */
    public static function getDescription(): string
    {
        return '在图片上叠加文字或图片水印，支持位置、透明度、字体大小等自定义配置。';
    }

    /**
     * {@inheritdoc}
     * 水印在压缩之后、WebP 转换之前执行。
     */
    public static function getOrder(): int
    {
        return 20;
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
            'type' => [
                'label'       => '水印类型',
                'type'        => 'select',
                'default'     => 'text',
                'options'     => [
                    'text'  => '文字水印',
                    'image' => '图片水印',
                ],
                'description' => '选择水印模式',
                'required'    => false,
            ],
            'text' => [
                'label'       => '水印文字',
                'type'        => 'text',
                'default'     => '',
                'description' => '文字水印内容',
                'required'    => false,
            ],
            'font_size' => [
                'label'       => '字体大小（px）',
                'type'        => 'number',
                'default'     => '16',
                'description' => '水印字体大小，单位像素，默认 16。需要配置 font_path 才生效。',
                'required'    => false,
            ],
            'font_color' => [
                'label'       => '字体颜色',
                'type'        => 'text',
                'default'     => '#ffffff',
                'description' => '十六进制颜色值，如 #ffffff（白色）、#000000（黑色），默认白色。',
                'required'    => false,
            ],
            'font_path' => [
                'label'       => 'TTF 字体路径',
                'type'        => 'text',
                'default'     => '',
                'description' => '服务器上 TTF/OTF 字体文件的绝对路径。中文水印需要含 CJK 字符集的字体。留空则使用插件默认字体(NotoSansCJK)，失败时降级使用 GD 内置字体（不支持中文）。',
                'required'    => false,
            ],
            'opacity' => [
                'label'       => '水印透明度',
                'type'        => 'number',
                'default'     => '80',
                'description' => '范围 0–100：0 为完全透明，100 为完全不透明，默认 80。',
                'required'    => false,
            ],
            'position' => [
                'label'       => '水印位置',
                'type'        => 'select',
                'default'     => 'bottom-right',
                'options'     => [
                    'top-left'     => '左上角',
                    'top-right'    => '右上角',
                    'bottom-left'  => '左下角',
                    'bottom-right' => '右下角',
                    'center'       => '居中',
                ],
                'description' => '水印在图片上的位置，默认右下角。',
                'required'    => false,
            ],
            'margin' => [
                'label'       => '边距（px）',
                'type'        => 'number',
                'default'     => '10',
                'description' => '水印距离图片边缘的像素距离，默认 10。',
                'required'    => false,
            ],
            'image_path' => [
                'label'       => '水印图片路径',
                'type'        => 'text',
                'default'     => '',
                'description' => '图片水印模式下有效。填写服务器上水印图片的绝对路径，支持 PNG（推荐，可带透明通道）、JPEG、GIF。',
                'required'    => false,
            ],
            'image_scale' => [
                'label'       => '水印图片比例（%）',
                'type'        => 'number',
                'default'     => '20',
                'description' => '水印图片宽度占原图宽度的百分比，默认 20%。',
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

        // 仅处理图片格式
        $supported = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $supported, true)) {
            return [$localFile, $mimeType];
        }

        // 加载底图
        $canvas = $this->loadImage($localFile, $mimeType);
        if (!$canvas) {
            return [$localFile, $mimeType];
        }

        $type = $config['type'] ?? 'text';

        if ($type === 'image') {
            $success = $this->applyImageWatermark($canvas, $config);
        } else {
            $success = $this->applyTextWatermark($canvas, $config);
        }

        if (!$success) {
            imagedestroy($canvas);
            return [$localFile, $mimeType];
        }

        // 保存到临时文件
        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_wm_');
        if (!$tmpFile) {
            imagedestroy($canvas);
            return [$localFile, $mimeType];
        }

        $saved = $this->saveImage($canvas, $tmpFile, $mimeType);
        imagedestroy($canvas);

        if (!$saved) {
            @unlink($tmpFile);
            return [$localFile, $mimeType];
        }

        return [$tmpFile, $mimeType];
    }

    /* ------------------------------------------------------------------ */
    /*  文字水印                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 在画布上叠加文字水印
     *
     * @param resource $canvas
     * @param array    $config
     * @return bool
     */
    private function applyTextWatermark($canvas, array $config): bool
    {
        $text      = $config['text']       ?? '© Blog';
        $fontSize  = max(8, (int)($config['font_size']   ?? 16));
        $colorHex  = $config['font_color'] ?? '#ffffff';
        $opacity   = max(0, min(100, (int)($config['opacity'] ?? 80)));
        $position  = $config['position']   ?? 'bottom-right';
        $margin    = max(0, (int)($config['margin'] ?? 10));
        $fontPath  = $config['font_path']  ?? '';

        $imgW = imagesx($canvas);
        $imgH = imagesy($canvas);

        // 解析颜色
        [$r, $g, $b] = $this->parseHexColor($colorHex);

        // GD 的 alpha 值范围 0（不透明）~127（完全透明）
        $gdAlpha = (int)round((100 - $opacity) * 127 / 100);

        $color = imagecolorallocatealpha($canvas, $r, $g, $b, $gdAlpha);
        if ($color === false) {
            error_log('[PicUp][Watermark] imagecolorallocatealpha 失败');
            return false;
        }

        // 尝试使用 TTF 字体；失败则回退到 GD 内置字体
        $font = $this->findFont($fontPath);
        if ($font && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            if ($this->drawTtfText($canvas, $text, $font, $fontSize, $color, $position, $margin, $imgW, $imgH)) {
                return true;
            }
            error_log('[PicUp][Watermark] TTF 绘制失败，回退到 GD 内置字体，font=' . $font);
        }

        // 降级：GD 内置字体（不支持中文，仅 ASCII）
        return $this->drawBuiltinText($canvas, $text, $color, $position, $margin, $imgW, $imgH);
    }

    /**
     * 使用 TTF 字体绘制文字
     */
    private function drawTtfText(
        $canvas,
        string $text,
        string $font,
        int $fontSize,
        int $color,
        string $position,
        int $margin,
        int $imgW,
        int $imgH
    ): bool {
        // 计算文字边框（用 @ 抑制字体加载警告，避免污染 HTTP 响应）
        $bbox = @imagettfbbox($fontSize, 0, $font, $text);
        if (!$bbox) {
            error_log('[PicUp][Watermark] imagettfbbox 返回 false，font=' . $font . '，text=' . $text);
            return false;
        }

        $textW = abs($bbox[2] - $bbox[0]);
        $textH = abs($bbox[7] - $bbox[1]);

        [$x, $y] = $this->calcPosition($position, $margin, $imgW, $imgH, $textW, $textH);

        // TTF 文字的 y 坐标是基线，需要偏移
        $y += $textH;

        imagealphablending($canvas, true);
        $result = @imagettftext($canvas, $fontSize, 0, $x, $y, $color, $font, $text);

        if ($result === false) {
            error_log('[PicUp][Watermark] imagettftext 返回 false，font=' . $font);
            return false;
        }
        return true;
    }

    /**
     * 使用 GD 内置字体绘制文字（不支持中文）
     */
    private function drawBuiltinText(
        $canvas,
        string $text,
        int $color,
        string $position,
        int $margin,
        int $imgW,
        int $imgH
    ): bool {
        $gdFont = 4; // 内置字体 1-5，4 为较大字体
        $charW  = imagefontwidth($gdFont);
        $charH  = imagefontheight($gdFont);
        // 内置字体仅支持 ASCII，中文字节数 != 字符数，取 mb_strlen 避免位置偏差
        $charCount = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $textW  = $charW * $charCount;
        $textH  = $charH;

        [$x, $y] = $this->calcPosition($position, $margin, $imgW, $imgH, $textW, $textH);

        imagealphablending($canvas, true);
        @imagestring($canvas, $gdFont, $x, $y, $text, $color);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  图片水印                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 在画布上叠加图片水印
     *
     * @param resource $canvas
     * @param array    $config
     * @return bool
     */
    private function applyImageWatermark($canvas, array $config): bool
    {
        $imagePath  = $config['image_path']  ?? '';
        $imageScale = max(1, min(100, (int)($config['image_scale'] ?? 20)));
        $opacity    = max(0, min(100, (int)($config['opacity']     ?? 80)));
        $position   = $config['position']    ?? 'bottom-right';
        $margin     = max(0, (int)($config['margin'] ?? 10));

        // 解析水印图片路径
        $wmPath = $this->resolvePath($imagePath);
        if (!$wmPath || !file_exists($wmPath)) {
            return false;
        }

        // 加载水印图片
        $wmMime = $this->getFileMime($wmPath);
        $wm = $this->loadImage($wmPath, $wmMime);
        if (!$wm) {
            return false;
        }

        $imgW = imagesx($canvas);
        $imgH = imagesy($canvas);
        $wmW  = imagesx($wm);
        $wmH  = imagesy($wm);

        // 按比例缩放水印
        $targetW = (int)($imgW * $imageScale / 100);
        $targetH = (int)($wmH * $targetW / $wmW);

        if ($targetW <= 0 || $targetH <= 0) {
            imagedestroy($wm);
            return false;
        }

        // 创建缩放后的水印
        $wmResized = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($wmResized, false);
        imagesavealpha($wmResized, true);
        $transparent = imagecolorallocatealpha($wmResized, 0, 0, 0, 127);
        imagefill($wmResized, 0, 0, $transparent);

        imagecopyresampled($wmResized, $wm, 0, 0, 0, 0, $targetW, $targetH, $wmW, $wmH);
        imagedestroy($wm);

        // 计算位置
        [$x, $y] = $this->calcPosition($position, $margin, $imgW, $imgH, $targetW, $targetH);

        // 叠加水印（带透明度）
        imagealphablending($canvas, true);
        $this->imageCopyMergeAlpha($canvas, $wmResized, $x, $y, 0, 0, $targetW, $targetH, $opacity);
        imagedestroy($wmResized);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  辅助方法                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 计算水印的左上角坐标
     *
     * @return int[] [x, y]
     */
    private function calcPosition(
        string $position,
        int $margin,
        int $imgW,
        int $imgH,
        int $elemW,
        int $elemH
    ): array {
        switch ($position) {
            case 'top-left':
                return [$margin, $margin];
            case 'top-right':
                return [$imgW - $elemW - $margin, $margin];
            case 'bottom-left':
                return [$margin, $imgH - $elemH - $margin];
            case 'center':
                return [(int)(($imgW - $elemW) / 2), (int)(($imgH - $elemH) / 2)];
            case 'bottom-right':
            default:
                return [$imgW - $elemW - $margin, $imgH - $elemH - $margin];
        }
    }

    /**
     * 解析颜色十六进制字符串，返回 [r, g, b]
     *
     * @return int[]
     */
    private function parseHexColor(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * 搜索可用的 TTF 字体文件
     *
     * 搜索顺序：
     * 1. 配置中指定的 font_path
     * 2. extensions/ 目录下的 font.ttf / font.otf / font.ttc
     * 3. 自动下载内置中文字体（WQY Microhei）到 extensions/font.ttc
     * 4. 常见系统字体路径
     */
    private function findFont(string $configPath): ?string
    {
        // 1. 配置中指定的字体路径
        if (!empty($configPath)) {
            $resolved = $this->resolvePath($configPath);
            if ($resolved && file_exists($resolved)) {
                return $resolved;
            }
        }

        // 2. 插件 extensions/ 目录下的 font.ttf / font.otf / font.ttc
        $extDir = __DIR__;
        foreach (['font.ttf', 'font.otf', 'font.ttc'] as $fname) {
            $p = $extDir . '/' . $fname;
            if (file_exists($p)) {
                return $p;
            }
        }

        // 3. 尝试自动下载内置中文字体（文泉驿微米黑）
        $downloaded = $this->downloadBuiltinFont();
        if ($downloaded) {
            return $downloaded;
        }

        // 4. 常见系统字体（优先中文字体）
        $systemFonts = [
            // Linux win-fonts 目录（常见于安装了 Windows 字体包的系统）
            '/usr/share/fonts/win-fonts/simhei.ttf',
            '/usr/share/fonts/win-fonts/STXIHEI.TTF',
            '/usr/share/fonts/win-fonts/simkai.ttf',
            '/usr/share/fonts/win-fonts/simsun.ttc',
            '/usr/share/fonts/win-fonts/msyh.ttc',
            '/usr/share/fonts/windows/simhei.ttf',
            '/usr/share/fonts/windows/simsun.ttc',
            // Linux 中文字体包
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/google-noto/NotoSansSC-Regular.otf',
            // Linux 通用英文
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            // macOS 中文
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/STHeiti Light.ttc',
            '/Library/Fonts/Microsoft/SimHei.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            '/Library/Fonts/Arial.ttf',
            // Windows
            'C:\\Windows\\Fonts\\simhei.ttf',
            'C:\\Windows\\Fonts\\simsun.ttc',
            'C:\\Windows\\Fonts\\msyh.ttc',
            'C:\\Windows\\Fonts\\arial.ttf',
        ];

        foreach ($systemFonts as $fontPath) {
            if (file_exists($fontPath)) {
                return $fontPath;
            }
        }

        return null; // 未找到字体，将使用 GD 内置字体
    }

    /**
     * 自动下载内置中文字体（文泉驿微米黑）到 extensions/font.ttc
     *
     * 下载来源按优先级尝试多个 CDN，下载失败时静默返回 null（不影响上传流程）。
     * 字体大小约 4.5MB，仅首次使用时下载一次。
     *
     * @return string|null 成功返回字体文件路径，失败返回 null
     */
    private function downloadBuiltinFont(): ?string
    {
        $dest = __DIR__ . '/font.ttc';

        // 已下载过
        if (file_exists($dest) && filesize($dest) > 100000) {
            return $dest;
        }

        // extensions/ 目录不可写，放弃
        if (!is_writable(__DIR__)) {
            error_log('[PicUp][Watermark] extensions/ 目录不可写，无法下载内置字体');
            return null;
        }

        // 多 CDN 回退列表（文泉驿微米黑 WQY Microhei ~4.5MB，支持中日韩文字）
        $urls = [
            // jsDelivr via npm wqy-microhei
            'https://cdn.jsdelivr.net/npm/wqy-microhei@0.2.0-beta/fonts/wqy-microhei.ttc',
            // GitHub 官方镜像（SourceForge 上的 GitHub 镜像）
            'https://raw.githubusercontent.com/ousiri/wqy-microhei-lite/master/wqy-microhei.ttc',
            // jsDelivr GitHub 镜像
            'https://cdn.jsdelivr.net/gh/ousiri/wqy-microhei-lite@master/wqy-microhei.ttc',
        ];

        $ctx = stream_context_create([
            'http' => [
                'timeout'  => 30,
                'header'   => "User-Agent: PicUp-Typecho-Plugin/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        foreach ($urls as $url) {
            error_log('[PicUp][Watermark] 尝试下载内置字体：' . $url);
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false && strlen($data) > 100000) {
                if (@file_put_contents($dest, $data) !== false) {
                    error_log('[PicUp][Watermark] 内置字体下载成功：' . $dest . '（' . round(strlen($data) / 1024) . ' KB）');
                    return $dest;
                }
            }
        }

        error_log('[PicUp][Watermark] 内置字体下载失败，所有 CDN 均不可用');
        return null;
    }

    /**
     * 根据 MIME 类型加载 GD 图像资源
     *
     * @return resource|false
     */
    private function loadImage(string $filePath, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return @imagecreatefromjpeg($filePath);

            case 'image/png':
                $img = @imagecreatefrompng($filePath);
                if ($img) {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
                return $img;

            case 'image/gif':
                return @imagecreatefromgif($filePath);

            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($filePath);
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * 保存 GD 图像到文件
     *
     * @param resource $img
     */
    private function saveImage($img, string $destFile, string $mimeType): bool
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return (bool)@imagejpeg($img, $destFile, 95);

            case 'image/png':
                imagesavealpha($img, true);
                imagealphablending($img, false);
                return (bool)@imagepng($img, $destFile, 1);

            case 'image/gif':
                return (bool)@imagegif($img, $destFile);

            case 'image/webp':
                if (function_exists('imagewebp')) {
                    return (bool)@imagewebp($img, $destFile, 90);
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * 获取文件的 MIME 类型（简单判断）
     */
    private function getFileMime(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        return $map[$ext] ?? 'image/jpeg';
    }

    /**
     * 解析路径：支持绝对路径和相对于 Typecho 根目录的路径
     */
    private function resolvePath(string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // 绝对路径
        if (file_exists($path)) {
            return $path;
        }

        // 相对于 Typecho 根目录
        if (defined('__TYPECHO_ROOT_DIR__')) {
            $abs = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . ltrim($path, '/');
            if (file_exists($abs)) {
                return $abs;
            }
        }

        return null;
    }

    /**
     * 带透明度的图片合并，正确支持 PNG 透明通道（imagecopymerge 会丢弃 alpha，不能直接使用）
     *
     * 原理：对 src 中每个像素的 alpha 值叠加全局不透明度后，
     * 通过 imagecopy 合并到 dst——imagecopy 本身遵守逐像素 alpha，
     * 因此可以正确保留水印 PNG 的透明区域。
     *
     * @param resource $dst
     * @param resource $src
     * @param int      $pct 全局不透明度 0-100
     */
    private function imageCopyMergeAlpha($dst, $src, int $dstX, int $dstY, int $srcX, int $srcY, int $srcW, int $srcH, int $pct): void
    {
        if ($pct <= 0) {
            return;
        }

        if ($pct >= 100) {
            // imagecopy 会尊重逐像素 alpha，直接合并即可
            imagealphablending($dst, true);
            imagecopy($dst, $src, $dstX, $dstY, $srcX, $srcY, $srcW, $srcH);
            return;
        }

        // 将 src 复制到临时画布，然后对每个像素的 alpha 叠加全局不透明度
        // GD alpha: 0 = 完全不透明，127 = 完全透明
        $tmp = imagecreatetruecolor($srcW, $srcH);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        // 初始化为完全透明
        $clearColor = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $clearColor);
        // 复制源像素（保留 alpha）
        imagecopy($tmp, $src, 0, 0, $srcX, $srcY, $srcW, $srcH);

        // 对每个像素叠加全局不透明度：
        //   原像素不透明度 = (127 - a) / 127
        //   叠加后不透明度 = 原不透明度 * (pct / 100)
        //   新 alpha       = 127 - round(新不透明度 * 127)
        //                  = a + round((127 - a) * (100 - pct) / 100)
        for ($y = 0; $y < $srcH; $y++) {
            for ($x = 0; $x < $srcW; $x++) {
                $color = imagecolorat($tmp, $x, $y);
                $a = ($color >> 24) & 0x7F;
                // 完全透明像素无需处理
                if ($a >= 127) {
                    continue;
                }
                $r    = ($color >> 16) & 0xFF;
                $g    = ($color >>  8) & 0xFF;
                $b    =  $color        & 0xFF;
                $newA = $a + (int)round((127 - $a) * (100 - $pct) / 100);
                imagesetpixel($tmp, $x, $y, imagecolorallocatealpha($tmp, $r, $g, $b, $newA));
            }
        }

        // imagecopy 遵守逐像素 alpha，正确混合 PNG 透明区域
        imagealphablending($dst, true);
        imagecopy($dst, $tmp, $dstX, $dstY, 0, 0, $srcW, $srcH);
        imagedestroy($tmp);
    }
}
