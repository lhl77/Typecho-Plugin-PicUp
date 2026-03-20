<?php

/**
 * PicUp for Typecho - S.EE (原 SM.MS) 驱动
 *
 * API 文档：https://s.ee/api/v1/file
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class SmmsDriver implements DriverInterface
{
    /** @var array 当前配置 */
    private $config;

    /**
     * 上传成功后临时保存 hash，供 getStoredPath() 构造删除凭据。
     * @var string
     */
    private $lastHash = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** {@inheritdoc} */
    public static function getName(): string
    {
        return 'S.EE 图床 (原 SM.MS)';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 s.ee 后台「用户设置」→「API Token」中获取',
                'required'    => true,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $token = trim($this->config['token'] ?? '');
        if (empty($token)) {
            return false;
        }

        $this->lastHash = '';
        $url  = 'https://s.ee/api/v1/file/upload';
        $mime = $mimeType ?: 'application/octet-stream';
        $name = basename($remotePath);

        $postFields = [
            'smfile' => new \CURLFile($localFile, $mime, $name),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $token,
                'Accept: application/json',
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || $resp === false) {
            return false;
        }

        $data = json_decode((string) $resp, true);
        if (!$data) {
            return false;
        }

        // {"success":true,"data":{"url":"...","hash":"...","delete":"..."}}
        if (!empty($data['success']) && isset($data['data']['url'])) {
            $this->lastHash = $data['data']['hash'] ?? '';
            return $data['data']['url'];
        }

        return false;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $token = trim($this->config['token'] ?? '');
        if (empty($token)) {
            return false;
        }

        $hash = $this->extractHash($remotePath);
        if (empty($hash)) {
            return false;
        }

        $url = 'https://s.ee/api/v1/file/delete/' . rawurlencode($hash);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $token,
                'Accept: application/json',
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return false;
        }

        $data = json_decode((string) $resp, true);
        return !empty($data['success']) || $http === 200;
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        // 存储格式: smms://{hash}|{url} 或直接是 URL
        if (strpos($remotePath, 'smms://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 将 hash 和 URL 一起编码存储，供后续删除使用
        if (!empty($this->lastHash)) {
            return 'smms://' . $this->lastHash . '|' . $uploadedUrl;
        }
        return $uploadedUrl;
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return true;
    }

    /* ------------------------------------------------------------------ */

    /**
     * 从存储路径中提取 hash
     */
    private function extractHash(string $remotePath): string
    {
        if (strpos($remotePath, 'smms://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[0] ?? '';
        }
        return '';
    }
}
