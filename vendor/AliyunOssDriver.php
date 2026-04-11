<?php

/**
 * PicUp for Typecho - 阿里云 OSS 驱动
 *
 * 使用 OSS Signature V1（HMAC-SHA1）原生签名，无需 SDK。
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class AliyunOssDriver implements DriverInterface
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
        return '阿里云 OSS';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'endpoint' => [
                'label'       => 'Endpoint（不含 Bucket）',
                'type'        => 'text',
                'default'     => 'oss-cn-hangzhou.aliyuncs.com',
                'description' => '地域 Endpoint，如 oss-cn-hangzhou.aliyuncs.com（不含 Bucket 前缀，不含协议头）',
                'required'    => true,
            ],
            'bucket' => [
                'label'       => 'Bucket 名称',
                'type'        => 'text',
                'default'     => '',
                'description' => 'OSS Bucket 名称',
                'required'    => true,
            ],
            'accessKeyId' => [
                'label'       => 'Access Key ID',
                'type'        => 'text',
                'default'     => '',
                'description' => '阿里云 RAM 访问凭证 AccessKeyId',
                'required'    => true,
            ],
            'accessKeySecret' => [
                'label'       => 'Access Key Secret',
                'type'        => 'password',
                'default'     => '',
                'description' => '阿里云 RAM 访问凭证 AccessKeySecret',
                'required'    => true,
            ],
            'prefix' => [
                'label'       => '存储路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件在 Bucket 中的目录前缀，如 blog/images/（末尾加 /），留空则存根目录',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => '访问域名（CDN / 自定义域名）',
                'type'        => 'text',
                'default'     => '',
                'description' => '公网访问域名，如 https://cdn.example.com。留空则使用 https://{bucket}.{endpoint}',
                'required'    => false,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $endpoint = trim($this->config['endpoint']       ?? '', '/ ');
        $bucket   = trim($this->config['bucket']         ?? '');
        $akId     = trim($this->config['accessKeyId']    ?? '');
        $akSecret = trim($this->config['accessKeySecret'] ?? '');
        $prefix   = $this->config['prefix'] ?? '';

        if (empty($endpoint) || empty($bucket) || empty($akId) || empty($akSecret)) {
            return false;
        }

        $key      = ltrim($prefix . ltrim($remotePath, '/'), '/');
        $url      = "https://{$bucket}.{$endpoint}/{$key}";
        $date     = gmdate('D, d M Y H:i:s \G\M\T');
        $mime     = $mimeType ?: 'application/octet-stream';
        $resource = '/' . $bucket . '/' . $key;
        $toSign   = "PUT\n\n{$mime}\n{$date}\n{$resource}";
        $sign     = base64_encode(hash_hmac('sha1', $toSign, $akSecret, true));
        $auth     = "OSS {$akId}:{$sign}";

        $fileContent = file_get_contents($localFile);
        if ($fileContent === false) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Date: '          . $date,
                'Content-Type: '  . $mime,
                'Authorization: ' . $auth,
                'Content-Length: ' . strlen($fileContent),
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http >= 300) {
            return false;
        }

        // 返回 key（相对路径），由 getUrl() 构造完整 URL
        return $key;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $endpoint = trim($this->config['endpoint']       ?? '', '/ ');
        $bucket   = trim($this->config['bucket']         ?? '');
        $akId     = trim($this->config['accessKeyId']    ?? '');
        $akSecret = trim($this->config['accessKeySecret'] ?? '');

        if (empty($endpoint) || empty($bucket) || empty($akId) || empty($akSecret)) {
            return false;
        }

        // getStoredPath() 存的是完整 URL，需还原为 key
        $key = preg_match('#^https?://#i', $remotePath)
            ? rawurldecode(ltrim(parse_url($remotePath, PHP_URL_PATH) ?: '', '/'))
            : ltrim($remotePath, '/');
        $url      = "https://{$bucket}.{$endpoint}/{$key}";
        $date     = gmdate('D, d M Y H:i:s \G\M\T');
        $resource = '/' . $bucket . '/' . $key;
        $toSign   = "DELETE\n\n\n{$date}\n{$resource}";
        $sign     = base64_encode(hash_hmac('sha1', $toSign, $akSecret, true));
        $auth     = "OSS {$akId}:{$sign}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Date: '          . $date,
                'Authorization: ' . $auth,
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return !$errno && ($http === 204 || $http === 200);
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');
        $endpoint  = trim($this->config['endpoint']  ?? '', '/ ');
        $bucket    = trim($this->config['bucket']    ?? '');
        $key       = ltrim($remotePath, '/');

        if (!empty($urlPrefix)) {
            return $urlPrefix . '/' . $key;
        }

        return "https://{$bucket}.{$endpoint}/{$key}";
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $this->getUrl($uploadedUrl);
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return false;
    }
}
