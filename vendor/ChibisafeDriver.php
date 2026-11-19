<?php

/**
 * PicUp for Typecho - chibisafe 驱动
 *
 * 项目地址：https://github.com/chibisafe/chibisafe
 *   上传：POST <server>/api/upload        （Header: x-api-key: <key>）
 *   删除：DELETE <server>/api/file/<id>    （Header: x-api-key: <key>）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ChibisafeDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'chibisafe';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '站点地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'chibisafe 站点域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'api_key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '后台 Dashboard → Settings → API Key',
                'required'    => true,
            ],
            'url_prefix' => [
                'label'       => '自定义 URL 前缀（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '返回相对路径时用于拼接完整地址；留空则使用站点地址',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $key    = trim((string)($this->config['api_key'] ?? ''));

        if ($server === '' || $key === '') {
            error_log('[PicUp][Chibisafe] 配置不完整：server 或 api_key 为空');
            return false;
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];
        $headers = [
            'x-api-key: ' . $key,
            'Accept: application/json',
        ];

        $response = $this->curlRequest('POST', $server . '/api/upload', $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][Chibisafe] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][Chibisafe] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // {"message":"ok","files":[{"url":"/abc.png","name":"abc.png"}]}
        $file = $data['files'][0] ?? $data[0] ?? null;
        if (is_array($file)) {
            if (!empty($file['url'])) {
                return $this->absolutize((string)$file['url']);
            }
            if (!empty($file['name'])) {
                return $this->absolutize('/' . ltrim((string)$file['name'], '/'));
            }
        }
        if (!empty($data['url'])) {
            return $this->absolutize((string)$data['url']);
        }

        $errMsg = $data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][Chibisafe] 上传失败：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $key    = trim((string)($this->config['api_key'] ?? ''));

        if ($server === '' || $key === '') {
            return false;
        }

        $id = $this->extractId($remotePath);
        if ($id === '') {
            error_log('[PicUp][Chibisafe] 无法解析文件 id：' . $remotePath);
            return false;
        }

        $resp = $this->curlRequest('DELETE', $server . '/api/file/' . rawurlencode($id), null, [
            'x-api-key: ' . $key,
            'Accept: application/json',
        ]);
        if ($resp === false) {
            return false;
        }

        $data = json_decode($resp, true);
        if (is_array($data)) {
            return !isset($data['error']) && !isset($data['errors']);
        }
        return trim((string)$resp) === '';
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'chibisafe://') === 0) {
            $parts = explode('|', substr($remotePath, 12), 2);
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
        $id = preg_replace('#\.[a-zA-Z0-9]+$#', '', basename((string)parse_url($url, PHP_URL_PATH)));
        return $id ? 'chibisafe://' . $id . '|' . $url : $url;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    private function extractId(string $remotePath): string
    {
        if (strpos($remotePath, 'chibisafe://') === 0) {
            $parts = explode('|', substr($remotePath, 12), 2);
            return (string)($parts[0] ?? '');
        }
        if (preg_match('#^https?://#i', $remotePath)) {
            return (string)preg_replace('#\.[a-zA-Z0-9]+$#', '', basename((string)parse_url($remotePath, PHP_URL_PATH)));
        }
        return $remotePath;
    }

    private function absolutize(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $prefix = rtrim((string)($this->config['url_prefix'] ?? ''), '/');
        if ($prefix === '') {
            $prefix = rtrim((string)($this->config['server'] ?? ''), '/');
        }
        return $prefix . '/' . ltrim($url, '/');
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
            error_log('[PicUp][Chibisafe] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
