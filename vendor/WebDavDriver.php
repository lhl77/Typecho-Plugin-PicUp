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

    /**
     * 最近一次上传失败的详情，由 Plugin::uploadHandle 读取后向用户展示。
     * 格式：'HTTP {code}：{response_snippet}'
     */
    public static $lastError = '';

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
            error_log('[PicUp][WebDAV] upload: 缺少必要配置（endpoint/username/password）');
            return false;
        }

        $fullRemote = $basePath . '/' . ltrim($remotePath, '/');

        // 自动创建目录
        if (($this->config['autoMkdir'] ?? '1') === '1') {
            $this->ensureDirectory($endpoint, dirname($fullRemote), $username, $password);
        }

        $url = $endpoint . '/' . ltrim($fullRemote, '/');

        // 使用流式 PUT 上传（CURLOPT_INFILE），避免 CURLOPT_POSTFIELDS 的
        // Expect: 100-continue 问题以及大文件内存消耗问题
        $fileSize = filesize($localFile);
        $fh = fopen($localFile, 'rb');
        if ($fh === false || $fileSize === false) {
            error_log('[PicUp][WebDAV] upload: 无法打开本地文件 ' . $localFile);
            return false;
        }

        $result = $this->curlPut($url, $fh, $fileSize, $mimeType, $username, $password);
        fclose($fh);

        // 201 Created 或 204 No Content 都是成功
        if ($result['httpCode'] >= 200 && $result['httpCode'] < 300) {
            self::$lastError = '';
            return $this->getUrl($remotePath);
        }

        $snippet = mb_substr(strip_tags($result['body']), 0, 300);
        self::$lastError = 'PUT HTTP ' . $result['httpCode'] . '：' . $snippet
            . '  [URL=' . $url . ']';
        error_log('[PicUp][WebDAV] upload: PUT 失败，HTTP ' . $result['httpCode']
            . '，URL=' . $url . '，响应=' . mb_substr($result['body'], 0, 500));
        return false;
    }

    /**
     * 使用流式 CURLOPT_INFILE 发送 PUT 请求（专用于文件上传）
     *
     * @return array ['httpCode' => int, 'body' => string]
     */
    private function curlPut(
        string $url,
        $fileHandle,
        int $fileSize,
        string $mimeType,
        string $username,
        string $password
    ): array {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fileHandle,
            CURLOPT_INFILESIZE     => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $mimeType,
                'Content-Length: ' . $fileSize,  // 显式设置，避免部分 libcurl 使用分块传输
                'Expect:',  // 抑制 Expect: 100-continue
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if (!empty($username)) {
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('[PicUp][WebDAV] curlPut: cURL 错误 — ' . $curlErr);
        }

        return [
            'httpCode' => (int) $httpCode,
            'body'     => $response ?: '',
        ];
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

        // 抑制 Expect: 100-continue（许多 WebDAV 服务器不支持）
        if (!isset($headers['Expect'])) {
            $headers['Expect'] = '';
        }

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
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('[PicUp][WebDAV] curlRequest(' . $method . '): cURL 错误 — ' . $curlErr);
        }

        return [
            'httpCode' => (int) $httpCode,
            'body'     => $response ?: '',
        ];
    }
}
