<?php

/**
 * PicUp for Typecho - ImgBB 驱动
 *
 * API 文档：https://api.imgbb.com/
 *   上传：POST https://api.imgbb.com/1/upload?key=<API_KEY>（multipart/form-data）
 *   删除：官方未提供删除接口（删除需登录网页后台）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ImgbbDriver implements DriverInterface
{
    const API_BASE = 'https://api.imgbb.com/1/upload';

    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'ImgBB';
    }

    public static function getConfigFields(): array
    {
        return [
            'key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 https://api.imgbb.com/ 点「Get API key」获取（免费账号即可）',
                'required'    => true,
            ],
            'expiration' => [
                'label'       => '自动过期时间（秒，可选）',
                'type'        => 'number',
                'default'     => '',
                'description' => '留空表示永久保存；填了则必须介于 60 ~ 15552000 之间',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $key = trim((string)($this->config['key'] ?? ''));
        if ($key === '') {
            error_log('[PicUp][Imgbb] 配置不完整：API Key 为空');
            return false;
        }

        $postFields = [
            'image' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];
        if (!empty($this->config['expiration'])) {
            $postFields['expiration'] = (int)$this->config['expiration'];
        }

        $response = $this->curlPost(self::API_BASE . '?key=' . rawurlencode($key), $postFields);
        if ($response === false) {
            error_log('[PicUp][Imgbb] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Imgbb] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if (!empty($data['success']) && !empty($data['data']['url'])) {
            return $data['data']['url'];
        }

        $errMsg = $data['error']['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][Imgbb] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // ImgBB 没有公开的删除 API，只能去网页后台手动删除
        error_log('[PicUp][Imgbb] 该图床不支持 API 删除，请登录 imgbb.com 手动删除');
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'imgbb://') === 0) {
            $parts = explode('|', substr($remotePath, 8), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 没有 id 可用于删除，直接把完整 URL 存进库
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

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
            error_log('[PicUp][Imgbb] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
