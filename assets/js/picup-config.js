/**
 * PicUp Config Page JavaScript
 * Combined JS from all Plugin.php methods.
 * Requires window.__PICUP__ to be set by PHP before loading.
 */
;(function(){
'use strict';
var P = window.__PICUP__;
if (!P) { return; }

/* ========================================================================
 * 1. config() — Version & Update checker + Promote modal
 * ======================================================================== */
var __PICUP_VER__ = P.version;
var __PICUP_ADMIN_EXTENDING_URL__ = P.adminExtendingUrl || '';
var __PICUP_GH_REPO__ = P.ghRepo || 'lhl77/Typecho-Plugin-PicUp';

function compareVer(a,b){
    var pa=(a||'').replace(/^v/i,'').split('.').map(Number);
    var pb=(b||'').replace(/^v/i,'').split('.').map(Number);
    var len=Math.max(pa.length,pb.length);
    for(var i=0;i<len;i++){
        var na=pa[i]||0;var nb=pb[i]||0;
        if(na>nb)return 1;if(na<nb)return -1;
    }
    return 0;
}

function stripVerTag(tag){return (tag||'').replace(/^v/i,'');}

/* ── 检查更新 → 行内显示 ── */
(function(){
    var checkBtn=document.getElementById('picup-check-update-btn');
    var dot=document.getElementById('picup-update-dot');
    var result=document.getElementById('picup-update-result');
    var latestTag=null; var latestUrl=null;
    if(!checkBtn||!result) return;

    function renderResult(hasUpdate){
        var curVer=stripVerTag(__PICUP_VER__);
        if(hasUpdate){
            var adminUrl=__PICUP_ADMIN_EXTENDING_URL__||'';
            var releaseUrl=latestUrl||('https://github.com/'+__PICUP_GH_REPO__+'/releases/tag/'+(latestTag||''));
            var storeHtml=adminUrl
                ? '<a class="pu-update-act pu-store" href="'+adminUrl+'" target="_blank">前往 AB Store 更新</a>'
                : '<span class="pu-update-act pu-no-store">不支持 AB Store 一键升级</span>';
            result.innerHTML=''
                +'发现新版本 <span class="pu-latest-ver">'+latestTag+'</span>'
                +'（当前 v'+curVer+'）'
                +'<span class="pu-update-actions">'
                + storeHtml
                + '<a class="pu-update-act pu-gh" href="'+releaseUrl+'" target="_blank">GitHub Release</a>'
                +'</span>';
            result.style.display='block';
        } else {
            result.innerHTML='已是最新版本 👍';
            result.style.display='block';
            setTimeout(function(){ result.style.display='none'; }, 3000);
        }
    }

    function doCheck(){
        result.innerHTML='正在获取最新版本…';
        result.style.display='block';
        fetch('https://api.github.com/repos/'+__PICUP_GH_REPO__+'/releases/latest',{
            headers:{'Accept':'application/vnd.github.v3+json'}
        })
        .then(function(r){return r.json();})
        .then(function(data){
            if(data&&data.tag_name){
                latestTag=data.tag_name;
                latestUrl=data.html_url||'';
                var hasUpdate=compareVer(latestTag,__PICUP_VER__)>0;
                renderResult(hasUpdate);
                if(hasUpdate){
                    dot.classList.add('has-update');
                    if(checkBtn) checkBtn.title='发现新版本 '+latestTag;
                } else {
                    dot.classList.remove('has-update');
                }
            } else {
                result.innerHTML='获取版本信息失败，请稍后重试';
                result.style.display='block';
            }
        }).catch(function(){
            result.innerHTML='网络请求失败，请检查网络';
            result.style.display='block';
        });
    }

    if(checkBtn) {
        checkBtn.addEventListener('click',doCheck);
    }

    /* 页面加载时后台静默检测一次，仅更新圆点状态，不弹窗 */
    fetch('https://api.github.com/repos/'+__PICUP_GH_REPO__+'/releases/latest',{
        headers:{'Accept':'application/vnd.github.v3+json'}
    })
    .then(function(r){return r.json();})
    .then(function(data){
        if(data&&data.tag_name){
            latestTag=data.tag_name;latestUrl=data.html_url||'';
            if(compareVer(latestTag,__PICUP_VER__)>0){
                dot.classList.add('has-update');
                if(checkBtn) checkBtn.title='发现新版本 '+latestTag;
            }
        }
    }).catch(function(){});
})();

/* ── 推广弹窗 ── */
(function(){
    function initPromoteModal(){
        var openBtn=document.getElementById('picup-promote-open');
        var closeBtn=document.getElementById('picup-promote-close');
        var mask=document.getElementById('picup-promote-modal-mask');
        var copyBtn=document.getElementById('picup-promote-copy');
        var textArea=document.getElementById('picup-promote-markdown');
        var status=document.getElementById('picup-promote-copy-status');
        if(!openBtn||!mask||!copyBtn||!textArea){return;}

        if(mask.parentNode!==document.body){
            document.body.appendChild(mask);
        }

        if(openBtn.dataset.promoteBound==='1'){return;}
        openBtn.dataset.promoteBound='1';

        function openModal(){
            mask.classList.add('is-open');
            mask.setAttribute('aria-hidden','false');
            document.documentElement.style.overflow='hidden';
            setTimeout(function(){ textArea.focus(); textArea.select(); }, 0);
        }

        function closeModal(){
            mask.classList.remove('is-open');
            mask.setAttribute('aria-hidden','true');
            document.documentElement.style.overflow='';
        }

        openBtn.addEventListener('click',openModal);
        if(closeBtn){closeBtn.addEventListener('click',closeModal);}
        mask.addEventListener('click',function(e){ if(e.target===mask){closeModal();} });

        document.addEventListener('keydown',function(e){
            if(e.key==='Escape'&&mask.classList.contains('is-open')){closeModal();}
        });

        copyBtn.addEventListener('click',function(){
            var text=textArea.value||'';
            function setOk(msg){
                if(status){status.textContent=msg||'复制成功';}
                setTimeout(function(){ if(status&&status.textContent===msg){status.textContent='';} }, 2200);
            }
            if(navigator.clipboard&&navigator.clipboard.writeText){
                navigator.clipboard.writeText(text).then(function(){
                    setOk('复制成功');
                }).catch(function(){
                    textArea.focus();textArea.select();
                    try{document.execCommand('copy');setOk('复制成功');}catch(err){if(status){status.textContent='复制失败，请手动复制';}}
                });
            } else {
                textArea.focus();textArea.select();
                try{document.execCommand('copy');setOk('复制成功');}catch(err){if(status){status.textContent='复制失败，请手动复制';}}
            }
        });
    }

    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initPromoteModal);}else{initPromoteModal();}
    document.addEventListener('ab:pageload',initPromoteModal);
})();

/* ========================================================================
 * 2. buildSummaryCardHtml() — Summary card
 * ======================================================================== */
(function(){
var __PICUP_DRIVER_NAMES__ = P.driverNames || {};
var __PICUP_EXT_NAMES__ = P.extNames || {};
var __PICUP_ENV__ = P.env || {};

function getProfileExts(profiles, profileName){
    var p=profiles[profileName]||null;
    if(!p) return [];
    var extConf=p._extensions&&typeof p._extensions==='object'?p._extensions:{};
    var list=[];
    Object.keys(extConf).forEach(function(k){
        var c=extConf[k]||{};
        if(c.enabled===true||c.enabled==='true'){
            list.push(__PICUP_EXT_NAMES__[k]||k);
        }
    });
    return list;
}

function updateSummary(){
    var cfgTa=document.querySelector('textarea[name="configJson"]');
    var dpInp=document.querySelector('input[name="defaultProfile"]');
    var msInp=document.querySelector('input[name="mimeScope"]:checked');
    var sfTa=document.querySelector('textarea[name="suffixProfiles"]');
    var badge=document.getElementById('psc-badge');
    var elProfile=document.getElementById('psc-profile');
    var elDriver=document.getElementById('psc-driver');
    var elScope=document.getElementById('psc-scope');
    var elExts=document.getElementById('psc-exts');
    var elRules=document.getElementById('psc-rules');
    if(!cfgTa||!elProfile) return;

    var profileName=dpInp?dpInp.value.trim()||'default':'default';
    var profiles={};
    try{profiles=JSON.parse(cfgTa.value)||{};}catch(e){}
    var profile=profiles[profileName]||null;

    badge.textContent=profileName;
    elProfile.textContent=profileName;

    if(profile){
        var dk=profile.driver||'';
        elDriver.textContent=__PICUP_DRIVER_NAMES__[dk]||dk||'—';
    } else {
        elDriver.textContent='方案不存在';
    }

    var mimeScope='image';
    if(msInp){mimeScope=msInp.value||'image';}
    elScope.textContent=mimeScope==='all'?'所有文件':'仅图片';

    /* 启用插件 */
    var activeExts=getProfileExts(profiles,profileName);
    if(activeExts.length){
        elExts.innerHTML=activeExts.map(function(n){return '<span class="psc-ext-tag">'+n+'</span>';}).join('');
    } else {
        elExts.innerHTML='<span class="psc-none">无</span>';
    }

    /* 后缀规则（含各目标方案的插件） */
    var suffixMap={};
    try{suffixMap=JSON.parse(sfTa?sfTa.value:'{}')||{};}catch(e){}
    var rules=[];
    Object.keys(suffixMap).forEach(function(suffixes){
        var pn=suffixMap[suffixes]||'';
        if(!pn) return;
        var tag='<span class="psc-rule-tag">'+suffixes+' → '+pn+'</span>';
        var pexts=getProfileExts(profiles,pn);
        if(pexts.length){
            tag+='<span style="font-size:11px;opacity:.7;">（插件：'
                +pexts.map(function(n){return '<span class="psc-ext-tag">'+n+'</span>';}).join('')
                +'）</span>';
        }
        rules.push(tag);
    });
    if(rules.length){
        elRules.innerHTML=rules.join(' ');
    } else {
        elRules.innerHTML='<span class="psc-none">无</span>';
    }

    /* 服务器环境 */
    renderEnv();
}

function renderEnv(){
    var wrap=document.getElementById('psc-env-wrap');
    if(!wrap||!__PICUP_ENV__) return;
    var e=__PICUP_ENV__;
    var t=function(label,ok){return '<span class="psc-env-tag '+(ok?'pet-ok':'pet-no')+'">'+label+'</span>';};
    var html='';
    html+=t('PHP '+e.phpVer,true);
    html+=t(e.opensslVer.length>20?e.opensslVer.slice(0,20)+'…':e.opensslVer,true);
    html+=t('GD WebP',e.gdWebp);
    html+=t('GD AVIF',e.gdAvif);
    if(e.imAvailable){
        html+=t('Imagick WebP',e.imWebp);
        html+=t('Imagick AVIF',e.imAvif);
    } else {
        html+=t('Imagick 未安装',false);
    }
    wrap.innerHTML=html;
}

function bindSummaryRefresh(){
    var cfgTa=document.querySelector('textarea[name="configJson"]');
    var dpInp=document.querySelector('input[name="defaultProfile"]');
    var msInps=document.querySelectorAll('input[name="mimeScope"]');
    var sfTa=document.querySelector('textarea[name="suffixProfiles"]');
    if(cfgTa) cfgTa.addEventListener('blur',updateSummary);
    if(dpInp) dpInp.addEventListener('blur',updateSummary);
    if(dpInp) dpInp.addEventListener('input',updateSummary);
    msInps.forEach(function(r){r.addEventListener('change',updateSummary);});
    if(sfTa) sfTa.addEventListener('blur',updateSummary);
    updateSummary();
}

if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',bindSummaryRefresh);}else{bindSummaryRefresh();}
document.addEventListener('ab:pageload',bindSummaryRefresh);
})();

/* ========================================================================
 * 3. buildAbPromoHtml() — AB Admin promotion panel (pure static)
 * ======================================================================== */
(function(){
    function ensureViewer(){
        var viewer=document.getElementById('picup-ab-promo-viewer');
        if(viewer){return viewer;}
        viewer=document.createElement('div');
        viewer.id='picup-ab-promo-viewer';
        viewer.className='picup-ab-fullscreen';
        viewer.innerHTML=''
            + '<button type="button" class="picup-ab-fullscreen-close" aria-label="关闭预览">关闭(Esc)</button>'
            + '<img alt="AB Admin 图片预览" src="">';
        (document.body||document.documentElement).appendChild(viewer);

        var closeBtn=viewer.querySelector('.picup-ab-fullscreen-close');
        var img=viewer.querySelector('img');

        function closeViewer(){
            viewer.classList.remove('is-open');
            if(img){img.src='';}
            document.documentElement.style.overflow='';
        }

        viewer.addEventListener('click',function(e){
            if(e.target===viewer){closeViewer();}
        });
        if(closeBtn){closeBtn.addEventListener('click',closeViewer);}

        document.addEventListener('keydown',function(e){
            if(e.key==='Escape'&&viewer.classList.contains('is-open')){
                closeViewer();
            }
        });

        viewer.__open=function(src,alt){
            if(!img){return;}
            img.src=src;
            img.alt=alt||'AB Admin 分享图预览';
            viewer.classList.add('is-open');
            document.documentElement.style.overflow='hidden';
        };
        return viewer;
    }

    function bindShots(){
        var viewer=ensureViewer();
        var shots=document.querySelectorAll('.picup-ab-promo-shot[data-full-src]');
        shots.forEach(function(btn){
            if(btn.dataset.bound==='1'){return;}
            btn.dataset.bound='1';
            btn.addEventListener('click',function(){
                var img=btn.querySelector('img');
                var src=btn.getAttribute('data-full-src')||'';
                var alt=img?img.getAttribute('alt')||'':'';
                if(src&&viewer&&typeof viewer.__open==='function'){
                    viewer.__open(src,alt);
                }
            });
        });
    }

    function setup(){
        var panel=document.getElementById('picup-ab-promo-panel');
        var openBtn=document.getElementById('picup-ab-promo-open');
        var closeBtn=document.getElementById('picup-ab-promo-close');
        var hiddenInput=document.querySelector('input[name="abPromoHidden"]');
        if(!panel||!openBtn||!hiddenInput){return;}

        var row=hiddenInput.closest('li');
        if(row){row.style.display='none';}

        function syncByValue(){
            var hidden=String(hiddenInput.value||'0')==='1';
            panel.style.display=hidden?'none':'block';
            openBtn.textContent=hidden?'查看详情':'隐藏图文';
        }

        openBtn.addEventListener('click',function(){
            var hidden=String(hiddenInput.value||'0')==='1';
            hiddenInput.value=hidden?'0':'1';
            syncByValue();
        });

        if(closeBtn){
            closeBtn.addEventListener('click',function(){
                hiddenInput.value='1';
                syncByValue();
            });
        }

        bindShots();
        syncByValue();
    }

    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',setup);}else{setup();}
    document.addEventListener('ab:pageload',setup);
})();

/* ========================================================================
 * 4. buildBackupHtml() — Backup area (reads data-url/data-token from DOM)
 * ======================================================================== */
(function(){
    function initBackup(){
        var wrap=document.getElementById('picup-backup-wrap');
        if(!wrap) return;
        if(wrap.dataset.picupBackupInit==='1') return;
        wrap.dataset.picupBackupInit='1';

        var url=wrap.dataset.url;
        var token=wrap.dataset.token;
        if(!url) return;

        var selId=0;
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
            picupDialog('confirm','确定要从该备份中恢复配置吗？\n当前未保存的修改将被覆盖，恢复后请点击页面下方的「保存设置」。').then(function(ok){
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
            picupDialog('confirm','确定删除此条备份？\n此操作不可撤销。').then(function(ok){
                if(!ok) return;
                post('delete',{id:selId},function(res){
                    if(res.code===0){setStatus('✅ 删除成功');selId=0;loadList();}
                    else{setStatus('❌ '+res.message,true);}
                });
            });
        });
        if(window._pbNavHandler) document.removeEventListener('ab:pageload',window._pbNavHandler);
        window._pbNavHandler=function(){ var el=document.getElementById('picup-backup-wrap'); if(el) loadList(); };
        document.addEventListener('ab:pageload',window._pbNavHandler);
        if(!window.AdminBeautify||!window.AdminBeautify._ajaxNavActive){
            if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',loadList);}
            else{loadList();}
        }
    }

    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initBackup);}else{initBackup();}
})();

/* ========================================================================
 * 5. buildSuffixProfilesGuiHtml() — Suffix profiles GUI (pure static)
 * ======================================================================== */
(function(){
    function initSuffixGui(){
        var listWrap=document.getElementById('ps-list');
        var addBtn=document.getElementById('ps-add-btn');
        var ta=document.querySelector('textarea[name="suffixProfiles"]');
        if(!ta||!listWrap) return;
        if(ta.dataset.picupSuffixInit==='1') return;
        ta.dataset.picupSuffixInit='1';

        function getProfileKeys(){
            var cfgTa=document.querySelector('textarea[name="configJson"]');
            if(!cfgTa) return [];
            try{return Object.keys(JSON.parse(cfgTa.value)||{});}catch(e){return [];}
        }

        function getMapping(){
            try{return JSON.parse(ta.value)||{};}catch(e){return {};}
        }
        function saveMapping(m){
            ta.value=JSON.stringify(m,null,2);
        }

        function render(){
            var m=getMapping();
            var keys=Object.keys(m);
            var profiles=getProfileKeys();
            listWrap.innerHTML='';
            if(!keys.length){
                var empty=document.createElement('div');empty.className='ps-empty';
                empty.textContent='暂无后缀映射规则，点击下方按钮添加。';
                listWrap.appendChild(empty);
                return;
            }
            keys.forEach(function(suffixes){
                var profileName=m[suffixes]||'';
                var row=document.createElement('div');row.className='ps-row';

                var inp=document.createElement('input');inp.type='text';inp.value=suffixes;
                inp.placeholder='后缀名，如 jpg,jpeg,png';
                inp.title='文件后缀名（逗号分隔，不含点号）';
                row.appendChild(inp);

                var sel=document.createElement('select');
                var optEmpty=document.createElement('option');optEmpty.value='';optEmpty.textContent='— 选择方案 —';
                sel.appendChild(optEmpty);
                profiles.forEach(function(p){
                    var o=document.createElement('option');o.value=p;o.textContent=p;
                    if(p===profileName)o.selected=true;
                    sel.appendChild(o);
                });
                if(profileName&&profiles.indexOf(profileName)===-1){
                    var oMissing=document.createElement('option');oMissing.value=profileName;
                    oMissing.textContent=profileName+' (已删除)';oMissing.selected=true;
                    oMissing.style.color='#b3261e';sel.appendChild(oMissing);
                }
                row.appendChild(sel);

                var del=document.createElement('button');del.type='button';del.className='ps-del-btn';
                del.textContent='删除';
                row.appendChild(del);

                listWrap.appendChild(row);

                function sync(){
                    var nm=getMapping();
                    var oldKey=suffixes;
                    var newKey=inp.value.trim();
                    var newVal=sel.value;
                    if(oldKey!==newKey) delete nm[oldKey];
                    if(newKey) nm[newKey]=newVal;
                    saveMapping(nm);
                }
                inp.addEventListener('change',function(){sync();suffixes=inp.value.trim();});
                inp.addEventListener('input',function(){sync();suffixes=inp.value.trim();});
                sel.addEventListener('change',sync);
                del.addEventListener('click',function(){
                    var nm=getMapping();delete nm[suffixes];saveMapping(nm);render();
                });
            });
        }

        addBtn.addEventListener('click',function(){
            var m=getMapping();m['']='';saveMapping(m);render();
            var rows=listWrap.querySelectorAll('.ps-row');
            if(rows.length){var lastInp=rows[rows.length-1].querySelector('input');if(lastInp)lastInp.focus();}
        });

        ta.addEventListener('blur',render);
        var cfgTa=document.querySelector('textarea[name="configJson"]');
        if(cfgTa) cfgTa.addEventListener('blur',render);

        if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',render);}
        else{render();}
        document.addEventListener('ab:pageload',render);
    }

    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',initSuffixGui);}else{initSuffixGui();}
})();

/* ========================================================================
 * 6. buildGuiHtml() — Main GUI editor + collapse
 * ======================================================================== */
(function(){
    var DRIVERS = P.driversMeta || {};
    var EXTENSIONS = P.extensionsMeta || {};
    var profileSel  = null;
    var formDiv     = null;
    var extSection  = null;
    var addBtn      = null;
    var renameBtn   = null;
    var delBtn      = null;
    var applyBtn    = null;
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
        if(!profileSel) return;
        profileSel.innerHTML='';
        var keys=Object.keys(profiles);
        if(!keys.length){
            var ph=document.createElement('option');
            ph.value='';ph.textContent='(无方案)';ph.disabled=true;
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
        if(!formDiv) return;
        formDiv.innerHTML='';
        if(!profile) return;
        var driverKey=profile.driver||'';

        /* 驱动选择行 */
        var dRow=document.createElement('div');dRow.className='picup-field-row';
        var dLeft=document.createElement('div');dLeft.className='picup-field-left';
        var dLbl=document.createElement('label');dLbl.className='picup-field-label';
        dLbl.textContent='驱动类型 *';
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
        if(!extSection) return;
        extSection.innerHTML='';
        if(!EXTENSIONS||!Object.keys(EXTENSIONS).length) return;

        var extConf = (profile&&profile._extensions&&typeof profile._extensions==='object')
            ? profile._extensions : {};

        /* 分隔线 + 标题 */
        var sep=document.createElement('div');sep.className='picup-section-sep';extSection.appendChild(sep);
        var title=document.createElement('div');title.className='picup-section-title';
        title.innerHTML='<span>插件扩展</span><small class="picup-hint"> — 每个方案独立配置</small>';
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
                avail.textContent='可用';header.appendChild(avail);
            } else {
                var unavail=document.createElement('span');unavail.className='picup-ext-badge picup-ext-unavail';
                unavail.title='缺少 PHP 扩展: '+ext.missingExts.join(', ');
                unavail.textContent='不可用 — 缺 '+ext.missingExts.join(', ');
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
        profileSel  = document.getElementById('picup-profile-sel');
        formDiv     = document.getElementById('picup-profile-form');
        extSection  = document.getElementById('picup-ext-section');
        addBtn      = document.getElementById('picup-add-btn');
        renameBtn   = document.getElementById('picup-rename-btn');
        delBtn      = document.getElementById('picup-del-btn');
        applyBtn    = document.getElementById('picup-apply-btn');
        if(!profileSel||!formDiv) return;

        var p=getProfiles();var first=Object.keys(p)[0]||null;
        renderSelect(p,first);
        renderForm(first?p[first]:null);
    }

    function setupGui(){
        init();
        if(!profileSel) return;

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
            picupDialog('confirm','确认删除方案 "'+name+'"？\n此操作不可撤销。').then(function(ok){
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
            applyBtn.textContent='已应用';
            setTimeout(function(){ applyBtn.textContent='应用此方案'; },1800);
        });

        var ta=getTA();
        if(ta){
            ta.addEventListener('blur',function(){
                var p=getProfiles();var cur=profileSel.value;
                renderSelect(p,cur&&p[cur]?cur:(Object.keys(p)[0]||null));
                renderForm(p[profileSel.value]||null);
            });
        }
    }

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',setupGui);
    } else {
        setupGui();
    }
})();

/* ========================================================================
 * 7. buildGuiHtml() — Collapse initialization (pure static)
 * ======================================================================== */
(function(){
if(window.__picupCollapseInit) return;
window.__picupCollapseInit=true;

/**
 * 为目标元素添加折叠功能（包裹式卡片）。
 */
window.picupAddCollapse=function(el,title,key,defaultOpen){
    if(!el||el.dataset.picupColl) return;
    el.dataset.picupColl='1';
    var stored=localStorage.getItem('picup_c_'+key);
    var open=(stored===null)?defaultOpen:(stored==='1');
    var animating=false;
    var snapHeight=null;

    /* 创建包裹容器 */
    var wrap=document.createElement('div');
    wrap.className='picup-collapse-wrap';

    /* 创建折叠头 */
    var hdr=document.createElement('div');
    hdr.className='picup-collapse-hdr'+(open?'':' is-closed');
    hdr.innerHTML='<span class="pch-title"><span>'+title+'</span></span><span class="pca">▾</span>';

    /* 将 el 移入 wrap */
    el.parentNode.insertBefore(wrap,el);
    wrap.appendChild(hdr);
    wrap.appendChild(el);

    /* 给 el 添加 body 类并设置初始状态 */
    el.classList.add('picup-collapse-body');
    el.style.overflow='hidden';
    if(!open){
        el.classList.add('is-closed');
        el.style.maxHeight='0';el.style.opacity='0';
    } else {
        el.style.maxHeight='none';el.style.opacity='1';el.style.overflow='visible';
    }

    function cleanupAnim(){
        animating=false;
        el.style.transition='';
    }

    function snap(){
        el.style.transition='none';
        snapHeight=getComputedStyle(el).maxHeight;
        if(snapHeight==='none'){snapHeight=el.scrollHeight+'px';}
        el.style.maxHeight=snapHeight;
        void el.offsetHeight;
    }

    function animateOpen(){
        snap();
        animating=true;
        el.classList.remove('is-closed');
        el.style.overflow='hidden';
        el.style.transition='';
        var targetH=el.scrollHeight;
        el.style.maxHeight=snapHeight;
        void el.offsetHeight;
        el.style.maxHeight=targetH+'px';el.style.opacity='1';
        var done=function(e){
            if(e&&e.target!==el) return;
            el.removeEventListener('transitionend',done);
            if(!el.classList.contains('is-closed')){
                el.style.maxHeight='none';el.style.overflow='visible';
            }
            cleanupAnim();
        };
        el.addEventListener('transitionend',done);
        setTimeout(function(){done({target:el});},420);
    }

    function animateClose(){
        snap();
        animating=true;
        el.style.overflow='hidden';
        el.classList.add('is-closed');
        el.style.transition='';
        el.style.maxHeight=snapHeight;
        void el.offsetHeight;
        el.style.maxHeight='0';el.style.opacity='0';
        var done=function(e){
            if(e&&e.target!==el) return;
            el.removeEventListener('transitionend',done);
            if(el.classList.contains('is-closed')){el.style.maxHeight='0';}
            cleanupAnim();
        };
        el.addEventListener('transitionend',done);
        setTimeout(function(){done({target:el});},420);
    }

    hdr.addEventListener('click',function(){
        var goingOpen=!open;
        open=goingOpen;
        hdr.classList.toggle('is-closed',!open);
        localStorage.setItem('picup_c_'+key,open?'1':'0');
        if(animating){
            snap();
            cleanupAnim();
            if(goingOpen){animateOpen();}else{animateClose();}
        } else {
            if(goingOpen){animateOpen();}else{animateClose();}
        }
    });
};

function findParentCard(el){
    var p=el.parentNode;
    if(p&&p.classList&&p.classList.contains('ab-options-card')) return p;
    return null;
}

function findUlOrCard(el){
    if(!el) return null;
    var ul=el.closest?el.closest('ul.typecho-option'):null;
    if(!ul){var p=el;while(p&&p.tagName!=='UL')p=p.parentNode;ul=p||null;}
    return ul?(findParentCard(ul)||ul):null;
}

function groupCollapse(targets,title,key,open){
    targets=targets.filter(Boolean);
    if(!targets.length) return;
    if(targets.length===1){window.picupAddCollapse(targets[0],title,key,open);return;}
    var grp=document.createElement('div');
    targets[0].parentNode.insertBefore(grp,targets[0]);
    targets.forEach(function(t){grp.appendChild(t);});
    window.picupAddCollapse(grp,title,key,open);
}

function runCollapse(){
    /* 配置编辑器 */
    var gui=document.getElementById('typecho-option-item-picup-gui');
    if(gui){
        var card=findParentCard(gui);
        window.picupAddCollapse(card||gui,'配置编辑器','editor',false);
    }
    /* 全局设置：全局默认方案 + 默认文件存储目录模板 */
    groupCollapse([
        findUlOrCard(document.querySelector('input[name="defaultProfile"]')),
        findUlOrCard(document.querySelector('input[name="storagePathTemplate"]'))
    ],'全局设置','global',false);
    /* JSON 配置：configJson + suffixProfiles */
    groupCollapse([
        findUlOrCard(document.querySelector('textarea[name="configJson"]')),
        findUlOrCard(document.querySelector('textarea[name="suffixProfiles"]'))
    ],'JSON 配置','json',false);
    /* 配置备份 */
    var bk=document.getElementById('typecho-option-item-picup-backup');
    if(bk){ groupCollapse([findParentCard(bk)||bk],'配置备份','backup',false); }
    /* 方案规则：文件接管范围 + 后缀方案 GUI */
    groupCollapse([
        findUlOrCard(document.querySelector('input[name="mimeScope"]')),
        (function(){ var s=document.getElementById('typecho-option-item-picup-suffix-gui'); return s?(findParentCard(s)||s):null; })()
    ],'方案规则','suffixrule',false);
}

if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',runCollapse);}
else{runCollapse();}
document.addEventListener('ab:pageload',function(){
    document.querySelectorAll('[data-picup-coll]').forEach(function(el){delete el.dataset.picupColl;});
    window.__picupCollapseInit=false;
    runCollapse();
});
})();

})();
