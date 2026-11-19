<?php

/**
 * PicUp for Typecho - zPic 图床驱动
 *
 * zPic（https://zpic.org）基于 Chevereto V3 构建，使用其 API v1 上传：
 *   POST {server}/api/1/upload
 *   multipart: key=<API Key>、action=upload、source=<文件>
 *   可选：album（相册 id_encoded）、expiration（过期分钟数）、nsfw（0/1）
 *
 * 兼容性：同时发送 key 表单字段与 X-API-Key 请求头，
 * 因此 Chevereto V3（字段鉴权）与 V4（请求头鉴权）站点都能用。
 *
 * @package PicUp
 * @author LHL
 * @version 1.1.0
 */

namespace TypechoPlugin\PicUp\vendor;

class ZpicDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'zPic 图床';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '站点地址',
                'type'        => 'text',
                'default'     => 'https://zpic.org',
                'description' => '默认为 https://zpic.org；自建 Chevereto 站点填自己的域名（末尾不加 /）',
                'required'    => true,
            ],
            'key' => [
                'label'       => 'API Key',
                'type'        => 'password',
                'default'     => '',
                'description' => '登录后在「Dashboard → Settings → API」里生成',
                'required'    => true,
            ],
            'album' => [
                'label'       => '相册 ID（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '上传到指定相册时填写相册的 id_encoded（相册地址 /album/<id_encoded>）',
                'required'    => false,
            ],
            'expiration' => [
                'label'       => '自动过期（分钟，可选）',
                'type'        => 'number',
                'default'     => '',
                'description' => '留空表示永久保存；站点开启该功能后才会生效',
                'required'    => false,
            ],
            'nsfw' => [
                'label'       => '标记为成人内容',
                'type'        => 'select',
                'default'     => '0',
                'description' => '部分站点开启 NSFW 分区时会用到，默认否',
                'required'    => false,
                'options'     => [
                    '0' => '否',
                    '1' => '是',
                ],
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        $key    = trim((string)($this->config['key'] ?? ''));

        if ($server === '') {
            $server = 'https://zpic.org';
        }
        if ($key === '') {
            error_log('[PicUp][ZPic] 配置不完整：API Key 为空');
            return false;
        }

        $postFields = [
            'key'    => $key,
            'action' => 'upload',
            'format' => 'json',
            'source' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $album = trim((string)($this->config['album'] ?? ''));
        if ($album !== '') {
            $postFields['album'] = $album;
        }

        $expiration = trim((string)($this->config['expiration'] ?? ''));
        if ($expiration !== '' && (int)$expiration > 0) {
            $postFields['expiration'] = (int)$expiration;
        }

        if ((string)($this->config['nsfw'] ?? '0') === '1') {
            $postFields['nsfw'] = 1;
        }

        $headers = [
            // Chevereto V4 走请求头鉴权；V3 会忽略这个头
            'X-API-Key: ' . $key,
            'Accept: application/json',
        ];

        $response = $this->curlPost($server . '/api/1/upload', $postFields, $headers);
        if ($response === false) {
            error_log('[PicUp][ZPic] cURL 请求失败');
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][ZPic] 响应解析失败：' . substr($response, 0, 200));
            return false;
        }

        $image = $data['image'] ?? null;
        if (is_array($image)) {
            if (!empty($image['url'])) {
                return (string)$image['url'];
            }
            if (!empty($image['display_url'])) {
                return (string)$image['display_url'];
            }
        }

        $errMsg = $data['error']['message']
            ?? $data['status_txt']
            ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log('[PicUp][ZPic] 上传失败（status=' . ($data['status_code'] ?? '?') . '）：' . $errMsg);
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // Chevereto 的公开 API 只提供上传，没有删除接口
        error_log('[PicUp][ZPic] 该图床的 API 不支持删除，请登录站点在图片页手动删除');
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        if (strpos($remotePath, 'zpic://') === 0) {
            $parts = explode('|', substr($remotePath, 7), 2);
            return $parts[1] ?? '';
        }
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // 上传直接返回完整 URL 且没有可用的删除接口，存 URL 即可
        return (string)$uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

    private function curlPost(string $url, array $postFields, array $headers = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        if ($errno) {
            error_log('[PicUp][ZPic] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $errno ? false : $response;
    }
}
