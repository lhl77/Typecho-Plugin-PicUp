<?php

/**
 * PicUp for Typecho - NodeImage 图床驱动
 *
 * API 文档：
 *   上传：POST https://api.nodeimage.com/api/upload，Header: X-API-Key
 *   删除：DELETE https://api.nodeimage.com/api/image/{image_id}，Header: X-API-Key
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class NodeimageDriver implements DriverInterface
{
    const API_BASE = 'https://api.nodeimage.com';

    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'NodeImage';
    }

    public static function getConfigFields(): array
    {
        return [
            'api_key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 NodeImage 后台获取的 API Key（X-API-Key）',
                'required'    => true,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $apiKey = $this->config['api_key'] ?? '';

        if (empty($apiKey)) {
            error_log('[PicUp][NodeImage] API Key 未填写');
            return false;
        }

        $url = self::API_BASE . '/api/upload';

        $postFields = [
            'image' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $headers = [
            'X-API-Key: ' . $apiKey,
        ];

        $response = $this->curlPost($url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][NodeImage] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][NodeImage] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // 实际响应格式（经测试）：
        // {
        //   "success": true,
        //   "message": "Image uploaded successfully",
        //   "image_id": "1IXlApm6AL2Yx0smpcuujaxnno8Lvrkg",
        //   "filename": "xxx.png",
        //   "size": 2129,
        //   "links": {
        //     "direct": "https://cdn.nodeimage.com/i/xxx.png",
        //     "html": "...",
        //     "markdown": "...",
        //     "bbcode": "..."
        //   }
        // }
        if (!empty($data['success']) && !empty($data['links']['direct'])) {
            $imgUrl  = $data['links']['direct'];
            $imageId = $data['image_id'] ?? '';
            if (!empty($imageId)) {
                $this->lastImageId = $imageId;
            }
            return $imgUrl;
        }

        $errMsg = $data['message'] ?? $data['error'] ?? json_encode($data);
        error_log('[PicUp][NodeImage] 上传失败：' . $errMsg);
        return false;
    }

    /** @var string|null 最后上传的 image id，用于 getStoredPath */
    private $lastImageId = null;

    public function delete(string $remotePath): bool
    {
        // remotePath 格式：nodeimage://<image_id>|<url>
        $imageId = $this->extractImageId($remotePath);
        if (empty($imageId)) {
            return false;
        }

        $apiKey = $this->config['api_key'] ?? '';
        if (empty($apiKey)) {
            return false;
        }

        $url     = self::API_BASE . '/api/image/' . $imageId;
        $headers = ['X-API-Key: ' . $apiKey];

        $resp = $this->curlRequest('DELETE', $url, null, $headers);
        $data = json_decode($resp, true);
        return is_array($data) && !empty($data['success']);
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'nodeimage://') === 0) {
            $parts = explode('|', substr($remotePath, 12), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 若 upload() 拿到了 image_id，保存为 nodeimage://<id>|<url> 以支持删除
        if (!empty($this->lastImageId)) {
            $stored = 'nodeimage://' . $this->lastImageId . '|' . $uploadedUrl;
            $this->lastImageId = null;
            return $stored;
        }
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    private function extractImageId(string $remotePath): string
    {
        if (strpos($remotePath, 'nodeimage://') === 0) {
            $parts = explode('|', substr($remotePath, 12), 2);
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
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PicUp/1.0)',
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            error_log('[PicUp][NodeImage] cURL 错误 #' . $errno . ': ' . $errMsg);
            return false;
        }
        return $response;
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
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64; rv:146.0) Gecko/20100101 Firefox/146.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
