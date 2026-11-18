<?php

/**
 * PicUp for Typecho - EXIF 信息抹除扩展
 *
 * 上传前抹除图片里的 EXIF / GPS / XMP / IPTC 等元数据，避免泄露拍摄地点、
 * 设备型号与后期软件信息。
 *
 * 处理方式为**直接改写文件结构**（JPEG 段、PNG 块、WebP RIFF 块），
 * 不重新编码、不损失画质；可单独保留 ICC 色彩配置与方向（Orientation）标签。
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

class ExifExtension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return 'EXIF 信息抹除';
    }

    /**
     * {@inheritdoc}
     */
    public static function getDescription(): string
    {
        return '上传前抹除 EXIF / GPS / XMP / IPTC 元数据，防止泄露拍摄地点与设备信息；'
             . 'JPEG / PNG / WebP 直接改写文件结构，不重新编码、不掉画质。';
    }

    /**
     * {@inheritdoc}
     * 排在压缩（10）之后、水印（20）之前：压缩若发生重编码，元数据本就已被丢掉，
     * 此时本扩展会自动跳过；没开压缩时由它负责无损抹除。
     */
    public static function getOrder(): int
    {
        return 12;
    }

    /**
     * {@inheritdoc}
     * 纯 PHP 字符串处理，不依赖 gd / imagick。
     */
    public static function getRequiredPhpExtensions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'keepOrientation' => [
                'label'       => '保留方向信息',
                'type'        => 'select',
                'default'     => '1',
                'options'     => [
                    '1' => '保留（推荐）',
                    '0' => '一并抹除',
                ],
                'description' => '手机竖拍照片的旋转信息就存在 EXIF 的 Orientation 标签里，抹掉后部分浏览器会把照片显示成横的。保留时只会写入这一个标签，不包含任何隐私信息。',
                'required'    => false,
            ],
            'keepIcc' => [
                'label'       => 'ICC 色彩配置',
                'type'        => 'select',
                'default'     => '1',
                'options'     => [
                    '1' => '保留（推荐）',
                    '0' => '移除',
                ],
                'description' => 'ICC 配置决定色彩还原（Adobe RGB / Display P3 图片尤其重要），一般不建议移除。',
                'required'    => false,
            ],
            'stripIptc' => [
                'label'       => 'IPTC / XMP',
                'type'        => 'select',
                'default'     => '1',
                'options'     => [
                    '1' => '一并移除（推荐）',
                    '0' => '保留',
                ],
                'description' => 'IPTC / XMP 常包含标题、作者、版权与地理位置等描述性元数据。',
                'required'    => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function process(string $localFile, string $mimeType, array $config): array
    {
        if (!is_file($localFile) || !is_readable($localFile)) {
            return [$localFile, $mimeType];
        }

        $keepOrientation = $this->flag($config, 'keepOrientation', true);
        $keepIcc         = $this->flag($config, 'keepIcc', true);
        $stripIptc       = $this->flag($config, 'stripIptc', true);

        $data = @file_get_contents($localFile);
        if ($data === false || $data === '') {
            return [$localFile, $mimeType];
        }

        $result = null;
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $result = $this->stripJpeg($data, $keepIcc, $stripIptc, $keepOrientation);
        } elseif ($mimeType === 'image/png') {
            $result = $this->stripPng($data, $keepIcc, $stripIptc);
        } elseif ($mimeType === 'image/webp') {
            $result = $this->stripWebp($data, $keepIcc, $stripIptc);
        }

        // 没改动（或格式不支持）时原样返回，调用方不会产生额外临时文件
        if ($result === null || $result === $data) {
            return [$localFile, $mimeType];
        }

        $tmpFile = @tempnam(sys_get_temp_dir(), 'picup_exif_');
        if (!$tmpFile) {
            return [$localFile, $mimeType];
        }
        if (@file_put_contents($tmpFile, $result) === false) {
            @unlink($tmpFile);
            return [$localFile, $mimeType];
        }

        return [$tmpFile, $mimeType];
    }

    /* ------------------------------------------------------------------ */
    /*  JPEG：按段（marker segment）重写                                    */
    /* ------------------------------------------------------------------ */

    /**
     * 逐段扫描 JPEG，丢弃携带元数据的 APP 段；需要时再补一个只含 Orientation 的
     * 最小 EXIF 段（放在 SOI 之后），保证竖拍照片方向正确。
     *
     * @return string|null 新数据；无需修改时返回 null
     */
    private function stripJpeg(string $data, bool $keepIcc, bool $stripIptc, bool $keepOrientation): ?string
    {
        $len = strlen($data);
        if ($len < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $out         = "\xFF\xD8";
        $pos         = 2;
        $orientation = 1;
        $changed     = false;

        while ($pos + 1 < $len) {
            if (ord($data[$pos]) !== 0xFF) {
                return null; // 结构异常，保守起见不改
            }

            $marker = ord($data[$pos + 1]);

            // 0xFF 填充字节
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }

            // 无长度字段的独立标记
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($data, $pos, 2);
                $pos += 2;
                continue;
            }

            if ($marker === 0xDA) { // SOS：其后全是熵编码数据，原样搬到结尾
                $out .= substr($data, $pos);
                $pos = $len;
                break;
            }

            if ($marker === 0xD9) { // EOI
                $out .= substr($data, $pos);
                $pos = $len;
                break;
            }

            if ($pos + 4 > $len) {
                return null;
            }

            $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return null;
            }

            $payload = substr($data, $pos + 4, $segLen - 2);
            $segment = substr($data, $pos, $segLen + 2);
            $drop    = false;

            if ($marker === 0xE1) { // APP1 —— Exif / XMP
                if (strncmp($payload, "Exif\x00\x00", 6) === 0) {
                    if ($orientation === 1) {
                        $found = $this->readExifOrientation(substr($payload, 6));
                        if ($found >= 1 && $found <= 8) {
                            $orientation = $found;
                        }
                    }
                    $drop = true;
                } elseif ($stripIptc && (
                    strncmp($payload, "http://ns.adobe.com/xap/1.0/\x00", 29) === 0
                    || strncmp($payload, "http://ns.adobe.com/xmp/extension/\x00", 35) === 0
                )) {
                    $drop = true;
                }
            } elseif ($marker === 0xE2) { // APP2 —— ICC 色彩配置
                if (!$keepIcc && strncmp($payload, "ICC_PROFILE\x00", 12) === 0) {
                    $drop = true;
                }
            } elseif ($marker === 0xED && $stripIptc) { // APP13 —— IPTC / Photoshop 资源
                $drop = true;
            } elseif ($marker === 0xFE && $stripIptc) { // COM —— 注释
                $drop = true;
            }

            if ($drop) {
                $changed = true;
            } else {
                $out .= $segment;
            }

            $pos += $segLen + 2;
        }

        if ($pos < $len) {
            return null; // 没有正常走到 SOS / EOI，放弃
        }

        if (!$changed) {
            return null;
        }

        if ($keepOrientation && $orientation > 1) {
            // 丢掉 EXIF 会连带丢掉旋转标记 → 补回一个 34 字节的最小 EXIF 段
            $out = "\xFF\xD8" . $this->buildOrientationApp1($orientation) . substr($out, 2);
        }

        return $out;
    }

    /**
     * 读取 EXIF（TIFF 结构）中的 Orientation 标签值。
     *
     * @return int 1–8；读不到时返回 1
     */
    private function readExifOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $byteOrder = substr($tiff, 0, 2);
        if ($byteOrder === 'II') {
            $little = true;
        } elseif ($byteOrder === 'MM') {
            $little = false;
        } else {
            return 1;
        }

        $ifdOffset = $this->readUint32($tiff, 4, $little);
        if ($ifdOffset <= 0 || $ifdOffset + 2 > strlen($tiff)) {
            return 1;
        }

        $count = $this->readUint16($tiff, $ifdOffset, $little);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifdOffset + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if ($this->readUint16($tiff, $entry, $little) !== 0x0112) {
                continue;
            }
            // type=3(SHORT) 时值内联在偏移字段的前 2 字节
            if ($this->readUint16($tiff, $entry + 2, $little) === 3) {
                $value = $this->readUint16($tiff, $entry + 8, $little);
                if ($value >= 1 && $value <= 8) {
                    return $value;
                }
            }
            break;
        }

        return 1;
    }

    /**
     * 构造只含 Orientation 一个标签的最小 EXIF APP1 段。
     */
    private function buildOrientationApp1(int $orientation): string
    {
        $tiff  = 'II' . pack('v', 42) . pack('V', 8);        // 小端 TIFF 头，IFD0 偏移 8
        $tiff .= pack('v', 1);                               // 1 个条目
        $tiff .= pack('v', 0x0112) . pack('v', 3) . pack('V', 1)
               . pack('v', $orientation) . pack('v', 0);     // tag / type=SHORT / count / 值 + 填充
        $tiff .= pack('V', 0);                               // 没有下一个 IFD

        $payload = "Exif\x00\x00" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    private function readUint16(string $s, int $offset, bool $little): int
    {
        if ($offset + 2 > strlen($s)) {
            return 0;
        }
        $pair = substr($s, $offset, 2);

        return $little ? unpack('v', $pair)[1] : unpack('n', $pair)[1];
    }

    private function readUint32(string $s, int $offset, bool $little): int
    {
        if ($offset + 4 > strlen($s)) {
            return 0;
        }
        $quad = substr($s, $offset, 4);

        return $little ? unpack('V', $quad)[1] : unpack('N', $quad)[1];
    }

    /* ------------------------------------------------------------------ */
    /*  PNG：按块（chunk）重写                                              */
    /* ------------------------------------------------------------------ */

    /**
     * 丢弃 eXIf / 文本块 / iCCP（可选），其余块整块复制（CRC 保持不变）。
     *
     * @return string|null
     */
    private function stripPng(string $data, bool $keepIcc, bool $stripIptc): ?string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (strncmp($data, $signature, 8) !== 0) {
            return null;
        }

        $out     = $signature;
        $pos     = 8;
        $len     = strlen($data);
        $changed = false;

        while ($pos + 8 <= $len) {
            $chunkLen = unpack('N', substr($data, $pos, 4))[1];
            $type     = substr($data, $pos + 4, 4);
            $total    = 12 + $chunkLen;
            if ($chunkLen < 0 || $pos + $total > $len) {
                return null;
            }

            $drop = false;
            if ($type === 'eXIf') {
                $drop = true;
            } elseif ($stripIptc && ($type === 'tEXt' || $type === 'zTXt' || $type === 'iTXt')) {
                $drop = true;
            } elseif (!$keepIcc && $type === 'iCCP') {
                $drop = true;
            }

            if ($drop) {
                $changed = true;
            } else {
                $out .= substr($data, $pos, $total);
            }

            $pos += $total;
            if ($type === 'IEND') {
                break;
            }
        }

        return $changed ? $out : null;
    }

    /* ------------------------------------------------------------------ */
    /*  WebP：按 RIFF 块重写                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 丢弃 EXIF / XMP / ICCP（可选）块，并同步更新 RIFF 长度与 VP8X 标志位。
     *
     * @return string|null
     */
    private function stripWebp(string $data, bool $keepIcc, bool $stripIptc): ?string
    {
        $len = strlen($data);
        if ($len < 12 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
            return null;
        }

        $pos        = 12;
        $body       = '';
        $changed    = false;
        $flagsIndex = null;

        while ($pos + 8 <= $len) {
            $fourcc  = substr($data, $pos, 4);
            $size    = unpack('V', substr($data, $pos + 4, 4))[1];
            $total   = 8 + $size + ($size % 2);   // 块数据按偶数长度对齐
            if ($pos + $total > $len) {
                return null;
            }
            $chunk = substr($data, $pos, $total);

            if ($fourcc === 'EXIF') {
                $changed = true;
            } elseif ($fourcc === 'XMP ' && $stripIptc) {
                $changed = true;
            } elseif ($fourcc === 'ICCP' && !$keepIcc) {
                $changed = true;
            } else {
                if ($fourcc === 'VP8X') {
                    $flagsIndex = strlen($body) + 8; // 块头 8 字节之后就是标志位
                }
                $body .= $chunk;
            }

            $pos += $total;
        }

        if (!$changed) {
            return null;
        }

        if ($flagsIndex !== null && $flagsIndex < strlen($body)) {
            $flags = ord($body[$flagsIndex]);
            $flags &= ~0x08;            // E: EXIF
            if ($stripIptc) {
                $flags &= ~0x04;        // X: XMP
            }
            if (!$keepIcc) {
                $flags &= ~0x20;        // I: ICC
            }
            $body[$flagsIndex] = chr($flags);
        }

        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }

    /* ------------------------------------------------------------------ */

    /**
     * 读取配置里的布尔开关（支持 '1' / 'true' / true，缺省走默认值）。
     */
    private function flag(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
