<?php

/**
 * PicUp for Typecho - Zipline 驱动
 *
 * API 文档：https://zipline.diced.sh/docs/api
 *   上传：POST <server>/api/upload       （Header: Authorization: <token>）
 *   删除：DELETE <server>/api/files/<id>  （Header: Authorization: <token>）
 *
 * Zipline 默认返回纯文本 URL；带 X-Zipline-Format: json 时返回
 * {"files":[{"url":"...","id":"..."}]}，这里两种都兼容。
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ZiplineDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Zipline';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '站点地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Zipline 站点域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '后台 Dashboard → Manage Account → API Token',
                'required'    => true,
            ],
            'folder' => [
                'label'       => '目标文件夹 ID（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '上传到指定文件夹时填写其 id，留空则传到根目录',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $token  = trim((string)($this->config['token'] ?? ''));

        if ($server === '' || $token === '') {
            error_log('[PicUp][Zipline] 配置不完整：server 或 token 为空');
            return false;
        }

        $url = $server . '/api/upload';
        if (!empty($this->config['folder'])) {
            $url .= '?folder=' . rawurlencode((string)$this->config['folder']);
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];
        $headers = [
            'Authorization: ' . $token,
            'X-Zipline-Format: json',
            'Accept: application/json',
        ];

        $response = $this->curlRequest('POST', $url, $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][Zipline] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (is_array($data)) {
            // {"files":[{"url":"...","id":"..."}]}
            if (!empty($data['files'][0]['url'])) {
                return $this->absolutize((string)$data['files'][0]['url'], $server);
            }
            if (!empty($data['url'])) {
                return $this->absolutize((string)$data['url'], $server);
            }
            $errMsg = $data['error'] ?? $data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
            error_log('[PicUp][Zipline] 上传失败：' . $errMsg);
            return false;
        }

        // 纯文本响应：整段就是 URL
        $text = trim($response);
        if (preg_match('#^https?://#i', $text)) {
            return $text;
        }
        if ($text !== '' && strpos($text, '/') === 0) {
            return $server . $text;
        }

        error_log('[PicUp][Zipline] 响应无法识别：' . substr($response, 0, 200));
        return false;
    }

    public function delete(string $remotePath): bool
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $token  = trim((string)($this->config['token'] ?? ''));

        if ($server === '' || $token === '') {
            return false;
        }

        $id = $this->extractId($remotePath);
        if ($id === '') {
            error_log('[PicUp][Zipline] 无法解析文件 id：' . $remotePath);
            return false;
        }

        $resp = $this->curlRequest('DELETE', $server . '/api/files/' . rawurlencode($id), null, [
            'Authorization: ' . $token,
            'Accept: application/json',
        ]);
        if ($resp === false) {
            return false;
        }

        $data = json_decode($resp, true);
        if (is_array($data)) {
            return ($data['status'] ?? '') === 'success'
                || !empty($data['deleted'])
                || !isset($data['error']);
        }
        // 204 无响应体也算成功
        return trim((string)$resp) === '';
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'zipline://') === 0) {
            $parts = explode('|', substr($remotePath, 10), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        $url = (string)$uploadedUrl;
        if ($url === '') {
            return $url;
        }
        $id = $this->idFromUrl($url);
        return $id === '' ? $url : 'zipline://' . $id . '|' . $url;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    /**
     * Zipline 的文件 id 就是 URL 里的文件名（不含扩展名）
     */
    private function idFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($path);
        return preg_replace('#\.[a-zA-Z0-9]+$#', '', $name) ?: '';
    }

    private function extractId(string $remotePath): string
    {
        if (strpos($remotePath, 'zipline://') === 0) {
            $parts = explode('|', substr($remotePath, 10), 2);
            return (string)($parts[0] ?? '');
        }
        if (preg_match('#^https?://#i', $remotePath)) {
            return $this->idFromUrl($remotePath);
        }
        return $remotePath;
    }

    private function absolutize(string $url, string $server): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $server . '/' . ltrim($url, '/');
    }

    private function curlRequest(string $method, string $url, ?array $postFields, array $headers = [])
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $postFields;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        if ($errno) {
            error_log('[PicUp][Zipline] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
