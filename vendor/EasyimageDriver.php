<?php

/**
 * PicUp for Typecho - EasyImage 图床驱动
 *
 * 支持 EasyImage 自建图床，通过 POST /api/index.php 上传。
 * 文档：https://github.com/icret/EasyImages2.0
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class EasyimageDriver implements DriverInterface
{
    /** @var array 当前配置 */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** {@inheritdoc} */
    public static function getName(): string
    {
        return 'EasyImage 图床';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'domain' => [
                'label'       => '图床域名',
                'type'        => 'text',
                'default'     => '',
                'description' => 'EasyImage 站点地址，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '在 EasyImage 后台 tokenList 文件中获取',
                'required'    => true,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $domain = rtrim($this->config['domain'] ?? '', '/');
        $token  = trim($this->config['token']  ?? '');

        if (empty($domain) || empty($token)) {
            return false;
        }

        $url  = $domain . '/api/index.php';
        $mime = $mimeType ?: 'application/octet-stream';
        $name = basename($remotePath);

        $postFields = [
            'image' => new \CURLFile($localFile, $mime, $name),
            'token' => $token,
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
                'Accept: application/json',
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http >= 400) {
            return false;
        }

        $data = json_decode((string) $resp, true);
        if (!$data) {
            return false;
        }

        // {"result":"success","code":200,"url":"https://...","srcName":"...","del":"..."}
        if (
            (isset($data['result']) && $data['result'] === 'success') ||
            (isset($data['code'])   && $data['code'] == 200)
        ) {
            if (!empty($data['url'])) {
                return $data['url'];
            }
        }

        return false;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        // EasyImage 通过 del 链接删除（返回的 url 中没有包含 del 链接）
        // 由于图片 URL 直接作为 remotePath 存储，删除功能暂不支持
        return false;
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        return $remotePath;
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $uploadedUrl;
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return true;
    }
}
