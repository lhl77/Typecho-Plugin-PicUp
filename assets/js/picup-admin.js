/**
 * PicUp · 媒体库增强（后台全局注入）
 *
 * ① 管理 → 文件（列表）：给每个卡片加「已备份 N」角标
 * ② 管理 → 文件 → 详情页（media.php?cid=X）：
 *      - 移除 Typecho 自带的上传面板（替换文件会换掉链接，与迁移逻辑冲突）
 *      - 在「文件地址」下方插入 PicUp 面板：列出各方案链接 + 迁移
 *
 * 依赖 window.__PICUP_MIG__ = { url, token, siteUrl }（由 Plugin::adminHeader 注入）
 */
(function () {
    'use strict';

    var MIG = window.__PICUP_MIG__ || {};

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function post(doName, extra) {
        var fd = new FormData();
        fd.append('do', doName);
        fd.append('_', MIG.token || '');
        if (extra) {
            Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
        }
        return fetch(MIG.url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    /* ================= ① 列表页角标 ================= */
    var countsCache = null;

    function fetchCounts() {
        if (countsCache) { return Promise.resolve(countsCache); }
        return post('media-counts').then(function (res) {
            countsCache = (res && res.code === 0 && res.data && res.data.counts) ? res.data.counts : {};
            return countsCache;
        }).catch(function () {
            countsCache = {};
            return countsCache;
        });
    }

    function applyBadges() {
        if (!countsCache) { return; }
        Object.keys(countsCache).forEach(function (cid) {
            var n = parseInt(countsCache[cid], 10);
            if (!n) { return; }
            var card = document.getElementById('attachment-' + cid);
            if (!card || card.querySelector('.picup-bak-badge')) { return; }
            var badge = document.createElement('span');
            badge.className = 'picup-bak-badge';
            badge.textContent = '已备份 ' + n;
            badge.title = '已在 ' + n + ' 个方案中备份，点开文件详情可查看与迁移';
            (card.querySelector('.ab-media-thumb') || card).appendChild(badge);
        });
    }

    function mediaBadges() {
        // 媒体卡片由 AB Admin 在页面加载后才渲染，这里直接多轮重试，不能因为"当前没有卡片"就放弃
        fetchCounts().then(function () {
            applyBadges();
            [300, 800, 1600, 2600].forEach(function (ms) { setTimeout(applyBadges, ms); });
        });
    }

    /* ================= ② 详情页面板 ================= */
    function mediaDetail() {
        var urlInput = document.getElementById('attachment-url');
        if (!urlInput || document.getElementById('picup-file-panel')) { return; }

        // 移除 Typecho 自带上传面板（替换文件后链接会变，与迁移/备份逻辑冲突）
        var up = document.getElementById('upload-panel');
        if (up && up.parentNode) { up.parentNode.removeChild(up); }

        var m = /[?&]cid=(\d+)/.exec(location.search);
        var cid = m ? parseInt(m[1], 10) : 0;
        if (!cid) { return; }

        var host = urlInput.parentNode;      // <p>
        var card = document.createElement('div');
        card.id = 'picup-file-panel';
        card.className = 'picup-file-panel';
        card.innerHTML =
            '<div class="pfp-head">'
          +   '<span class="pfp-title"><i class="pfp-dot"></i>PicUp · 自动备份链接</span>'
          +   '<span class="pfp-current" id="pfp-current"></span>'
          + '</div>'
          + '<div class="pfp-links" id="pfp-links"><div class="pfp-empty">加载中…</div></div>'
          + '<div class="pfp-row">'
          +   '<label class="pfp-field"><span>迁移到</span>'
          +     '<select id="pfp-target" class="pfp-sel"></select>'
          +   '</label>'
          +   '<label class="pfp-check"><input type="checkbox" id="pfp-replace" checked>'
          +     '<span>同时替换文章与附件里的旧链接</span></label>'
          +   '<button type="button" class="pfp-btn is-primary" id="pfp-run">开始迁移</button>'
          + '</div>'
          + '<div class="pfp-result" id="pfp-result"></div>';

        host.parentNode.insertBefore(card, host.nextSibling);

        var linksBox  = card.querySelector('#pfp-links');
        var resultBox = card.querySelector('#pfp-result');
        var targetSel = card.querySelector('#pfp-target');
        var replaceCb = card.querySelector('#pfp-replace');
        var runBtn    = card.querySelector('#pfp-run');
        var curLabel  = card.querySelector('#pfp-current');

        function setResult(html, cls) {
            resultBox.innerHTML = html ? '<span class="' + (cls || '') + '">' + html + '</span>' : '';
        }

        function applyNewUrl(newUrl) {
            if (!newUrl) { return; }
            urlInput.value = newUrl;
            curLabel.textContent = '当前：' + newUrl;
            var img = document.querySelector('.typecho-attachment-photo');
            if (img) { img.src = newUrl + (newUrl.indexOf('?') === -1 ? '?t=' + Date.now() : ''); }
        }

        function loadLinks() {
            linksBox.innerHTML = '<div class="pfp-empty">加载中…</div>';
            post('file-links', { cid: cid }).then(function (res) {
                if (!res || res.code !== 0) {
                    linksBox.innerHTML = '<div class="pfp-empty">' + esc((res && res.message) || '读取失败') + '</div>';
                    return;
                }
                var d = res.data || {};
                var profs = d.profiles || [];
                if (d.path) { curLabel.textContent = '当前：' + d.path; }

                if (!profs.length) {
                    linksBox.innerHTML = '<div class="pfp-empty">该文件还没有备份记录（上传时未开启自动备份）</div>';
                } else {
                    var html = '';
                    profs.forEach(function (p) {
                        html += '<div class="pfp-link' + (p.current ? ' is-current' : '') + '">'
                             +   '<span class="pfp-prof">' + esc(p.profile) + '</span>'
                             +   '<a class="pfp-url" href="' + esc(p.url) + '" target="_blank" rel="noopener noreferrer">' + esc(p.url) + '</a>'
                             +   (p.current
                                    ? '<span class="pfp-tag">当前</span>'
                                    : '<button type="button" class="pfp-mini" data-profile="' + esc(p.profile) + '">设为当前</button>')
                             + '</div>';
                    });
                    linksBox.innerHTML = html;

                    [].slice.call(linksBox.querySelectorAll('.pfp-mini')).forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            doMigrate(btn.dataset.profile);
                        });
                    });
                }

                // 目标下拉：配置里的方案 + 记录里出现过的方案名
                var names = [];
                var curProf = '';
                profs.forEach(function (p) { if (p.current) { curProf = p.profile; } });
                (d.options || []).forEach(function (n) { names.push({ v: n, label: n + '（方案）' }); });
                profs.forEach(function (p) {
                    if ((d.options || []).indexOf(p.profile) === -1) {
                        names.push({ v: p.profile, label: p.profile + '（已备份链接）' });
                    }
                });
                targetSel.innerHTML = names.length
                    ? names.map(function (n) { return '<option value="' + esc(n.v) + '">' + esc(n.label) + '</option>'; }).join('')
                    : '<option value="">（没有可用方案）</option>';
                // 默认挑一个「不是当前方案」的目标，否则一进页面就是「迁移到自己」
                if (names.length && curProf) {
                    var pick = null;
                    for (var i = 0; i < names.length; i++) {
                        if (names[i].v !== curProf) { pick = names[i]; break; }
                    }
                    targetSel.value = (pick || names[0]).v;
                }
            }).catch(function () {
                linksBox.innerHTML = '<div class="pfp-empty">读取失败：网络错误</div>';
            });
        }

        function doMigrate(target) {
            if (!target) { setResult('请选择要迁移到的方案', 'is-err'); return; }
            if (runBtn.dataset.busy === '1') { return; }
            runBtn.dataset.busy = '1';
            runBtn.disabled = true;
            var oldText = runBtn.textContent;
            runBtn.textContent = '迁移中…';
            setResult('', '');

            post('file-migrate', {
                cid: cid,
                target: target,
                replaceInPosts: replaceCb && replaceCb.checked ? '1' : '0'
            }).then(function (res) {
                runBtn.dataset.busy = '';
                runBtn.disabled = false;
                runBtn.textContent = oldText;
                if (!res || res.code !== 0) {
                    setResult(esc((res && res.message) || '迁移失败'), 'is-err');
                    return;
                }
                var d = res.data || {};
                applyNewUrl(d.url);
                var s = d.stats || {};
                var tail = (s.total ? '；同时替换了 ' + s.total + ' 处引用（附件 ' + s.attachments + ' / 文章 ' + s.posts + '）' : '');
                setResult('迁移完成 → ' + esc(target) + '（原方案的链接已保留）' + tail, 'is-ok');
                loadLinks();
            }).catch(function () {
                runBtn.dataset.busy = '';
                runBtn.disabled = false;
                runBtn.textContent = oldText;
                setResult('迁移失败：网络错误', 'is-err');
            });
        }

        runBtn.addEventListener('click', function () { doMigrate(targetSel.value); });

        loadLinks();
    }

    function init() {
        try { mediaBadges(); } catch (e) {}
        try { mediaDetail(); } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('ab:pageload', function () {
        // AJAX 导航后列表可能被重绘 / 数据可能已变，重置缓存后重新打角标
        countsCache = null;
        init();
    });
})();
