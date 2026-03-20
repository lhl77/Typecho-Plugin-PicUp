<?php

/**
 * PicUp for Typecho - WebDAV 存储驱动
 *
 * 支持标准 WebDAV 协议的存储服务（Alist、Nextcloud、坚果云、Box 等）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class WebDavDriver implements DriverInterface
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
        return 'WebDAV 存储';
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigFields(): array
    {
        return [
            'endpoint' => [
                'label'       => 'WebDAV 地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'WebDAV 服务地址，如 https://dav.example.com/dav （末尾不加 /）',
                'required'    => true,
            ],
            'username' => [
                'label'       => '用户名',
                'type'        => 'text',
                'default'     => '',
                'description' => 'WebDAV 认证用户名',
                'required'    => true,
            ],
            'password' => [
                'label'       => '密码',
                'type'        => 'password',
                'default'     => '',
                'description' => 'WebDAV 认证密码或应用专用密码',
                'required'    => true,
            ],
            'basePath' => [
                'label'       => '存储基础路径',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件上传到的 WebDAV 目录路径，如 /upload/blog （不要以 / 结尾）',
                'required'    => false,
            ],
            'urlPrefix' => [
                'label'       => '公开访问地址前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '文件公开访问的 URL 前缀，如 https://cdn.example.com/d/blog 。此 URL 加上文件路径即为访问地址',
                'required'    => true,
            ],
            'autoMkdir' => [
                'label'       => '自动创建目录',
                'type'        => 'select',
                'default'     => '1',
                'description' => '上传前自动通过 MKCOL 创建目录结构',
                'required'    => false,
                'options'     => [
                    '1' => '是',
                    '0' => '否',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $endpoint = rtrim($this->config['endpoint'] ?? '', '/');
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $basePath = rtrim($this->config['basePath'] ?? '', '/');

        if (empty($endpoint) || empty($username) || empty($password)) {
            return false;
        }

        $fullRemote = $basePath . '/' . ltrim($remotePath, '/');

        // 自动创建目录
        if (($this->config['autoMkdir'] ?? '1') === '1') {
            $this->ensureDirectory($endpoint, dirname($fullRemote), $username, $password);
        }

        $url = $endpoint . '/' . ltrim($fullRemote, '/');

        $body = file_get_contents($localFile);
        if ($body === false) {
            return false;
        }

        $result = $this->curlRequest('PUT', $url, $body, [
            'Content-Type' => $mimeType,
        ], $username, $password);

        // 201 Created 或 204 No Content 都是成功
        if ($result['httpCode'] >= 200 && $result['httpCode'] < 300) {
            return $this->getUrl($remotePath);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $remotePath): bool
    {
        $endpoint = rtrim($this->config['endpoint'] ?? '', '/');
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $basePath = rtrim($this->config['basePath'] ?? '', '/');

        if (empty($endpoint) || empty($username) || empty($password)) {
            return false;
        }

        $fullRemote = $basePath . '/' . ltrim($remotePath, '/');
        $url = $endpoint . '/' . ltrim($fullRemote, '/');

        $result = $this->curlRequest('DELETE', $url, null, [], $username, $password);

        // 204 No Content 或 200 OK 或 404 Not Found（已不存在）都算成功
        return $result['httpCode'] >= 200 && $result['httpCode'] < 300
            || $result['httpCode'] === 404;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(string $remotePath): string
    {
        $urlPrefix = rtrim($this->config['urlPrefix'] ?? '', '/');
        $objectPath = ltrim($remotePath, '/');

        return $urlPrefix . '/' . $objectPath;
    }

    /**
     * {@inheritdoc}
     * WebDAV 存储 remotePath（相对路径），URL 在 getUrl() 中拼接前缀。
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return $remotePath;
    }

    /**
     * {@inheritdoc}
     * WebDAV 支持覆盖写（PUT 已有文件），可复用旧路径。
     */
    public function alwaysNewPath(): bool
    {
        return false;
    }

    /**
     * 递归确保远程目录存在（MKCOL）
     */
    private function ensureDirectory(string $endpoint, string $dirPath, string $username, string $password): void
    {
        $dirPath = trim($dirPath, '/');
        if (empty($dirPath)) {
            return;
        }

        $parts   = explode('/', $dirPath);
        $current = '';

        foreach ($parts as $part) {
            $current .= '/' . $part;
            $url = $endpoint . $current . '/';

            // 先 PROPFIND 检查是否存在
            $check = $this->curlRequest('PROPFIND', $url, null, [
                'Depth' => '0',
            ], $username, $password);

            if ($check['httpCode'] === 207 || $check['httpCode'] === 200) {
                continue; // 已存在
            }

            // 不存在则创建
            $this->curlRequest('MKCOL', $url, null, [], $username, $password);
        }
    }

    /**
     * 通用 CURL 请求
     *
     * @return array ['httpCode' => int, 'body' => string]
     */
    private function curlRequest(
        string $method,
        string $url,
        $body = null,
        array $headers = [],
        string $username = '',
        string $password = ''
    ): array {
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

        if (!empty($username)) {
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

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
