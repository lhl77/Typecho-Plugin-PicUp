<?php

/**
 * PicUp for Typecho - Zpic 图床驱动
 *
 * 使用 Zpic V3 API（兼容 ImgURL Pro V2 API）
 * V3 文档：POST /api/v3/upload，Authorization: Bearer <token>
 * V2 文档：POST /api/v2/upload，uid + token 参数
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ZpicDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Zpic 图床';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '图床域名',
                'type'        => 'text',
                'default'     => '',
                'description' => '图床的基础域名，如 https://zpic.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'api_version' => [
                'label'       => 'API 版本',
                'type'        => 'select',
                'default'     => 'v3',
                'description' => 'V3 使用 Bearer Token（推荐），V2 使用 uid + token',
                'required'    => true,
                'options'     => [
                    'v3' => 'V3（Bearer Token，推荐）',
                    'v2' => 'V2（uid + token，兼容 ImgURL Pro）',
                ],
            ],
            'token' => [
                'label'       => 'Token / API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => 'V3：在【后台 - 开放接口】获取，格式 sk-xxx。V2：Token 值（不含前缀）',
                'required'    => true,
            ],
            'uid' => [
                'label'       => 'UID（仅 V2）',
                'type'        => 'text',
                'default'     => '',
                'description' => 'V2 API 所需的 UID，V3 版本留空即可',
                'required'    => false,
            ],
            'album_id' => [
                'label'       => '相册 ID',
                'type'        => 'number',
                'default'     => '0',
                'description' => '上传到指定相册，0 表示默认相册',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server  = rtrim($this->config['server'] ?? '', '/');
        $token   = $this->config['token'] ?? '';
        $version = $this->config['api_version'] ?? 'v3';

        if (empty($server) || empty($token)) {
            error_log('[PicUp][Zpic] 配置不完整：server 或 token 为空');
            return false;
        }

        if ($version === 'v2') {
            return $this->uploadV2($localFile, $server, $token, $mimeType, basename($remotePath));
        }

        return $this->uploadV3($localFile, $server, $token, $mimeType, basename($remotePath));
    }

    /**
     * V3 API 上传：POST /api/v3/upload
     * Authorization: Bearer <token>
     */
    private function uploadV3(string $localFile, string $server, string $token, string $mimeType, string $fileName)
    {
        $url = $server . '/api/v3/upload';

        $albumId  = (int)($this->config['album_id'] ?? 0);
        $params   = json_encode(['album_id' => $albumId, 'dedup' => false]);

        $postFields = [
            'file'   => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', $fileName),
            'params' => $params,
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->curlPost($url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][Zpic] V3 cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Zpic] V3 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if (($data['code'] ?? -1) === 200 && !empty($data['data']['url'])) {
            return $data['data']['url'];
        }

        error_log('[PicUp][Zpic] V3 上传失败，code=' . ($data['code'] ?? '?') . '，msg=' . ($data['msg'] ?? ''));
        return false;
    }

    /**
     * V2 API 上传：POST /api/v2/upload
     * 表单字段：file, uid, token, album_id
     */
    private function uploadV2(string $localFile, string $server, string $token, string $mimeType, string $fileName)
    {
        $url = $server . '/api/v2/upload';
        $uid = $this->config['uid'] ?? '';

        if (empty($uid)) {
            error_log('[PicUp][Zpic] V2 模式需要填写 UID');
            return false;
        }

        $postFields = [
            'file'  => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', $fileName),
            'uid'   => $uid,
            'token' => $token,
        ];

        $albumId = (int)($this->config['album_id'] ?? 0);
        if ($albumId > 0) {
            $postFields['album_id'] = $albumId;
        }

        $response = $this->curlPost($url, $postFields, []);
        if ($response === false) {
            error_log('[PicUp][Zpic] V2 cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Zpic] V2 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        if (($data['code'] ?? -1) === 200 && !empty($data['data']['url'])) {
            return $data['data']['url'];
        }

        error_log('[PicUp][Zpic] V2 上传失败，code=' . ($data['code'] ?? '?') . '，msg=' . ($data['msg'] ?? ''));
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // remotePath 格式：zpic://<imgid>|<url>
        $imgId = $this->extractImgId($remotePath);
        if (empty($imgId)) {
            return false;
        }

        $server  = rtrim($this->config['server'] ?? '', '/');
        $token   = $this->config['token'] ?? '';
        $version = $this->config['api_version'] ?? 'v3';

        if (empty($server) || empty($token)) {
            return false;
        }

        if ($version === 'v3') {
            $url     = $server . '/api/v3/delete_images';
            $headers = [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ];
            $body    = json_encode(['image_ids' => [$imgId]]);
            $resp    = $this->curlRequest('POST', $url, $body, $headers);
            $data    = json_decode($resp, true);
            return is_array($data) && ($data['code'] ?? -1) === 200;
        }

        // V2 通过删除链接删除（若有保存则访问该 URL）
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'zpic://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 需要保存 imgid 用于删除，格式：zpic://<imgid>|<url>
        // imgid 从 upload 响应中无法直接获取（upload 只返回 URL），
        // 因此这里尝试从 URL 中反解 imgid（Zpic URL 格式：https://domain/YYYY/MM/DD/imgid.ext）
        $imgId = $this->extractImgIdFromUrl($uploadedUrl);
        if ($imgId) {
            return 'zpic://' . $imgId . '|' . $uploadedUrl;
        }
        return $uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    private function extractImgId(string $remotePath): string
    {
        if (strpos($remotePath, 'zpic://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[0] ?? '';
        }
        return '';
    }

    /**
     * 从 URL 中提取 imgid（8 位字母+数字，在文件名部分）
     * 例：https://domain.com/2026/01/19/vzB3OYiy.jpg  →  vzB3OYiy
     */
    private function extractImgIdFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return '';
        }
        $basename = pathinfo($path, PATHINFO_FILENAME);
        // Zpic V3 imgid 为 8 位大小写字母+数字
        if (preg_match('/^[A-Za-z0-9]{8}$/', $basename)) {
            return $basename;
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
