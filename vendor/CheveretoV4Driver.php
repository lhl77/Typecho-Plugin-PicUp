<?php

/**
 * PicUp for Typecho - Chevereto V4 图床驱动
 *
 * Chevereto V4 API（/api/1/upload）
 * 文档：https://v4-docs.chevereto.com/developer/api/api-v1.html
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class CheveretoV4Driver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Chevereto V4';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '图床地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Chevereto V4 站点地址，如 https://pic.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'api_key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 Chevereto 后台「Dashboard → API」中获取 API v1 Key',
                'required'    => true,
            ],
            'album_id' => [
                'label'       => '相册 ID（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '上传到指定相册，填写相册 ID（字母+数字），留空则不归入相册',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim($this->config['server'] ?? '', '/');
        $apiKey = $this->config['api_key'] ?? '';

        if (empty($server) || empty($apiKey)) {
            error_log('[PicUp][CheveretoV4] 配置不完整：server 或 api_key 为空');
            return false;
        }

        $url = $server . '/api/1/upload';

        $postFields = [
            'source'  => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
            'key'     => $apiKey,
            'format'  => 'json',
        ];

        $albumId = $this->config['album_id'] ?? '';
        if (!empty($albumId)) {
            $postFields['album_id'] = $albumId;
        }

        $response = $this->curlPost($url, $postFields);
        if ($response === false) {
            error_log('[PicUp][CheveretoV4] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][CheveretoV4] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // 成功响应：{"status_code": 200, "image": {"url": "...", "id_encoded": "..."}}
        if (
            ($data['status_code'] ?? 0) === 200
            && !empty($data['image']['url'])
        ) {
            return $data['image']['url'];
        }

        // 兼容 success 格式
        if (!empty($data['success']) && !empty($data['image']['url'])) {
            return $data['image']['url'];
        }

        $errMsg = $data['error']['message'] ?? ($data['status_txt'] ?? '未知错误');
        error_log('[PicUp][CheveretoV4] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // Chevereto V4 API v1 不提供标准删除接口，此处不支持
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    private function curlPost(string $url, array $postFields, array $headers = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);
        return $errno ? false : $response;
    }
}
