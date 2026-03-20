<?php

/**
 * PicUp for Typecho - 存储驱动接口
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0

 */

namespace TypechoPlugin\PicUp\vendor;

interface DriverInterface
{
    /**
     * 获取驱动名称
     *
     * @return string
     */
    public static function getName(): string;

    /**
     * 获取驱动所需的配置字段定义
     * 返回格式: [
     *   'fieldName' => [
     *     'label'       => '显示名称',
     *     'type'        => 'text|password|number|select',
     *     'default'     => '默认值',
     *     'description' => '字段说明',
     *     'required'    => true|false,
     *     'options'     => ['key' => 'label']  // 仅 select 类型
     *   ],
     * ]
     *
     * @return array
     */
    public static function getConfigFields(): array;

    /**
     * 上传文件到远程存储
     *
     * @param string $localFile  本地文件路径（tmp_name 或绝对路径）
     * @param string $remotePath 远程存储路径（如 /2025/07/abc123.jpg）
     * @param string $mimeType   文件 MIME 类型
     * @return string|false      成功返回公开访问 URL，失败返回 false
     */
    public function upload(string $localFile, string $remotePath, string $mimeType);

    /**
     * 从远程存储删除文件
     *
     * @param string $remotePath 远程存储路径
     * @return bool
     */
    public function delete(string $remotePath): bool;

    /**
     * 根据远程路径获取公开访问 URL
     *
     * @param string $remotePath 远程存储路径
     * @return string
     */
    public function getUrl(string $remotePath): string;

    /**
     * 决定最终存储到数据库的路径。
     * 对于 S3/WebDAV 等驱动，存储 remotePath（相对路径），getUrl() 在读取时拼接域名。
     * 对于 Lsky Pro 等驱动，upload() 直接返回不可预测的完整 URL，应存储 uploadedUrl。
     *
     * @param string $remotePath  生成的远程路径（如 2025/07/abc.jpg）
     * @param string $uploadedUrl upload() 返回的结果（URL 或 false 的字符串形式）
     * @return string 应写入数据库 path 字段的值
     */
    public function getStoredPath(string $remotePath, string $uploadedUrl): string;

    /**
     * 驱动是否每次上传都需要生成全新路径（不复用旧附件路径）。
     * 对 Lsky Pro 等服务端自动管理路径的驱动，返回 true。
     * 对 S3/WebDAV 等可以覆盖写的驱动，返回 false。
     *
     * @return bool
     */
    public function alwaysNewPath(): bool;
}
