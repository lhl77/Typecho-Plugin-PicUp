<?php

/**
 * PicUp for Typecho — 备份管理 Action 处理器
 *
 * 注册 action 名称：picup-backup
 * 支持操作（POST 参数 do）：
 *   backup  — 将当前 configJson + defaultProfile 保存为一条备份记录
 *   list    — 返回所有备份列表
 *   restore — 将指定备份恢复为当前 configJson + defaultProfile
 *   delete  — 删除指定备份
 *
 * @package PicUp
 */

namespace TypechoPlugin\PicUp;

use Typecho\Db;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    /** @var Db */
    private Db $db;

    /** 备份表名（含前缀） */
    private string $table;

    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->db    = Db::get();
        $this->table = $this->db->getPrefix() . 'PicUpBackup';
    }

    // ================================================================
    //  入口
    // ================================================================

    public function action(): void
    {
        // 仅管理员可操作
        $this->checkAdmin();

        $do = $this->request->get('do', '');
        switch ($do) {
            case 'backup':
                $this->doBackup();
                break;
            case 'list':
                $this->doList();
                break;
            case 'restore':
                $this->doRestore();
                break;
            case 'delete':
                $this->doDelete();
                break;
            case 'links-status':
                $this->doLinksStatus();
                break;
            case 'links-create':
                $this->doLinksCreate();
                break;
            case 'links-list':
                $this->doLinksList();
                break;
            case 'links-profiles':
                $this->doLinksProfiles();
                break;
            case 'media-counts':
                $this->doMediaCounts();
                break;
            case 'file-links':
                $this->doFileLinks();
                break;
            case 'file-migrate':
                $this->doFileMigrate();
                break;
            case 'migrate-preview':
                $this->doMigratePreview();
                break;
            case 'migrate-run':
                $this->doMigrateRun();
                break;
            default:
                $this->jsonError('未知操作', 400);
        }
    }

    // ================================================================
    //  操作实现
    // ================================================================

    /**
     * 保存当前 configJson 为一条备份记录
     */
    private function doBackup(): void
    {
        // 读取当前插件配置
        try {
            $opts       = Options::alloc()->plugin('PicUp');
            $configJson = $opts->configJson ?? '{}';
            $defProfile = $opts->defaultProfile ?? 'default';
        } catch (\Exception $e) {
            $this->jsonError('读取插件配置失败：' . $e->getMessage());
            return;
        }

        $label = trim($this->request->get('label', ''));
        if ($label === '') {
            $label = '备份 ' . date('Y-m-d H:i:s');
        }
        $now = date('Y-m-d H:i:s');

        try {
            // 使用 Query Builder，query() 会自动返回 lastInsertId
            $id = $this->db->query(
                $this->db->insert('table.PicUpBackup')
                    ->rows([
                        'label'           => $label,
                        'config_json'     => $configJson,
                        'default_profile' => $defProfile,
                        'backup_date'     => $now,
                    ])
            );
            $this->jsonSuccess(['id' => (int)$id, 'label' => $label, 'backup_date' => $now], '备份成功');
        } catch (\Exception $e) {
            $this->jsonError('备份写入数据库失败：' . $e->getMessage());
        }
    }

    /**
     * 列出所有备份（按时间倒序）
     */
    private function doList(): void
    {
        try {
            $rows = $this->db->fetchAll(
                $this->db->select('id', 'label', 'default_profile', 'backup_date')
                    ->from('table.PicUpBackup')
                    ->order('backup_date', Db::SORT_DESC)
            );
            $this->jsonSuccess(['list' => $rows ?: []]);
        } catch (\Exception $e) {
            $this->jsonError('读取备份列表失败：' . $e->getMessage());
        }
    }

    /**
     * 恢复指定备份 — 将其 config_json / default_profile 写回插件选项
     */
    private function doRestore(): void
    {
        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonError('参数错误：缺少 id', 400);
        }

        try {
            $row = $this->db->fetchRow(
                $this->db->select()->from('table.PicUpBackup')->where('id = ?', $id)
            );
        } catch (\Exception $e) {
            $this->jsonError('读取备份失败：' . $e->getMessage());
        }

        if (empty($row)) {
            $this->jsonError('备份不存在', 404);
        }

        // 将 config_json / default_profile 写入插件选项
        try {
            $this->db->query(
                $this->db->update('table.options')
                    ->rows(['value' => $row['config_json']])
                    ->where('name = ?', 'plugin:PicUp:configJson')
            );
            $this->db->query(
                $this->db->update('table.options')
                    ->rows(['value' => $row['default_profile']])
                    ->where('name = ?', 'plugin:PicUp:defaultProfile')
            );
            $this->jsonSuccess([
                'config_json'     => $row['config_json'],
                'default_profile' => $row['default_profile'],
            ], '恢复成功，但在保存设置后才能生效。');
        } catch (\Exception $e) {
            $this->jsonError('恢复失败：' . $e->getMessage());
        }
    }

    /**
     * 删除指定备份
     */
    private function doDelete(): void
    {
        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonError('参数错误：缺少 id', 400);
        }

        try {
            $this->db->query(
                $this->db->delete('table.PicUpBackup')
                    ->where('id = ?', $id)
            );
            $this->jsonSuccess([], '删除成功');
        } catch (\Exception $e) {
            $this->jsonError('删除失败：' . $e->getMessage());
        }
    }

    // ================================================================
    //  自动备份：links 表
    // ================================================================

    /**
     * links 表是否已就绪（不存在时查询会抛异常）。
     */
    private function linksTableReady(): bool
    {
        return Api::linksTableReady();
    }

    /**
     * 查询建表状态与记录数
     */
    private function doLinksStatus(): void
    {
        $ready = $this->linksTableReady();
        $count = 0;
        if ($ready) {
            try {
                $row   = $this->db->fetchRow($this->db->select('COUNT(id) AS c')->from('table.PicUpLinks'));
                $count = (int)($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $count = 0;
            }
        }

        $this->jsonSuccess([
            'exists' => $ready,
            'count'  => $count,
            'table'  => Plugin::linksTableName(),
        ]);
    }

    /**
     * 建表（幂等）——供设置页的「开启自动备份」按钮调用
     */
    private function doLinksCreate(): void
    {
        if ($this->linksTableReady()) {
            $this->jsonSuccess(['exists' => true, 'created' => false], '自动备份已是开启状态');
            return;
        }

        if (!Plugin::createLinksTable()) {
            $this->jsonError('建表失败，请确认数据库账号有 CREATE TABLE 权限', 500);
            return;
        }

        $this->jsonSuccess(['exists' => true, 'created' => true], '自动备份已开启');
    }

    /**
     * 链接查看器：分页 + 关键字搜索（文件名 / 方案名 / 链接）
     */
    private function doLinksList(): void
    {
        if (!$this->linksTableReady()) {
            $this->jsonSuccess(['list' => [], 'total' => 0, 'exists' => false], '尚未开启自动备份');
            return;
        }

        $q      = trim((string)$this->request->get('q', ''));
        $page   = max(1, (int)$this->request->get('page', 1));
        $limit  = (int)$this->request->get('limit', 20);
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

        try {
            $select = $this->db->select('id', 'filename', 'primary_profile', 'primary_url', 'links', 'created_at')
                ->from('table.PicUpLinks');
            if ($where !== '') {
                $select->where($where, ...$params);
            }
            $rows = $this->db->fetchAll($select->order('id', Db::SORT_DESC)->offset($offset)->limit($limit));

            $countSel = $this->db->select('COUNT(id) AS c')->from('table.PicUpLinks');
            if ($where !== '') {
                $countSel->where($where, ...$params);
            }
            $cntRow = $this->db->fetchRow($countSel);
            $total  = (int)($cntRow['c'] ?? 0);
        } catch (\Throwable $e) {
            $this->jsonError('读取链接记录失败：' . $e->getMessage());
            return;
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

        $this->jsonSuccess([
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'exists' => true,
        ]);
    }

    /**
     * 保存勾选的备份方案（复选框一变就存，不用点整页「保存设置」）
     */
    private function doLinksProfiles(): void
    {
        $raw = (string)$this->request->get('profiles', '[]');

        $res = Api::setBackupProfiles($raw);
        if (empty($res['ok'])) {
            $this->jsonError((string)($res['error'] ?? '保存失败'), (int)($res['code'] ?? 500));
            return;
        }

        $clean = $res['profiles'];
        $this->jsonSuccess(['profiles' => $clean], empty($clean) ? '已清空备份方案' : '已保存备份方案');
    }

    // ================================================================
    //  迁移与替换
    // ================================================================

    /** LIKE 通配符转义 */
    private function escapeLike(string $s): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    }

    /**
     * 读取全部 links 记录（links 字段已解码）。
     */
    private function linksRowsAll(): array
    {
        return Api::linksRowsAll();
    }

    /**
     * cid => [name, path, mime, type]（typecho_contents 中 type=attachment 的行）
     */
    private function attachmentMap(): array
    {
        return Api::attachmentMap();
    }

    /**
     * 找到某个附件对应的 links 行。
     *
     * ⚠️ **只认 URL**：附件当前地址必须命中记录里的某条链接才算匹配。
     * 不能按文件名回退——同名文件（如 `image.png`）很常见，会把 A 文件的备份记录
     * 挂到 B 文件上，导致「没开自动备份却已经存在备份链接」。
     * `cid` 列只用来在两个候选行之间消歧（以及回填）。
     */
    private function findRowForAttachment(array $rows, int $cid, string $path, string $name): ?array
    {
        return Api::findRowForAttachment($rows, $cid, $path);
    }

    /**
     * 把旧链接替换成新链接——同时处理 attachment 的 JSON（转义形式 `\/`）与文章正文。
     *
     * @return array{attachments:int, posts:int, total:int}
     */
    private function replaceUrlEverywhere(string $old, string $new): array
    {
        return Api::replaceUrlEverywhere($old, $new);
    }

    /**
     * 批量替换：源方案 → 目标方案
     */
    private function doMigratePreview(): void
    {
        $from = trim((string)$this->request->get('from', ''));
        $to   = trim((string)$this->request->get('to', ''));

        $res = Api::migratePreview($from, $to);
        if (empty($res['ok'])) {
            $this->jsonError((string)($res['error'] ?? '操作失败'), (int)($res['code'] ?? 400));
            return;
        }
        unset($res['ok']);
        $this->jsonSuccess($res);
    }

    private function doMigrateRun(): void
    {
        $from = trim((string)$this->request->get('from', ''));
        $to   = trim((string)$this->request->get('to', ''));

        $res = Api::migrateRun($from, $to);
        if (empty($res['ok'])) {
            $this->jsonError((string)($res['error'] ?? '操作失败'), (int)($res['code'] ?? 400));
            return;
        }
        unset($res['ok'], $res['from'], $res['to'], $res['errors']);

        $this->jsonSuccess($res, '替换完成：' . $res['records'] . ' 条记录、'
            . $res['attachments'] . ' 个附件、' . $res['posts'] . ' 篇文章');
    }

    /**
     * 单文件：返回该附件在所有方案下的链接
     */
    private function doFileLinks(): void
    {
        $cid = (int)$this->request->get('cid', 0);
        if ($cid <= 0) {
            $this->jsonError('参数错误：缺少 cid', 400);
            return;
        }

        $res = Api::fileLinks($cid);
        if (empty($res['ok'])) {
            $this->jsonError((string)($res['error'] ?? '读取失败'), (int)($res['code'] ?? 500));
            return;
        }
        unset($res['ok']);
        $this->jsonSuccess($res);
    }

    /**
     * 单文件迁移：目标方案里没有就传一份，然后把附件地址切过去
     */
    private function doFileMigrate(): void
    {
        $cid     = (int)$this->request->get('cid', 0);
        $target  = trim((string)$this->request->get('target', ''));
        $replace = (string)$this->request->get('replaceInPosts', '1') === '1';

        $res = Api::fileMigrate($cid, $target, $replace);
        if (empty($res['ok'])) {
            $this->jsonError((string)($res['error'] ?? '迁移失败'), (int)($res['code'] ?? 500));
            return;
        }

        $message = isset($res['message']) ? (string)$res['message'] : '迁移完成';
        unset($res['ok'], $res['message'], $res['links']);
        $this->jsonSuccess($res, $message);
    }

    /**
     * 直接把附件的 path 改成新地址
     */
    private function setAttachmentPath(int $cid, string $newUrl): bool
    {
        return Api::setAttachmentPath($cid, $newUrl);
    }

    /**
     * 媒体库列表角标：cid => 备份数（links 条数 - 1）
     *
     * 同样只按 URL 匹配（见 findRowForAttachment 里的说明），不按文件名。
     */
    private function doMediaCounts(): void
    {
        $res = Api::mediaCounts();
        unset($res['ok']);
        $this->jsonSuccess($res);
    }

    // ================================================================
    //  工具
    // ================================================================

    private function checkAdmin(): void
    {
        try {
            $user = \Typecho\Widget::widget('Widget\\User');
            if (!$user->hasLogin()) {
                $this->jsonError('请先登录', 401);
            }
            if ($user->pass('administrator', true) === false) {
                $this->jsonError('权限不足，仅管理员可操作', 403);
            }
        } catch (\Exception $e) {
            $this->jsonError('认证失败', 401);
        }
    }

    /** 简单转义（用于拼接 INSERT/UPDATE 的字符串值） */
    private function escape(string $str): string
    {
        return str_replace(["'", "\\"], ["\\'", "\\\\"], $str);
    }

    private function jsonSuccess(array $data = [], string $message = 'OK'): void
    {
        $this->response->throwJson([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    private function jsonError(string $message = 'Error', int $code = 500): void
    {
        $this->response->throwJson([
            'code'    => $code,
            'message' => $message,
            'data'    => null,
        ]);
    }
}
