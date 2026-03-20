<?php

/**
 * PicUp for Typecho - Imgur 图床驱动
 *
 * 使用 Imgur API v3（匿名上传 / Client-ID 上传）
 * 支持自定义 CDN 替换域名
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ImgurDriver implements DriverInterface
{
    /** Imgur API 端点 */
    const API_BASE = 'https://api.imgur.com/3';

    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Imgur';
    }

    public static function getConfigFields(): array
    {
        return [
            'client_id' => [
                'label'       => 'Client ID',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 https://api.imgur.com/oauth2/addclient 注册应用后获取 Client ID（匿名上传无需 Access Token）',
                'required'    => true,
            ],
            'access_token' => [
                'label'       => 'Access Token（可选）',
                'type'        => 'password',
                'default'     => '',
                'description' => '填写后图片将上传到您的账户，留空则为匿名上传（匿名上传有流量限制）',
                'required'    => false,
            ],
            'album_hash' => [
                'label'       => '相册 Hash（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '上传到指定相册，填写相册的 deletehash（需配合 Access Token 使用）',
                'required'    => false,
            ],
            'cdn' => [
                'label'       => 'CDN 替换域名（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '将返回 URL 中的 https://i.imgur.com 替换为此 CDN 域名，如 https://img.example.com（末尾不加 /）。留空则使用 Imgur 原始 URL。',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $clientId   = $this->config['client_id']    ?? '';
        $accessToken = $this->config['access_token'] ?? '';

        if (empty($clientId)) {
            error_log('[PicUp][Imgur] Client ID 未填写');
            return false;
        }

        $url = self::API_BASE . '/image';

        $postFields = [
            'image' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
            'type'  => 'file',
        ];

        $albumHash = $this->config['album_hash'] ?? '';
        if (!empty($albumHash)) {
            $postFields['album'] = $albumHash;
        }

        // 优先使用 Access Token（账户上传），否则用 Client-ID（匿名上传）
        if (!empty($accessToken)) {
            $authHeader = 'Authorization: Bearer ' . $accessToken;
        } else {
            $authHeader = 'Authorization: Client-ID ' . $clientId;
        }

        $headers = [
            $authHeader,
        ];

        $response = $this->curlPost($url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][Imgur] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Imgur] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if (!empty($data['success']) && !empty($data['data']['link'])) {
            $imgUrl = $data['data']['link'];
            return $this->applyCdn($imgUrl);
        }

        $errMsg = $data['data']['error'] ?? ($data['status'] ?? '未知错误');
        error_log('[PicUp][Imgur] 上传失败：' . (is_array($errMsg) ? json_encode($errMsg) : $errMsg));
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // remotePath 格式：imgur://<deletehash>|<url>
        $deleteHash = $this->extractDeleteHash($remotePath);
        if (empty($deleteHash)) {
            return false;
        }

        $clientId    = $this->config['client_id']    ?? '';
        $accessToken = $this->config['access_token'] ?? '';

        if (empty($clientId)) {
            return false;
        }

        $url    = self::API_BASE . '/image/' . $deleteHash;
        $auth   = !empty($accessToken)
            ? 'Authorization: Bearer ' . $accessToken
            : 'Authorization: Client-ID ' . $clientId;
        $resp   = $this->curlRequest('DELETE', $url, null, [$auth]);
        $data   = json_decode($resp, true);
        return is_array($data) && !empty($data['success']);
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'imgur://') === 0) {
            $parts = explode('|', substr($remotePath, 8), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 无法在 upload() 返回值中拿到 deletehash，需要重新请求解析
        // 这里暂存为 imgur://<url>，删除功能需要 Access Token + Image ID
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    /**
     * 应用 CDN 替换：将 i.imgur.com 替换为自定义域名
     */
    private function applyCdn(string $url): string
    {
        $cdn = rtrim($this->config['cdn'] ?? '', '/');
        if (empty($cdn)) {
            return $url;
        }
        // 替换 https://i.imgur.com 或 http://i.imgur.com
        return preg_replace('#^https?://i\.imgur\.com#', $cdn, $url);
    }

    private function extractDeleteHash(string $remotePath): string
    {
        if (strpos($remotePath, 'imgur://') === 0) {
            $parts = explode('|', substr($remotePath, 8), 2);
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
