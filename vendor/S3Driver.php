<?php

/**
 * PicUp for Typecho - S3 兼容存储驱动
 *
 * 支持 AWS S3、MinIO、Cloudflare R2、阿里云 OSS（S3 兼容模式）、腾讯云 COS 等
 * 使用 AWS Signature V4 签名，纯 PHP 实现，无需 composer 依赖
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class S3Driver implements DriverInterface
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
        return 'S3 兼容存储';
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'endpoint' => [
                'label'       => 'Endpoint 地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'S3 API 端点，如 https://s3.us-east-1.amazonaws.com 或 https://your-minio:9000',
                'required'    => true,
            ],
            'region' => [
                'label'       => 'Region 区域',
                'type'        => 'text',
                'default'     => 'us-east-1',
                'description' => 'S3 区域标识，如 us-east-1。MinIO / R2 可填 auto 或任意值',
                'required'    => true,
            ],
            'bucket' => [
                'label'       => 'Bucket 存储桶',
                'type'        => 'text',
                'default'     => '',
                'description' => '存储桶名称',
                'required'    => true,
            ],
            'accessKey' => [
                'label'       => 'Access Key',
                'type'        => 'text',
                'default'     => '',
                'description' => 'S3 访问密钥 ID',
                'required'    => true,
            ],
            'secretKey' => [
                'label'       => 'Secret Key',
                'type'        => 'password',
                'default'     => '',
                'description' => 'S3 访问密钥 Secret',
                'required'    => true,
            ],
            'pathStyle' => [
                'label'       => '路径风格',
                'type'        => 'select',
                'default'     => 'auto',
                'description' => 'auto = 自动检测；path = 路径风格（MinIO 等需要选此项）；virtual = 虚拟主机风格',
                'required'    => false,
                'options'     => [
                    'auto'    => '自动（Auto）',
                    'path'    => '路径风格（Path Style）',
                    'virtual' => '虚拟主机（Virtual Hosted）',
                ],
            ],
            'urlPrefix' => [
                'label'       => '自定义访问域名',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件公开访问的域名前缀，如 https://cdn.example.com 。留空将自动生成 S3 URL',
                'required'    => false,
            ],
            'prefix' => [
                'label'       => '存储路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件在 Bucket 中的路径前缀，如 blog/images （不要以 / 开头或结尾）',
                'required'    => false,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $endpoint  = rtrim($this->config['endpoint'] ?? '', '/');
        $region    = $this->config['region'] ?? 'us-east-1';
        $bucket    = $this->config['bucket'] ?? '';
        $accessKey = $this->config['accessKey'] ?? '';
        $secretKey = $this->config['secretKey'] ?? '';
        $prefix    = trim($this->config['prefix'] ?? '', '/');

        if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
            return false;
        }

        // 构建对象 Key
        $objectKey = ltrim($remotePath, '/');
        if (!empty($prefix)) {
            $objectKey = $prefix . '/' . $objectKey;
        }

        $body = file_get_contents($localFile);
        if ($body === false) {
            return false;
        }

        $url = $this->buildUrl($endpoint, $bucket, $objectKey);

        $headers = $this->signRequest('PUT', $url, $objectKey, $body, $mimeType, [
            'endpoint'  => $endpoint,
            'region'    => $region,
            'bucket'    => $bucket,
            'accessKey' => $accessKey,
            'secretKey' => $secretKey,
        ]);

        $headers['Content-Type'] = $mimeType;

        $response = $this->curlRequest('PUT', $url, $body, $headers);

        if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
            return $this->getUrl($remotePath);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $remotePath): bool
    {
        $endpoint  = rtrim($this->config['endpoint'] ?? '', '/');
        $region    = $this->config['region'] ?? 'us-east-1';
        $bucket    = $this->config['bucket'] ?? '';
        $accessKey = $this->config['accessKey'] ?? '';
        $secretKey = $this->config['secretKey'] ?? '';
        $prefix    = trim($this->config['prefix'] ?? '', '/');

        if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
            return false;
        }

        $objectKey = ltrim($remotePath, '/');
        if (!empty($prefix)) {
            $objectKey = $prefix . '/' . $objectKey;
        }

        $url = $this->buildUrl($endpoint, $bucket, $objectKey);

        $headers = $this->signRequest('DELETE', $url, $objectKey, '', '', [
            'endpoint'  => $endpoint,
            'region'    => $region,
            'bucket'    => $bucket,
            'accessKey' => $accessKey,
            'secretKey' => $secretKey,
        ]);

        $response = $this->curlRequest('DELETE', $url, null, $headers);

        return $response['httpCode'] >= 200 && $response['httpCode'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');
        $prefix    = trim($this->config['prefix'] ?? '', '/');

        $objectKey = ltrim($remotePath, '/');
        if (!empty($prefix)) {
            $objectKey = $prefix . '/' . $objectKey;
        }

        if (!empty($urlPrefix)) {
            return $urlPrefix . '/' . $objectKey;
        }

        $endpoint = rtrim($this->config['endpoint'] ?? '', '/');
        $bucket   = $this->config['bucket'] ?? '';

        return $this->buildUrl($endpoint, $bucket, $objectKey);
    }

    /**
     * {@inheritdoc}
     * S3 存储 remotePath（相对路径），URL 在 getUrl() 中动态拼接。
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $remotePath;
    }

    /**
     * {@inheritdoc}
     * S3 支持覆盖写，可以复用旧附件的路径。
     */
    public function alwaysNewPath(): bool
    {
        return false;
    }

    /**
     * 构建 S3 对象 URL
     */
    private function buildUrl(string $endpoint, string $bucket, string $objectKey): string
    {
        $usePathStyle = $this->usePathStyle($endpoint);

        if ($usePathStyle) {
            return $endpoint . '/' . $bucket . '/' . rawurlencode($objectKey);
        }

        // 虚拟主机风格: https://{bucket}.{host}/{key}
        $parsed = parse_url($endpoint);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $bucket . '.' . $host . $port . '/' . rawurlencode($objectKey);
    }

    /**
     * 判断是否使用路径风格
     */
    private function usePathStyle(string $endpoint): bool
    {
        $pathStyle = $this->config['pathStyle'] ?? 'auto';

        if ($pathStyle === 'path') {
            return true;
        }
        if ($pathStyle === 'virtual') {
            return false;
        }

        // auto: 带端口号或 IP 地址的用路径风格
        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '';

        if (isset($parsed['port'])) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        if ($host === 'localhost') {
            return true;
        }

        return false;
    }

    /**
     * AWS Signature V4 签名
     *
     * @return array 签名后的 HTTP headers（key=>value）
     */
    private function signRequest(
        string $method,
        string $url,
        string $objectKey,
        string $body,
        string $contentType,
        array $cred
    ): array {
        $parsed   = parse_url($url);
        $host     = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $path     = $parsed['path'] ?? '/';
        $region   = $cred['region'];
        $service  = 's3';

        $now       = new \DateTime('UTC');
        $datestamp = $now->format('Ymd');
        $amzDate   = $now->format('Ymd\THis\Z');

        $payloadHash = hash('sha256', $body);

        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'          => $amzDate,
        ];

        if (!empty($contentType)) {
            $headers['content-type'] = $contentType;
        }

        // 按 key 排序
        ksort($headers);

        $signedHeaders     = implode(';', array_keys($headers));
        $canonicalHeaders  = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '',  // query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $datestamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSignatureKey($cred['secretKey'], $datestamp, $region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $cred['accessKey'] . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return [
            'Authorization'        => $authorization,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'          => $amzDate,
            'Host'                 => $host,
        ];
    }

    /**
     * 生成签名密钥
     */
    private function getSignatureKey(string $key, string $datestamp, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $datestamp, 'AWS4' . $key, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return $kSigning;
    }

    /**
     * 通用 CURL 请求
     *
     * @return array ['httpCode' => int, 'body' => string]
     */
    private function curlRequest(string $method, string $url, $body = null, array $headers = []): array
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => (int) $httpCode,
            'body'     => $response ?: '',
        ];
    }
}
