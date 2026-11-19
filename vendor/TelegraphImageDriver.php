<?php

/**
 * PicUp for Typecho - Telegraph-Image 驱动
 *
 * 项目地址：https://github.com/cf-pages/Telegraph-Image
 *   上传：POST <server>/upload  （multipart/form-data，字段名 file）
 *   响应：[{"src":"/file/abc123.png"}]（开启短链时 src 直接是完整地址）
 *   认证：可选 HTTP Basic（UPLOAD_BASIC_USER / UPLOAD_BASIC_PASS）
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class TelegraphImageDriver implements DriverInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'Telegraph-Image';
    }

    public static function getConfigFields(): array
    {
        return [
            'server' => [
                'label'       => '站点地址',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Cloudflare Pages 部署后的域名，如 https://img.example.com（末尾不加 /）',
                'required'    => true,
            ],
            'basic_user' => [
                'label'       => 'Basic 认证用户名（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '对应环境变量 UPLOAD_BASIC_USER；没开启就不填',
                'required'    => false,
            ],
            'basic_pass' => [
                'label'       => 'Basic 认证密码（可选）',
                'type'        => 'password',
                'default'     => '',
                'description' => '对应环境变量 UPLOAD_BASIC_PASS',
                'required'    => false,
            ],
            'url_prefix' => [
                'label'       => '自定义 URL 前缀（可选）',
                'type'        => 'text',
                'default'     => '',
                'description' => '绑定了独立图片域名时填写（如 https://cdn.example.com），留空则用站点地址',
                'required'    => false,
            ],
        ];
    }

    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $server = rtrim((string)($this->config['server'] ?? ''), '/');
        if ($server === '') {
            error_log('[PicUp][TelegraphImage] 配置不完整：server 为空');
            return false;
        }

        $postFields = [
            'file' => new \CURLFile($localFile, $mimeType ?: 'application/octet-stream', basename($remotePath)),
        ];

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $server . '/upload',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        $user = (string)($this->config['basic_user'] ?? '');
        $pass = (string)($this->config['basic_pass'] ?? '');
        if ($user !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD]  = $user . ':' . $pass;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            error_log('[PicUp][TelegraphImage] cURL 错误：' . curl_error($ch));
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        if ($errno) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('[PicUp][TelegraphImage] 响应解析失败（HTTP ' . $code . '）：' . substr($response, 0, 200));
            return false;
        }

        // [{"src":"/file/abc.png"}]
        $item = $data[0] ?? $data;
        if (is_array($item) && !empty($item['src'])) {
            return $this->absolutize((string)$item['src']);
        }
        if (is_array($item) && !empty($item['url'])) {
            return $this->absolutize((string)$item['url']);
        }

        error_log('[PicUp][TelegraphImage] 上传失败（HTTP ' . $code . '）：' . substr($response, 0, 200));
        return false;
    }

    public function delete(string $remotePath): bool
    {
        // Telegraph-Image 的删除入口与版本相关（且需要后台登录态），这里不做尝试
        error_log('[PicUp][TelegraphImage] 该图床暂不支持 API 删除，请在后台图片管理里删除');
        return false;
    }

    public function getUrl(string $remotePath): string
    {
        return $remotePath;
    }

    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        return (string)$uploadedUrl;
    }

    public function alwaysNewPath(): bool
    {
        return true;
    }

    // ---- 内部辅助 ----

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
}
