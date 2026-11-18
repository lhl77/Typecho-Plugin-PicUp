<?php

/**
 * PicUp for Typecho — 可复用的业务接口层
 *
 * 这里的每个方法都是 **public static**，返回值是普通数组（不直接输出 JSON），
 * 目的是让「后台 Ajax（Action.php）」和「AB Admin 的 AI 助手」共用同一份实现，
 * 不会出现两边逻辑走偏。
 *
 * 返回约定：
 *   成功 → ['ok' => true,  ...业务字段]
 *   失败 → ['ok' => false, 'error' => '人类可读的原因', 'code' => HTTP 状态码]
 *
 * @package PicUp
 */

namespace TypechoPlugin\PicUp;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Api
{
    // ================================================================
    //  基础读取
    // ================================================================

    /** links 表是否已就绪（不存在时查询会抛异常） */
    public static function linksTableReady(): bool
    {
        try {
            Db::get()->fetchRow(Db::get()->select('id')->from('table.PicUpLinks')->limit(1));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 读取全部 links 记录（links 字段已解码）。
     */
    public static function linksRowsAll(): array
    {
        if (!self::linksTableReady()) {
            return [];
        }

        $db = Db::get();
        try {
            $rows = $db->fetchAll(
                $db->select('id', 'filename', 'primary_profile', 'primary_url', 'links', 'created_at', 'cid')
                    ->from('table.PicUpLinks')
                    ->order('id', Db::SORT_DESC)
            );
        } catch (\Throwable $e) {
            // 老表可能还没有 cid 列
            try {
                $rows = $db->fetchAll(
                    $db->select('id', 'filename', 'primary_profile', 'primary_url', 'links', 'created_at')
                        ->from('table.PicUpLinks')
                        ->order('id', Db::SORT_DESC)
                );
            } catch (\Throwable $e2) {
                return [];
            }
        }

        $out = [];
        foreach ($rows ?: [] as $row) {
            $links = json_decode((string)($row['links'] ?? '{}'), true);
            $row['links'] = is_array($links) ? $links : [];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * cid => ['name','path','mime','type']（typecho_contents 中 type=attachment 的行）
     */
    public static function attachmentMap(): array
    {
        $db = Db::get();
        try {
            $rows = $db->fetchAll(
                $db->select('cid', 'title', 'text')->from('table.contents')->where('type = ?', 'attachment')
            );
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows ?: [] as $row) {
            $text = (string)($row['text'] ?? '');
            $data = json_decode($text, true);
            if (!is_array($data)) {
                $data = @unserialize($text, ['allowed_classes' => false]);
            }
            if (!is_array($data)) {
                continue;
            }
            $map[(int)$row['cid']] = [
                'name' => (string)($data['name'] ?? $row['title']),
                'path' => (string)($data['path'] ?? ''),
                'mime' => (string)($data['mime'] ?? ''),
                'type' => (string)($data['type'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * 找到某个附件对应的 links 行。
     *
     * ⚠️ **只认 URL**：附件当前地址必须命中记录里的某条链接才算匹配。
     * 不能按文件名回退——同名文件（如 `image.png`）很常见，会把 A 文件的备份记录
     * 挂到 B 文件上，导致「没开自动备份却已经存在备份链接」。
     * `cid` 列只用来在两个候选行之间消歧（以及回填）。
     */
    public static function findRowForAttachment(array $rows, int $cid, string $path): ?array
    {
        if ($path === '') {
            // 没有地址时只能靠 cid
            foreach ($rows as $row) {
                if ($cid > 0 && (int)($row['cid'] ?? 0) === $cid) {
                    return $row;
                }
            }
            return null;
        }

        $hits = [];
        foreach ($rows as $row) {
            $matched = false;
            foreach (($row['links'] ?: []) as $url) {
                if ((string)$url === $path) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched && (string)($row['primary_url'] ?? '') === $path) {
                $matched = true;
            }
            if ($matched) {
                $hits[] = $row;
            }
        }

        if (!$hits) {
            return null;
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
        // 多条命中（同一个文件被多个方案分别记录）→ 优先 cid 相等的
        foreach ($hits as $row) {
            if ($cid > 0 && (int)($row['cid'] ?? 0) === $cid) {
                return $row;
            }
        }

        return $hits[0];
    }

    // ================================================================
    //  状态 / 开关
    // ================================================================

    /**
     * 插件整体状态：方案、默认方案、自动备份开关、链接记录数。
     */
    public static function status(): array
    {
        $ready = self::linksTableReady();
        $count = 0;
        if ($ready) {
            try {
                $db    = Db::get();
                $row   = $db->fetchRow($db->select('COUNT(id) AS c')->from('table.PicUpLinks'));
                $count = (int)($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $count = 0;
            }
        }

        $default = '';
        try {
            $default = trim((string)(Options::alloc()->plugin('PicUp')->defaultProfile ?? ''));
        } catch (\Throwable $e) {
            $default = '';
        }

        return [
            'ok'             => true,
            'table'          => Plugin::linksTableName(),
            'linksReady'     => $ready,
            'linksCount'     => $count,
            'profiles'       => Plugin::getProfileNames(),
            'defaultProfile' => $default !== '' ? $default : 'default',
            'backupProfiles' => Plugin::getBackupProfiles(),
            // 自动备份 = 建了表 且 至少勾了一个备份方案
            'backupEnabled'  => $ready && count(Plugin::getBackupProfiles()) > 0,
        ];
    }

    /**
     * 建表（幂等），即「开启自动备份」的第一步。
     */
    public static function enableBackupTable(): array
    {
        if (self::linksTableReady()) {
            return ['ok' => true, 'exists' => true, 'created' => false];
        }
        if (!Plugin::createLinksTable()) {
            return ['ok' => false, 'error' => '建表失败，请确认数据库账号有 CREATE TABLE 权限', 'code' => 500];
        }
        return ['ok' => true, 'exists' => true, 'created' => true];
    }

    /**
     * 保存勾选的备份方案。$raw 可以是 JSON 数组字符串，也可以是逗号分隔的名字列表。
     */
    public static function setBackupProfiles($raw): array
    {
        $arr = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($arr)) {
            $arr = array_filter(array_map('trim', explode(',', (string)$raw)));
        }

        $existing = Plugin::getProfileNames();
        $clean    = [];
        foreach ($arr as $name) {
            $name = trim((string)$name);
            if ($name !== '' && in_array($name, $existing, true) && !in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        $json = json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!Plugin::setPluginOption('backupProfiles', $json)) {
            return ['ok' => false, 'error' => '保存失败：无法写入插件配置', 'code' => 500];
        }

        return ['ok' => true, 'profiles' => $clean];
    }

    /**
     * 链接记录列表（分页 + 关键字搜索：文件名 / 方案名 / 链接）。
     */
    public static function listLinks(string $q = '', int $page = 1, int $limit = 20): array
    {
        if (!self::linksTableReady()) {
            return ['ok' => true, 'list' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'exists' => false];
        }

        $page = max(1, $page);
        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }
        $offset = ($page - 1) * $limit;

        $where  = '';
        $params = [];
        if ($q !== '') {
            $like   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where  = 'filename LIKE ? OR primary_url LIKE ? OR primary_profile LIKE ? OR links LIKE ?';
            $params = [$like, $like, $like, $like];
        }

        $db = Db::get();
        try {
            $select = $db->select('id', 'filename', 'primary_profile', 'primary_url', 'links', 'created_at')
                ->from('table.PicUpLinks');
            if ($where !== '') {
                $select->where($where, ...$params);
            }
            $rows = $db->fetchAll($select->order('id', Db::SORT_DESC)->offset($offset)->limit($limit));

            $countSel = $db->select('COUNT(id) AS c')->from('table.PicUpLinks');
            if ($where !== '') {
                $countSel->where($where, ...$params);
            }
            $cntRow = $db->fetchRow($countSel);
            $total  = (int)($cntRow['c'] ?? 0);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '读取链接记录失败：' . $e->getMessage(), 'code' => 500];
        }

        $list = [];
        foreach ($rows ?: [] as $row) {
            $links = json_decode((string)($row['links'] ?? '{}'), true);
            $list[] = [
                'id'              => (int)$row['id'],
                'filename'        => (string)$row['filename'],
                'primary_profile' => (string)$row['primary_profile'],
                'primary_url'     => (string)$row['primary_url'],
                'links'           => is_array($links) ? $links : [],
                'created_at'      => (string)$row['created_at'],
            ];
        }

        return ['ok' => true, 'list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit, 'exists' => true];
    }

    // ================================================================
    //  链接替换
    // ================================================================

    /**
     * 把旧链接替换成新链接——同时处理 attachment 的 JSON（转义形式 `\/`）与文章正文。
     *
     * @return array{attachments:int, posts:int, total:int}
     */
    public static function replaceUrlEverywhere(string $old, string $new): array
    {
        $stat = ['attachments' => 0, 'posts' => 0, 'total' => 0];
        if ($old === '' || $new === '' || $old === $new) {
            return $stat;
        }

        $pairs = [
            [$old, $new],
            [str_replace('/', '\\/', $old), str_replace('/', '\\/', $new)],
        ];

        // 复合存储路径（`nodeimage://id|https://…`）：附件里存的是整串，文章里用的是后段
        // 真正的 http 地址。两类行必须用各自的替换规则 —— 把复合串写进正文会变成坏链接。
        $pubOld = Plugin::publicUrl($old);
        $pubNew = Plugin::publicUrl($new);
        if ($pubOld === '') {
            $pubOld = $old;
        }
        if ($pubNew === '') {
            $pubNew = $new;
        }

        $pairsStore = [
            [$old, $new],
            [str_replace('/', '\\/', $old), str_replace('/', '\\/', $new)],
        ];
        $pairsPublic = [
            [$pubOld, $pubNew],
            [str_replace('/', '\\/', $pubOld), str_replace('/', '\\/', $pubNew)],
        ];

        // ⚠️ 只拿**文件名**做 LIKE 粗筛：库里的地址可能是明文 `http://…`，也可能是 JSON 转义的
        // `http:\/\/…`；而 `\/` 在三种数据库的 LIKE 里语义还不一样（MySQL 当转义符、SQLite/PG
        // 当字面量），拿整条 URL 去 LIKE 必然有一边匹配不到。文件名两种写法里都一样，
        // 精确的替换在 PHP 里做，粗筛多抓几行无所谓。
        $anchorSrc = preg_match('#^https?://#i', $pubOld) ? $pubOld : $old;
        $anchor    = (string)basename((string)(parse_url($anchorSrc, PHP_URL_PATH) ?: ''));
        if ($anchor === '') {
            $anchor = $anchorSrc;
        }

        $db = Db::get();
        try {
            $rows = $db->fetchAll(
                $db->select('cid', 'type', 'text')
                    ->from('table.contents')
                    ->where('text LIKE ?', '%' . $anchor . '%')
            );
        } catch (\Throwable $e) {
            return $stat;
        }

        foreach ($rows ?: [] as $row) {
            $text  = (string)$row['text'];
            $next  = $text;
            // 附件行存的是「存储值」，文章行存的是「公网地址」，规则不同（见上面的 $pairs*）
            $pairs = ((string)$row['type'] === 'attachment') ? $pairsStore : $pairsPublic;
            foreach ($pairs as $pair) {
                $next = str_replace($pair[0], $pair[1], $next);
            }
            if ($next === $text) {
                continue;
            }

            try {
                $db->query(
                    $db->update('table.contents')->rows(['text' => $next])->where('cid = ?', (int)$row['cid']),
                    Db::WRITE
                );
            } catch (\Throwable $e) {
                continue;
            }

            $stat['total']++;
            if ((string)$row['type'] === 'attachment') {
                $stat['attachments']++;
            } else {
                $stat['posts']++;
            }
        }

        return $stat;
    }

    /** 直接把附件的 path 改成新地址 */
    public static function setAttachmentPath(int $cid, string $newUrl): bool
    {
        $db = Db::get();
        try {
            $row = $db->fetchRow($db->select('text')->from('table.contents')->where('cid = ?', $cid));
            if (empty($row)) {
                return false;
            }
            $data = json_decode((string)$row['text'], true);
            if (!is_array($data)) {
                $data = @unserialize((string)$row['text'], ['allowed_classes' => false]);
            }
            if (!is_array($data)) {
                return false;
            }
            $data['path'] = $newUrl;
            $db->query(
                $db->update('table.contents')
                    ->rows(['text' => json_encode($data, JSON_UNESCAPED_UNICODE)])
                    ->where('cid = ?', $cid),
                Db::WRITE
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ================================================================
    //  批量迁移（设置页「迁移与替换」）
    // ================================================================

    public static function migratePreview(string $from, string $to): array
    {
        if ($from === '' || $to === '' || $from === $to) {
            return ['ok' => false, 'error' => '请选择两个不同的方案', 'code' => 400];
        }

        $rows = self::linksRowsAll();
        $atts = self::attachmentMap();
        $path2cid = [];
        foreach ($atts as $cid => $a) {
            if ($a['path'] !== '') {
                $path2cid[$a['path']] = $cid;
            }
        }

        $records     = 0;
        $attachments = 0;
        $skipped     = 0;
        $posts       = 0;
        $scanned     = 0;

        foreach ($rows as $row) {
            $links = $row['links'] ?: [];
            if (!isset($links[$from]) || !isset($links[$to])) {
                $skipped++;
                continue;
            }
            $old = (string)$links[$from];
            $new = (string)$links[$to];
            if ($old === '' || $new === '' || $old === $new) {
                $skipped++;
                continue;
            }
            $records++;
            if (isset($path2cid[$old])) {
                $attachments++;
            }
            // 文章命中数：最多统计前 200 条，避免站点很大时卡住
            if ($scanned < 200) {
                $scanned++;
                try {
                    // 与 replaceUrlEverywhere() 一致：用文件名粗筛，
                    // 避免 `http:\/\/` 转义形式在不同数据库 LIKE 下匹配不到。
                    $anchor = (string)basename((string)(parse_url($old, PHP_URL_PATH) ?: ''));
                    if ($anchor === '') {
                        $anchor = $old;
                    }
                    $db     = Db::get();
                    $cntRow = $db->fetchRow(
                        $db->select('COUNT(*) AS c')->from('table.contents')
                            ->where('type != ?', 'attachment')
                            ->where('text LIKE ?', '%' . $anchor . '%')
                    );
                    $posts += (int)($cntRow['c'] ?? 0);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        return [
            'ok'             => true,
            'from'           => $from,
            'to'             => $to,
            'records'        => $records,
            'attachments'    => $attachments,
            'posts'          => $posts,
            'skipped'        => $skipped,
            'postScanCapped' => $scanned >= 200,
        ];
    }

    public static function migrateRun(string $from, string $to): array
    {
        if ($from === '' || $to === '' || $from === $to) {
            return ['ok' => false, 'error' => '请选择两个不同的方案', 'code' => 400];
        }

        Plugin::ensureLinksCidColumn();

        $db    = Db::get();
        $rows  = self::linksRowsAll();
        $stat  = ['records' => 0, 'attachments' => 0, 'posts' => 0, 'skipped' => 0, 'total' => 0];
        $done  = [];
        $errors = [];

        foreach ($rows as $row) {
            $id    = (int)$row['id'];
            $links = $row['links'] ?: [];
            if (!isset($links[$from]) || !isset($links[$to])) {
                $stat['skipped']++;
                continue;
            }
            $old = (string)$links[$from];
            $new = (string)$links[$to];
            if ($old === '' || $new === '' || $old === $new) {
                $stat['skipped']++;
                continue;
            }
            if (isset($done[$old])) {   // 同一旧链接只处理一次
                $stat['skipped']++;
                continue;
            }
            $done[$old] = true;

            try {
                $db->query(
                    $db->update('table.PicUpLinks')
                        ->rows(['primary_profile' => $to, 'primary_url' => $new])
                        ->where('id = ?', $id),
                    Db::WRITE
                );
                $stat['records']++;
            } catch (\Throwable $e) {
                $stat['skipped']++;
                $errors[] = '记录 #' . $id . '：' . $e->getMessage();
                continue;
            }

            $r = self::replaceUrlEverywhere($old, $new);
            $stat['attachments'] += $r['attachments'];
            $stat['posts']       += $r['posts'];
            $stat['total']       += $r['total'];
        }

        $summary = [
            'at'      => date('Y-m-d H:i:s'),
            'from'    => $from,
            'to'      => $to,
            'records' => $stat['records'],
        ];
        Plugin::setPluginOption('lastMigrate', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $stat['ok']     = true;
        $stat['from']   = $from;
        $stat['to']     = $to;
        $stat['errors'] = $errors;

        return $stat;
    }

    // ================================================================
    //  单文件迁移（媒体库详情页）
    // ================================================================

    /**
     * 该附件在所有方案下的链接（没有备份记录时按 URL 反推方案名）。
     */
    public static function fileLinks(int $cid): array
    {
        Plugin::ensureLinksCidColumn();

        $atts = self::attachmentMap();
        if (!isset($atts[$cid])) {
            return ['ok' => false, 'error' => '找不到该附件（cid=' . $cid . '）', 'code' => 404];
        }
        $att  = $atts[$cid];
        $rows = self::linksRowsAll();
        $row  = self::findRowForAttachment($rows, $cid, $att['path']);

        $db = Db::get();

        // 命中后回填 cid，下次可直接用
        if ($row && (int)($row['cid'] ?? 0) !== $cid) {
            try {
                $db->query(
                    $db->update('table.PicUpLinks')->rows(['cid' => $cid])->where('id = ?', (int)$row['id']),
                    Db::WRITE
                );
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // 自愈：旧版本按文件名匹配过，可能把某条记录错误地绑定到本 cid。
        // 现在既然按地址匹配不上，说明这个绑定是错的，解开它，避免误导后续消歧。
        if (!$row) {
            foreach ($rows as $r) {
                if ((int)($r['cid'] ?? 0) === $cid) {
                    try {
                        $db->query(
                            $db->update('table.PicUpLinks')->rows(['cid' => 0])->where('id = ?', (int)$r['id']),
                            Db::WRITE
                        );
                    } catch (\Throwable $e) {
                        // ignore
                    }
                    break;
                }
            }
        }

        $links = $row ? ($row['links'] ?: []) : [];
        if (!$links && $att['path'] !== '') {
            // 没有自动备份记录时只剩「当前」这一条。方案名并不存在附件里
            // （attachment.text 只有 path），所以按各方案的域名 / 上传目录反推，
            // 反推不出来才退回中性标签。
            $guess = Plugin::guessProfileForUrl($att['path']);
            $links = [($guess !== '' ? $guess : '(当前)') => $att['path']];
        }

        $realProfiles = Plugin::getProfileNames();
        $profiles = [];
        foreach ($links as $name => $url) {
            $isReal = in_array((string)$name, $realProfiles, true);
            $profiles[] = [
                'profile' => (string)$name,
                // 非真方案键（`(原方案)` / `(当前)` 这类历史链接）在界面上统一叫「原链接」
                'label'   => $isReal ? (string)$name : '原链接',
                'virtual' => !$isReal,
                'url'     => (string)$url,
                'display' => Plugin::publicUrl((string)$url),
                'current' => ((string)$url === $att['path']),
            ];
        }

        return [
            'ok'          => true,
            'cid'         => $cid,
            'name'        => $att['name'],
            'path'        => $att['path'],
            'displayPath' => Plugin::publicUrl($att['path']),
            'hasRow'      => (bool)$row,
            'profiles'    => $profiles,
            'options'     => $realProfiles,
        ];
    }

    /**
     * 单文件迁移：目标方案里没有就上传一份，然后把附件地址切过去。
     *
     * 「先备份、再替换」：切换前会把当前链接记到它所属方案名下，
     * 所以迁移到 bak 之后 default 的链接仍然保留，可随时切回。
     */
    public static function fileMigrate(int $cid, string $target, bool $replaceInPosts = true): array
    {
        $target = trim($target);
        if ($cid <= 0 || $target === '') {
            return ['ok' => false, 'error' => '参数错误：缺少 cid 或目标方案', 'code' => 400];
        }

        Plugin::ensureLinksCidColumn();

        $atts = self::attachmentMap();
        if (!isset($atts[$cid])) {
            return ['ok' => false, 'error' => '找不到该附件（cid=' . $cid . '）', 'code' => 404];
        }
        $att = $atts[$cid];
        $old = $att['path'];
        if ($old === '') {
            return ['ok' => false, 'error' => '该附件没有记录地址', 'code' => 400];
        }

        $db   = Db::get();
        $rows = self::linksRowsAll();
        $row  = self::findRowForAttachment($rows, $cid, $old);
        $links = $row ? ($row['links'] ?: []) : [];

        // 目标可以是「真方案」，也可以是记录里已有的**历史链接**
        // （例如 `(原方案)` —— 当初反推不出方案名时留下的键）。
        // 后者不需要上传，直接把 path 指回去即可，否则用户就没法切回迁移前的图。
        $realProfiles = Plugin::getProfileNames();
        $isRealTarget = in_array($target, $realProfiles, true);
        if (!$isRealTarget && (!isset($links[$target]) || (string)$links[$target] === '')) {
            $known = array_values(array_unique(array_filter(
                array_merge($realProfiles, array_keys($links)),
                function ($n) {
                    return is_string($n) && trim($n) !== '';
                }
            )));
            return [
                'ok'    => false,
                'error' => '方案「' . $target . '」不存在，可用方案：' . implode('、', $known),
                'code'  => 400,
            ];
        }

        // 先备份、再替换：把「当前这条」链接记到它所属的方案名下，
        // 这样迁移到别的方案后原方案的链接仍然保留（还能在详情页一键切回）。
        if (!in_array($old, $links, true)) {
            $src = Plugin::guessProfileForUrl($old);
            if ($src === '' || $src === $target) {
                $src = '(原方案)';
            }
            $base = $src;
            $i    = 2;
            while (isset($links[$src])) {   // 方案名撞车时避免覆盖已有链接
                $src = $base . ' #' . $i;
                $i++;
            }
            $links[$src] = $old;
        }

        // 目标链接：已记录就直接用，否则先上传
        $new = '';
        if (isset($links[$target]) && (string)$links[$target] !== '') {
            $new = (string)$links[$target];
        } else {
            $tmp = Plugin::downloadToTemp($old);
            if (!$tmp) {
                return ['ok' => false, 'error' => '下载原文件失败，无法迁移', 'code' => 500];
            }
            // 复合存储路径要归一化后再取扩展名（否则 parse_url 拿不到 path）
            $oldPublic = Plugin::publicUrl($old);
            $ext  = $att['type'] !== '' ? $att['type'] : strtolower((string)pathinfo(parse_url($oldPublic, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $mime = $att['mime'] !== '' ? $att['mime'] : 'application/octet-stream';
            $new  = (string)Plugin::uploadFileToProfile($target, $tmp, $mime, $ext);
            @unlink($tmp);
            if ($new === '') {
                return ['ok' => false, 'error' => '上传到方案「' . $target . '」失败，请检查该方案配置', 'code' => 500];
            }
            $links[$target] = $new;
        }

        if ($new === $old) {
            return ['ok' => true, 'url' => $new, 'old' => $old, 'changed' => 0, 'message' => '当前已经是该方案的链接'];
        }

        // ① 替换正文与附件里出现的旧链接（attachment 的 JSON 会被一并处理）
        $stat = $replaceInPosts ? self::replaceUrlEverywhere($old, $new) : ['attachments' => 0, 'posts' => 0, 'total' => 0];

        // ② 保险：即使 $replace=false 也要把附件地址改掉
        if (!$replaceInPosts) {
            self::setAttachmentPath($cid, $new);
        }

        // ③ 更新 links 行
        try {
            if ($row) {
                $db->query(
                    $db->update('table.PicUpLinks')
                        ->rows([
                            'primary_profile' => $target,
                            'primary_url'     => $new,
                            'links'           => json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'cid'             => $cid,
                        ])
                        ->where('id = ?', (int)$row['id']),
                    Db::WRITE
                );
            } else {
                $db->query($db->insert('table.PicUpLinks')->rows([
                    'filename'        => mb_substr($att['name'], 0, 250),
                    'primary_profile' => $target,
                    'primary_url'     => $new,
                    'links'           => json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'cid'             => $cid,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]), Db::WRITE);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '链接已切换，但写入链接记录失败：' . $e->getMessage(), 'code' => 500];
        }

        return [
            'ok'      => true,
            'url'     => $new,
            'old'     => $old,
            'stats'   => $stat,
            'links'   => $links,
            'message' => '迁移完成：' . $target,
        ];
    }

    /**
     * 媒体库列表角标：cid => 备份数（links 条数 - 1）。
     */
    public static function mediaCounts(): array
    {
        Plugin::ensureLinksCidColumn();

        $rows = self::linksRowsAll();
        $atts = self::attachmentMap();

        $path2cid = [];
        foreach ($atts as $cid => $att) {
            if ($att['path'] !== '') {
                $path2cid[$att['path']] = $cid;
            }
        }

        $counts = [];
        foreach ($rows as $row) {
            $links = $row['links'] ?: [];
            $n     = max(0, count($links) - 1);
            if ($n <= 0) {
                continue;
            }
            $cid = 0;
            foreach ($links as $url) {
                if (isset($path2cid[(string)$url])) {
                    $cid = $path2cid[(string)$url];
                    break;
                }
            }
            if ($cid <= 0 && isset($path2cid[(string)($row['primary_url'] ?? '')])) {
                $cid = $path2cid[(string)$row['primary_url']];
            }
            if ($cid > 0) {
                $counts[$cid] = max($counts[$cid] ?? 0, $n);
            }
        }

        return ['ok' => true, 'counts' => $counts, 'profiles' => Plugin::getProfileNames()];
    }
}
