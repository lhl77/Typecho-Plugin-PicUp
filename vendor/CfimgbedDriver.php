<?php

/**
 * PicUp for Typecho - CloudFlare ImgBed 驱动
 *
 * 支持 CloudFlare ImgBed 自建图床（基于 Cloudflare Workers + R2/Telegram 等渠道）。
 * 项目地址：https://github.com/MarSeventh/CloudFlare-ImgBed
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class CfimgbedDriver implements DriverInterface
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
        return 'CloudFlare ImgBed';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'domain' => [
                'label'       => '站点域名',
                'type'        => 'text',
                'default'     => '',
                'description' => 'CloudFlare ImgBed 部署域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'token' => [
                'label'       => 'API Token',
                'type'        => 'password',
                'default'     => '',
                'description' => '具有 upload 和 delete 权限的 API Token（用于 Bearer 认证）',
                'required'    => false,
            ],
            'authCode' => [
                'label'       => '上传认证码（authCode）',
                'type'        => 'password',
                'default'     => '',
                'description' => '上传专用认证码。若已填 API Token 则可留空；若两者都填，优先使用 authCode 上传',
                'required'    => false,
            ],
            'uploadChannel' => [
                'label'       => '上传渠道',
                'type'        => 'select',
                'default'     => 'telegram',
                'description' => '文件存储渠道',
                'required'    => false,
                'options'     => [
                    'telegram'   => 'Telegram',
                    'cfr2'       => 'Cloudflare R2',
                    's3'         => 'S3',
                    'discord'    => 'Discord',
                    'huggingface' => 'HuggingFace',
                ],
            ],
            'uploadFolder' => [
                'label'       => '上传目录',
                'type'        => 'text',
                'default'     => '',
                'description' => '存储目录（相对路径），如 blog/images，留空则存储在默认位置',
                'required'    => false,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $domain        = rtrim($this->config['domain'] ?? '', '/');
        $token         = trim($this->config['token']        ?? '');
        $authCode      = trim($this->config['authCode']     ?? '');
        $uploadChannel = $this->config['uploadChannel'] ?? 'telegram';
        $uploadFolder  = $this->config['uploadFolder']  ?? '';

        if (empty($domain) || (empty($token) && empty($authCode))) {
            return false;
        }

        // 构建上传 URL
        $query = [];
        if (!empty($authCode)) {
            $query['authCode'] = $authCode;
        }
        if (!empty($uploadChannel)) {
            $query['uploadChannel'] = $uploadChannel;
        }
        if (!empty($uploadFolder)) {
            $query['uploadFolder'] = $uploadFolder;
        }
        $uploadUrl = $domain . '/upload' . (empty($query) ? '' : '?' . http_build_query($query));

        $mime = $mimeType ?: 'application/octet-stream';
        $name = basename($remotePath);

        $postFields = [
            'file' => new \CURLFile($localFile, $mime, $name),
        ];

        $headers = ['Accept: application/json'];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $uploadUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $http >= 400) {
            return false;
        }

        // 响应: [{"src": "/file/abc123_image.jpg"}]
        $data = json_decode((string) $resp, true);
        if (!is_array($data) || empty($data[0]['src'])) {
            return false;
        }

        // 返回 src 路径（如 /file/abc123_image.jpg），getUrl() 拼接域名
        return $data[0]['src'];
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $domain = rtrim($this->config['domain'] ?? '', '/');
        $token  = trim($this->config['token']  ?? '');

        if (empty($domain) || empty($token)) {
            return false;
        }

        // getStoredPath() 存的是完整 URL，需还原为 file/xxx 路径
        $path = preg_match('#^https?://#i', $remotePath)
            ? ltrim(parse_url($remotePath, PHP_URL_PATH) ?: '', '/')
            : ltrim($remotePath, '/');
        $url     = $domain . '/api/manage/delete/' . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return false;
        }

        $data = json_decode((string) $resp, true);
        return !empty($data['success']) || $http === 200;
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        $domain = rtrim($this->config['domain'] ?? '', '/');
        // remotePath 已经是 /file/xxx 格式，直接拼接域名
        return $domain . '/' . ltrim($remotePath, '/');
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // upload() 返回的是 /file/xxx 路径，需拼接部署域名存储为完整 URL，
        // 避免 attachmentHandle() 将以 / 开头的路径误认为本地路径而拼接博客域名
        $domain = rtrim($this->config['domain'] ?? '', '/');
        return $domain . '/' . ltrim($uploadedUrl, '/');
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return true;
    }
}
