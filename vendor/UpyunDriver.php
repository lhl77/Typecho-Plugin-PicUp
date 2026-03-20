<?php

/**
 * PicUp for Typecho - 又拍云 USS（对象存储）驱动
 *
 * 使用又拍云 REST API v1 签名（HMAC-SHA1），无需 SDK。
 * 文档：https://help.upyun.com/knowledge-base/rest_api/
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class UpyunDriver implements DriverInterface
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
        return '又拍云 USS';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'service' => [
                'label'       => '服务名（Bucket）',
                'type'        => 'text',
                'default'     => '',
                'description' => '又拍云存储服务名称',
                'required'    => true,
            ],
            'operator' => [
                'label'       => '操作员名',
                'type'        => 'text',
                'default'     => '',
                'description' => '有读写权限的操作员名称',
                'required'    => true,
            ],
            'password' => [
                'label'       => '操作员密码',
                'type'        => 'password',
                'default'     => '',
                'description' => '操作员登录密码',
                'required'    => true,
            ],
            'prefix' => [
                'label'       => '存储路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件存储目录前缀，如 /blog/images（末尾不加 /），留空则存根目录',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => '访问域名',
                'type'        => 'text',
                'default'     => '',
                'description' => '绑定的 CDN 或自定义加速域名，如 https://cdn.example.com',
                'required'    => true,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $service  = trim($this->config['service']  ?? '');
        $operator = trim($this->config['operator'] ?? '');
        $password = $this->config['password'] ?? '';
        $prefix   = rtrim($this->config['prefix'] ?? '', '/');

        if (empty($service) || empty($operator) || empty($password)) {
            return false;
        }

        $fileContent = file_get_contents($localFile);
        if ($fileContent === false) {
            return false;
        }

        $path = $prefix . '/' . ltrim($remotePath, '/');
        $uri  = "/{$service}{$path}";
        $url  = 'https://v0.api.upyun.com' . $uri;
        $mime = $mimeType ?: 'application/octet-stream';
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $md5  = md5($fileContent);
        $auth = $this->buildAuth('PUT', $uri, $date, $md5, $operator, $password);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Date: '           . $date,
                'Content-Type: '   . $mime,
                'Content-Length: ' . strlen($fileContent),
                'Content-MD5: '    . $md5,
                'Authorization: '  . $auth,
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || ($http !== 200 && $http !== 201)) {
            return false;
        }

        // 返回存储路径（不含服务名）
        return $path;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $service  = trim($this->config['service']  ?? '');
        $operator = trim($this->config['operator'] ?? '');
        $password = $this->config['password'] ?? '';

        if (empty($service) || empty($operator) || empty($password)) {
            return false;
        }

        $path = '/' . ltrim($remotePath, '/');
        $uri  = "/{$service}{$path}";
        $url  = 'https://v0.api.upyun.com' . $uri;
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $auth = $this->buildAuth('DELETE', $uri, $date, '', $operator, $password);

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

        return !$errno && ($http === 200 || $http === 204);
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');
        $path      = '/' . ltrim($remotePath, '/');
        return $urlPrefix . $path;
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
     * 构造又拍云 REST API v1 Authorization
     *
     * sign = base64( hmac-sha1( md5(password), "METHOD\nURI\nDATE\nCONTENT-MD5" ) )
     */
    private function buildAuth(
        string $method,
        string $uri,
        string $date,
        string $contentMd5,
        string $operator,
        string $password
    ): string {
        $signStr = "{$method}\n{$uri}\n{$date}\n{$contentMd5}";
        $sign    = base64_encode(
            hash_hmac('sha1', $signStr, md5($password), true)
        );
        return "UPYUN {$operator}:{$sign}";
    }
}
