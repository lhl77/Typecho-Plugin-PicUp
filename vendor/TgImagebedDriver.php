<?php

/**
 * PicUp for Typecho - Telegram 图床驱动
 *
 * 对接兼容 tg-telegram-imagebed 项目的图床
 * 支持匿名上传（POST /api/upload）和 Token 认证上传（POST /api/auth/upload）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class TgImagebedDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Telegram 图床 (tg-imagebed)';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '图床地址',
                'type'        => 'text',
                'default'     => '',
                'description' => '图床的域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'Token（可选）',
                'type'        => 'password',
                'default'     => '',
                'description' => '填写后使用认证上传（POST /api/auth/upload），享有更高上传限额。留空则为匿名上传（POST /api/upload）。',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim($this->config['server'] ?? '', '/');
        $token  = $this->config['token'] ?? '';

        if (empty($server)) {
            error_log('[PicUp][TgImagebed] server 未配置');
            return false;
        }

        if (!empty($token)) {
            $url     = $server . '/api/auth/upload';
            $headers = ['Authorization: Bearer ' . $token];
        } else {
            $url     = $server . '/api/upload';
            $headers = [];
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $response = $this->curlPost($url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][TgImagebed] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][TgImagebed] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // 成功响应：{"success": true, "data": {"url": "https://...", ...}}
        if (!empty($data['success']) && !empty($data['data']['url'])) {
            return $data['data']['url'];
        }

        // 兼容：{"url": "..."}
        if (!empty($data['url'])) {
            return $data['url'];
        }

        error_log('[PicUp][TgImagebed] 上传失败：' . json_encode($data));
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // tg-imagebed 暂无删除接口
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
