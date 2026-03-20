<?php

/**
 * PicUp for Typecho —— 多存储后端图片/附件上传插件，支持多种远程存储服务，多 Profile 通过 JSON 存储，可随时切换。
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 * @link https://github.com/lhl77/Typecho-Plugin-PicUp
 */

namespace TypechoPlugin\PicUp;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Config;
use Typecho\Common;
use Typecho\Date;
use Typecho\Plugin as TypechoPlugin;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/vendor/DriverInterface.php';
// 自动扫描 vendor 目录，加载所有实现了 DriverInterface 的驱动文件
foreach (glob(__DIR__ . '/vendor/*Driver.php') as $_driverFile) {
    require_once $_driverFile;
}

require_once __DIR__ . '/extensions/ExtensionInterface.php';
// 自动扫描 extensions 目录，加载所有实现了 ExtensionInterface 的扩展文件
foreach (glob(__DIR__ . '/extensions/*Extension.php') as $_extFile) {
    require_once $_extFile;
}

/* ------------------------------------------------------------------ */
/*  自定义 Form 元素：输出任意 HTML（用于图形化配置面板）              */
/* ------------------------------------------------------------------ */

class HtmlElement extends \Typecho\Widget\Helper\Form\Element
{
    /** @var string 要直接输出的 HTML 字符串 */
    private string $rawHtml;

    public function __construct(string $html)
    {
        $this->name = '__picup_html_' . (++self::$uniqueId);
        $this->rawHtml = $html;
    }

    public function input(?string $name = null, ?array $options = null): ?\Typecho\Widget\Helper\Layout
    {
        return null;
    }

    protected function inputValue($value): void {}

    public function render(): void
    {
        echo $this->rawHtml;
    }
}

/* ------------------------------------------------------------------ */
/*  主插件类                                                           */
/* ------------------------------------------------------------------ */

class Plugin implements PluginInterface
{
    /**
     * 所有可用驱动类映射（自动扫描构建，key 为驱动标识符）
     * 驱动文件放入 vendor/ 目录，文件名形如 XxxDriver.php，
     * 类名形如 TypechoPlugin\PicUp\vendor\XxxDriver，
     * 实现 DriverInterface 即可自动被识别。
     */
    private static function getDrivers(): array
    {
        static $drivers = null;
        if ($drivers !== null) {
            return $drivers;
        }
        $drivers = [];
        $ns = 'TypechoPlugin\\PicUp\\vendor\\';
        foreach (glob(__DIR__ . '/vendor/*Driver.php') as $file) {
            $baseName  = basename($file, '.php');
            $className = $ns . $baseName;
            if (!class_exists($className)) {
                continue;
            }
            $interfaces = class_implements($className);
            if (!$interfaces || !isset($interfaces[$ns . 'DriverInterface'])) {
                continue;
            }
            // 驱动标识：去掉 "Driver" 后缀并转小写
            $key = strtolower(substr($baseName, 0, -6));
            $drivers[$key] = $className;
        }
        ksort($drivers);
        return $drivers;
    }

    /**
     * 自动扫描 extensions/ 目录，返回所有实现了 ExtensionInterface 的扩展，按 getOrder() 排序。
     * 扩展标识为文件名去掉 "Extension" 后缀并转小写。
     */
    private static function getExtensions(): array
    {
        static $extensions = null;
        if ($extensions !== null) {
            return $extensions;
        }
        $extensions = [];
        $ns = 'TypechoPlugin\\PicUp\\extensions\\';
        foreach (glob(__DIR__ . '/extensions/*Extension.php') as $file) {
            $baseName  = basename($file, '.php');
            $className = $ns . $baseName;
            if (!class_exists($className)) {
                continue;
            }
            $interfaces = class_implements($className);
            if (!$interfaces || !isset($interfaces[$ns . 'ExtensionInterface'])) {
                continue;
            }
            // 扩展标识：去掉 "Extension" 后缀并转小写
            $key = strtolower(substr($baseName, 0, -9));
            $extensions[$key] = $className;
        }
        // 按 getOrder() 排序
        uasort($extensions, function ($a, $b) {
            return $a::getOrder() <=> $b::getOrder();
        });
        return $extensions;
    }

    /* ------------------------------------------------------------------ */
    /*  PluginInterface                                                    */
    /* ------------------------------------------------------------------ */

    public static function activate()
    {
        TypechoPlugin::factory('Widget\\Upload')->uploadHandle     = [__CLASS__, 'uploadHandle'];
        TypechoPlugin::factory('Widget\\Upload')->modifyHandle     = [__CLASS__, 'modifyHandle'];
        TypechoPlugin::factory('Widget\\Upload')->deleteHandle     = [__CLASS__, 'deleteHandle'];
        TypechoPlugin::factory('Widget\\Upload')->attachmentHandle = [__CLASS__, 'attachmentHandle'];

        // 注入后台 header：上传 Toast 提示
        // Typecho 中 admin/header.php 调用 ->filter('header', $header)，
        // 因此 hook 名为 'header'，而非 'filter'
        TypechoPlugin::factory('admin/header.php')->header = [__CLASS__, 'adminHeader'];
    }

    public static function deactivate() {}

    /* ------------------------------------------------------------------ */
    /*  后台 Header 注入：上传提示 Toast                                  */
    /* ------------------------------------------------------------------ */

    /**
     * 向后台 <head> 注入 CSS + JS，拦截 fetch 上传请求并弹出 Toast 提示。
     *
     * @param string $header
     * @return string
     */
    public static function adminHeader(string $header): string
    {
        $header .= <<<'END_SCRIPT'
<style>
#picup-toast{position:fixed;top:56px;right:20px;z-index:99999;padding:9px 16px 9px 12px;
border-radius:5px;font-size:13px;color:#fff;box-shadow:0 3px 12px rgba(0,0,0,.25);
display:none;max-width:300px;line-height:1.4;pointer-events:none;transition:opacity .3s;}
#picup-toast.pu-uploading{background:#3b82f6;}
#picup-toast.pu-success{background:#22c55e;}
#picup-toast.pu-error{background:#ef4444;}
</style>
<script>
(function(){
    var _toast,_timer,_count=0;
    function getToast(){
        if(!_toast){_toast=document.createElement('div');_toast.id='picup-toast';document.body.appendChild(_toast);}
        return _toast;
    }
    function showToast(msg,cls,dur){
        var t=getToast();clearTimeout(_timer);
        t.textContent=msg;t.className=cls;t.style.display='block';t.style.opacity='1';
        if(dur){_timer=setTimeout(function(){t.style.opacity='0';setTimeout(function(){t.style.display='none';},300);},dur);}
    }
    var _origFetch=window.fetch;
    window.fetch=function(resource,init){
        var urlStr=typeof resource==='string'?resource:(resource&&resource.url?resource.url:'');
        if(urlStr.indexOf('/action/upload')!==-1){
            _count++;showToast('\u2b06 \u6b63\u5728\u4e0a\u4f20\u2026 ('+_count+'\u4e2a)','pu-uploading');
            var p=_origFetch.apply(this,arguments);
            p.then(function(resp){
                return resp.clone().json().then(function(data){
                    _count=Math.max(0,_count-1);
                    if(data&&Array.isArray(data)&&data[1]&&data[1].title){
                        if(_count===0){showToast('\u4e0a\u4f20\u6210\u529f\uff1a'+data[1].title,'pu-success',3000);}
                        else{showToast('\u2b06 \u6b63\u5728\u4e0a\u4f20\u2026 ('+_count+'\u4e2a)','pu-uploading');}
                    }else{showToast('\u4e0a\u4f20\u5931\u8d25\uff0c\u670d\u52a1\u5668\u62d2\u7edd\u6216\u9a71\u52a8\u914d\u7f6e\u9519\u8bef','pu-error',4000);}
                }).catch(function(){
                    _count=Math.max(0,_count-1);showToast('\u4e0a\u4f20\u5931\u8d25','pu-error',4000);
                });
            }).catch(function(){
                _count=Math.max(0,_count-1);showToast('\u4e0a\u4f20\u5931\u8d25\uff0c\u7f51\u7edc\u9519\u8bef','pu-error',4000);
            });
            return p;
        }
        return _origFetch.apply(this,arguments);
    };
})();
</script>
END_SCRIPT;
        return $header;
    }

    /* ------------------------------------------------------------------ */
    /*  插件配置                                                           */
    /* ------------------------------------------------------------------ */

    public static function config(Form $form)
    {
        // 0. 顶部插件信息 & AdminBeautify 推荐
        $form->addInput(new HtmlElement(<<<'HTML'
<style>
.picup-info-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch;margin:0 0 20px;}
.picup-info-card{flex:1;min-width:220px;padding:14px 16px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;line-height:1.6;}
.picup-info-card h4{margin:0 0 6px;font-size:14px;font-weight:600;color:#374151;}
.picup-info-card p{margin:0;font-size:12px;color:#6b7280;}
.picup-info-card a{color:#3b82f6;text-decoration:none;}
.picup-info-card a:hover{text-decoration:underline;}
.picup-ab-card{border-color:#f59e0b;background:#fffbeb;}
.picup-ab-card h4{color:#92400e;}
.picup-ab-card .ab-badge{display:inline-block;padding:1px 7px;border-radius:9999px;background:#f59e0b;color:#fff;font-size:11px;font-weight:600;margin-left:6px;vertical-align:middle;}
</style>
<div class="picup-info-bar">
  <div class="picup-info-card">
    <h4>📦 PicUp — 多存储后端图片上传&处理插件</h4>
    <p>
      作者：<a href="https://blog.lhl.one" target="_blank">LHL</a>　|　
      <a href="https://github.com/lhl77/Typecho-Plugin-PicUp" target="_blank">GitHub</a>　|　
      <a href="https://blog.lhl.one/artical/1026.html" target="_blank">使用文档</a>
    </p>
  </div>
  <div class="picup-info-card picup-ab-card">
    <h4>✨ 推荐安装 Admin Beautify<span class="ab-badge">AB-Store</span></h4>
    <p>
      最美后台美化插件 (<a href="https://blog.lhl.one/artical/977.html" target="_blank"> 图文详情 </a>)，安装 <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautify" target="_blank">AdminBeautify</a> 后，
      可通过内置 <strong>AB-Store</strong> 插件仓库一键更新本插件，无需手动下载替换。
    </p>
  </div>
</div>
HTML));

        // 1. 当前使用的方案名
        $defaultProfile = new Text(
            'defaultProfile',
            null,
            'default',
            _t('当前使用的配置方案'),
            _t('填写下方 JSON 配置中某个方案的 key，该方案将用于文件上传。')
        );
        $form->addInput($defaultProfile);

        // 2. 图形化配置编辑器
        $drivers     = self::getDrivers();
        $driversMeta = [];
        foreach ($drivers as $key => $class) {
            $driversMeta[$key] = [
                'name'   => $class::getName(),
                'fields' => $class::getConfigFields(),
            ];
        }

        // 扩展元数据（含可用性检测）
        $extClasses    = self::getExtensions();
        $extensionsMeta = [];
        foreach ($extClasses as $key => $class) {
            $missingExts = [];
            foreach ($class::getRequiredPhpExtensions() as $phpExt) {
                if (!extension_loaded($phpExt)) {
                    $missingExts[] = $phpExt;
                }
            }
            $extensionsMeta[$key] = [
                'name'        => $class::getName(),
                'description' => $class::getDescription(),
                'available'   => $class::isAvailable(),
                'missingExts' => $missingExts,
                'fields'      => $class::getConfigFields(),
            ];
        }

        $form->addInput(new HtmlElement(self::buildGuiHtml($driversMeta, $extensionsMeta)));

        // 3. JSON 原始配置
        $configJson = new Textarea(
            'configJson',
            null,
            self::buildConfigTemplate(),
            _t('存储配置（JSON）'),
            _t('与上方编辑器保持同步，也可以直接编辑。每个方案需包含 <code>driver</code> 字段（可选值：'
                . implode('、', array_keys($drivers))
                . '）及对应驱动的配置项。')
        );
        $configJson->input->setAttribute(
            'style',
            'width:100%;max-width:800px;height:300px;font-family:monospace;font-size:13px;'
        );
        $form->addInput($configJson);
    }

    public static function personalConfig(Form $form) {}

    /* ------------------------------------------------------------------ */
    /*  Upload Hooks                                                       */
    /* ------------------------------------------------------------------ */

    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            error_log('[PicUp] uploadHandle: 文件名为空');
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if (empty($ext)) {
            error_log('[PicUp] uploadHandle: 无法识别文件扩展名，文件名=' . $file['name']);
            return false;
        }
        if (!\Widget\Upload::checkFileType($ext)) {
            error_log('[PicUp] uploadHandle: 文件类型不在允许列表中，ext=' . $ext);
            return false;
        }

        $driver = self::getDriver();
        if (!$driver) {
            error_log('[PicUp] uploadHandle: 无法初始化存储驱动，请检查插件配置（插件设置→当前使用的配置方案 与 JSON 中的 key 是否一致）');
            return false;
        }

        [$localFile, $mimeType, $tmpCreated] = self::resolveLocalFile($file);
        if (!$localFile) {
            error_log('[PicUp] uploadHandle: 无法获取本地临时文件，tmp_name=' . ($file['tmp_name'] ?? '(空)'));
            return false;
        }

        // 应用扩展处理（压缩 / 水印 / WebP 转换等）
        $activeConfig = self::getActiveConfig() ?? [];
        [$processedFile, $processedMime, $extTmpFiles] = self::applyExtensions($localFile, $mimeType, $activeConfig);

        // 若 MIME 发生变化（如转为 WebP），同步更新文件扩展名
        if ($processedMime !== $mimeType) {
            $newExt = self::mimeToExt($processedMime);
            if ($newExt) {
                $ext = $newExt;
            }
        }

        $date       = new Date();
        $fileName   = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $remotePath = $date->year . '/' . $date->month . '/' . $fileName;

        $uploadedUrl = $driver->upload($processedFile, $remotePath, $processedMime);

        // 清理临时文件
        if ($tmpCreated) {
            @unlink($localFile);
        }
        foreach ($extTmpFiles as $tf) {
            @unlink($tf);
        }

        if ($uploadedUrl === false) {
            error_log('[PicUp] uploadHandle: 驱动上传失败，driver=' . get_class($driver) . '，remotePath=' . $remotePath);
            return false;
        }

        return [
            'name' => $file['name'],
            'path' => $driver->getStoredPath($remotePath, $uploadedUrl),
            'size' => $file['size'] ?? 0,
            'type' => $ext,
            'mime' => $processedMime,
        ];
    }

    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if (isset($content['attachment']) && $content['attachment']->type != $ext) {
            return false;
        }

        $driver = self::getDriver();
        if (!$driver) {
            return false;
        }

        $oldPath = isset($content['attachment']) ? ($content['attachment']->path ?? null) : null;
        if ($oldPath) {
            $driver->delete($oldPath);
        }

        [$localFile, $mimeType, $tmpCreated] = self::resolveLocalFile($file);
        if (!$localFile) {
            return false;
        }

        // 应用扩展处理（压缩 / 水印 / WebP 转换等）
        $activeConfig = self::getActiveConfig() ?? [];
        [$processedFile, $processedMime, $extTmpFiles] = self::applyExtensions($localFile, $mimeType, $activeConfig);

        // 若 MIME 发生变化，更新扩展名
        if ($processedMime !== $mimeType) {
            $newExt = self::mimeToExt($processedMime);
            if ($newExt) {
                $ext = $newExt;
            }
        }

        // 由驱动决定是否需要新路径
        if ($driver->alwaysNewPath() || !$oldPath) {
            $date       = new Date();
            $fileName   = sprintf('%u', crc32(uniqid())) . '.' . $ext;
            $remotePath = $date->year . '/' . $date->month . '/' . $fileName;
        } else {
            // 若 MIME 变化（如 jpg→webp），即便驱动支持复用路径也需要新路径
            if ($processedMime !== $mimeType) {
                $date       = new Date();
                $fileName   = sprintf('%u', crc32(uniqid())) . '.' . $ext;
                $remotePath = $date->year . '/' . $date->month . '/' . $fileName;
            } else {
                $remotePath = $oldPath;
            }
        }

        $uploadedUrl = $driver->upload($processedFile, $remotePath, $processedMime);

        // 清理临时文件
        if ($tmpCreated) {
            @unlink($localFile);
        }
        foreach ($extTmpFiles as $tf) {
            @unlink($tf);
        }

        if ($uploadedUrl === false) {
            return false;
        }

        return [
            'name' => isset($content['attachment']) ? $content['attachment']->name : $file['name'],
            'path' => $driver->getStoredPath($remotePath, $uploadedUrl),
            'size' => $file['size'] ?? 0,
            'type' => $ext,
            'mime' => $processedMime,
        ];
    }

    public static function deleteHandle(array $content): bool
    {
        $driver = self::getDriver();
        if (!$driver) {
            return false;
        }

        $path = '';
        if (isset($content['attachment'])) {
            $path = is_object($content['attachment'])
                ? ($content['attachment']->path ?? '')
                : ($content['attachment']['path'] ?? '');
        }

        return !empty($path) && $driver->delete($path);
    }

    public static function attachmentHandle(Config $attachment): string
    {
        $driver = self::getDriver();
        $path   = $attachment->path ?? '';
        if (!$driver || empty($path)) {
            return $path;
        }
        return $driver->getUrl($path);
    }

    /* ------------------------------------------------------------------ */
    /*  Internal Helpers                                                   */
    /* ------------------------------------------------------------------ */

    private static function getDriver()
    {
        static $driver = null, $loaded = false;
        if ($loaded) {
            return $driver;
        }
        $loaded = true;

        $config = self::getActiveConfig();
        if (!$config) {
            error_log('[PicUp] getDriver: 未找到有效配置，请在插件设置中保存配置（defaultProfile 须与 JSON 中的 key 匹配）');
            return null;
        }

        $driverKey = $config['driver'] ?? '';
        if (empty($driverKey)) {
            error_log('[PicUp] getDriver: 配置中缺少 driver 字段');
            return null;
        }

        $drivers   = self::getDrivers();
        if (!isset($drivers[$driverKey])) {
            error_log('[PicUp] getDriver: 未知驱动标识 "' . $driverKey . '"，可用驱动：' . implode(', ', array_keys($drivers)));
            return null;
        }

        $class  = $drivers[$driverKey];
        $driver = new $class($config);
        return $driver;
    }

    private static function getActiveConfig(): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }

        try {
            $pluginConfig = Options::alloc()->plugin('PicUp');
        } catch (\Exception $e) {
            error_log('[PicUp] getActiveConfig: 读取插件配置失败：' . $e->getMessage());
            return ($cache = null);
        }

        $defaultProfile = trim((string) ($pluginConfig->defaultProfile ?? ''));
        if (empty($defaultProfile)) {
            $defaultProfile = 'default';
        }

        $jsonStr = $pluginConfig->configJson ?? '{}';
        $all     = json_decode($jsonStr, true);

        if (!is_array($all)) {
            error_log('[PicUp] getActiveConfig: configJson 解析失败（JSON 格式错误）');
            return ($cache = null);
        }

        if (!isset($all[$defaultProfile])) {
            error_log('[PicUp] getActiveConfig: 在 configJson 中未找到方案 "' . $defaultProfile . '"，现有方案：' . implode(', ', array_keys($all)));
            return ($cache = null);
        }

        return ($cache = $all[$defaultProfile]);
    }

    /** 从 $_FILES 条目中取得本地路径、MIME 类型，必要时创建临时文件 */
    private static function resolveLocalFile(array $file): array
    {
        $localFile  = $file['tmp_name'] ?? '';
        $mimeType   = $file['type'] ?? '';
        $tmpCreated = false;

        if (empty($localFile)) {
            $bits = $file['bytes'] ?? ($file['bits'] ?? null);
            if ($bits !== null) {
                $localFile = tempnam(sys_get_temp_dir(), 'picup_');
                if ($localFile === false || file_put_contents($localFile, $bits) === false) {
                    return [null, '', false];
                }
                $tmpCreated = true;
            }
        }

        if (empty($localFile) || !file_exists($localFile)) {
            return [null, '', false];
        }

        if (empty($mimeType)) {
            $mimeType = Common::mimeContentType($localFile);
        }

        return [$localFile, $mimeType, $tmpCreated];
    }

    private static function getSafeName(string &$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 按 Profile 中的 _extensions 配置，依次对本地文件执行扩展处理。
     * 返回 [处理后文件路径, 处理后 MIME, 需清理的临时文件列表]
     *
     * @param string $localFile   原始本地文件路径
     * @param string $mimeType    原始 MIME 类型
     * @param array  $profileConfig Profile 的完整配置（含 _extensions 键）
     * @return array [string $processedFile, string $processedMime, string[] $tmpFiles]
     */
    private static function applyExtensions(string $localFile, string $mimeType, array $profileConfig): array
    {
        $extClasses = self::getExtensions();
        $extConfig  = isset($profileConfig['_extensions']) && is_array($profileConfig['_extensions'])
            ? $profileConfig['_extensions']
            : [];

        $currentFile = $localFile;
        $currentMime = $mimeType;
        $tmpFiles    = [];

        foreach ($extClasses as $key => $class) {
            $conf    = isset($extConfig[$key]) && is_array($extConfig[$key]) ? $extConfig[$key] : [];
            $enabled = !empty($conf['enabled']) && $conf['enabled'] !== 'false';

            if (!$enabled) {
                continue;
            }

            if (!$class::isAvailable()) {
                continue;
            }

            $ext = new $class();
            [$newFile, $newMime] = $ext->process($currentFile, $currentMime, $conf);

            // 若产生了新临时文件（路径不同），记录以便后续清理
            if ($newFile && $newFile !== $currentFile) {
                $tmpFiles[]  = $newFile;
                $currentFile = $newFile;
            }

            if ($newMime) {
                $currentMime = $newMime;
            }
        }

        return [$currentFile, $currentMime, $tmpFiles];
    }

    /**
     * 将 MIME 类型映射为常见文件扩展名
     */
    private static function mimeToExt(string $mime): ?string
    {
        $map = [
            'image/jpeg'    => 'jpg',
            'image/jpg'     => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/bmp'     => 'bmp',
            'image/tiff'    => 'tiff',
            'image/svg+xml' => 'svg',
            'image/avif'    => 'avif',
        ];
        return $map[$mime] ?? null;
    }

    private static function buildConfigTemplate(): string
    {
        return json_encode([
            'default' => [
                'driver'      => 'local',
                'uploadDir'   => 'usr/uploads',
                'urlPrefix'   => '',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function buildGuiHtml(array $driversMeta, array $extensionsMeta = []): string
    {
        $driversJson    = json_encode($driversMeta,    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $extensionsJson = json_encode($extensionsMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        // nowdoc，避免 $ 被 PHP 插值
        $js = <<<'EOJS'
(function(){
    var DRIVERS=__DRIVERS_JSON__;
    var EXTENSIONS=__EXTENSIONS_JSON__;
    var profileSel  = document.getElementById('picup-profile-sel');
    var formDiv     = document.getElementById('picup-profile-form');
    var extSection  = document.getElementById('picup-ext-section');
    var addBtn      = document.getElementById('picup-add-btn');
    var renameBtn   = document.getElementById('picup-rename-btn');
    var delBtn      = document.getElementById('picup-del-btn');
    var applyBtn    = document.getElementById('picup-apply-btn');
    var jsonTA      = null;

    function getTA(){
        if(!jsonTA) jsonTA=document.querySelector('textarea[name="configJson"]');
        return jsonTA;
    }
    function getProfiles(){
        try{ return JSON.parse(getTA().value)||{}; }catch(e){ return {}; }
    }
    function saveProfiles(p){ getTA().value=JSON.stringify(p,null,2); }

    /* ── 只显示方案名 ── */
    function renderSelect(profiles, selected){
        profileSel.innerHTML='';
        var keys=Object.keys(profiles);
        if(!keys.length){
            var ph=document.createElement('option');
            ph.value='';ph.textContent='(\u65e0\u65b9\u6848)';ph.disabled=true;
            profileSel.appendChild(ph);
            return;
        }
        keys.forEach(function(k){
            var o=document.createElement('option');
            o.value=k; o.textContent=k;
            if(k===selected) o.selected=true;
            profileSel.appendChild(o);
        });
    }

    /* ── 构建单个输入控件 ── */
    function buildInput(fk, fd, curVal, dataAttr, dataAttrVal){
        var inp;
        if(fd.type==='select'&&fd.options){
            inp=document.createElement('select');inp.className='picup-ctrl picup-input';
            Object.keys(fd.options).forEach(function(v){
                var o=document.createElement('option');o.value=v;o.textContent=fd.options[v];
                var cur=curVal!=null?curVal:fd['default'];
                if(cur===v||String(cur)===String(v)) o.selected=true;
                inp.appendChild(o);
            });
        } else {
            inp=document.createElement('input');
            inp.type=fd.type==='password'?'password':(fd.type==='number'?'number':'text');
            inp.value=curVal!=null?curVal:(fd['default']||'');
            inp.className='picup-ctrl picup-input';
            inp.placeholder=fd['default']||'';
        }
        inp.dataset[dataAttr]=dataAttrVal||fk;
        return inp;
    }

    /* ── 渲染驱动字段 ── */
    function renderForm(profile){
        formDiv.innerHTML='';
        if(!profile) return;
        var driverKey=profile.driver||'';

        /* 驱动选择行 */
        var dRow=document.createElement('div');dRow.className='picup-field-row';
        var dLeft=document.createElement('div');dLeft.className='picup-field-left';
        var dLbl=document.createElement('label');dLbl.className='picup-field-label';
        dLbl.textContent='\u9a71\u52a8\u7c7b\u578b *';
        dLeft.appendChild(dLbl);
        var dSel=document.createElement('select');dSel.className='picup-ctrl picup-input';
        dSel.dataset.field='driver';
        Object.keys(DRIVERS).forEach(function(k){
            var o=document.createElement('option');
            o.value=k; o.textContent=DRIVERS[k].name;
            if(k===driverKey) o.selected=true;
            dSel.appendChild(o);
        });
        dLeft.appendChild(dSel);
        dRow.appendChild(dLeft);
        formDiv.appendChild(dRow);

        /* 驱动专属字段 */
        if(DRIVERS[driverKey]){
            var fields=DRIVERS[driverKey].fields;
            Object.keys(fields).forEach(function(fk){
                var fd=fields[fk];
                var row=document.createElement('div');row.className='picup-field-row';
                var left=document.createElement('div');left.className='picup-field-left';
                var lbl=document.createElement('label');lbl.className='picup-field-label';
                lbl.textContent=(fd.label||fk)+(fd.required?' *':'');
                left.appendChild(lbl);
                var inp=buildInput(fk,fd,profile[fk]!=null?profile[fk]:null,'field',fk);
                left.appendChild(inp);
                if(fd.description){
                    var desc=document.createElement('p');desc.className='picup-field-desc picup-hint';
                    desc.textContent=fd.description;left.appendChild(desc);
                }
                row.appendChild(left);formDiv.appendChild(row);
            });
        }

        formDiv.querySelectorAll('[data-field]').forEach(function(el){
            el.addEventListener('change', syncDriverFields);
            el.addEventListener('input', debounce(syncDriverFields,300));
        });
        dSel.addEventListener('change',function(){
            var p=getProfiles();var n=profileSel.value;
            if(p[n]){p[n].driver=this.value;saveProfiles(p);renderForm(p[n]);}
        });

        /* 渲染扩展面板 */
        renderExtensions(profile);
    }

    /* ── 渲染扩展面板 ── */
    function renderExtensions(profile){
        extSection.innerHTML='';
        if(!EXTENSIONS||!Object.keys(EXTENSIONS).length) return;

        var extConf = (profile&&profile._extensions&&typeof profile._extensions==='object')
            ? profile._extensions : {};

        /* 分隔线 + 标题 */
        var sep=document.createElement('div');sep.className='picup-section-sep';extSection.appendChild(sep);
        var title=document.createElement('div');title.className='picup-section-title';
        title.innerHTML='<span>\u63d2\u4ef6\u6269\u5c55</span><small class="picup-hint"> \u2014 \u6bcf\u4e2a\u65b9\u6848\u72ec\u7acb\u914d\u7f6e</small>';
        extSection.appendChild(title);

        Object.keys(EXTENSIONS).forEach(function(key){
            var ext=EXTENSIONS[key];
            var conf=extConf[key]&&typeof extConf[key]==='object'?extConf[key]:{};
            var enabled=conf.enabled===true||conf.enabled==='true';

            var card=document.createElement('div');card.className='picup-ext-card'+(enabled?' picup-ext-open':'');

            /* ── 头部行：checkbox + 名称 + badge + 描述 ── */
            var header=document.createElement('div');header.className='picup-ext-header';

            var toggleLabel=document.createElement('label');toggleLabel.className='picup-ext-toggle-label';
            var cb=document.createElement('input');cb.type='checkbox';cb.className='picup-ext-cb';
            cb.dataset.extKey=key;cb.checked=enabled;
            if(!ext.available){cb.disabled=true;}
            toggleLabel.appendChild(cb);

            var nameSpan=document.createElement('span');nameSpan.className='picup-ext-name';
            nameSpan.textContent=ext.name;
            toggleLabel.appendChild(nameSpan);
            header.appendChild(toggleLabel);

            /* 可用性 badge */
            if(ext.available){
                var avail=document.createElement('span');avail.className='picup-ext-badge picup-ext-ok';
                avail.textContent='\u53ef\u7528';header.appendChild(avail);
            } else {
                var unavail=document.createElement('span');unavail.className='picup-ext-badge picup-ext-unavail';
                unavail.title='\u7f3a\u5c11 PHP \u6269\u5c55: '+ext.missingExts.join(', ');
                unavail.textContent='\u4e0d\u53ef\u7528 \u2014 \u7f3a '+ext.missingExts.join(', ');
                header.appendChild(unavail);
            }

            if(ext.description){
                var desc=document.createElement('span');desc.className='picup-ext-desc picup-hint';
                desc.textContent=ext.description;header.appendChild(desc);
            }

            card.appendChild(header);

            /* ── 扩展专属字段（仅启用时显示）── */
            if(enabled && ext.fields && Object.keys(ext.fields).length>0){
                var fieldsDiv=document.createElement('div');fieldsDiv.className='picup-ext-fields';
                Object.keys(ext.fields).forEach(function(fk){
                    var fd=ext.fields[fk];
                    var row=document.createElement('div');row.className='picup-field-row';
                    var left=document.createElement('div');left.className='picup-field-left';
                    var lbl=document.createElement('label');lbl.className='picup-field-label';
                    lbl.textContent=(fd.label||fk)+(fd.required?' *':'');
                    left.appendChild(lbl);
                    var inp=buildInput(fk,fd,conf[fk]!=null?conf[fk]:null,'extField',fk);
                    inp.dataset.extKey=key;
                    left.appendChild(inp);
                    if(fd.description){
                        var d2=document.createElement('p');d2.className='picup-field-desc picup-hint';
                        d2.textContent=fd.description;left.appendChild(d2);
                    }
                    row.appendChild(left);fieldsDiv.appendChild(row);
                });
                fieldsDiv.querySelectorAll('[data-ext-field]').forEach(function(el){
                    el.addEventListener('change',syncExtFields);
                    el.addEventListener('input',debounce(syncExtFields,300));
                });
                card.appendChild(fieldsDiv);
            }

            extSection.appendChild(card);

            /* toggle 事件 */
            cb.addEventListener('change',function(){
                var p=getProfiles();var n=profileSel.value;
                if(!n||!p[n]) return;
                if(!p[n]._extensions||typeof p[n]._extensions!=='object') p[n]._extensions={};
                if(!p[n]._extensions[key]||typeof p[n]._extensions[key]!=='object') p[n]._extensions[key]={};
                p[n]._extensions[key].enabled=this.checked;
                saveProfiles(p);
                renderExtensions(p[n]);
            });
        });
    }

    /* ── 同步驱动字段 ── */
    function syncDriverFields(){
        var p=getProfiles();var n=profileSel.value;
        if(!n||!p[n]) return;
        formDiv.querySelectorAll('[data-field]').forEach(function(el){ p[n][el.dataset.field]=el.value; });
        saveProfiles(p);
    }

    /* ── 同步扩展字段 ── */
    function syncExtFields(){
        var p=getProfiles();var n=profileSel.value;
        if(!n||!p[n]) return;
        if(!p[n]._extensions||typeof p[n]._extensions!=='object') p[n]._extensions={};
        extSection.querySelectorAll('[data-ext-field]').forEach(function(el){
            var eKey=el.dataset.extKey;
            var fKey=el.dataset.extField;
            if(!p[n]._extensions[eKey]||typeof p[n]._extensions[eKey]!=='object') p[n]._extensions[eKey]={};
            p[n]._extensions[eKey][fKey]=el.value;
        });
        saveProfiles(p);
    }

    function debounce(fn,ms){ var t; return function(){ clearTimeout(t);t=setTimeout(fn,ms); }; }

    function init(){
        var p=getProfiles();var first=Object.keys(p)[0]||null;
        renderSelect(p,first);
        renderForm(first?p[first]:null);
    }

    profileSel.addEventListener('change',function(){
        var p=getProfiles(); renderForm(p[this.value]||null);
    });

    /* ── 添加方案 ── */
    addBtn.addEventListener('click',function(){
        var name=prompt('\u8bf7\u8f93\u5165\u65b0\u65b9\u6848\u540d\u79f0:');
        if(!name||!name.trim()) return; name=name.trim();
        var p=getProfiles();
        if(p[name]){alert('\u65b9\u6848 "'+name+'" \u5df2\u5b58\u5728\u3002');return;}
        var dk=Object.keys(DRIVERS)[0]||'local';
        p[name]={driver:dk,_extensions:{}};saveProfiles(p);renderSelect(p,name);renderForm(p[name]);
    });

    /* ── 重命名方案 ── */
    renameBtn.addEventListener('click',function(){
        var oldName=profileSel.value;
        if(!oldName) return;
        var newName=prompt('\u65b0\u65b9\u6848\u540d\u79f0:', oldName);
        if(!newName||!newName.trim()) return; newName=newName.trim();
        if(newName===oldName) return;
        var p=getProfiles();
        if(p[newName]){alert('\u65b9\u6848 "'+newName+'" \u5df2\u5b58\u5728\u3002');return;}
        var np={};
        Object.keys(p).forEach(function(k){ np[k===oldName?newName:k]=p[k]; });
        saveProfiles(np);renderSelect(np,newName);renderForm(np[newName]||null);
    });

    /* ── 删除方案 ── */
    delBtn.addEventListener('click',function(){
        var name=profileSel.value;if(!name) return;
        if(!confirm('\u786e\u8ba4\u5220\u9664\u65b9\u6848 "'+name+'"\uff1f\u6b64\u64cd\u4f5c\u4e0d\u53ef\u64a4\u9500\u3002')) return;
        var p=getProfiles();delete p[name];saveProfiles(p);
        var first=Object.keys(p)[0]||null;
        renderSelect(p,first);renderForm(first?p[first]:null);
    });

    /* ── 应用方案 ── */
    applyBtn.addEventListener('click',function(){
        var name=profileSel.value;if(!name) return;
        var dpInput=document.querySelector('input[name="defaultProfile"]');
        if(dpInput){ dpInput.value=name; }
        applyBtn.textContent='\u5df2\u5e94\u7528';
        setTimeout(function(){ applyBtn.textContent='\u5e94\u7528\u6b64\u65b9\u6848'; },1800);
    });

    function setup(){
        var ta=getTA();
        if(ta){
            ta.addEventListener('blur',function(){
                var p=getProfiles();var cur=profileSel.value;
                renderSelect(p,cur&&p[cur]?cur:(Object.keys(p)[0]||null));
                renderForm(p[profileSel.value]||null);
            });
        }
        init();
    }
    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',setup);
    } else {
        setup();
    }
})();
EOJS;

        $js = str_replace('__DRIVERS_JSON__',    $driversJson,    $js);
        $js = str_replace('__EXTENSIONS_JSON__', $extensionsJson, $js);

        $css = <<<'EOCSS'
<style>
/* ── PicUp 配置编辑器 ── */
#picup-gui{
    border:1px solid #dde1e6;border-radius:8px;background:#f7f8fa;
    padding:16px;max-width:760px;width:100%;box-sizing:border-box;
    color:inherit;box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.picup-ctrl{
    border:1px solid #c5cad3;background:#fff;color:#1a1a1a;border-radius:5px;
    outline:none;font-size:13px;
    transition:border-color .15s,background .15s,color .15s,box-shadow .15s;
}
.picup-ctrl:focus{border-color:#6750a4;box-shadow:0 0 0 3px rgba(103,80,164,.15);}
.picup-input{width:100%;padding:7px 10px;min-height:36px;box-sizing:border-box;}
/* 工具栏 */
#picup-toolbar{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #e4e7eb;
}
.picup-profile-label{font-size:13px;font-weight:600;white-space:nowrap;color:#3c3c3c;}
#picup-profile-row{display:flex;align-items:center;gap:8px;flex:1;min-width:140px;}
#picup-profile-sel{flex:1;min-width:0;}
#picup-btn-group{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
/* 字段行 */
.picup-field-row{margin-bottom:12px;}
.picup-field-left{display:flex;flex-direction:column;gap:4px;}
.picup-field-label{font-size:13px;font-weight:600;color:#3c3c3c;}
.picup-field-desc{margin:0;font-size:12px;line-height:1.5;}
.picup-hint{color:#6e6e6e;}
/* 按钮 */
.picup-bar-btn{
    padding:6px 12px;min-height:32px;border:none;border-radius:5px;
    font-size:12px;cursor:pointer;white-space:nowrap;
    transition:opacity .15s,transform .1s;
    display:inline-flex;align-items:center;justify-content:center;gap:4px;
}
.picup-bar-btn:hover{opacity:.85;}
.picup-bar-btn:active{transform:scale(.97);}
#picup-add-btn   {background:#6750a4;color:#fff;}
#picup-rename-btn{background:#2563eb;color:#fff;}
#picup-del-btn   {background:#b3261e;color:#fff;}
#picup-apply-btn {background:#16a34a;color:#fff;}
/* ── 扩展面板 ── */
.picup-section-sep{margin:16px 0 12px;border-top:1px solid #e4e7eb;}
.picup-section-title{font-size:13px;font-weight:700;color:#3c3c3c;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.picup-ext-card{
    border:1px solid #e0e3ea;border-radius:6px;margin-bottom:8px;
    background:#fff;overflow:hidden;transition:border-color .15s;
}
.picup-ext-card.picup-ext-open{border-color:#6750a4;}
.picup-ext-header{
    display:flex;align-items:center;flex-wrap:wrap;gap:8px;
    padding:10px 12px;
}
.picup-ext-toggle-label{display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none;}
.picup-ext-cb{width:16px;height:16px;cursor:pointer;accent-color:#6750a4;}
.picup-ext-name{font-size:13px;font-weight:600;color:#1a1a1a;}
.picup-ext-badge{
    display:inline-flex;align-items:center;padding:1px 7px;border-radius:10px;
    font-size:11px;font-weight:600;white-space:nowrap;
}
.picup-ext-ok{background:#dcfce7;color:#15803d;}
.picup-ext-unavail{background:#fee2e2;color:#b91c1c;cursor:help;}
.picup-ext-desc{font-size:12px;color:#6e6e6e;flex:1;min-width:0;}
.picup-ext-fields{padding:10px 12px 4px;border-top:1px solid #f0f0f0;background:#fafafa;}
/* ── 移动端 ── */
@media(max-width:600px){
    #picup-gui{padding:12px;border-radius:6px;}
    #picup-toolbar{flex-direction:column;align-items:stretch;gap:8px;}
    #picup-profile-row{flex:none;width:100%;}
    #picup-btn-group{width:100%;}
    .picup-bar-btn{flex:1;padding:8px 6px;min-height:38px;font-size:13px;}
    .picup-ext-header{flex-direction:column;align-items:flex-start;}
}
@media(max-width:400px){.picup-bar-btn{font-size:12px;padding:7px 4px;}}
/* ── 暗色模式 ── */
l[data-theme="dark"] #picup-gui,
[data-theme="dark"] #picup-gui{border-color:#49454f;background:#1c1b1f;box-shadow:0 2px 8px rgba(0,0,0,.35);}
l[data-theme="dark"] #picup-toolbar,
[data-theme="dark"] #picup-toolbar{border-bottom-color:#3a3740;}
l[data-theme="dark"] .picup-profile-label,
[data-theme="dark"] .picup-profile-label{color:#cac4d0;}
l[data-theme="dark"] .picup-ctrl,
[data-theme="dark"] .picup-ctrl{border-color:#49454f;background:#2b2930;color:#e6e1e5;}
l[data-theme="dark"] .picup-ctrl:focus,
[data-theme="dark"] .picup-ctrl:focus{border-color:#d0bcff;box-shadow:0 0 0 3px rgba(208,188,255,.2);}
l[data-theme="dark"] .picup-field-label,
[data-theme="dark"] .picup-field-label{color:#cac4d0;}
l[data-theme="dark"] .picup-hint,
[data-theme="dark"] .picup-hint{color:#938f99;}
l[data-theme="dark"] .picup-section-sep,
[data-theme="dark"] .picup-section-sep{border-top-color:#3a3740;}
l[data-theme="dark"] .picup-section-title,
[data-theme="dark"] .picup-section-title{color:#cac4d0;}
l[data-theme="dark"] .picup-ext-card,
[data-theme="dark"] .picup-ext-card{border-color:#3a3740;background:#242129;}
l[data-theme="dark"] .picup-ext-card.picup-ext-open,
[data-theme="dark"] .picup-ext-card.picup-ext-open{border-color:#d0bcff;}
l[data-theme="dark"] .picup-ext-name,
[data-theme="dark"] .picup-ext-name{color:#e6e1e5;}
l[data-theme="dark"] .picup-ext-fields,
[data-theme="dark"] .picup-ext-fields{border-top-color:#3a3740;background:#1c1b1f;}
</style>
EOCSS;

        $toolbar = '<div id="picup-toolbar">'
            . '<div id="picup-profile-row">'
            . '<span class="picup-profile-label">' . _t('方案：') . '</span>'
            . '<select id="picup-profile-sel" class="picup-ctrl picup-input"></select>'
            . '</div>'
            . '<div id="picup-btn-group">'
            . '<button type="button" id="picup-add-btn"    class="picup-bar-btn">+ ' . _t('添加') . '</button>'
            . '<button type="button" id="picup-rename-btn" class="picup-bar-btn">' . _t('重命名') . '</button>'
            . '<button type="button" id="picup-apply-btn"  class="picup-bar-btn">' . _t('应用此方案') . '</button>'
            . '<button type="button" id="picup-del-btn"    class="picup-bar-btn">' . _t('删除') . '</button>'
            . '</div>'
            . '</div>';

        return $css
            . '<ul class="typecho-option" id="typecho-option-item-picup-gui"><li>'
            . '<label class="typecho-label">' . _t('配置编辑器') . '</label>'
            . '<div id="picup-gui">'
            . $toolbar
            . '<div id="picup-profile-form"></div>'
            . '<div id="picup-ext-section"></div>'
            . '<p class="picup-hint" style="margin:8px 0 0;font-size:12px;">'
            . _t('修改实时同步到下方 JSON；手动编辑 JSON 后单击文本框外部可刷新编辑器。')
            . '</p>'
            . '</div>'
            . '<script>' . $js . '</script>'
            . '</li></ul>';
    }
}
