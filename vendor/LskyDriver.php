<?php

/**
 * PicUp for Typecho - Lsky Pro 兰空图床驱动
 *
 * 支持 Lsky Pro v1 / v2 API
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class LskyDriver implements DriverInterface
{
    /** @var array 当前配置 */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function getName(): string
    {
        return 'Lsky Pro 兰空图床';
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '图床地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Lsky Pro 站点地址，如 https://pic.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 Lsky Pro 后台「接口」→「令牌」中获取（仅填写 Token 值，不含 Bearer 前缀）',
                'required'    => true,
            ],
            'strategy_id' => [
                'label'       => '储存策略 ID',
                'type'        => 'number',
                'default'     => '',
                'description' => '留空则使用默认策略，可在管理后台「储存策略」中查看 ID',
                'required'    => false,
            ],
            'album_id' => [
                'label'       => '相册 ID',
                'type'        => 'number',
                'default'     => '',
                'description' => '留空则不归入相册（仅 V2 支持）',
                'required'    => false,
            ],
            'api_version' => [
                'label'       => 'API 版本',
                'type'        => 'select',
                'default'     => 'v1',
                'description' => 'v1 适用于 Lsky Pro 1.x，v2 适用于 Lsky Pro 2.x',
                'required'    => true,
                'options'     => [
                    'v1' => 'V1 (1.x)',
                    'v2' => 'V2 (2.x)',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server  = rtrim($this->config['server'] ?? '', '/');
        $token   = $this->config['token'] ?? '';
        $version = $this->config['api_version'] ?? 'v1';

        if (empty($server) || empty($token)) {
            return false;
        }

        $fileName = basename($remotePath);

        if ($version === 'v2') {
            return $this->uploadV2($localFile, $server, $token, $mimeType, $fileName);
        }

        return $this->uploadV1($localFile, $server, $token, $mimeType, $fileName);
    }

    /**
     * Lsky Pro V1 API 上传
     */
    private function uploadV1(string $localFile, string $server, string $token, string $mimeType = '', string $fileName = '')
    {
        $url = $server . '/api/upload';

        // Lsky Pro V1 API 使用 'file' 字段（非 'image'）
        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', $fileName ?: basename($localFile)),
        ];

        if (!empty($this->config['strategy_id'])) {
            $postFields['strategy_id'] = (int) $this->config['strategy_id'];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->curlPost($url, $postFields, $headers);

        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);

        if (!$data) {
            return false;
        }

        // V1 成功: {"code": 200, "data": {"url": "...", "key": "..."}}
        if (isset($data['code']) && $data['code'] == 200 && isset($data['data']['url'])) {
            return $data['data']['url'];
        }

        // V1 兼容: {"status": true, "data": {"url": "..."}}
        if (isset($data['status']) && $data['status'] === true && isset($data['data']['url'])) {
            return $data['data']['url'];
        }

        return false;
    }

    /**
     * Lsky Pro V2 API 上传
     */
    private function uploadV2(string $localFile, string $server, string $token, string $mimeType = '', string $fileName = '')
    {
        $url = $server . '/api/v1/upload';

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', $fileName ?: basename($localFile)),
        ];

        if (!empty($this->config['strategy_id'])) {
            $postFields['strategy_id'] = (int) $this->config['strategy_id'];
        }

        if (!empty($this->config['album_id'])) {
            $postFields['album_id'] = (int) $this->config['album_id'];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->curlPost($url, $postFields, $headers);

        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);

        if (!$data) {
            return false;
        }

        // V2 成功: {"status": true, "data": {"links": {"url": "..."}}}
        if (isset($data['status']) && $data['status'] === true && isset($data['data']['links']['url'])) {
            return $data['data']['links']['url'];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $remotePath): bool
    {
        // Lsky Pro 使用图片 key / hash 来删除，remotePath 实际是完整 URL
        // 对于 Lsky 驱动，我们将完整 URL 存储在 path 中
        // 删除功能需要 key，但上传时 Lsky 不一定返回 key
        // 这里暂时返回 true，实际删除需要通过 Lsky API 查询后操作
        $server  = rtrim($this->config['server'] ?? '', '/');
        $token   = $this->config['token'] ?? '';
        $version = $this->config['api_version'] ?? 'v1';

        if (empty($server) || empty($token)) {
            return false;
        }

        if ($version === 'v2') {
            return $this->deleteV2($remotePath, $server, $token);
        }

        return $this->deleteV1($remotePath, $server, $token);
    }

    /**
     * Lsky Pro V1 删除
     */
    private function deleteV1(string $remotePath, string $server, string $token): bool
    {
        // V1 使用 hash 删除: DELETE /api/delete/{hash}
        // remotePath 格式: lsky://{hash}|{url}  或者直接是 URL
        $hash = $this->extractHash($remotePath);
        if (empty($hash)) {
            return false;
        }

        $url = $server . '/api/delete/' . $hash;

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->curlRequest('DELETE', $url, null, $headers);
        $data = json_decode($response, true);

        return $data && (
            (isset($data['code']) && $data['code'] == 200) ||
            (isset($data['status']) && $data['status'] === true)
        );
    }

    /**
     * Lsky Pro V2 删除
     */
    private function deleteV2(string $remotePath, string $server, string $token): bool
    {
        // V2 使用 key 删除: DELETE /api/v1/images/{key}
        $hash = $this->extractHash($remotePath);
        if (empty($hash)) {
            return false;
        }

        $url = $server . '/api/v1/images/' . $hash;

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->curlRequest('DELETE', $url, null, $headers);
        $data = json_decode($response, true);

        return $data && isset($data['status']) && $data['status'] === true;
    }

    /**
     * 从存储路径中提取 Lsky hash/key
     * 路径格式: lsky://{hash}|{url}
     */
    private function extractHash(string $remotePath): string
    {
        if (strpos($remotePath, 'lsky://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[0] ?? '';
        }
        return '';
    }

    /**
     * 从存储路径中提取实际 URL
     */
    private function extractUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'lsky://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(string $remotePath): string
    {
        return $this->extractUrl($remotePath);
    }

    /**
     * {@inheritdoc}
     * Lsky Pro 上传后返回的是完整 URL，直接作为存储路径。
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // Lsky Pro 的 URL 就是唯一标识，不使用我们生成的 remotePath
        return $uploadedUrl;
    }

    /**
     * {@inheritdoc}
     * Lsky Pro 每次上传都由服务端分配路径，不支持覆盖写，因此总是需要新路径。
     */
    public function alwaysNewPath(): bool
    {
        return true;
    }

    /**
     * 发送 CURL POST 请求（multipart）
     *
     * @return string|false 成功返回响应体，失败返回 false
     */
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

    /**
     * 发送任意 CURL 请求
     */
    private function curlRequest(string $method, string $url, $body = null, array $headers = []): string
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
