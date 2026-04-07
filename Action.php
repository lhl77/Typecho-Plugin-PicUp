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
            ], '恢复成功，请刷新配置页面查看效果');
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
