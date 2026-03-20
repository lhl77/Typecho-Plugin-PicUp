<?php

/**
 * PicUp for Typecho - 七牛云 KODO 驱动
 *
 * 使用七牛云官方 UploadToken / QBox 认证，无需 SDK。
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class QiniuKodoDriver implements DriverInterface
{
    /** @var array 当前配置 */
    private $config;

    /** 各区域上传域名 */
    private static $zoneHosts = [
        'z0'   => 'up.qiniup.com',       // 华东
        'z1'   => 'up-z1.qiniup.com',    // 华北
        'z2'   => 'up-z2.qiniup.com',    // 华南
        'na0'  => 'up-na0.qiniup.com',   // 北美
        'as0'  => 'up-as0.qiniup.com',   // 东南亚
        'cn-east-2' => 'up-cn-east-2.qiniup.com', // 华东-浙江2
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** {@inheritdoc} */
    public static function getName(): string
    {
        return '七牛云 KODO';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'accessKey' => [
                'label'       => 'Access Key',
                'type'        => 'text',
                'default'     => '',
                'description' => '七牛云账号 Access Key',
                'required'    => true,
            ],
            'secretKey' => [
                'label'       => 'Secret Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '七牛云账号 Secret Key',
                'required'    => true,
            ],
            'bucket' => [
                'label'       => 'Bucket 名称',
                'type'        => 'text',
                'default'     => '',
                'description' => '存储空间名称',
                'required'    => true,
            ],
            'zone' => [
                'label'       => '存储区域',
                'type'        => 'select',
                'default'     => 'z0',
                'description' => '存储空间所在区域，华东=z0，华北=z1，华南=z2，北美=na0，东南亚=as0',
                'required'    => true,
                'options'     => [
                    'z0'   => '华东（z0）',
                    'z1'   => '华北（z1）',
                    'z2'   => '华南（z2）',
                    'na0'  => '北美（na0）',
                    'as0'  => '东南亚（as0）',
                    'cn-east-2' => '华东-浙江2',
                ],
            ],
            'prefix' => [
                'label'       => '存储路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件 Key 前缀，如 blog/（末尾加 /），留空则直接以日期目录存储',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => '访问域名',
                'type'        => 'text',
                'default'     => '',
                'description' => '绑定的自定义访问域名，如 https://cdn.example.com（必填，KODO 不提供默认公开域名）',
                'required'    => true,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $accessKey = trim($this->config['accessKey'] ?? '');
        $secretKey = trim($this->config['secretKey'] ?? '');
        $bucket    = trim($this->config['bucket']    ?? '');
        $zone      = $this->config['zone'] ?? 'z0';
        $prefix    = $this->config['prefix'] ?? '';

        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            return false;
        }

        $key      = ltrim($prefix . ltrim($remotePath, '/'), '/');
        $token    = $this->buildUploadToken($accessKey, $secretKey, $bucket, $key);
        $upHost   = self::$zoneHosts[$zone] ?? 'up.qiniup.com';
        $mime     = $mimeType ?: 'application/octet-stream';

        $postFields = [
            'token' => $token,
            'key'   => $key,
            'file'  => new \CURLFile($localFile, $mime, basename($remotePath)),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://{$upHost}/",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http >= 300) {
            return false;
        }

        $data = json_decode((string) $resp, true);
        if (empty($data['key'])) {
            return false;
        }

        return $data['key'];
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $accessKey = trim($this->config['accessKey'] ?? '');
        $secretKey = trim($this->config['secretKey'] ?? '');
        $bucket    = trim($this->config['bucket']    ?? '');

        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            return false;
        }

        $key         = ltrim($remotePath, '/');
        $entryCoded  = $this->base64UrlSafe($bucket . ':' . $key);
        $path        = '/rs/delete/' . $entryCoded;
        $accessToken = $this->buildQBoxToken($accessKey, $secretKey, $path);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://rs.qiniu.com' . $path,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: QBox ' . $accessToken,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return !$errno && $http === 200;
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');
        $key       = ltrim($remotePath, '/');
        return $urlPrefix . '/' . $key;
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $uploadedUrl;
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return false;
    }

    /* ------------------------------------------------------------------ */

    /**
     * 构造 UploadToken
     * 文档：https://developer.qiniu.com/kodo/manual/1208/upload-token
     */
    private function buildUploadToken(
        string $accessKey,
        string $secretKey,
        string $bucket,
        string $key
    ): string {
        $policy = [
            'scope'    => $bucket . ':' . $key,
            'deadline' => time() + 3600,
        ];
        $encodedPolicy = $this->base64UrlSafe(json_encode($policy));
        $sign          = $this->base64UrlSafe(
            hash_hmac('sha1', $encodedPolicy, $secretKey, true)
        );
        return $accessKey . ':' . $sign . ':' . $encodedPolicy;
    }

    /**
     * 构造管理凭证 QBox AccessToken
     * 文档：https://developer.qiniu.com/kodo/manual/1201/access-token
     */
    private function buildQBoxToken(string $accessKey, string $secretKey, string $path): string
    {
        $signStr = $path . "\n";
        $sign    = $this->base64UrlSafe(
            hash_hmac('sha1', $signStr, $secretKey, true)
        );
        return $accessKey . ':' . $sign;
    }

    /**
     * URL 安全的 Base64 编码
     */
    private function base64UrlSafe(string $data): string
    {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
    }
}
