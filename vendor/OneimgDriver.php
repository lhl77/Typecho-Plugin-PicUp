<?php

/**
 * PicUp for Typecho - 初春图床 (OneImg) 驱动
 *
 * API 文档：
 *   上传：POST /api/upload/images （multipart/form-data，Bearer Token）
 *   删除：DELETE /api/images/:id  （Bearer Token）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class OneimgDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return '初春图床 (OneImg)';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '图床地址',
                'type'        => 'text',
                'default'     => '',
                'description' => '初春图床站点地址，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'Bearer Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '在图床后台获取的 API Token',
                'required'    => true,
            ],
            'bucket_id' => [
                'label'       => '存储桶 ID（可选）',
                'type'        => 'number',
                'default'     => '',
                'description' => '指定存储桶 ID，留空使用默认存储桶',
                'required'    => false,
            ],
            'url_prefix' => [
                'label'       => '自定义 URL 前缀（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '当图床返回相对路径（如 /uploads/...）时，在此填写站点域名，如 https://img.example.com，将与相对路径拼接为完整 URL',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim($this->config['server'] ?? '', '/');
        $token  = $this->config['token'] ?? '';

        if (empty($server) || empty($token)) {
            error_log('[PicUp][Oneimg] 配置不完整：server 或 token 为空');
            return false;
        }

        $url = $server . '/api/upload/images';

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $bucketId = $this->config['bucket_id'] ?? '';
        if ($bucketId !== '' && $bucketId !== null) {
            $postFields['bucket_id'] = (int)$bucketId;
        }

        $headers = [
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->curlPost($url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][Oneimg] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Oneimg] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // 响应结构：{"code": 200, "data": {"files": [{"success": true, "url": "...", ...}]}}
        if (
            ($data['code'] ?? 0) === 200
            && !empty($data['data']['files'][0]['success'])
            && !empty($data['data']['files'][0]['url'])
        ) {
            $imgUrl = $data['data']['files'][0]['url'];
            return $this->buildFullUrl($imgUrl);
        }

        $errMsg = $data['message'] ?? json_encode($data);
        error_log('[PicUp][Oneimg] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // remotePath 格式：oneimg://<id>|<url>
        $imgId = $this->extractId($remotePath);
        if (empty($imgId)) {
            return false;
        }

        $server = rtrim($this->config['server'] ?? '', '/');
        $token  = $this->config['token'] ?? '';

        if (empty($server) || empty($token)) {
            return false;
        }

        $url     = $server . '/api/images/' . $imgId;
        $headers = [
            'Authorization: Bearer ' . $token,
        ];

        $resp = $this->curlRequest('DELETE', $url, null, $headers);
        $data = json_decode($resp, true);
        return is_array($data) && ($data['code'] ?? 0) === 200;
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'oneimg://') === 0) {
            $parts = explode('|', substr($remotePath, 9), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 上传响应中没有直接返回 id，但 url 格式通常包含文件名
        // 这里存为 oneimg://<url>，删除功能依赖图片 id（如需删除需从 URL 反推）
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    /**
     * 处理相对路径，拼接为完整 URL
     */
    private function buildFullUrl(string $url): string
    {
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        // 相对路径，使用自定义前缀或 server
        $prefix = rtrim($this->config['url_prefix'] ?? '', '/');
        if (empty($prefix)) {
            $prefix = rtrim($this->config['server'] ?? '', '/');
        }
        return $prefix . '/' . ltrim($url, '/');
    }

    private function extractId(string $remotePath): string
    {
        if (strpos($remotePath, 'oneimg://') === 0) {
            $parts = explode('|', substr($remotePath, 9), 2);
            return $parts[0] ?? '';
        }
        return '';
    }

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

    private function curlRequest(string $method, string $url, ?string $body, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
