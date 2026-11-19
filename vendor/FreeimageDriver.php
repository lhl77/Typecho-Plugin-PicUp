<?php

/**
 * PicUp for Typecho - FreeImage.host 驱动
 *
 * API 文档：https://freeimage.host/page/api
 *   上传：POST https://freeimage.host/api/1/upload
 *         字段：key / action=upload / format=json / source（文件或 base64）
 *   响应：{"status_code":200,"image":{"url":"...","display_url":"..."}}
 *   删除：官方未提供删除接口
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class FreeimageDriver implements DriverInterface
{
    const API_BASE = 'https://freeimage.host/api/1/upload';

    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'FreeImage.host';
    }

    public static function getConfigFields(): array
    {
        return [
            'key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '登录 freeimage.host 后到 https://freeimage.host/page/api 获取',
                'required'    => true,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $key = trim((string)($this->config['key'] ?? ''));
        if ($key === '') {
            error_log('[PicUp][FreeImage] 配置不完整：API Key 为空');
            return false;
        }

        $postFields = [
            'key'    => $key,
            'action' => 'upload',
            'format' => 'json',
            'source' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $response = $this->curlPost(self::API_BASE, $postFields);
        if ($response === false) {
            error_log('[PicUp][FreeImage] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][FreeImage] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if ((int)($data['status_code'] ?? 0) === 200 && !empty($data['image']['url'])) {
            return $data['image']['url'];
        }

        $errMsg = $data['error']['message']
            ?? $data['status_txt']
            ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][FreeImage] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        error_log('[PicUp][FreeImage] 该图床不提供公开的删除 API，请登录 freeimage.host 手动删除');
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return (string)$uploadedUrl;
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
            error_log('[PicUp][FreeImage] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
