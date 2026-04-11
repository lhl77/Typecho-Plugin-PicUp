<?php

/**
 * PicUp for Typecho - 腾讯云 COS 驱动
 *
 * 使用 COS Signature V5（HMAC-SHA1）原生签名，无需 SDK。
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class TencentCosDriver implements DriverInterface
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
        return '腾讯云 COS';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'region' => [
                'label'       => '地域 Region',
                'type'        => 'text',
                'default'     => 'ap-guangzhou',
                'description' => '如 ap-guangzhou、ap-beijing、ap-shanghai 等',
                'required'    => true,
            ],
            'bucket' => [
                'label'       => 'Bucket 名称（含 AppId）',
                'type'        => 'text',
                'default'     => '',
                'description' => '完整 Bucket 名，如 my-bucket-1250000000',
                'required'    => true,
            ],
            'secretId' => [
                'label'       => 'SecretId',
                'type'        => 'text',
                'default'     => '',
                'description' => '腾讯云 API 密钥 SecretId',
                'required'    => true,
            ],
            'secretKey' => [
                'label'       => 'SecretKey',
                'type'        => 'password',
                'default'     => '',
                'description' => '腾讯云 API 密钥 SecretKey',
                'required'    => true,
            ],
            'prefix' => [
                'label'       => '存储路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件在 Bucket 中的目录前缀，如 blog/（末尾加 /），留空则存根目录',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => '访问域名（CDN / 自定义域名）',
                'type'        => 'text',
                'default'     => '',
                'description' => '公网访问域名，如 https://cdn.example.com。留空则使用 COS 默认域名',
                'required'    => false,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $region    = trim($this->config['region']    ?? '');
        $bucket    = trim($this->config['bucket']    ?? '');
        $secretId  = trim($this->config['secretId']  ?? '');
        $secretKey = trim($this->config['secretKey'] ?? '');
        $prefix    = $this->config['prefix'] ?? '';

        if (empty($region) || empty($bucket) || empty($secretId) || empty($secretKey)) {
            return false;
        }

        $key  = ltrim($prefix . ltrim($remotePath, '/'), '/');
        $host = "{$bucket}.cos.{$region}.myqcloud.com";
        $url  = "https://{$host}/{$key}";
        $mime = $mimeType ?: 'application/octet-stream';

        $fileContent = file_get_contents($localFile);
        if ($fileContent === false) {
            return false;
        }

        $auth = $this->buildAuth('put', "/{$key}", $host, $secretId, $secretKey);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Host: '           . $host,
                'Content-Type: '   . $mime,
                'Content-Length: ' . strlen($fileContent),
                'Authorization: '  . $auth,
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http >= 300) {
            return false;
        }

        return $key;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $region    = trim($this->config['region']    ?? '');
        $bucket    = trim($this->config['bucket']    ?? '');
        $secretId  = trim($this->config['secretId']  ?? '');
        $secretKey = trim($this->config['secretKey'] ?? '');

        if (empty($region) || empty($bucket) || empty($secretId) || empty($secretKey)) {
            return false;
        }

        // getStoredPath() 存的是完整 URL，需还原为 key
        $key = preg_match('#^https?://#i', $remotePath)
            ? rawurldecode(ltrim(parse_url($remotePath, PHP_URL_PATH) ?: '', '/'))
            : ltrim($remotePath, '/');
        $host = "{$bucket}.cos.{$region}.myqcloud.com";
        $url  = "https://{$host}/{$key}";
        $auth = $this->buildAuth('delete', "/{$key}", $host, $secretId, $secretKey);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Host: '          . $host,
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
        $region    = trim($this->config['region']    ?? '');
        $bucket    = trim($this->config['bucket']    ?? '');
        $key       = ltrim($remotePath, '/');

        if (!empty($urlPrefix)) {
            return $urlPrefix . '/' . $key;
        }

        return "https://{$bucket}.cos.{$region}.myqcloud.com/{$key}";
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

    /* ------------------------------------------------------------------ */

    /**
     * 构造腾讯云 COS V5 Authorization 头
     *
     * 文档：https://cloud.tencent.com/document/product/436/7778
     */
    private function buildAuth(
        string $method,
        string $uriPath,
        string $host,
        string $secretId,
        string $secretKey
    ): string {
        $startTime = time() - 60;
        $endTime   = $startTime + 3600;
        $signTime  = "{$startTime};{$endTime}";

        // 签名密钥
        $signKey = hash_hmac('sha1', $signTime, $secretKey);

        // 请求头字符串（只签 host）
        $headerList   = 'host';
        $headerStr    = 'host=' . strtolower($host);

        // HttpString
        $httpString = strtolower($method) . "\n"
            . $uriPath . "\n"
            . "\n"                     // 无 query 参数
            . $headerStr . "\n";

        // StringToSign
        $stringToSign = "sha1\n{$signTime}\n" . sha1($httpString) . "\n";

        // 签名
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . $secretId
            . '&q-sign-time=' . $signTime
            . '&q-key-time=' . $signTime
            . '&q-header-list=' . $headerList
            . '&q-url-param-list='
            . '&q-signature=' . $signature;
    }
}
