<?php

/**
 * PicUp for Typecho - X BackBone 驱动
 *
 * 项目地址：https://github.com/SergiX44/XBackBone
 *   上传：POST <server>/api/upload     （Header: Authorization: Bearer <token>）
 *   删除：DELETE <server>/api/delete/<id>
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class XbackboneDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'X BackBone';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '站点地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'X BackBone 站点域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '后台 Settings → API Token 里生成',
                'required'    => true,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $token  = trim((string)($this->config['token'] ?? ''));

        if ($server === '' || $token === '') {
            error_log('[PicUp][XBackBone] 配置不完整：server 或 token 为空');
            return false;
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $response = $this->curlRequest('POST', $server . '/api/upload', $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][XBackBone] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][XBackBone] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        // {"status":"success","data":{"url":"...","id":123}}
        if (isset($data['data']['url'])) {
            return $this->absolutize((string)$data['data']['url'], $server);
        }
        if (isset($data['url'])) {
            return $this->absolutize((string)$data['url'], $server);
        }

        $errMsg = $data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][XBackBone] 上传失败：' . $errMsg);
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
            error_log('[PicUp][XBackBone] 无法解析文件 id：' . $remotePath);
            return false;
        }

        $resp = $this->curlRequest('DELETE', $server . '/api/delete/' . rawurlencode($id), null, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        if ($resp === false) {
            return false;
        }

        $data = json_decode($resp, true);
        if (is_array($data)) {
            return ($data['status'] ?? '') === 'success';
        }
        return trim((string)$resp) === '';
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'xbackbone://') === 0) {
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
        $id = $this->idFromUrl($url);
        return $id === '' ? $url : 'xbackbone://' . $id . '|' . $url;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    /**
     * XBackBone 的直链形如 <server>/view/<id>/<name>.<ext> 或 <server>/<id>.<ext>
     * 这里尽量取出 id，取不到就返回空（删除时不动作）。
     */
    private function idFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (preg_match('#/view/([^/]+)/#', $path, $m)) {
            return $m[1];
        }
        $name = basename($path);
        return preg_replace('#\.[a-zA-Z0-9]+$#', '', $name) ?: '';
    }

    private function extractId(string $remotePath): string
    {
        if (strpos($remotePath, 'xbackbone://') === 0) {
            $parts = explode('|', substr($remotePath, 12), 2);
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
            error_log('[PicUp][XBackBone] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
