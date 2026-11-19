<?php

/**
 * PicUp for Typecho - Cloudinary 驱动
 *
 * API 文档：https://cloudinary.com/documentation/image_upload_api_reference
 *   上传：POST https://api.cloudinary.com/v1_1/<cloud_name>/image/upload
 *   删除：POST https://api.cloudinary.com/v1_1/<cloud_name>/image/destroy
 *
 * 支持两种模式：
 *   1) 签名上传（api_key + api_secret）
 *   2) 免签名上传（upload_preset）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class CloudinaryDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Cloudinary';
    }

    public static function getConfigFields(): array
    {
        return [
            'cloud_name' => [
                'label'       => 'Cloud Name',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Cloudinary 控制台首页的 Cloud name；接口域名形如 https://api.cloudinary.com/v1_1/<cloud_name>',
                'required'    => true,
            ],
            'api_key' => [
                'label'       => 'API Key',
                'type'        => 'text',
                'default'     => '',
                'description' => '控制台 → Account Details 里的 API Key（与 API Secret 配合做签名上传）',
                'required'    => false,
            ],
            'api_secret' => [
                'label'       => 'API Secret',
                'type'        => 'password',
                'default'     => '',
                'description' => '控制台 → Account Details 里的 API Secret；填写后才能删除文件',
                'required'    => false,
            ],
            'upload_preset' => [
                'label'       => '上传预设（免签名时必填）',
                'type'        => 'text',
                'default'     => '',
                'description' => '控制台 → Settings → Upload → Upload presets，需设为 Unsigned。留空则走签名上传',
                'required'    => false,
            ],
            'folder' => [
                'label'       => '目标文件夹（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '如 picup/2026；留空则由 Cloudinary 决定',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $cloud  = trim((string)($this->config['cloud_name'] ?? ''));
        $key    = trim((string)($this->config['api_key'] ?? ''));
        $secret = trim((string)($this->config['api_secret'] ?? ''));
        $preset = trim((string)($this->config['upload_preset'] ?? ''));
        $folder = trim((string)($this->config['folder'] ?? ''));

        if ($cloud === '') {
            error_log('[PicUp][Cloudinary] 配置不完整：cloud_name 为空');
            return false;
        }
        if ($preset === '' && ($key === '' || $secret === '')) {
            error_log('[PicUp][Cloudinary] 配置不完整：请填写 upload_preset，或同时填写 api_key + api_secret');
            return false;
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];
        if ($folder !== '') {
            $postFields['folder'] = $folder;
        }

        if ($preset !== '') {
            $postFields['upload_preset'] = $preset;
        }
        if ($key !== '' && $secret !== '') {
            $timestamp = time();
            $toSign    = ['timestamp' => $timestamp];
            if ($folder !== '') {
                $toSign['folder'] = $folder;
            }
            ksort($toSign);
            $pairs = [];
            foreach ($toSign as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $postFields['api_key']   = $key;
            $postFields['timestamp'] = $timestamp;
            $postFields['signature'] = sha1(implode('&', $pairs) . $secret);
        }

        $url      = 'https://api.cloudinary.com/v1_1/' . rawurlencode($cloud) . '/image/upload';
        $response = $this->curlPost($url, $postFields);
        if ($response === false) {
            error_log('[PicUp][Cloudinary] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Cloudinary] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if (!empty($data['secure_url'])) {
            return $data['secure_url'];
        }
        if (!empty($data['url'])) {
            return $data['url'];
        }

        $errMsg = $data['error']['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][Cloudinary] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        $cloud  = trim((string)($this->config['cloud_name'] ?? ''));
        $key    = trim((string)($this->config['api_key'] ?? ''));
        $secret = trim((string)($this->config['api_secret'] ?? ''));

        if ($cloud === '' || $key === '' || $secret === '') {
            error_log('[PicUp][Cloudinary] 未配置 api_key / api_secret，无法删除');
            return false;
        }

        $publicId = $this->extractPublicId($remotePath);
        if ($publicId === '') {
            error_log('[PicUp][Cloudinary] 无法从存储路径解析 public_id：' . $remotePath);
            return false;
        }

        $timestamp = time();
        $signature = sha1('public_id=' . $publicId . '&timestamp=' . $timestamp . $secret);

        $url      = 'https://api.cloudinary.com/v1_1/' . rawurlencode($cloud) . '/image/destroy';
        $response = $this->curlPost($url, [
            'public_id' => $publicId,
            'api_key'   => $key,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Cloudinary] 删除响应解析失败：' . substr($response, 0, 200));
            return false;
        }
        if (($data['result'] ?? '') === 'ok' || ($data['result'] ?? '') === 'not found') {
            return true;
        }

        error_log('[PicUp][Cloudinary] 删除失败：' . json_encode($data, JSON_UNESCAPED_UNICODE));
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'cloudinary://') === 0) {
            $parts = explode('|', substr($remotePath, 13), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 存 cloudinary://<public_id>|<url>，删除时靠 public_id
        if (!is_string($uploadedUrl) || $uploadedUrl === '') {
            return (string)$uploadedUrl;
        }
        $id = $this->publicIdFromUrl($uploadedUrl);
        if ($id === '') {
            return $uploadedUrl;
        }
        return 'cloudinary://' . $id . '|' . $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    /**
     * 从 https://res.cloudinary.com/<cloud>/image/upload/v123/folder/name.png
     * 里反推出 public_id（folder/name，去掉版本号与扩展名）
     */
    private function publicIdFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (strpos($path, '/image/upload/') === false) {
            return '';
        }
        $rest = substr($path, strpos($path, '/image/upload/') + strlen('/image/upload/'));
        // 去掉开头的 v123456789 版本段
        $rest = preg_replace('#^v\d+/#', '', $rest);
        $rest = preg_replace('#\.[a-zA-Z0-9]+$#', '', $rest);
        return trim($rest, '/');
    }

    private function extractPublicId(string $remotePath): string
    {
        if (strpos($remotePath, 'cloudinary://') === 0) {
            $parts = explode('|', substr($remotePath, 13), 2);
            return (string)($parts[0] ?? '');
        }
        if (preg_match('#^https?://#i', $remotePath)) {
            return $this->publicIdFromUrl($remotePath);
        }
        return $remotePath;
    }

    private function curlPost(string $url, array $postFields)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        if ($errno) {
            error_log('[PicUp][Cloudinary] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
