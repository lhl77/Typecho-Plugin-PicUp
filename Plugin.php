<?php

/**
 * PicUp for Typecho —— 多存储后端图片上传&处理插件，支持多种远程存储服务，多 Profile 通过 JSON 存储，可随时切换。
 *
 * @package PicUp
 * @author LHL
 * @version 1.2.9
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
use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Utils\Helper;
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
     * 每次请求仅执行一次上传回调兼容修复。
     */
    private static bool $uploadCompatPatched = false;

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
        TypechoPlugin::factory('admin/header.php')->header = [__CLASS__, 'adminHeader'];

        // 注册备份 Action
        Helper::addAction('picup-backup', __NAMESPACE__ . '\\Action');

        // 建备份表（若不存在）
        self::createBackupTable();

        // 迁移：确保新增的 suffixProfiles 选项存在（插件升级未经禁用/启用时自动补齐）
        self::ensureOption('suffixProfiles', '{}');
        self::ensureOption('storagePathTemplate', '{year}/{month}/');
        self::ensureOption('abPromoHidden', '0');

        // 清理旧版本保存的 HtmlElement 占位键（__picup_html_*），
        // 这些键曾因 addInput 误用而被写入配置，key 编号漂移会导致 getInput() 返回 null 崩溃
        self::cleanupStaleOptions();

        // 清除 PHP OPcache 缓存，确保插件文件更新后立即生效
        if (function_exists('opcache_reset')) {
            opcache_reset();
        } elseif (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }
    }

    public static function deactivate()
    {
        // 移除备份 Action 路由
        Helper::removeAction('picup-backup');

        // 停用时同样清除缓存
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * 检测当前数据库类型。
     * 返回 'sqlite' | 'pgsql' | 'mysql'（MariaDB / MySQL / Mysqli 均视为 mysql）
     */
    private static function getDbType(): string
    {
        try {
            $name = strtolower(Db::get()->getAdapterName());
            if (strpos($name, 'sqlite') !== false) {
                return 'sqlite';
            }
            if (strpos($name, 'pgsql') !== false || strpos($name, 'postgres') !== false) {
                return 'pgsql';
            }
        } catch (\Exception $e) {
            // ignore
        }
        return 'mysql'; // MySQL / MariaDB / Mysqli
    }

    /**
     * 确保插件选项存在（用于升级迁移）。
     * 若选项不在数据库中，自动插入默认值，避免升级后未经禁用/启用导致读取报错。
     */
    private static function ensureOption(string $name, string $defaultValue): void
    {
        try {
            $db    = Db::get();
            $table = $db->getPrefix() . 'options';
            // 读取当前插件配置
            $row = $db->fetchRow(
                $db->select('value')->from($table)->where('name = ?', 'plugin:PicUp')
            );
            if (!$row) {
                return; // 插件尚未有任何配置记录
            }
            $settings = unserialize($row['value']);
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!array_key_exists($name, $settings)) {
                $settings[$name] = $defaultValue;
                $db->query(
                    $db->update($table)
                        ->rows(['value' => serialize($settings)])
                        ->where('name = ?', 'plugin:PicUp')
                );
            }
        } catch (\Throwable $e) {
            error_log('[PicUp] ensureOption(' . $name . '): ' . $e->getMessage());
        }
    }

    /**
     * 清理插件配置中残留的 HtmlElement 占位键（__picup_html_*）。
     * 旧版本使用 $form->addInput(new HtmlElement(...))，导致这些键被写入配置。
     * 当表单元素数量变化后，编号漂移使 getInput() 返回 null 而崩溃。
     */
    private static function cleanupStaleOptions(): void
    {
        try {
            $db    = Db::get();
            $table = $db->getPrefix() . 'options';
            $row   = $db->fetchRow(
                $db->select('value')->from($table)->where('name = ?', 'plugin:PicUp')
            );
            if (!$row) {
                return;
            }
            $settings = unserialize($row['value']);
            if (!is_array($settings)) {
                return;
            }
            $dirty = false;
            foreach (array_keys($settings) as $key) {
                if (strpos($key, '__picup_html_') === 0) {
                    unset($settings[$key]);
                    $dirty = true;
                }
            }
            if ($dirty) {
                $cleanedSerial = serialize($settings);

                // 1. 更新数据库
                $db->query(
                    $db->update($table)
                        ->rows(['value' => $cleanedSerial])
                        ->where('name = ?', 'plugin:PicUp')
                );

                // 2. 同步更新 Options 内存单例，避免当前请求仍使用旧缓存
                try {
                    $optsObj = Options::alloc();

                    // 2a. 更新 Widget::$row['plugin:PicUp']（protected 属性，通过父类反射访问）
                    $rowProp = (new \ReflectionClass('Typecho\Widget'))->getProperty('row');
                    $rowProp->setAccessible(true);
                    $rowData = $rowProp->getValue($optsObj);
                    if (is_array($rowData)) {
                        $rowData['plugin:PicUp'] = $cleanedSerial;
                        $rowProp->setValue($optsObj, $rowData);
                    }

                    // 2b. 清除 Options::$pluginConfig['PicUp'] 缓存，
                    //     让下次调用 plugin('PicUp') 从更新后的 row 重新反序列化
                    $pcProp = (new \ReflectionClass(Options::class))->getProperty('pluginConfig');
                    $pcProp->setAccessible(true);
                    $pcData = $pcProp->getValue($optsObj);
                    if (is_array($pcData)) {
                        unset($pcData['PicUp']);
                        $pcProp->setValue($optsObj, $pcData);
                    }
                } catch (\Throwable $e) {
                    // 反射失败时仅记录日志；config() 末尾的安全网会兜底
                    error_log('[PicUp] cleanupStaleOptions 内存刷新失败：' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[PicUp] cleanupStaleOptions: ' . $e->getMessage());
        }
    }

    /**
     * 创建备份表 {prefix}_PicUpBackup（若不存在则建表）。
     * 自动检测数据库类型（MySQL/MariaDB、SQLite、PostgreSQL），使用对应 DDL。
     */
    private static function createBackupTable(): void
    {
        try {
            $db     = Db::get();
            $table  = $db->getPrefix() . 'PicUpBackup';
            $dbType = self::getDbType();

            switch ($dbType) {
                case 'sqlite':
                    $db->query(
                        "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                        . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                        . '"label" TEXT NOT NULL DEFAULT \'\', '
                        . '"config_json" TEXT NOT NULL DEFAULT \'{}\', '
                        . '"default_profile" TEXT NOT NULL DEFAULT \'default\', '
                        . '"backup_date" TEXT NOT NULL'
                        . ');',
                        Db::WRITE
                    );
                    break;

                case 'pgsql':
                    $db->query(
                        "CREATE TABLE IF NOT EXISTS \"{$table}\" ("
                        . '"id" SERIAL PRIMARY KEY, '
                        . '"label" VARCHAR(255) NOT NULL DEFAULT \'\', '
                        . '"config_json" TEXT NOT NULL DEFAULT \'{}\', '
                        . '"default_profile" VARCHAR(128) NOT NULL DEFAULT \'default\', '
                        . '"backup_date" TIMESTAMP NOT NULL DEFAULT NOW()'
                        . ');',
                        Db::WRITE
                    );
                    break;

                default: // mysql / mariadb
                    $db->query(
                        "CREATE TABLE IF NOT EXISTS `{$table}` ("
                        . '`id`              INT          NOT NULL AUTO_INCREMENT, '
                        . '`label`           VARCHAR(255) NOT NULL DEFAULT \'\', '
                        . '`config_json`     MEDIUMTEXT   NOT NULL, '
                        . '`default_profile` VARCHAR(128) NOT NULL DEFAULT \'default\', '
                        . '`backup_date`     DATETIME     NOT NULL, '
                        . 'PRIMARY KEY (`id`)'
                        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
                        Db::WRITE
                    );
            }
        } catch (\Exception $e) {
            error_log('[PicUp] 创建备份表失败：' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  后台 Header 注入：上传提示 Toast                                  */
    /* ------------------------------------------------------------------ */

    /**
     * 向后台 <head> 注入 CSS + JS：
     * ① 上传 Toast 提示；
     * ② 在上传面板（#upload-panel / AdminBeautify 弹框）中注入 PicUp 方案状态栏，支持切换方案与强制上传。
     *
     * @param string $header
     * @return string
     */
    public static function adminHeader(string $header): string
    {
        // 在后台请求期提前修复上传回调顺序，避免首次上传仍被后续插件覆盖。
        self::ensureUploadCompatibilityPatch();

        // ── 读取当前插件配置，输出到前端 JS ──────────────────────────────
        $picupCfgJson = '{}';
        try {
            $pluginOpts   = Options::alloc()->plugin('PicUp');
            $configJson   = $pluginOpts->configJson ?? '{}';
            $allProfiles  = json_decode($configJson, true);
            $profileKeys  = is_array($allProfiles) ? array_keys($allProfiles) : [];
            $curProfile   = trim((string)($pluginOpts->defaultProfile ?? 'default')) ?: 'default';
            $mimeScope    = (string)($pluginOpts->mimeScope ?? 'image') ?: 'image';
            $picupCfgJson = json_encode([
                'profiles'       => $profileKeys,
                'defaultProfile' => $curProfile,
                'mimeScope'      => $mimeScope,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            // 插件未启用时忽略
        }

        $header .= '<script>window.__PICUP_CFG__=' . $picupCfgJson . ';</script>';

        /* ── PicUp 异步对话框助手（兼容 AdminBeautify Dialog 劫持）── */
        $header .= <<<'END_DIALOG_HELPER'
<script>
(function(){
    /**
     * picupDialog(type, message [, defaultVal])
     *   type: 'alert' | 'confirm' | 'prompt'
     *   返回 Promise:
     *     alert   → resolve(undefined)
     *     confirm → resolve(true/false)
     *     prompt  → resolve(string/null)
     *
     * 优先使用 AdminBeautify.alert / .confirm / .prompt 公开 API（v2.2.0+）；
     * 降级到 _abPendingConfirm / _abPendingPrompt 全局回调（旧版 AB）；
     * 无 AB 时使用浏览器原生同步对话框。
     */
    window.picupDialog = function(type, msg, defVal){
        var AB = window.AdminBeautify;
        /* ① AB 公开 Promise API（推荐） */
        if(AB && typeof AB[type] === 'function'){
            return AB[type](msg, defVal);
        }
        return new Promise(function(resolve){
            if(!AB){
                /* ② 无 AB：原生同步 */
                if(type==='confirm') resolve(confirm(msg));
                else if(type==='prompt') resolve(prompt(msg,defVal));
                else { alert(msg); resolve(); }
                return;
            }
            /* ③ 旧版 AB：全局回调 */
            if(type==='confirm'){
                window._abPendingConfirm = function(r){ resolve(!!r); };
                window.confirm(msg);
            } else if(type==='prompt'){
                window._abPendingPrompt = function(r){ resolve(r); };
                window.prompt(msg, defVal||'');
            } else {
                window.alert(msg);
                resolve();
            }
        });
    };
})();
</script>
END_DIALOG_HELPER;

        $header .= <<<'END_SCRIPT'
<style>
#picup-toast{position:fixed;top:56px;right:20px;z-index:99999;padding:9px 16px 9px 12px;
border-radius:5px;font-size:13px;color:#fff;box-shadow:0 3px 12px rgba(0,0,0,.25);
display:none;max-width:300px;line-height:1.4;pointer-events:none;transition:opacity .3s;}
#picup-toast.pu-uploading{background:#3b82f6;}
#picup-toast.pu-success{background:#22c55e;}
#picup-toast.pu-error{background:#ef4444;}
/* ── PicUp 上传方案状态栏 ── */
#picup-upload-bar{
    display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;
    padding:8px 12px;margin-bottom:8px;
    background:var(--md-surface-container,#f5f5f5);
    border:1px solid var(--md-outline-variant,#e0e0e0);
    border-radius:6px;font-size:12px;
}
.pu-bar-section{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.pu-logo{font-size:11px;font-weight:700;background:var(--md-primary,#467b96);color:#fff;
  padding:1px 7px;border-radius:9999px;white-space:nowrap;}
.pu-scope-badge{display:inline-block;padding:1px 7px;border-radius:9999px;font-size:11px;
  font-weight:600;white-space:nowrap;}
.pu-scope-image{background:#dbeafe;color:#1d4ed8;}
.pu-scope-all{background:#d1fae5;color:#065f46;}
.pu-bar-label{font-size:12px;color:var(--md-on-surface-variant,#666);white-space:nowrap;}
#pu-profile-sel{
    padding:3px 8px;border:1px solid var(--md-outline-variant,#e0e0e0);
    border-radius:4px;background:var(--md-surface,#fff);
    color:var(--md-on-surface,#333);font-size:12px;cursor:pointer;
}
.pu-force-wrap{display:flex;align-items:center;gap:5px;cursor:pointer;
  color:var(--md-on-surface-variant,#666);}
.pu-force-wrap input{cursor:pointer;accent-color:var(--md-primary,#467b96);width:14px;height:14px;}
[data-theme="dark"] #picup-upload-bar{background:var(--md-dark-surface-container,#2b2930);border-color:var(--md-dark-outline-variant,#49454f);}
[data-theme="dark"] #pu-profile-sel{background:var(--md-dark-surface,#1c1b1f);border-color:var(--md-dark-outline-variant,#49454f);color:var(--md-dark-on-surface,#e6e1e5);}
[data-theme="dark"] .pu-bar-label{color:var(--md-dark-on-surface-variant,#cac4d0);}
[data-theme="dark"] .pu-force-wrap{color:var(--md-dark-on-surface-variant,#cac4d0);}
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

    /* ── PicUp 上传方案状态栏注入 ── */
    function buildUploadBar(){
        var cfg=window.__PICUP_CFG__||{};
        var profiles=cfg.profiles||[];
        var cur=cfg.defaultProfile||'default';
        var scope=cfg.mimeScope||'image';
        var div=document.createElement('div');div.id='picup-upload-bar';

        /* logo + scope badge */
        var sec1=document.createElement('div');sec1.className='pu-bar-section';
        var logo=document.createElement('span');logo.className='pu-logo';logo.textContent='PicUp';sec1.appendChild(logo);
        var badge=document.createElement('span');
        if(scope==='image'){badge.className='pu-scope-badge pu-scope-image';badge.textContent='仅图片';}
        else{badge.className='pu-scope-badge pu-scope-all';badge.textContent='所有文件';}
        badge.title='文件接管范围：'+(scope==='image'?'只接管图片，其他文件本地存储':'接管所有文件');
        sec1.appendChild(badge);
        div.appendChild(sec1);

        /* 方案切换 */
        var sec2=document.createElement('div');sec2.className='pu-bar-section';
        var lbl=document.createElement('span');lbl.className='pu-bar-label';lbl.textContent='上传方案：';sec2.appendChild(lbl);
        var sel=document.createElement('select');sel.id='pu-profile-sel';
        profiles.forEach(function(k){
            var o=document.createElement('option');o.value=k;o.textContent=k;
            if(k===cur)o.selected=true;sel.appendChild(o);
        });
        if(!profiles.length){
            var o=document.createElement('option');o.value=cur;o.textContent=cur+' ✓';sel.appendChild(o);
        }
        sec2.appendChild(sel);div.appendChild(sec2);

        /* 强制上传复选框 */
        var sec3=document.createElement('div');sec3.className='pu-bar-section';
        var fl=document.createElement('label');fl.className='pu-force-wrap';
        var cb=document.createElement('input');cb.type='checkbox';cb.id='pu-force-cb';
        fl.appendChild(cb);
        var ft=document.createElement('span');ft.textContent='忽略范围限制，强制使用以上方案上传';fl.appendChild(ft);
        sec3.appendChild(fl);div.appendChild(sec3);
        return div;
    }

    function injectBar(panel){
        if(panel.querySelector('#picup-upload-bar')) return;
        var cfg=window.__PICUP_CFG__;
        if(!cfg||!cfg.profiles) return;
        var bar=buildUploadBar();
        panel.insertBefore(bar,panel.firstChild);
    }
    /* 注入 AdminBeautify manage-medias 上传对话框 */
    function injectAbDialog(dialog){
        var body=dialog.querySelector('.ab-upload-dialog-body');
        if(!body) return;
        if(body.querySelector('#picup-upload-bar')) return;
        var cfg=window.__PICUP_CFG__;
        if(!cfg||!cfg.profiles) return;
        var dz=body.querySelector('#ab-upload-dropzone');
        var bar=buildUploadBar();
        body.insertBefore(bar,dz||body.firstChild);
    }
    /* 注入 AdminBeautify write-post 附件选择器上传标签页 */
    function injectAbAttachPicker(){
        var pane=document.getElementById('ab-ap-pane-upload');
        if(!pane) return;
        if(pane.querySelector('#picup-upload-bar')) return;
        var cfg=window.__PICUP_CFG__;
        if(!cfg||!cfg.profiles) return;
        var dz=pane.querySelector('#ab-ap-dropzone');
        var bar=buildUploadBar();
        pane.insertBefore(bar,dz||pane.firstChild);
    }

    function scanAndInject(){
        var panel=document.getElementById('upload-panel');
        if(panel) injectBar(panel);
        var dlg=document.getElementById('ab-upload-dialog');
        if(dlg) injectAbDialog(dlg);
        injectAbAttachPicker();
    }

    /* MutationObserver 监听上传面板出现（弹出面板场景） */
    if(window.MutationObserver){
        var obs=new MutationObserver(function(muts){
            for(var i=0;i<muts.length;i++){
                var nodes=muts[i].addedNodes;
                for(var j=0;j<nodes.length;j++){
                    var n=nodes[j];
                    if(n.nodeType!==1) continue;
                    if(n.id==='upload-panel'){injectBar(n);continue;}
                    if(n.id==='ab-upload-dialog'){injectAbDialog(n);continue;}
                    if(n.id==='ab-ap-pane-upload'||n.id==='ab-attach-picker-overlay'){
                        setTimeout(injectAbAttachPicker,50);continue;
                    }
                    var inner=n.querySelector&&n.querySelector('#upload-panel');
                    if(inner){injectBar(inner);continue;}
                    var abDlg=n.querySelector&&n.querySelector('#ab-upload-dialog');
                    if(abDlg){injectAbDialog(abDlg);continue;}
                    var abPicker=n.querySelector&&n.querySelector('#ab-ap-pane-upload');
                    if(abPicker){setTimeout(injectAbAttachPicker,50);}
                }
            }
        });
        obs.observe(document.body||document.documentElement,{childList:true,subtree:true});
    }

    /* ── 辅助函数：获取当前可见的 PicUp 上传方案选择器 ── */
    function getVisiblePuSel(){
        var sels=document.querySelectorAll('#pu-profile-sel');
        for(var i=sels.length-1;i>=0;i--){
            if(sels[i].offsetParent!==null) return sels[i];
        }
        return sels.length?sels[sels.length-1]:null;
    }
    function getVisiblePuCb(){
        var cbs=document.querySelectorAll('#pu-force-cb');
        for(var i=cbs.length-1;i>=0;i--){
            if(cbs[i].offsetParent!==null) return cbs[i];
        }
        return cbs.length?cbs[cbs.length-1]:null;
    }

    /* ── XHR 拦截：为 AdminBeautify 的 XHR 上传注入方案头 ── */
    (function(){
        var _open=XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open=function(method,url){
            this.__pu_url=(url||'').toString();
            return _open.apply(this,arguments);
        };
        var _send=XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send=function(body){
            var u=this.__pu_url||'';
            if(u.indexOf('/action/upload')!==-1||u.indexOf('do=upload-media')!==-1||u.indexOf('do=upload')!==-1){
                var sel=getVisiblePuSel();
                var cb=getVisiblePuCb();
                if(sel&&sel.value){
                    try{this.setRequestHeader('X-PicUp-Profile',encodeURIComponent(sel.value));}catch(e){}
                }
                if(cb&&cb.checked){
                    try{this.setRequestHeader('X-PicUp-Force','1');}catch(e){}
                }
            }
            return _send.apply(this,arguments);
        };
    })();

    /* ── fetch 拦截：Toast + 注入方案覆盖参数 ── */
    var _origFetch=window.fetch;
    window.fetch=function(resource,init){
        var urlStr=typeof resource==='string'?resource:(resource&&resource.url?resource.url:'');
        if(urlStr.indexOf('/action/upload')!==-1){
            /* 注入 PicUp 方案覆盖参数 */
            if(init&&init.body instanceof FormData){
                var sel=getVisiblePuSel();
                var forceCb=getVisiblePuCb();
                var selProfile=sel?sel.value:'';
                /* 始终发送选择的方案名，确保上传面板的选择生效 */
                if(selProfile){
                    init.body.append('_picup_profile',selProfile);
                }
                if(forceCb&&forceCb.checked){
                    init.body.append('_picup_force','1');
                }
            }
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

    /* 常规初始化（页面加载 & AdminBeautify AJAX 导航） */
    function init(){
        scanAndInject();
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}
    else{init();}
    document.addEventListener('ab:pageload',init);
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
        // 清理旧版本遗留的 HtmlElement 占位键，防止 getInput() 返回 null 崩溃
        self::cleanupStaleOptions();

        // -1. OpenSSL / TLS 版本检测警告横幅
        $sslWarningHtml = self::buildSslWarningHtml();
        if ($sslWarningHtml) {
            $form->addItem(new HtmlElement($sslWarningHtml));
        }

        // 0. 顶部插件信息 & AdminBeautify 推荐
        $pluginVersion = '1.2.9';
        try {
            $siteUrl = rtrim(Options::alloc()->siteUrl, '/');
        } catch (\Throwable $e) {
            $siteUrl = '';
        }
        $assetsUrl = $siteUrl . '/usr/plugins/PicUp/assets';

        // 仅当 AB Admin 已启用（plugin:AdminBeautify 选项存在）时才提供 AB Store 一键更新入口
        $abStoreActive = false;
        try {
            $abRow = Db::get()->fetchRow(
                Db::get()->select('value')->from('table.options')->where('name = ?', 'plugin:AdminBeautify')
            );
            $abStoreActive = !empty($abRow);
        } catch (\Throwable $e) {
            $abStoreActive = false;
        }
        $adminExtendingUrl = $abStoreActive
            ? ($siteUrl . '/admin/extending.php?panel=AdminBeautifyStore%2FPanel.php')
            : '';

        // ── 外置 CSS ──
        $form->addItem(new HtmlElement(
            '<link rel="stylesheet" href="' . $assetsUrl . '/css/picup-config.css">'
        ));

        // ── 数据注入脚本（所有 JS 模块依赖的 PHP 数据）──
        $drivers     = self::getDrivers();
        $extClasses  = self::getExtensions();

        // driverNames / extNames（用于摘要卡）
        $driverNames = [];
        foreach ($drivers as $k => $class) {
            $driverNames[$k] = $class::getName();
        }
        $extNames = [];
        foreach ($extClasses as $k => $class) {
            $extNames[$k] = $class::getName();
        }

        // env（服务器环境）
        $phpVer     = PHP_VERSION;
        $opensslVer = '';
        if (defined('OPENSSL_VERSION_TEXT')) {
            $opensslVer = OPENSSL_VERSION_TEXT;
        } elseif (function_exists('curl_version')) {
            $cv = curl_version();
            $opensslVer = $cv['ssl_version'] ?? '';
        }
        $gdWebp = function_exists('imagewebp')
            && (!function_exists('gd_info') || (gd_info()['WebP Support'] ?? true));
        $gdAvif = function_exists('imageavif')
            && (!function_exists('gd_info') || (gd_info()['AVIF Support'] ?? true));
        $imWebp = false; $imAvif = false;
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            try {
                $fmts = \Imagick::queryFormats();
                if (is_array($fmts)) {
                    $imWebp = in_array('WEBP', $fmts, true);
                    $imAvif = in_array('AVIF', $fmts, true);
                }
            } catch (\Throwable $e) {}
        }
        $imAvailable = extension_loaded('imagick') && class_exists('Imagick');

        // driversMeta / extensionsMeta（用于 GUI 编辑器）
        $driversMeta = [];
        foreach ($drivers as $key => $class) {
            $driversMeta[$key] = [
                'name'   => $class::getName(),
                'fields' => $class::getConfigFields(),
            ];
        }
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

        $picupData = json_encode([
            'version'           => $pluginVersion,
            'adminExtendingUrl' => $adminExtendingUrl,
            'ghRepo'            => 'lhl77/Typecho-Plugin-PicUp',
            'driverNames'       => $driverNames,
            'extNames'          => $extNames,
            'env'               => [
                'phpVer'      => $phpVer,
                'opensslVer'  => $opensslVer ?: '—',
                'gdWebp'      => $gdWebp,
                'gdAvif'      => $gdAvif,
                'imAvailable' => $imAvailable,
                'imWebp'      => $imWebp,
                'imAvif'      => $imAvif,
            ],
            'driversMeta'    => $driversMeta,
            'extensionsMeta' => $extensionsMeta,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        $form->addItem(new HtmlElement(
            '<script>window.__PICUP__=' . $picupData . ';</script>'
        ));

        // ── 推广栏 / 信息卡 / 分享弹窗（纯 HTML）──
        $form->addItem(new HtmlElement(<<<HTML
<div class="picup-top-actions">
    <div class="picup-promote-box">
        <span class="picup-promote-text">如果您觉得 PicUp 插件好用的话，欢迎</span>
        <button type="button" id="picup-promote-open" class="picup-promote-btn pu-primary">分享PicUp</button>
        <a class="picup-promote-btn pu-warn" href="https://blog.lhl.one/about.html#支持" target="_blank">打赏作者</a>
    </div>
</div>
<div class="picup-info-bar">
  <div class="picup-info-card">
    <h4 class="picup-info-title">PicUp — 多存储后端上传&处理插件 <small>by LHL</small></h4>
    <div class="picup-info-meta">
      <a href="https://blog.lhl.one" target="_blank">作者博客</a><span class="pu-sep">·</span>
      <a href="https://github.com/lhl77/Typecho-Plugin-PicUp" target="_blank">GitHub</a><span class="pu-sep">·</span>
      <a href="https://blog.lhl.one/artical/1026.html" target="_blank">使用文档</a>
    </div>
    <div class="picup-info-actions">
      <span class="picup-ver-badge" id="picup-ver-badge">v{$pluginVersion}</span>
      <button type="button" id="picup-check-update-btn" class="picup-update-btn">
        <span class="picup-update-dot" id="picup-update-dot"></span>检查更新
      </button>
    </div>
    <div id="picup-update-result" class="picup-update-result" style="display:none;"></div>
  </div>
  <div class="picup-info-card picup-ab-card">
    <h4 class="picup-info-title">✨ 推荐安装 AB Admin</h4>
    <div class="picup-info-meta">
      最美后台美化插件，基于 Material Design 3，让后台更美观更好用。
    </div>
    <p style="margin:0!important;display:flex!important;align-items:center!important;gap:8px!important;flex-wrap:wrap!important;">
      <button type="button" id="picup-ab-promo-open" class="picup-ab-open-btn">查看详情</button>
    </p>
  </div>
</div>

<div id="picup-promote-modal-mask" class="picup-promote-modal-mask" aria-hidden="true">
    <div class="picup-promote-modal" role="dialog" aria-modal="true" aria-labelledby="picup-promote-modal-title">
        <div class="picup-promote-modal-head">
            <h4 id="picup-promote-modal-title" class="picup-promote-modal-title">分享PicUp文案（Markdown）</h4>
            <button type="button" id="picup-promote-close" class="picup-promote-modal-close">关闭</button>
        </div>
        <div class="picup-promote-modal-body">
            <p class="picup-promote-modal-tip">可按需替换下方图片链接为你的截图，再点击"复制文案"发布到社群、博客或朋友圈。</p>
            <textarea id="picup-promote-markdown" class="picup-promote-md"># 我在用 PicUp：一个好用的 Typecho 上传插件

如果你在用 Typecho，推荐试试 PicUp。

- 支持多种存储驱动（本地、OSS、COS、Qiniu Kodo、WebDAV、GitHub、Upyun 等）
- 支持上传流程扩展（如压缩、水印、格式处理）
- 支持默认目录模板和灵活路径规则
- 支持按后缀切换上传方案
- 与 AB Admin 深度适配，附件插入与管理体验更顺手

项目地址：
- GitHub: https://github.com/lhl77/Typecho-Plugin-PicUp
- 使用文档: https://blog.lhl.one/artical/1026.html

效果截图（可替换为你的截图）：
![PicUp 截图 1](https://i.see.you/2026/03/28/Pg7m/8ba3d18125f5565ba61cf5c59e171cb9.webp)
![PicUp 截图 2](https://your-image-2.png)
![PicUp 截图 3](https://your-image-3.png)


分享示例： https://blog.lhl.one/artical/1026.html

如果你也觉得好用，欢迎一起分享 PicUp ❤️
</textarea>
            <div class="picup-promote-modal-actions">
                <button type="button" id="picup-promote-copy" class="picup-promote-copy">复制文案</button>
                <span id="picup-promote-copy-status" class="picup-promote-copy-status"></span>
            </div>
        </div>
    </div>
</div>
HTML));

                $abPromoHidden = new Text(
                        'abPromoHidden',
                        null,
                        '0',
                        _t('AB Admin 分享开关（隐藏字段）'),
                        _t('内部使用')
                );
                $abPromoHidden->input->setAttribute('type', 'hidden');
                $form->addInput($abPromoHidden);

                $form->addItem(new HtmlElement(self::buildAbPromoHtml()));

                // 设置摘要卡片（当前方案、规则、插件一览）
                $form->addItem(new HtmlElement(self::buildSummaryCardHtml()));

        // 1. 当前使用的方案名
        $defaultProfile = new Text(
            'defaultProfile',
            null,
            'default',
            _t('全局默认方案'),
            _t('填写下方 JSON 配置中某个方案的 key，该方案将用于文件上传。<br/>优先级：<b>后缀自定义方案 > 文件接管范围 > 全局默认方案</b>')
        );
        $form->addInput($defaultProfile);

        $storagePathTemplate = new Text(
            'storagePathTemplate',
            null,
            '{year}/{month}/',
            _t('默认文件存储目录模板'),
            _t('用于生成远程存储目录，适用于支持路径的存储驱动（如 local、COS、OSS、WebDAV、GitHub、Upyun、Qiniu Kodo）。'
                . '<br/>默认：<code>{year}/{month}/</code>'
                . '<br/>可用变量：<code>{year}</code>、<code>{month}</code>、<code>{day}</code>、<code>{md5}</code>、<code>{random}</code>、<code>{random-数字}</code>（如 <code>{random-5}</code>，默认长度 5）。')
        );
        $form->addInput($storagePathTemplate);

        // 2. 图形化配置编辑器（复用上方已计算的 $driversMeta 和 $extensionsMeta）
        $form->addItem(new HtmlElement(self::buildGuiHtml($driversMeta, $extensionsMeta)));

        // 文件接管范围（全局，不随方案切换）—— 保持原始 addInput 顺序不变
        $mimeScope = new \Typecho\Widget\Helper\Form\Element\Radio(
            'mimeScope',
            [
                'image' => _t('只接管图片（gif jpg jpeg png bmp tiff webp avif svg）'),
                'all'   => _t('接管所有文件（图片 + 多媒体 + 文档等）'),
            ],
            'image',
            _t('文件接管范围'),
            _t('选择「只接管图片」时，PicUp 仅处理图片类型的上传；其他类型文件将交由 Typecho 默认处理器接管（存储到本地服务器）。<br/>优先级：<b>后缀自定义方案 > 文件接管范围 > 全局默认方案</b>')
        );
        $form->addInput($mimeScope);

        // 后缀方案映射（JSON）—— 新增字段，放在末尾以避免影响已有选项顺序
        $existingSuffixProfiles = '{}';
        try {
            $existingOpts = Options::alloc()->plugin('PicUp');
            $existingSuffixProfiles = (string)($existingOpts->suffixProfiles ?? '{}') ?: '{}';
        } catch (\Throwable $e) {
            // ignore
        }
        $suffixProfiles = new Textarea(
            'suffixProfiles',
            null,
            $existingSuffixProfiles,
            _t('后缀方案映射（JSON）'),
            _t('为特定文件后缀名指定专用上传方案。<br/>格式：<code>{"jpg,jpeg,png": "方案名", "gif,webp": "另一个方案名"}</code>。'
                . '<br/>优先级：后缀自定义方案 &gt; 上传时选择的方案（仅在勾选「忽略范围限制」时生效） &gt; 全局默认方案。'
                . '<br/>若指定的方案已被删除，则自动回退到全局默认方案。')
        );
        $suffixProfiles->input->setAttribute(
            'style',
            'width:100%;max-width:800px;height:80px;font-family:monospace;font-size:13px;display:block;margin:0 auto;'
        );
        $form->addInput($suffixProfiles);

        // 方案规则：后缀方案 GUI 编辑器
        $form->addItem(new HtmlElement(self::buildSuffixProfilesGuiHtml()));


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
            'width:100%;max-width:800px;height:300px;font-family:monospace;font-size:13px;display:block;margin:0 auto;'
        );
        $form->addInput($configJson);

        // 备份管理区域
        $form->addItem(new HtmlElement(self::buildBackupHtml()));

        // 备份数据表缺失警告横幅
        $dbWarningHtml = self::buildDbWarningHtml();
        if ($dbWarningHtml) {
            $form->addItem(new HtmlElement($dbWarningHtml));
        }

        
        // 安全网：将 DB 中所有在表单里找不到对应输入的 key，添加一个不输出任何 HTML 的虚拟占位元素。
        // 这样 Config.php 的 $form->getInput($key)->value($val) 就不会对 null 调用 value()。
        // （仅在反射清理失败时起保护作用；如果反射成功，Options 内存已是干净数据，操岁不会进入此分支）
        try {
            $savedOpts = Options::alloc()->plugin('PicUp');
            foreach ($savedOpts as $key => $val) {
                if (!$form->getInput($key)) {
                    $dummy = new HtmlElement('');
                    $dummy->name = $key;
                    $form->addInput($dummy);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // ── 外置 JS（放最后，确保 DOM 已渲染）──
        $form->addItem(new HtmlElement(
            '<script src="' . $assetsUrl . '/js/picup-config.js"></script>'
        ));
    }

    public static function personalConfig(Form $form) {}

        /**
         * 构建设置摘要卡片（当前方案、驱动、插件一览），在全局设置上方。
         * CSS/JS 已外置至 assets/，PHP 数据通过 window.__PICUP__ 注入。
         */
        private static function buildSummaryCardHtml(): string
        {
            return <<<HTML
<ul class="typecho-option" id="typecho-option-item-picup-summary"><li>
<div id="picup-summary-card" class="picup-summary-card">
  <div class="psc-head">
    <span class="psc-title">设置摘要</span>
    <span class="psc-badge" id="psc-badge">—</span>
  </div>
  <div class="psc-grid" id="psc-grid">
    <div class="psc-item"><span class="psc-label">当前方案</span><span class="psc-value" id="psc-profile">—</span></div>
    <div class="psc-item"><span class="psc-label">上传驱动</span><span class="psc-value" id="psc-driver">—</span></div>
    <div class="psc-item"><span class="psc-label">接管范围</span><span class="psc-value" id="psc-scope">—</span></div>
    <div class="psc-item"><span class="psc-label">启用插件</span><span class="psc-value psc-ext" id="psc-exts">—</span></div>
    <div class="psc-item psc-full"><span class="psc-label">后缀规则</span><div class="psc-rules" id="psc-rules">—</div></div>
    <div class="psc-item psc-full"><span class="psc-label">服务器环境</span><div class="psc-env-wrap" id="psc-env-wrap"></div></div>
  </div>
</div>
</li></ul>
HTML;
        }

        /**
         * 构建 AB Admin 图文推广面板（可关闭并可从推荐卡重新打开）。
         */
        private static function buildAbPromoHtml(): string
        {
                return <<<'HTML'
<ul class="typecho-option" id="typecho-option-item-picup-ab-promo"><li>
<div id="picup-ab-promo-panel" class="picup-ab-promo-panel">
    <div class="picup-ab-promo-head">
        <h4 class="picup-ab-promo-title">为什么推荐深度适配 PicUp 的插件 AB-Admin ？</h4>
        <button type="button" id="picup-ab-promo-close" class="picup-ab-promo-close">不再显示</button>
    </div>
    <p class="picup-ab-promo-tip">
        AB Admin是一个一款为 Typecho 打造的后台美化增强插件，基于 Material Design 3 风格设计，让后台更美观、更好用。内含更好用的编辑器（适配PicUp）以及更多功能，欢迎下载使用。<br/>
        图文介绍: <a href="https://see.lhl.one/Typecho-AB-Admin" target="_blank">https://see.lhl.one/Typecho-AB-Admin</a><br/>
        Github: <a href="https://github.com/lhl77/Typecho-Plugin-AdminBeautify" target="_blank">lhl77/Typecho-Plugin-AdminBeautify</a> 
    </p>

    <div class="picup-ab-promo-grid">
        <div class="picup-ab-promo-item">
            <h5>1. 更方便直观的图片附件插入</h5>
            <p>支持更直观的附件选择和插入操作，降低编辑成本。</p>
            <button type="button" class="picup-ab-promo-shot" data-full-src="https://i.see.you/2026/07/15/soX9/e051af1be8482e28383c019079cf352a.jpg" aria-label="全屏预览图片 1">
                <img alt="更方便直观的图片附件插入" src="https://i.see.you/2026/07/15/soX9/e051af1be8482e28383c019079cf352a.jpg">
            </button>
        </div>

        <div class="picup-ab-promo-item">
            <h5>2. 支持多文件同时选择和上传</h5>
            <p>可一次选择多个文件并批量上传，显著提升素材整理效率。</p>
            <button type="button" class="picup-ab-promo-shot" data-full-src="https://i.see.you/2026/07/15/xX6y/e6a720fedf3c91da079582c19b2b84e0.jpg" aria-label="全屏预览图片 2">
                <img alt="支持多文件同时选择和上传" src="https://i.see.you/2026/07/15/xX6y/e6a720fedf3c91da079582c19b2b84e0.jpg">
            </button>
        </div>

        <div class="picup-ab-promo-item">
            <h5>3. 支持调用其他文章中的图片或附件</h5>
            <p>插入附件时可跨文章检索并复用已有文件，避免重复上传。</p>
            <button type="button" class="picup-ab-promo-shot" data-full-src="https://i.see.you/2026/07/15/Kdw9/0ad28c9743dd686c381b9fc7ce840776.jpg" aria-label="全屏预览图片 3">
                <img alt="支持调用其他文章中的图片或附件" src="https://i.see.you/2026/07/15/Kdw9/0ad28c9743dd686c381b9fc7ce840776.jpg">
            </button>
        </div>

        <div class="picup-ab-promo-item">
            <h5>4. 直观的文件管理界面</h5>
            <p>更清晰的文件卡片与操作入口，日常维护与归档管理更高效。</p>
            <button type="button" class="picup-ab-promo-shot" data-full-src="https://i.see.you/2026/07/15/c6kF/2ab6f637f6029d05199377aaa5d432b4.jpg" aria-label="全屏预览图片 4">
                <img alt="直观的文件管理界面" src="https://i.see.you/2026/07/15/c6kF/2ab6f637f6029d05199377aaa5d432b4.jpg">
            </button>
        </div>
    </div>
</div>
</li></ul>
HTML;
        }

    /**
     * 检测服务器 OpenSSL 版本，若低于 TLS 1.2 兼容要求则返回警告横幅 HTML，否则返回空字符串。
     * OpenSSL < 1.1.0 在连接 Cloudflare 等强制 TLS 1.2+ 的服务时会出现握手失败（errno=35）。
     */
    private static function buildSslWarningHtml(): string
    {
        // 获取 OpenSSL 版本号，格式如 "OpenSSL/1.0.2u" 或 "OpenSSL 1.0.2u ..."
        $opensslVer = '';
        if (defined('OPENSSL_VERSION_TEXT')) {
            $opensslVer = OPENSSL_VERSION_TEXT; // e.g. "OpenSSL 1.0.2u  20 Dec 2019"
        } elseif (function_exists('curl_version')) {
            $cv = curl_version();
            $opensslVer = $cv['ssl_version'] ?? ''; // e.g. "OpenSSL/1.0.2u"
        }

        if (empty($opensslVer)) {
            return '';
        }

        // 从字符串中提取版本号，如 1.0.2u → 1.0.2
        if (!preg_match('/(\d+)\.(\d+)\.(\d+)/i', $opensslVer, $m)) {
            return '';
        }
        $major = (int)$m[1];
        $minor = (int)$m[2];
        // patch = $m[3]，暂不需要

        // OpenSSL >= 1.1.0 才完整支持 TLS 1.2 默认协商
        // OpenSSL 1.0.2 存在问题：默认握手可能被 Cloudflare 拒绝
        $needsWarning = ($major < 1) || ($major === 1 && $minor < 1);

        if (!$needsWarning) {
            return '';
        }

        $verDisplay = htmlspecialchars($opensslVer);

        return <<<HTML
<div class="picup-ssl-warn">
  <span class="picup-ssl-icon">⚠️</span>
  <div>
    <strong>服务器 OpenSSL 版本过低，可能导致部分图床上传失败</strong><br>
    当前版本：<code>{$verDisplay}</code>（建议升级至 OpenSSL 1.1.0 及以上）<br>
    <strong>影响：</strong>OpenSSL 1.0.x 默认使用 TLS 1.0/1.1 进行握手，而 Cloudflare 等 CDN 已强制要求最低 <strong>TLS 1.2</strong>，握手会被拒绝（错误码 35）。<br>
    已受影响的图床：<strong>NodeImage</strong>（及其他使用 Cloudflare 的服务）。<br>
  </div>
</div>
HTML;
    }

    /**
     * 检测备份数据表是否存在，若不存在则返回提示横幅 HTML，否则返回空字符串。
     * 表缺失通常意味着插件是从旧版升级而来，未经历 activate() 建表流程。
     */
    private static function buildDbWarningHtml(): string
    {
        try {
            $db     = Db::get();
            $table  = $db->getPrefix() . 'PicUpBackup';
            $dbType = self::getDbType();

            switch ($dbType) {
                case 'sqlite':
                    $row = $db->fetchRow(
                        $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'", Db::READ)
                    );
                    break;
                case 'pgsql':
                    $row = $db->fetchRow(
                        $db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename='{$table}'", Db::READ)
                    );
                    break;
                default: // mysql / mariadb
                    $row = $db->fetchRow(
                        $db->query("SHOW TABLES LIKE '{$table}'", Db::READ)
                    );
            }
            if ($row) {
                return '';
            }
        } catch (\Exception $e) {
            // 查询报错 → 显示警告
        }

        return <<<'HTML'
<div class="picup-db-warn">
  <span class="picup-db-icon">🗄️</span>
  <div>
    <strong>备份数据表不存在，配置备份功能暂不可用</strong><br>
    检测到数据库中缺少 <code>{prefix}PicUpBackup</code> 表，这通常发生在插件从旧版直接升级后未经过完整的启用流程。<br>
    <ol>
      <li>前往 <strong>控制台 → 插件管理</strong>，找到 <strong>PicUp</strong></li>
      <li>点击「<strong>禁用</strong>」</li>
      <li>再点击「<strong>启用</strong>」</li>
      <li>重新打开本设置页即可正常使用备份功能</li>
    </ol>
  </div>
</div>
HTML;
    }

    /* ------------------------------------------------------------------ */
    /*  Upload Hooks                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * 根据「文件接管范围」设置判断本次文件是否应由 PicUp 处理。
     * 返回 false 时交由 Typecho 默认处理器接管（本地存储）。
     */
    private static function shouldHandleFile(array $file, string $ext): bool
    {
        try {
            $picupOpts = Options::alloc()->plugin('PicUp');
            // 注意：Typecho Config 类只实现了 __isSet()（大写 S）而非 PHP 标准 __isset()，
            // 因此 isset($obj->prop) 永远返回 false。必须直接调用 __get() 读取真实值。
            $mimeScope = (string)($picupOpts->mimeScope) ?: 'image';
        } catch (\Throwable $e) {
            $mimeScope = 'image';
        }

        if ($mimeScope !== 'image') {
            return true; // 接管所有文件
        }

        // 只接管图片：优先通过 MIME 探测，回退到扩展名
        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath && file_exists($tmpPath)) {
            $detectedMime = '';
            if (function_exists('mime_content_type')) {
                $detectedMime = (string)mime_content_type($tmpPath);
            } elseif (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = (string)finfo_file($fi, $tmpPath);
                finfo_close($fi);
            }
            if ($detectedMime !== '') {
                return strpos($detectedMime, 'image/') === 0;
            }
        }

        // 无法探测 MIME 时通过扩展名判断
        static $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif', 'svg', 'ico'];
        return in_array(strtolower($ext), $imgExts, true);
    }

    /**
     * 将 PicUp 的 upload/modify 回调移动到同组件回调链末尾。
     *
     * 背景：Typecho 会执行所有回调并以最后一个返回值作为最终结果；
     * 如 Mirai Core 在 PicUp 后返回 false 时，会覆盖 PicUp 成功结果。
     *
     * 该修复直接更新 plugins 配置并同步当前请求内存态，无需禁用/启用插件。
     */
    private static function ensureUploadCompatibilityPatch(): void
    {
        if (self::$uploadCompatPatched) {
            return;
        }
        self::$uploadCompatPatched = true;

        try {
            $plugins = TypechoPlugin::export();
            if (!is_array($plugins) || !isset($plugins['handles']) || !is_array($plugins['handles'])) {
                return;
            }

            $changed = false;
            // Mirai Core 同时接管 Widget_Upload 时，可能返回 false 覆盖 PicUp 结果。
            // 这里直接移除其 upload/modify 回调，保留 PicUp 作为唯一上传处理器。
            $changed = self::removeMiraiUploadCallbacks($plugins['handles'], 'Widget_Upload:uploadHandle', 'uploadHandle') || $changed;
            $changed = self::removeMiraiUploadCallbacks($plugins['handles'], 'Widget_Upload:modifyHandle', 'modifyHandle') || $changed;

            $changed = self::movePicUpCallbackToTail($plugins['handles'], 'Widget_Upload:uploadHandle', 'uploadHandle') || $changed;
            $changed = self::movePicUpCallbackToTail($plugins['handles'], 'Widget_Upload:modifyHandle', 'modifyHandle') || $changed;

            if (!$changed) {
                return;
            }

            // 同步当前请求中的插件回调表
            TypechoPlugin::init($plugins);

            // 持久化到 options.plugins，后续请求无需重复修复
            Helper::setOption('plugins', $plugins);
        } catch (\Throwable $e) {
            error_log('[PicUp] 兼容 Mirai Core 回调顺序修复失败：' . $e->getMessage());
        }
    }

    /**
     * 将 PicUp 的指定回调移动到组件回调链末尾。
     */
    private static function movePicUpCallbackToTail(array &$handles, string $componentKey, string $method): bool
    {
        if (!isset($handles[$componentKey]) || !is_array($handles[$componentKey])) {
            return false;
        }

        $callbacks = $handles[$componentKey];
        $original  = $callbacks;

        foreach ($callbacks as $weight => $callback) {
            if (self::isPicUpCallback($callback, $method)) {
                unset($callbacks[$weight]);
            }
        }

        if (count($callbacks) === count($original)) {
            // 当前链中没有 PicUp 回调，无需修复
            return false;
        }

        $maxWeight = 0.0;
        foreach (array_keys($callbacks) as $weight) {
            $w = (float)$weight;
            if ($w > $maxWeight) {
                $maxWeight = $w;
            }
        }

        $newWeight = $maxWeight + 0.001;
        $callbacks[(string)$newWeight] = [__CLASS__, $method];
        ksort($callbacks, SORT_NUMERIC);

        if ($callbacks === $original) {
            return false;
        }

        $handles[$componentKey] = $callbacks;
        return true;
    }

    /**
     * 判断某个回调是否属于 PicUp 指定方法。
     */
    private static function isPicUpCallback($callback, string $method): bool
    {
        if (!is_array($callback) || count($callback) < 2) {
            return false;
        }

        $className  = ltrim((string)$callback[0], '\\');
        $methodName = (string)$callback[1];
        if (strcasecmp($methodName, $method) !== 0) {
            return false;
        }

        if ($className === __CLASS__) {
            return true;
        }

        return stripos($className, 'TypechoPlugin\\PicUp\\Plugin') !== false
            || stripos($className, 'PicUp_Plugin') !== false;
    }

    /**
     * 移除 Mirai Core 对上传链路的回调，避免覆盖 PicUp 返回值。
     */
    private static function removeMiraiUploadCallbacks(array &$handles, string $componentKey, string $method): bool
    {
        if (!isset($handles[$componentKey]) || !is_array($handles[$componentKey])) {
            return false;
        }

        $callbacks = $handles[$componentKey];
        $changed = false;

        foreach ($callbacks as $weight => $callback) {
            if (!is_array($callback) || count($callback) < 2) {
                continue;
            }

            $className  = ltrim((string)$callback[0], '\\');
            $methodName = (string)$callback[1];

            if (strcasecmp($methodName, $method) !== 0) {
                continue;
            }

            if ($className === 'MiraiCore_Plugin' || stripos($className, 'MiraiCore_Plugin') !== false) {
                unset($callbacks[$weight]);
                $changed = true;
            }
        }

        if ($changed) {
            ksort($callbacks, SORT_NUMERIC);
            $handles[$componentKey] = $callbacks;
        }

        return $changed;
    }

    public static function uploadHandle(array $file)
    {
        self::ensureUploadCompatibilityPatch();

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

        // 文件接管范围检查：'image' 模式下仅处理图片，其余执行本地存储
        // 允许通过 POST 参数 _picup_force=1 或 HTTP 头 X-PicUp-Force 强制走 PicUp
        $overrideProfile = isset($_POST['_picup_profile']) ? trim((string)$_POST['_picup_profile']) : '';
        if ($overrideProfile === '' && !empty($_SERVER['HTTP_X_PICUP_PROFILE'])) {
            $overrideProfile = trim(urldecode((string)$_SERVER['HTTP_X_PICUP_PROFILE']));
        }
        $forceUpload = !empty($_POST['_picup_force']) || !empty($_SERVER['HTTP_X_PICUP_FORCE']);

        // 后缀自定义方案：最高优先级
        $suffixProfile = self::getSuffixProfile($ext);

        // 注意：不能直接 return false —— Typecho Plugin::call() 会将 signal 无条件置为 true，
        // 导致默认本地存储逻辑被跳过，上传彻底失败。必须自行完成本地存储并返回结果数组。
        if (!$suffixProfile && !$forceUpload && !self::shouldHandleFile($file, $ext)) {
            return self::_localUpload($file, $ext);
        }

        // 优先级：后缀自定义方案 > 上传时选择的方案（仅 forceUpload 时生效） > 全局默认方案
        $effectiveProfile = '';
        if ($suffixProfile !== '') {
            $effectiveProfile = $suffixProfile;
        } elseif ($overrideProfile !== '') {
            $effectiveProfile = $overrideProfile;
        }

        if ($effectiveProfile !== '') {
            $driver      = self::getDriverForProfile($effectiveProfile);
            $activeConfig = self::getActiveConfigForProfile($effectiveProfile) ?? [];
            // 指定的方案不存在（已被删除），回退到全局默认
            if (!$driver) {
                $driver      = self::getDriver();
                $activeConfig = self::getActiveConfig() ?? [];
            }
        } else {
            $driver      = self::getDriver();
            $activeConfig = self::getActiveConfig() ?? [];
        }
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
        [$processedFile, $processedMime, $extTmpFiles] = self::applyExtensions($localFile, $mimeType, $activeConfig);

        // 若 MIME 发生变化（如转为 WebP），同步更新文件扩展名
        if ($processedMime !== $mimeType) {
            $newExt = self::mimeToExt($processedMime);
            if ($newExt) {
                $ext = $newExt;
            }
        }

        $remotePath = self::buildRemotePath($ext, $processedFile);

        $uploadedUrl = $driver->upload($processedFile, $remotePath, $processedMime);

        // 清理临时文件
        if ($tmpCreated) {
            @unlink($localFile);
        }
        foreach ($extTmpFiles as $tf) {
            @unlink($tf);
        }

        if ($uploadedUrl === false) {
            $driverClass = get_class($driver);
            // 尝试从 WebDavDriver 读取详细错误信息（HTTP 状态码 + 响应片段）
            $detail = '';
            if (property_exists($driverClass, 'lastError')) {
                $detail = $driverClass::$lastError;
            }
            $msg = '[PicUp] 上传失败（' . basename(str_replace('\\', '/', $driverClass)) . '）'
                . ($detail !== '' ? '：' . $detail : '，remotePath=' . $remotePath);
            error_log($msg);
            // 抛出异常，使 AdminBeautify 等有 try/catch 的调用方能直接向用户展示真实原因
            throw new \RuntimeException($msg);
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
        self::ensureUploadCompatibilityPatch();

        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if (isset($content['attachment']) && $content['attachment']->type != $ext) {
            return false;
        }

        // 文件接管范围检查：'image' 模式下仅处理图片，其余执行本地存储
        // 同 uploadHandle，不能 return false，须自行完成本地存储。
        $overrideProfile = isset($_POST['_picup_profile']) ? trim((string)$_POST['_picup_profile']) : '';
        if ($overrideProfile === '' && !empty($_SERVER['HTTP_X_PICUP_PROFILE'])) {
            $overrideProfile = trim(urldecode((string)$_SERVER['HTTP_X_PICUP_PROFILE']));
        }
        $forceUpload = !empty($_POST['_picup_force']) || !empty($_SERVER['HTTP_X_PICUP_FORCE']);

        // 后缀自定义方案：最高优先级
        $suffixProfile = self::getSuffixProfile($ext);

        if (!$suffixProfile && !$forceUpload && !self::shouldHandleFile($file, $ext)) {
            return self::_localUpload($file, $ext);
        }

        // 优先级：后缀自定义方案 > 上传时选择的方案（仅 forceUpload 时生效） > 全局默认方案
        $effectiveProfile = '';
        if ($suffixProfile !== '') {
            $effectiveProfile = $suffixProfile;
        } elseif ($overrideProfile !== '') {
            $effectiveProfile = $overrideProfile;
        }

        if ($effectiveProfile !== '') {
            $driver       = self::getDriverForProfile($effectiveProfile);
            $activeConfig = self::getActiveConfigForProfile($effectiveProfile) ?? [];
            if (!$driver) {
                $driver       = self::getDriver();
                $activeConfig = self::getActiveConfig() ?? [];
            }
        } else {
            $driver       = self::getDriver();
            $activeConfig = self::getActiveConfig() ?? [];
        }
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
            $remotePath = self::buildRemotePath($ext, $processedFile);
        } else {
            // 若 MIME 变化（如 jpg→webp），即便驱动支持复用路径也需要新路径
            if ($processedMime !== $mimeType) {
                $remotePath = self::buildRemotePath($ext, $processedFile);
            } else {
                $remotePath = preg_match('#^https?://#i', (string)$oldPath)
                    ? self::buildRemotePath($ext, $processedFile)
                    : $oldPath;
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
            $driverClass = get_class($driver);
            $detail = '';
            if (property_exists($driverClass, 'lastError')) {
                $detail = $driverClass::$lastError;
            }
            $msg = '[PicUp] 修改文件上传失败（' . basename(str_replace('\\', '/', $driverClass)) . '）'
                . ($detail !== '' ? '：' . $detail : '，remotePath=' . $remotePath);
            error_log($msg);
            throw new \RuntimeException($msg);
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
        $path = '';
        if (isset($content['attachment'])) {
            $path = is_object($content['attachment'])
                ? ($content['attachment']->path ?? '')
                : ($content['attachment']['path'] ?? '');
        }

        if (empty($path)) {
            return false;
        }

        // 本地路径（以 / 开头）：交由本地文件系统删除，不走远程驱动
        if ($path[0] === '/') {
            $root = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
            return @unlink(rtrim($root, '/') . $path);
        }

        $driver = self::getDriver();
        if (!$driver) {
            return false;
        }

        return $driver->delete($path);
    }

    public static function attachmentHandle($content): string
    {
        $attachment = null;
        if ($content instanceof Config) {
            $attachment = $content;
        } elseif (is_array($content) && isset($content['attachment'])) {
            $attachment = is_object($content['attachment'])
                ? $content['attachment']
                : new Config((array)$content['attachment']);
        }

        $path = (string)($attachment->path ?? '');
        if (empty($path)) {
            return '';
        }

        // 本地路径（以 / 开头，或者以 usr/uploads 开头）：模拟 Typecho 默认行为，拼接站点 URL
        if ($path[0] === '/' || strpos($path, 'usr/uploads/') === 0) {
            $options = Options::alloc();
            $path = $path[0] === '/' ? $path : '/' . $path;
            return Common::url(
                $path,
                defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl
            );
        }

        // 已是完整 URL（由 Lsky Pro、Imgur 等驱动直接存储的），直接返回，
        // 不再通过当前驱动的 getUrl() 生成，避免错误地加上当前方案的前缀
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        // 自定义 URI scheme 格式（nodeimage://id|url、smms://hash|url、zpic://id|url 等）：
        // 提取 | 后面的真实 URL，不依赖当前激活驱动，避免切换方案后无法解码
        if (preg_match('#^[a-z][a-z0-9+\-.]+://[^|]+\|(.+)$#i', $path, $m)) {
            return $m[1];
        }

        // 远程相对路径（如 2025/07/abc.jpg）：使用 PicUp 驱动生成访问 URL
        $driver = self::getDriver();
        if (!$driver) {
            return $path;
        }
        return $driver->getUrl($path);
    }

    /* ------------------------------------------------------------------ */
    /*  Internal Helpers                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * 按指定方案 key 读取配置（不使用静态缓存，每次实时读取）。
     */
    private static function getActiveConfigForProfile(string $profileKey): ?array
    {
        try {
            $pluginConfig = Options::alloc()->plugin('PicUp');
        } catch (\Exception $e) {
            return null;
        }
        $all = json_decode($pluginConfig->configJson ?? '{}', true);
        if (!is_array($all) || !isset($all[$profileKey])) {
            return null;
        }
        return $all[$profileKey];
    }

    /**
     * 根据文件后缀名查找自定义方案映射。
     * 返回匹配的方案名称，无匹配时返回空字符串。
     * 映射格式：{"jpg,jpeg,png": "profileName", "gif,webp": "anotherProfile"}
     */
    private static function getSuffixProfile(string $ext): string
    {
        try {
            $pluginConfig = Options::alloc()->plugin('PicUp');
            $suffixJson = (string)($pluginConfig->suffixProfiles ?? '{}') ?: '{}';
        } catch (\Throwable $e) {
            return '';
        }

        $mapping = json_decode($suffixJson, true);
        if (!is_array($mapping)) {
            return '';
        }

        $extLower = strtolower($ext);
        foreach ($mapping as $suffixes => $profileName) {
            $suffixList = array_map('trim', explode(',', strtolower($suffixes)));
            if (in_array($extLower, $suffixList, true)) {
                $profileName = trim((string)$profileName);
                if ($profileName === '') {
                    continue;
                }
                // 验证方案是否仍然存在
                $config = self::getActiveConfigForProfile($profileName);
                if ($config !== null) {
                    return $profileName;
                }
                // 方案已被删除，跳过此映射（回退到下一优先级）
                error_log('[PicUp] getSuffixProfile: 后缀 "' . $ext . '" 映射的方案 "' . $profileName . '" 不存在，已跳过');
            }
        }
        return '';
    }

    /**
     * 按指定方案 key 实例化驱动。
     */
    private static function getDriverForProfile(string $profileKey)
    {
        $config = self::getActiveConfigForProfile($profileKey);
        if (!$config) {
            return null;
        }
        $driverKey = $config['driver'] ?? '';
        if (empty($driverKey)) {
            return null;
        }
        $drivers = self::getDrivers();
        if (!isset($drivers[$driverKey])) {
            return null;
        }
        return new $drivers[$driverKey]($config);
    }

    private static function getDriver()
    {        static $driver = null, $loaded = false;
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

    /**
     * 将文件存储到 Typecho 本地上传目录（复现 Widget\Upload 内置存储逻辑）。
     * 当 mimeScope='image' 且当前文件非图片时，由此方法完成本地存储，
     * 避免 Typecho Plugin::call() signal 机制导致上传彻底失败。
     *
     * @param array  $file 上传文件数组（含 name, size, tmp_name 等键）
     * @param string $ext  文件扩展名（已 getSafeName 处理）
     * @return array|false 成功返回与 uploadHandle 一致的结果数组，失败返回 false
     */
    private static function _localUpload(array $file, string $ext)
    {
        $uploadDir  = defined('__TYPECHO_UPLOAD_DIR__')      ? __TYPECHO_UPLOAD_DIR__      : \Widget\Upload::UPLOAD_DIR;
        $uploadRoot = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;

        $date    = new Date();
        $absDir  = Common::url($uploadDir, $uploadRoot) . '/' . $date->year . '/' . $date->month;

        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            error_log('[PicUp] _localUpload: 无法创建上传目录 ' . $absDir);
            return false;
        }

        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $absPath  = $absDir . '/' . $fileName;
        $relPath  = $uploadDir . '/' . $date->year . '/' . $date->month . '/' . $fileName;

        if (isset($file['tmp_name']) && $file['tmp_name']) {
            if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
                error_log('[PicUp] _localUpload: move_uploaded_file 失败');
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (file_put_contents($absPath, $file['bytes']) === false) {
                error_log('[PicUp] _localUpload: file_put_contents(bytes) 失败');
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (file_put_contents($absPath, $file['bits']) === false) {
                error_log('[PicUp] _localUpload: file_put_contents(bits) 失败');
                return false;
            }
        } else {
            error_log('[PicUp] _localUpload: 无可用文件内容（tmp_name/bytes/bits 均为空）');
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($absPath);
        }

        return [
            'name' => $file['name'],
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => Common::mimeContentType($absPath),
        ];
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

    /**
     * 读取全局默认存储目录模板。
     */
    private static function getStoragePathTemplate(): string
    {
        try {
            $pluginConfig = Options::alloc()->plugin('PicUp');
            $template = trim((string)($pluginConfig->storagePathTemplate ?? ''));
            if ($template !== '') {
                return $template;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '{year}/{month}/';
    }

    /**
     * 按目录模板生成远程路径（目录 + 随机文件名）。
     */
    private static function buildRemotePath(string $ext, string $localFile = ''): string
    {
        $dir = self::renderStoragePathTemplate(self::getStoragePathTemplate(), $localFile);
        $fileName = sprintf('%u', crc32(uniqid('', true))) . '.' . $ext;

        return $dir === '' ? $fileName : ($dir . '/' . $fileName);
    }

    /**
     * 渲染目录模板，支持 {year}/{month}/{day}/{md5}/{random}/{random-N}。
     */
    private static function renderStoragePathTemplate(string $template, string $localFile = ''): string
    {
        $template = trim(str_replace('\\', '/', $template));
        if ($template === '') {
            return '';
        }

        $md5 = '';
        if ($localFile !== '' && is_file($localFile)) {
            $md5 = (string)@md5_file($localFile);
        }
        if ($md5 === '') {
            $md5 = md5(uniqid('', true));
        }

        $rendered = str_ireplace(
            ['{year}', '{month}', '{day}', '{md5}'],
            [date('Y'), date('m'), date('d'), $md5],
            $template
        );

        $rendered = preg_replace_callback('/\{random(?:-(\d+))?\}/i', function ($m) {
            $len = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 5;
            if ($len <= 0) {
                $len = 5;
            }
            if ($len > 128) {
                $len = 128;
            }
            return self::generateRandomString($len);
        }, $rendered);

        $rendered = preg_replace('#/+#', '/', $rendered);
        return trim((string)$rendered, '/');
    }

    /**
     * 生成指定长度的随机字母数字字符串。
     */
    private static function generateRandomString(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
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

    /**
     * 构建 JSON 配置区下方的备份管理区域 HTML
     */
    private static function buildBackupHtml(): string
    {
        try {
            $options  = \Widget\Options::alloc();
            $security = \Typecho\Widget::widget('Widget\\Security');
            $ajaxUrl  = \Typecho\Common::url('/action/picup-backup', $options->index);
            $token    = $security->getToken($ajaxUrl);
        } catch (\Exception $e) {
            return '';
        }

        $ajaxUrlEsc = htmlspecialchars($ajaxUrl);
        $tokenEsc   = htmlspecialchars($token);

        return <<<HTML
<ul class="typecho-option" id="typecho-option-item-picup-backup"><li>
<label class="typecho-label">配置备份 (先保存设置后备份)</label>
<div id="picup-backup-wrap" data-url="{$ajaxUrlEsc}" data-token="{$tokenEsc}">
<div class="pb-toolbar">
  <div class="pb-label-wrap">
    <input type="text" id="pb-label-inp" class="pb-label-inp" placeholder="备份名称（留空自动生成）">
  </div><br/>
  <button type="button" class="pb-btn pb-btn-primary" id="pb-backup-btn">备份当前配置</button>
  <button type="button" class="pb-btn pb-btn-restore" id="pb-restore-btn" disabled>恢复选中备份</button>
  <button type="button" class="pb-btn pb-btn-del" id="pb-del-btn" disabled>删除备份</button>
</div>
<div id="pb-list-wrap">
  <div class="pb-empty">加载中…</div>
</div>
<p class="pb-status" id="pb-status"></p>
</div>
</li></ul>
HTML;
    }

    /**
     * 构建后缀自定义方案 GUI 编辑器 HTML
     */
    private static function buildSuffixProfilesGuiHtml(): string
    {
        return <<<'HTML'
<ul class="typecho-option" id="typecho-option-item-picup-suffix-gui"><li>
<label class="typecho-label">后缀自定义方案编辑器</label>
<div id="picup-suffix-gui">
<div id="ps-list"></div>
<button type="button" class="ps-add-btn" id="ps-add-btn">+ 添加后缀映射</button>
<p class="ps-hint">每行指定一组文件后缀（逗号分隔，如 <code>jpg,jpeg,png</code>）及其对应的上传方案。修改实时同步到下方 JSON。</p>
</div>
</li></ul>
HTML;
    }

    private static function buildGuiHtml(array $driversMeta, array $extensionsMeta = []): string
    {
        $toolbar = '<div id="picup-toolbar">'
            . '<div id="picup-profile-row">'
            . '<span class="picup-profile-label">' . _t('方案：') . '</span>'
            . '<select id="picup-profile-sel" class="picup-ctrl picup-input"></select>'
            . '</div>'
            . '<div id="picup-btn-group">'
            . '<button type="button" id="picup-add-btn"    class="picup-bar-btn">+ ' . _t('添加') . '</button>'
            . '<button type="button" id="picup-rename-btn" class="picup-bar-btn">' . _t('重命名') . '</button>'
            . '<button type="button" id="picup-apply-btn"  class="picup-bar-btn">' . _t('设为全局') . '</button>'
            . '<button type="button" id="picup-del-btn"    class="picup-bar-btn">' . _t('删除') . '</button>'
            . '</div>'
            . '</div>';

        return '<ul class="typecho-option" id="typecho-option-item-picup-gui"><li>'
            . '<label class="typecho-label">' . _t('配置编辑器') . '</label>'
            . '<div id="picup-gui">'
            . $toolbar
            . '<div id="picup-profile-form"></div>'
            . '<div id="picup-ext-section"></div>'
            . '<p class="picup-hint" style="margin:8px 0 0;font-size:12px;">'
            . _t('修改实时同步到下方 JSON；手动编辑 JSON 后单击文本框外部可刷新编辑器。')
            . '</p>'
            . '</div>'
            . '</li></ul>';
    }
}
