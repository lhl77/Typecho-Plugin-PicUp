<?php

/**
 * PicUp for Typecho - GitHub 仓库驱动
 *
 * 通过 GitHub Contents API 将文件存入指定仓库分支。
 * 支持自定义 CDN / jsDelivr 加速。
 *
 * @package PicUp
 * @author  LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\vendor;

class GithubDriver implements DriverInterface
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
        return 'GitHub 仓库';
    }

    /** {@inheritdoc} */
    public static function getConfigFields(): array
    {
        return [
            'token' => [
                'label'       => 'Personal Access Token',
                'type'        => 'password',
                'default'     => '',
                'description' => 'GitHub PAT，需要 Contents 读写权限（经典 Token 需要 repo scope，Fine-grained Token 需要 Repository Contents Read & Write）',
                'required'    => true,
            ],
            'repo' => [
                'label'       => '仓库 (owner/repo)',
                'type'        => 'text',
                'default'     => '',
                'description' => '格式为 用户名/仓库名，如 yourname/img-bed',
                'required'    => true,
            ],
            'branch' => [
                'label'       => '分支',
                'type'        => 'text',
                'default'     => 'main',
                'description' => '目标分支，默认 main',
                'required'    => false,
            ],
            'prefix' => [
                'label'       => '路径前缀',
                'type'        => 'text',
                'default'     => '',
                'description' => '仓库内存储目录前缀，如 images/（末尾须加 /），留空则直接存在根目录',
                'required'    => false,
            ],
            'cdn' => [
                'label'       => 'CDN / 访问域名',
                'type'        => 'text',
                'default'     => '',
                'description' => '自定义访问地址前缀，如 https://cdn.jsdelivr.net/gh/owner/repo@main 或 https://cdn.example.com。留空则使用 raw.githubusercontent.com',
                'required'    => false,
            ],
        ];
    }

    /** {@inheritdoc} */
    public function upload(string $localFile, string $remotePath, string $mimeType)
    {
        $token  = trim($this->config['token'] ?? '');
        $repo   = trim($this->config['repo']  ?? '', '/ ');
        $branch = trim($this->config['branch'] ?? '') ?: 'main';
        $prefix = $this->config['prefix'] ?? '';

        if (empty($token) || empty($repo)) {
            return false;
        }

        // 构建仓库内路径
        $repoPath = ltrim($prefix . ltrim($remotePath, '/'), '/');
        $content  = base64_encode((string) file_get_contents($localFile));
        $apiUrl   = "https://api.github.com/repos/{$repo}/contents/" . rawurlencode($repoPath);
        // rawurlencode 会把 / 也编码，需还原
        $apiUrl   = "https://api.github.com/repos/{$repo}/contents/{$repoPath}";

        // 先 GET，获取已存在文件的 SHA（用于覆盖更新）
        $sha     = null;
        $getResp = $this->apiRequest('GET', $apiUrl, null, $token);
        if ($getResp !== false) {
            $existing = json_decode($getResp, true);
            if (!empty($existing['sha'])) {
                $sha = $existing['sha'];
            }
        }

        $body = [
            'message' => 'Upload via PicUp',
            'content' => $content,
            'branch'  => $branch,
        ];
        if ($sha !== null) {
            $body['sha'] = $sha;
        }

        $resp = $this->apiRequest('PUT', $apiUrl, json_encode($body), $token);
        if ($resp === false) {
            return false;
        }

        $data = json_decode($resp, true);
        if (empty($data['content']['path'])) {
            return false;
        }

        // 返回仓库内路径，由 getUrl() 拼接完整 URL
        return $repoPath;
    }

    /** {@inheritdoc} */
    public function delete(string $remotePath): bool
    {
        $token  = trim($this->config['token'] ?? '');
        $repo   = trim($this->config['repo']  ?? '', '/ ');
        $branch = trim($this->config['branch'] ?? '') ?: 'main';

        if (empty($token) || empty($repo)) {
            return false;
        }

        $repoPath = ltrim($remotePath, '/');
        $apiUrl   = "https://api.github.com/repos/{$repo}/contents/{$repoPath}";

        // 必须先获取文件 SHA 才能删除
        $getResp = $this->apiRequest('GET', $apiUrl, null, $token);
        if ($getResp === false) {
            return false;
        }
        $existing = json_decode($getResp, true);
        if (empty($existing['sha'])) {
            return false;
        }

        $body = [
            'message' => 'Delete via PicUp',
            'sha'     => $existing['sha'],
            'branch'  => $branch,
        ];

        $resp = $this->apiRequest('DELETE', $apiUrl, json_encode($body), $token);
        if ($resp === false) {
            return false;
        }

        $data = json_decode($resp, true);
        return !empty($data['commit']);
    }

    /** {@inheritdoc} */
    public function getUrl(string $remotePath): string
    {
        $repo   = trim($this->config['repo']   ?? '', '/ ');
        $branch = trim($this->config['branch'] ?? '') ?: 'main';
        $cdn    = rtrim($this->config['cdn']   ?? '', '/');
        $path   = ltrim($remotePath, '/');

        if (!empty($cdn)) {
            return $cdn . '/' . $path;
        }

        return "https://raw.githubusercontent.com/{$repo}/{$branch}/{$path}";
    }

    /** {@inheritdoc} */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string
    {
        // upload() 返回的是仓库内路径，直接存储；getUrl() 负责拼接完整 URL
        return $uploadedUrl;
    }

    /** {@inheritdoc} */
    public function alwaysNewPath(): bool
    {
        return false;
    }

    /* ------------------------------------------------------------------ */

    /**
     * 发送 GitHub API 请求
     *
     * @param string      $method  HTTP 方法
     * @param string      $url     完整 API URL
     * @param string|null $body    JSON 请求体
     * @param string      $token   Personal Access Token
     * @return string|false
     */
    private function apiRequest(string $method, string $url, $body, string $token)
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $token,
                'Accept: application/vnd.github.v3+json',
                'User-Agent: PicUp-Typecho-Plugin/1.0',
                'Content-Type: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $http >= 400) {
            return false;
        }

        return (string) $resp;
    }
}
