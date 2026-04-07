<?php

/**
 * PicUp for Typecho —— 多存储后端图片上传&处理插件，支持多种远程存储服务，多 Profile 通过 JSON 存储，可随时切换。
 *
 * @package PicUp
 * @author LHL
 * @version 1.2.0
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
     * 优先使用 AdminBeautify.alert / .confirm / .prompt 公开 API（v2.1.32+）；
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

    /* ── XHR 拦截：为 AdminBeautify 的 XHR 上传注入方案头 ── */
    (function(){
        var _open=XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open=function(method,url){
            this.__pu_url=(url||'').toString();
            return _open.apply(this,arguments);
        };
        var _send=XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send=function(body){
            if(this.__pu_url&&this.__pu_url.indexOf('do=upload-media')!==-1){
                var sel=document.getElementById('pu-profile-sel');
                var cb=document.getElementById('pu-force-cb');
                if(sel&&sel.value){
                    try{this.setRequestHeader('X-PicUp-Profile',sel.value);}catch(e){}
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
                var cfg=window.__PICUP_CFG__||{};
                var sel=document.getElementById('pu-profile-sel');
                var forceCb=document.getElementById('pu-force-cb');
                var selProfile=sel?sel.value:'';
                /* 仅当选择了非默认方案时注入（减少不必要的参数） */
                if(selProfile&&selProfile!==(cfg.defaultProfile||'')){
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
        // -1. OpenSSL / TLS 版本检测警告横幅
        $sslWarningHtml = self::buildSslWarningHtml();
        if ($sslWarningHtml) {
            $form->addInput(new HtmlElement($sslWarningHtml));
        }

        // 0. 顶部插件信息 & AdminBeautify 推荐
        $form->addInput(new HtmlElement(<<<'HTML'
<style>
.picup-info-bar{display:flex!important;flex-wrap:wrap!important;gap:10px!important;align-items:stretch!important;margin:0 0 20px!important;}
.picup-info-card{
    flex:1!important;min-width:220px!important;padding:14px 16px!important;border-radius:10px!important;line-height:1.6!important;
    border:1px solid var(--md-outline-variant,#cac4d0)!important;
    background:var(--md-surface-container-low,#f7f2fa)!important;
    box-sizing:border-box!important;
}
.picup-info-card h4{margin:0 0 6px!important;font-size:14px!important;font-weight:600!important;color:var(--md-on-surface,#1c1b1f)!important;}
.picup-info-card p{margin:0!important;font-size:12px!important;color:var(--md-on-surface-variant,#49454f)!important;}
.picup-info-card a{color:var(--md-primary,#6750a4)!important;text-decoration:none!important;}
.picup-info-card a:hover{text-decoration:underline!important;}
.picup-ab-card{border-color:#f59e0b!important;background:#fffbeb!important;}
.picup-ab-card h4{color:#92400e!important;}
.picup-ab-card p{color:#78350f!important;}
.picup-ab-card a{color:#b45309!important;}
.picup-ab-card .ab-badge{display:inline-block!important;padding:1px 7px!important;border-radius:9999px!important;background:#f59e0b!important;color:#fff!important;font-size:11px!important;font-weight:600!important;margin-left:6px!important;vertical-align:middle!important;}
[data-theme="dark"] .picup-info-card{border-color:var(--md-dark-outline-variant,#49454f)!important;background:var(--md-dark-surface-container,#2b2930)!important;}
[data-theme="dark"] .picup-info-card h4{color:var(--md-dark-on-surface,#e6e1e5)!important;}
[data-theme="dark"] .picup-info-card p{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
[data-theme="dark"] .picup-info-card a{color:var(--md-dark-primary,#d0bcff)!important;}
[data-theme="dark"] .picup-ab-card{border-color:#92400e!important;background:#2c1a00!important;}
[data-theme="dark"] .picup-ab-card h4{color:#fbbf24!important;}
[data-theme="dark"] .picup-ab-card p,[data-theme="dark"] .picup-ab-card a{color:#fcd34d!important;}
</style>
<div class="picup-info-bar">
  <div class="picup-info-card">
    <h4>📦 PicUp — 多存储后端上传&处理插件</h4>
    <p>
      作者：<a href="https://blog.lhl.one" target="_blank">LHL</a>　|　
      <a href="https://github.com/lhl77/Typecho-Plugin-PicUp" target="_blank">GitHub</a>　|　
      <a href="https://blog.lhl.one/artical/1026.html" target="_blank">使用文档</a>
    </p>
    <p>版本：v1.2.0</p>
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
            'width:100%;max-width:800px;height:300px;font-family:monospace;font-size:13px;display:block;margin:0 auto;'
        );
        $form->addInput($configJson);

        // 4. 备份管理区域
        $form->addInput(new HtmlElement(self::buildBackupHtml()));

        // -0. 备份数据表缺失警告横幅
        $dbWarningHtml = self::buildDbWarningHtml();
        if ($dbWarningHtml) {
            $form->addInput(new HtmlElement($dbWarningHtml));
        }
        
        // 1-b. 文件接管范围（全局设置，不随方案切换）
        $mimeScope = new \Typecho\Widget\Helper\Form\Element\Radio(
            'mimeScope',
            [
                'image' => _t('只接管图片（gif jpg jpeg png bmp tiff webp avif svg）'),
                'all'   => _t('接管所有文件（图片 + 多媒体 + 文档等）'),
            ],
            'image',
            _t('文件接管范围'),
            _t('选择「只接管图片」时，PicUp 仅处理图片类型的上传；其他类型文件将交由 Typecho 默认处理器接管（存储到本地服务器）。')
        );
        $form->addInput($mimeScope);
    }

    public static function personalConfig(Form $form) {}

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
<style>
.picup-ssl-warn{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;margin:0 0 16px;border-radius:8px;border:1px solid #f97316;background:#fff7ed;color:#7c2d12;font-size:13px;line-height:1.6;}
.picup-ssl-warn .picup-ssl-icon{font-size:22px;flex-shrink:0;margin-top:1px;}
.picup-ssl-warn strong{color:#9a3412;}
.picup-ssl-warn code{background:#fed7aa;padding:1px 5px;border-radius:4px;font-size:12px;}
.picup-ssl-warn ul{margin:4px 0 0 18px;padding:0;}
.picup-ssl-warn ul li{margin:2px 0;}
</style>
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
<style>
.picup-db-warn{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;margin:0 0 16px;
  border-radius:8px;border:1px solid #dc2626;background:#fef2f2;color:#7f1d1d;font-size:13px;line-height:1.6;}
.picup-db-warn .picup-db-icon{font-size:22px;flex-shrink:0;margin-top:1px;}
.picup-db-warn strong{color:#991b1b;}
.picup-db-warn code{background:#fecaca;padding:1px 5px;border-radius:4px;font-size:12px;}
.picup-db-warn ol{margin:6px 0 0 18px;padding:0;}
.picup-db-warn ol li{margin:3px 0;}
</style>
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

        // 文件接管范围检查：'image' 模式下仅处理图片，其余执行本地存储
        // 允许通过 POST 参数 _picup_force=1 或 HTTP 头 X-PicUp-Force 强制走 PicUp
        $overrideProfile = isset($_POST['_picup_profile']) ? trim((string)$_POST['_picup_profile']) : '';
        if ($overrideProfile === '' && !empty($_SERVER['HTTP_X_PICUP_PROFILE'])) {
            $overrideProfile = trim((string)$_SERVER['HTTP_X_PICUP_PROFILE']);
        }
        $forceUpload = !empty($_POST['_picup_force']) || !empty($_SERVER['HTTP_X_PICUP_FORCE']);

        // 注意：不能直接 return false —— Typecho Plugin::call() 会将 signal 无条件置为 true，
        // 导致默认本地存储逻辑被跳过，上传彻底失败。必须自行完成本地存储并返回结果数组。
        if (!$forceUpload && !self::shouldHandleFile($file, $ext)) {
            return self::_localUpload($file, $ext);
        }

        // 优先使用上传窗口覆盖的方案，否则使用全局默认方案
        if ($overrideProfile !== '') {
            $driver      = self::getDriverForProfile($overrideProfile);
            $activeConfig = self::getActiveConfigForProfile($overrideProfile) ?? [];
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

        // 文件接管范围检查：'image' 模式下仅处理图片，其余执行本地存储
        // 同 uploadHandle，不能 return false，须自行完成本地存储。
        $overrideProfile = isset($_POST['_picup_profile']) ? trim((string)$_POST['_picup_profile']) : '';
        if ($overrideProfile === '' && !empty($_SERVER['HTTP_X_PICUP_PROFILE'])) {
            $overrideProfile = trim((string)$_SERVER['HTTP_X_PICUP_PROFILE']);
        }
        $forceUpload = !empty($_POST['_picup_force']) || !empty($_SERVER['HTTP_X_PICUP_FORCE']);

        if (!$forceUpload && !self::shouldHandleFile($file, $ext)) {
            return self::_localUpload($file, $ext);
        }

        if ($overrideProfile !== '') {
            $driver       = self::getDriverForProfile($overrideProfile);
            $activeConfig = self::getActiveConfigForProfile($overrideProfile) ?? [];
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

    public static function attachmentHandle(Config $attachment): string
    {
        $path = (string)($attachment->path ?? '');
        if (empty($path)) {
            return '';
        }

        // 本地路径（以 / 开头）：模拟 Typecho 默认行为，拼接站点 URL
        if ($path[0] === '/') {
            $options = Options::alloc();
            return Common::url(
                $path,
                defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $options->siteUrl
            );
        }

        // 远程路径（完整 URL 或驱动专属格式）：使用 PicUp 驱动生成访问 URL
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
<style>
#picup-backup-wrap{padding:16px;max-width:800px!important;margin:0 auto!important;}
.pb-toolbar{display:flex!important;gap:8px!important;flex-wrap:wrap!important;align-items:center!important;margin-bottom:12px!important;}
.pb-btn{
    padding:6px 14px!important;min-height:32px!important;border:none!important;border-radius:20px!important;font-size:12px!important;
    cursor:pointer!important;white-space:nowrap!important;font-weight:500!important;letter-spacing:.02em!important;
    transition:opacity .15s,box-shadow .15s!important;
    display:inline-flex!important;align-items:center!important;gap:4px!important;
    box-sizing:border-box!important;
}
#typecho-option-item-configJson-3 li{padding:16px;}
.pb-btn:hover{opacity:.88!important;box-shadow:0 1px 4px rgba(0,0,0,.15)!important;}
.pb-btn:active{transform:scale(.97)!important;}
.pb-btn-primary{background:var(--md-primary,#6750a4)!important;color:var(--md-on-primary,#fff)!important;}
.pb-btn-restore{background:var(--md-primary-container,#eaddff)!important;color:var(--md-on-surface,#1c1b1f)!important;}
.pb-btn-del{background:var(--md-error,#b3261e)!important;color:#fff!important;}
.pb-btn:disabled{opacity:.4!important;cursor:not-allowed!important;box-shadow:none!important;}
.pb-label-wrap{display:flex!important;align-items:center!important;gap:6px!important;}
.pb-label-inp{
    border:1px solid var(--md-outline,#79747e)!important;
    border-radius:6px!important;padding:5px 9px!important;font-size:12px!important;min-width:180px!important;
    color:var(--md-on-surface,#1c1b1f)!important;
    background:var(--md-surface,#fffbfe)!important;
    transition:border-color .15s!important;outline:none!important;
    box-sizing:border-box!important;
}
.pb-label-inp:focus{border-color:var(--md-primary,#6750a4)!important;box-shadow:0 0 0 3px rgba(103,80,164,.15)!important;}
.pb-list{border:1px solid var(--md-outline-variant,#cac4d0)!important;border-radius:8px!important;overflow:hidden!important;margin-top:4px!important;}
.pb-list-head{
    display:grid!important;grid-template-columns:1fr 110px 90px 86px!important;gap:0!important;
    background:var(--md-surface-container,#f3edf7)!important;
    font-size:12px!important;font-weight:600!important;
    color:var(--md-on-surface-variant,#49454f)!important;
    padding:7px 10px!important;
}
.pb-list-row{
    display:grid!important;grid-template-columns:1fr 110px 90px 86px!important;gap:0!important;
    font-size:12px!important;padding:7px 10px!important;
    border-top:1px solid var(--md-outline-variant,#cac4d0)!important;
    align-items:center!important;color:var(--md-on-surface,#1c1b1f)!important;
    transition:background .1s!important;
}
.pb-list-row:hover{background:var(--md-surface-container-low,#f7f2fa)!important;}
.pb-row-label{font-weight:500!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;padding-right:6px!important;}
.pb-row-date{color:var(--md-on-surface-variant,#49454f)!important;font-size:11px!important;}
.pb-row-profile{color:var(--md-primary,#6750a4)!important;font-size:11px!important;font-weight:600!important;}
.pb-row-actions{display:flex!important;gap:4px!important;}
.pb-empty{padding:20px!important;text-align:center!important;color:var(--md-on-surface-variant,#49454f)!important;font-size:13px!important;}
.pb-status{margin:8px 0 0!important;font-size:12px!important;min-height:18px!important;color:var(--md-primary,#6750a4)!important;}
[data-theme="dark"] .pb-list{border-color:var(--md-dark-outline-variant,#49454f)!important;}
[data-theme="dark"] .pb-list-head{background:var(--md-dark-surface-container,#2b2930)!important;color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
[data-theme="dark"] .pb-list-row{border-top-color:var(--md-dark-outline-variant,#49454f)!important;color:var(--md-dark-on-surface,#e6e1e5)!important;}
[data-theme="dark"] .pb-list-row:hover{background:var(--md-dark-surface,#1c1b1f)!important;}
[data-theme="dark"] .pb-label-inp{border-color:var(--md-dark-outline,#938f99)!important;background:var(--md-dark-surface,#1c1b1f)!important;color:var(--md-dark-on-surface,#e6e1e5)!important;}
[data-theme="dark"] .pb-row-date{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
[data-theme="dark"] .pb-row-profile{color:var(--md-dark-primary,#d0bcff)!important;}
[data-theme="dark"] .pb-status{color:var(--md-dark-primary,#d0bcff)!important;}
[data-theme="dark"] .pb-btn-restore{background:#3a2f56!important;color:var(--md-dark-on-surface,#e6e1e5)!important;}
@media(max-width:600px){
  .pb-list-head,.pb-list-row{grid-template-columns:1fr 80px 70px!important;}
  .pb-head-profile,.pb-row-profile{display:none!important;}
}
</style>
<div class="pb-toolbar">
  <div class="pb-label-wrap">
    <input type="text" id="pb-label-inp" class="pb-label-inp" placeholder="备份名称（留空自动生成）">
  </div><br/>
  <button type="button" class="pb-btn pb-btn-primary" id="pb-backup-btn">备份当前配置</button>
  <button type="button" class="pb-btn pb-btn-restore" id="pb-restore-btn" disabled>↩ 从数据库中恢复</button>
  <button type="button" class="pb-btn pb-btn-del" id="pb-del-btn" disabled>删除备份</button>
</div>
<div id="pb-list-wrap">
  <div class="pb-empty">加载中…</div>
</div>
<p class="pb-status" id="pb-status"></p>
</div>
<script>
(function(){
  var wrap=document.getElementById('picup-backup-wrap');
  var url=wrap.dataset.url; var token=wrap.dataset.token; var selId=0;
  var btnBackup=document.getElementById('pb-backup-btn');
  var btnRestore=document.getElementById('pb-restore-btn');
  var btnDel=document.getElementById('pb-del-btn');
  var status=document.getElementById('pb-status');
  var labelInp=document.getElementById('pb-label-inp');
  function post(doName,extra,cb){
    var fd=new FormData(); fd.append('do',doName); fd.append('_',token);
    if(extra) Object.keys(extra).forEach(function(k){ fd.append(k,extra[k]); });
    fetch(url,{method:'POST',body:fd}).then(function(r){return r.json();}).then(cb)
      .catch(function(e){setStatus('请求失败：'+e,true);});
  }
  function setStatus(msg,isErr){
    status.style.color=isErr?'#b3261e':'#6750a4'; status.textContent=msg;
    if(!isErr) setTimeout(function(){if(status.textContent===msg)status.textContent='';},3500);
  }
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function renderList(list){
    var w=document.getElementById('pb-list-wrap');
    if(!list||!list.length){w.innerHTML='<div class="pb-empty">暂无备份记录</div>';selId=0;updateBtns();return;}
    var html='<div class="pb-list"><div class="pb-list-head"><span>备份名称</span><span>备份时间</span><span>使用方案</span><span></span></div>';
    list.forEach(function(row){
      var isSel=(parseInt(row.id)===selId);
      html+='<div class="pb-list-row" data-id="'+row.id+'" style="'+(isSel?'background:#f3f0fb;outline:2px solid #6750a4;outline-offset:-2px;border-radius:4px;':'')+'">'
        +'<span class="pb-row-label" title="'+esc(row.label)+'">'+esc(row.label)+'</span>'
        +'<span class="pb-row-date">'+esc(row.backup_date)+'</span>'
        +'<span class="pb-row-profile">'+esc(row.default_profile)+'</span>'
        +'<span class="pb-row-actions"></span></div>';
    });
    html+='</div>';
    w.innerHTML=html;
    w.querySelectorAll('.pb-list-row').forEach(function(el){
      el.style.cursor='pointer';
      el.addEventListener('click',function(){selId=parseInt(this.dataset.id);renderList(list);updateBtns();});
    });
  }
  function updateBtns(){var has=selId>0;btnRestore.disabled=!has;btnDel.disabled=!has;}
  function loadList(){
    post('list',{},function(res){
      if(res.code===0){renderList(res.data.list||[]);}
      else{document.getElementById('pb-list-wrap').innerHTML='<div class="pb-empty">加载失败：'+esc(res.message)+'</div>';}
    });
  }
  btnBackup.addEventListener('click',function(){
    var label=labelInp.value.trim(); btnBackup.disabled=true;
    post('backup',label?{label:label}:{},function(res){
      btnBackup.disabled=false;
      if(res.code===0){setStatus('✅ '+res.message);labelInp.value='';selId=res.data.id||0;loadList();}
      else{setStatus('❌ '+res.message,true);}
    });
  });
  btnRestore.addEventListener('click',function(){
    if(!selId) return;
    picupDialog('confirm','确定要从该备份中恢复配置吗？\\n当前未保存的修改将被覆盖，恢复后请点击页面下方的「保存设置」。').then(function(ok){
      if(!ok) return;
      post('restore',{id:selId},function(res){
        if(res.code===0){
          setStatus('✅ '+res.message);
          var ta=document.querySelector('textarea[name="configJson"]');
          var dp=document.querySelector('input[name="defaultProfile"]');
          if(ta&&res.data.config_json){ta.value=res.data.config_json;ta.dispatchEvent(new Event('blur'));}
          if(dp&&res.data.default_profile){dp.value=res.data.default_profile;}
        } else {setStatus('❌ '+res.message,true);}
      });
    });
  });
  btnDel.addEventListener('click',function(){
    if(!selId) return;
    picupDialog('confirm','确定删除此条备份？\\n此操作不可撤销。').then(function(ok){
      if(!ok) return;
      post('delete',{id:selId},function(res){
        if(res.code===0){setStatus('✅ 删除成功');selId=0;loadList();}
        else{setStatus('❌ '+res.message,true);}
      });
    });
  });
  // AdminBeautify AJAX 导航适配：监听 ab:pageload 事件，支持无刷新页面切换
  // （脚本中含 'ab:pageload' 字符串，可通过 AdminBeautify 兼容性检测，不再弹出警告）
  if(window._pbNavHandler) document.removeEventListener('ab:pageload',window._pbNavHandler);
  window._pbNavHandler=function(){ var el=document.getElementById('picup-backup-wrap'); if(el) loadList(); };
  document.addEventListener('ab:pageload',window._pbNavHandler);
  // 常规初始化路径（AdminBeautify AJAX 导航激活时由 ab:pageload 驱动，避免重复加载）
  if(!window.AdminBeautify||!window.AdminBeautify._ajaxNavActive){
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',loadList);}
    else{loadList();}
  }
})();
</script>
</li></ul>
HTML;
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
        picupDialog('prompt','请输入新方案名称:').then(function(name){
            if(!name||!name.trim()) return; name=name.trim();
            var p=getProfiles();
            if(p[name]){picupDialog('alert','方案 "'+name+'" 已存在。');return;}
            var dk=Object.keys(DRIVERS)[0]||'local';
            p[name]={driver:dk,_extensions:{}};saveProfiles(p);renderSelect(p,name);renderForm(p[name]);
        });
    });

    /* ── 重命名方案 ── */
    renameBtn.addEventListener('click',function(){
        var oldName=profileSel.value;
        if(!oldName) return;
        picupDialog('prompt','新方案名称:', oldName).then(function(newName){
            if(!newName||!newName.trim()) return; newName=newName.trim();
            if(newName===oldName) return;
            var p=getProfiles();
            if(p[newName]){picupDialog('alert','方案 "'+newName+'" 已存在。');return;}
            var np={};
            Object.keys(p).forEach(function(k){ np[k===oldName?newName:k]=p[k]; });
            saveProfiles(np);renderSelect(np,newName);renderForm(np[newName]||null);
        });
    });

    /* ── 删除方案 ── */
    delBtn.addEventListener('click',function(){
        var name=profileSel.value;if(!name) return;
        picupDialog('confirm','确认删除方案 "'+name+'"\uff1f\n此操作不可撤销。').then(function(ok){
            if(!ok) return;
            var p=getProfiles();delete p[name];saveProfiles(p);
            var first=Object.keys(p)[0]||null;
            renderSelect(p,first);renderForm(first?p[first]:null);
        });
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
/* ── PicUp 折叠区块（包裹式卡片：wrap > hdr + body） ── */
.picup-collapse-wrap{
    border:1px solid var(--md-outline-variant,#cac4d0)!important;
    border-radius:var(--md-radius-xl,16px)!important;
    overflow:hidden!important;
    margin-top:8px!important;
    background:var(--md-surface-container-low,#f7f2fa)!important;
}
.picup-collapse-hdr{
    display:flex!important;align-items:center!important;justify-content:space-between!important;
    padding:12px 20px!important;margin:0!important;
    background:var(--md-surface-container,#f3edf7)!important;
    border:none!important;border-bottom:1px solid var(--md-outline-variant,#cac4d0)!important;
    cursor:pointer!important;user-select:none!important;
    font-size:14px!important;font-weight:600!important;
    color:var(--md-on-surface,#1c1b1f)!important;
    transition:background .15s!important;
    box-sizing:border-box!important;border-radius:0!important;
}
.picup-collapse-hdr:hover{background:var(--md-surface-container-high,#ece6f0)!important;}
.picup-collapse-hdr .pca{font-size:16px!important;color:var(--md-on-surface-variant,#49454f)!important;transition:transform .25s!important;}
.picup-collapse-hdr.is-closed .pca{transform:rotate(-90deg)!important;}
.picup-collapse-hdr.is-closed{border-bottom:none!important;}
.picup-collapse-body{
    margin:0!important;border:none!important;border-radius:0!important;
    box-shadow:none!important;overflow:hidden!important;
    list-style:none!important;
    transition:max-height .3s ease,opacity .2s ease!important;
    opacity:1!important;
}
.picup-collapse-body.is-closed{max-height:0!important;opacity:0!important;overflow:hidden!important;}
/* 隐藏折叠体内的 Typecho 原生 label（标题已由折叠头部展示） */
.picup-collapse-body > li > .typecho-label{display:none!important;}
/* 覆盖 AB 包裹：折叠体不需要 ab-options-card 的样式 */
.picup-collapse-wrap .ab-options-card{
    border:none!important;border-radius:0!important;margin-top:0!important;
    box-shadow:none!important;overflow:visible!important;
}
/* 暗色模式 */
[data-theme="dark"] .picup-collapse-wrap{
    border-color:var(--md-dark-outline-variant,#49454f)!important;
    background:var(--md-dark-surface-container,#2b2930)!important;
}
[data-theme="dark"] .picup-collapse-hdr{
    background:var(--md-dark-surface-container,#2b2930)!important;
    border-bottom-color:var(--md-dark-outline-variant,#49454f)!important;
    color:var(--md-dark-on-surface,#e6e1e5)!important;
}
[data-theme="dark"] .picup-collapse-hdr:hover{background:var(--md-dark-surface-container-high,#36343b)!important;}

/* ── PicUp 配置编辑器容器 ── */
#picup-gui{
    border:none!important;
    border-radius:0!important;
    background:var(--md-surface-container-low,#f7f2fa)!important;
    padding:18px 20px!important;max-width:100%!important;width:100%!important;
    box-sizing:border-box!important;margin:0!important;
    color:inherit!important;
}
/* 输入控件 */
.picup-ctrl{
    border:1px solid var(--md-outline,#79747e)!important;
    background:var(--md-surface,#fffbfe)!important;
    color:var(--md-on-surface,#1c1b1f)!important;
    border-radius:6px!important;outline:none!important;font-size:13px!important;
    transition:border-color .15s,background .15s,color .15s,box-shadow .15s!important;
}
.picup-ctrl:focus{border-color:var(--md-primary,#6750a4)!important;box-shadow:0 0 0 3px rgba(103,80,164,.15)!important;}
.picup-input{width:100%!important;padding:8px 11px!important;min-height:36px!important;box-sizing:border-box!important;}
/* 工具栏 */
#picup-toolbar{
    display:flex!important;align-items:center!important;gap:8px!important;flex-wrap:wrap!important;
    margin-bottom:16px!important;padding-bottom:14px!important;
    border-bottom:1px solid var(--md-outline-variant,#cac4d0)!important;
    border-top:none!important;border-left:none!important;border-right:none!important;
}
.picup-profile-label{font-size:13px!important;font-weight:600!important;white-space:nowrap!important;color:var(--md-on-surface-variant,#49454f)!important;}
#picup-profile-row{display:flex!important;align-items:center!important;gap:8px!important;flex:1!important;min-width:140px!important;}
#picup-profile-sel{flex:1!important;min-width:0!important;}
#picup-btn-group{display:flex!important;gap:6px!important;flex-wrap:wrap!important;align-items:center!important;}
/* 字段行 */
.picup-field-row{margin-bottom:14px!important;}
.picup-field-left{display:flex!important;flex-direction:column!important;gap:4px!important;}
.picup-field-label{font-size:13px!important;font-weight:600!important;color:var(--md-on-surface,#1c1b1f)!important;}
.picup-field-desc{margin:0!important;font-size:12px!important;line-height:1.5!important;}
.picup-hint{color:var(--md-on-surface-variant,#49454f)!important;}
/* 按钮 */
.picup-bar-btn{
    padding:6px 16px!important;min-height:32px!important;border:none!important;border-radius:20px!important;
    font-size:12px!important;font-weight:500!important;cursor:pointer!important;white-space:nowrap!important;letter-spacing:.02em!important;
    transition:opacity .15s,box-shadow .15s!important;
    display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:4px!important;
    box-sizing:border-box!important;
}
.picup-bar-btn:hover{opacity:.88!important;box-shadow:0 1px 4px rgba(0,0,0,.15)!important;}
.picup-bar-btn:active{transform:scale(.97)!important;}
#picup-add-btn   {background:var(--md-primary,#6750a4)!important;color:var(--md-on-primary,#fff)!important;}
#picup-rename-btn{background:var(--md-primary-container,#eaddff)!important;color:var(--md-on-surface,#1c1b1f)!important;}
#picup-del-btn   {background:var(--md-error,#b3261e)!important;color:#fff!important;}
#picup-apply-btn {background:#16a34a!important;color:#fff!important;}
/* ── 扩展面板 ── */
.picup-section-sep{margin:16px 0 12px!important;border-top:1px solid var(--md-outline-variant,#cac4d0)!important;border-bottom:none!important;border-left:none!important;border-right:none!important;}
.picup-section-title{font-size:13px!important;font-weight:700!important;color:var(--md-on-surface-variant,#49454f)!important;margin-bottom:10px!important;display:flex!important;align-items:center!important;gap:6px!important;}
.picup-ext-card{
    border:1px solid var(--md-outline-variant,#cac4d0)!important;border-radius:6px!important;margin-bottom:8px!important;
    background:var(--md-surface,#fffbfe)!important;overflow:hidden!important;transition:border-color .15s!important;
}
.picup-ext-card.picup-ext-open{border-color:var(--md-primary,#6750a4)!important;}
.picup-ext-header{
    display:flex!important;align-items:center!important;flex-wrap:wrap!important;gap:8px!important;
    padding:10px 12px!important;
}
.picup-ext-toggle-label{display:flex!important;align-items:center!important;gap:6px!important;cursor:pointer!important;user-select:none!important;}
.picup-ext-cb{width:16px!important;height:16px!important;cursor:pointer!important;accent-color:var(--md-primary,#6750a4)!important;}
.picup-ext-name{font-size:13px!important;font-weight:600!important;color:var(--md-on-surface,#1c1b1f)!important;}
.picup-ext-badge{
    display:inline-flex!important;align-items:center!important;padding:1px 7px!important;border-radius:10px!important;
    font-size:11px!important;font-weight:600!important;white-space:nowrap!important;
}
.picup-ext-ok{background:#dcfce7!important;color:#15803d!important;}
.picup-ext-unavail{background:#fee2e2!important;color:#b91c1c!important;cursor:help!important;}
.picup-ext-desc{font-size:12px!important;color:var(--md-on-surface-variant,#49454f)!important;flex:1!important;min-width:0!important;}
.picup-ext-fields{
    padding:10px 12px 4px!important;border-top:1px solid var(--md-outline-variant,#cac4d0)!important;
    background:var(--md-surface-container-low,#f7f2fa)!important;
}
/* ── 移动端 ── */
@media(max-width:600px){
    #picup-gui{padding:12px!important;border-radius:6px!important;}
    #picup-toolbar{flex-direction:column!important;align-items:stretch!important;gap:8px!important;}
    #picup-profile-row{flex:none!important;width:100%!important;}
    #picup-btn-group{width:100%!important;}
    .picup-bar-btn{flex:1!important;padding:8px 6px!important;min-height:38px!important;font-size:13px!important;}
    .picup-ext-header{flex-direction:column!important;align-items:flex-start!important;}
}
@media(max-width:400px){.picup-bar-btn{font-size:12px!important;padding:7px 4px!important;}}
/* ── 暗色模式（使用 AdminBeautify 注入的 --md-dark-* 变量）── */
l[data-theme="dark"] #picup-gui,
[data-theme="dark"] #picup-gui{
    border:none!important;
    background:var(--md-dark-surface-container,#2b2930)!important;
}
l[data-theme="dark"] #picup-gui-wrap,
[data-theme="dark"] #picup-gui-wrap{border-color:var(--md-dark-outline-variant,#49454f)!important;}
l[data-theme="dark"] #picup-toolbar,
[data-theme="dark"] #picup-toolbar{border-bottom-color:var(--md-dark-outline-variant,#49454f)!important;}
l[data-theme="dark"] .picup-profile-label,
[data-theme="dark"] .picup-profile-label{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
l[data-theme="dark"] .picup-ctrl,
[data-theme="dark"] .picup-ctrl{
    border-color:var(--md-dark-outline,#938f99)!important;
    background:var(--md-dark-surface,#1c1b1f)!important;
    color:var(--md-dark-on-surface,#e6e1e5)!important;
}
l[data-theme="dark"] .picup-ctrl:focus,
[data-theme="dark"] .picup-ctrl:focus{border-color:var(--md-dark-primary,#d0bcff)!important;box-shadow:0 0 0 3px rgba(208,188,255,.2)!important;}
l[data-theme="dark"] .picup-field-label,
[data-theme="dark"] .picup-field-label{color:var(--md-dark-on-surface,#e6e1e5)!important;}
l[data-theme="dark"] .picup-hint,
[data-theme="dark"] .picup-hint{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
l[data-theme="dark"] .picup-section-sep,
[data-theme="dark"] .picup-section-sep{border-top-color:var(--md-dark-outline-variant,#49454f)!important;}
l[data-theme="dark"] .picup-section-title,
[data-theme="dark"] .picup-section-title{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
l[data-theme="dark"] .picup-ext-card,
[data-theme="dark"] .picup-ext-card{
    border-color:var(--md-dark-outline-variant,#49454f)!important;
    background:var(--md-dark-surface,#1c1b1f)!important;
}
l[data-theme="dark"] .picup-ext-card.picup-ext-open,
[data-theme="dark"] .picup-ext-card.picup-ext-open{border-color:var(--md-dark-primary,#d0bcff)!important;}
l[data-theme="dark"] .picup-ext-name,
[data-theme="dark"] .picup-ext-name{color:var(--md-dark-on-surface,#e6e1e5)!important;}
l[data-theme="dark"] .picup-ext-desc,
[data-theme="dark"] .picup-ext-desc{color:var(--md-dark-on-surface-variant,#cac4d0)!important;}
l[data-theme="dark"] .picup-ext-fields,
[data-theme="dark"] .picup-ext-fields{
    border-top-color:var(--md-dark-outline-variant,#49454f)!important;
    background:var(--md-dark-surface-container,#2b2930)!important;
}
</style>
EOCSS;

        // 折叠初始化 JS（在此定义公共函数，backup 区入口也会复用）
        $collapseJs = <<<'EOCOLLAPSE'
<script>
(function(){
if(window.__picupCollapseInit) return;
window.__picupCollapseInit=true;

/**
 * 为目标元素添加折叠功能（包裹式卡片）。
 * 创建 .picup-collapse-wrap > .picup-collapse-hdr + el.picup-collapse-body 结构。
 * el 会被移入 wrap 容器中，原位置由 wrap 替代。
 */
window.picupAddCollapse=function(el,title,key,defaultOpen){
    if(!el||el.dataset.picupColl) return;
    el.dataset.picupColl='1';
    var stored=localStorage.getItem('picup_c_'+key);
    var open=(stored===null)?defaultOpen:(stored==='1');
    var animating=false;

    /* 创建包裹容器 */
    var wrap=document.createElement('div');
    wrap.className='picup-collapse-wrap';

    /* 创建折叠头 */
    var hdr=document.createElement('div');
    hdr.className='picup-collapse-hdr'+(open?'':' is-closed');
    hdr.innerHTML='<span>'+title+'</span><span class="pca">▾</span>';

    /* 将 el 移入 wrap：先在 el 原位置插入 wrap，再把 el 追加到 wrap 中 */
    el.parentNode.insertBefore(wrap,el);
    wrap.appendChild(hdr);
    wrap.appendChild(el);

    /* 给 el 添加 body 类 */
    el.classList.add('picup-collapse-body');
    if(!open){
        el.classList.add('is-closed');
        el.style.maxHeight='0';
    } else {
        el.style.maxHeight='none';
    }

    function expandEl(){
        el.classList.remove('is-closed');
        el.style.maxHeight='0';el.style.opacity='0';el.style.overflow='hidden';
        var h=el.scrollHeight;
        requestAnimationFrame(function(){
            el.style.maxHeight=h+'px';el.style.opacity='1';
        });
        animating=true;
        var done=function(e){
            if(e&&e.target!==el) return;
            el.removeEventListener('transitionend',done);
            animating=false;
            if(!el.classList.contains('is-closed')){
                el.style.maxHeight='none';el.style.overflow='visible';
            }
        };
        el.addEventListener('transitionend',done);
        /* 安全兜底：动画最长 400ms */
        setTimeout(function(){done({target:el});},400);
    }

    function collapseEl(){
        /* 先设定当前展开高度，再在下一帧transition到0 */
        el.style.overflow='hidden';
        el.style.maxHeight=el.scrollHeight+'px';
        requestAnimationFrame(function(){
            el.style.maxHeight='0';el.style.opacity='0';
        });
        animating=true;
        var done=function(e){
            if(e&&e.target!==el) return;
            el.removeEventListener('transitionend',done);
            animating=false;
            if(el.classList.contains('is-closed')){
                el.style.maxHeight='0';
            }
        };
        el.addEventListener('transitionend',done);
        setTimeout(function(){done({target:el});},400);
        el.classList.add('is-closed');
    }

    hdr.addEventListener('click',function(){
        if(animating){
            /* 打断当前动画：立刻取消 transition, 清除回调, 反转方向 */
            el.style.transition='none';
            var cur=getComputedStyle(el).maxHeight;
            el.style.maxHeight=cur;
            /* 重新恢复 transition */
            void el.offsetHeight; /* force reflow */
            el.style.transition='';
            animating=false;
        }
        open=!open;
        hdr.classList.toggle('is-closed',!open);
        localStorage.setItem('picup_c_'+key,open?'1':'0');
        if(open){expandEl();}else{collapseEl();}
    });
};

function findParentCard(el){
    /* 如果 el 被 AB 包在 .ab-options-card 中，返回该 card；否则返回 el 本身 */
    var p=el.parentNode;
    if(p&&p.classList&&p.classList.contains('ab-options-card')) return p;
    return null;
}

function run(){
    /* 配置编辑器 */
    var gui=document.getElementById('typecho-option-item-picup-gui');
    if(gui){
        var card=findParentCard(gui);
        window.picupAddCollapse(card||gui,'🎛️ 配置编辑器','editor',false);
    }
    /* JSON 原始配置 */
    var ta=document.querySelector('textarea[name="configJson"]');
    if(ta){
        var ul=ta.closest?ta.closest('ul.typecho-option'):null;
        if(!ul){var p=ta;while(p&&p.tagName!=='UL')p=p.parentNode;ul=p;}
        if(ul){
            var card2=findParentCard(ul);
            window.picupAddCollapse(card2||ul,'📋 JSON 原始配置','json',false);
        }
    }
    /* 配置备份 */
    var bk=document.getElementById('typecho-option-item-picup-backup');
    if(bk){
        var card3=findParentCard(bk);
        window.picupAddCollapse(card3||bk,'💾 配置备份','backup',false);
    }
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);}
else{run();}
document.addEventListener('ab:pageload',function(){
    /* AB AJAX 切换页面后重新初始化 —— 先清除旧标记 */
    document.querySelectorAll('[data-picup-coll]').forEach(function(el){delete el.dataset.picupColl;});
    window.__picupCollapseInit=false;
    run();
});
})();
</script>
EOCOLLAPSE;

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

        return $css . $collapseJs
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
