<?php

/**
 * PicUp for Typecho - 图像处理扩展接口
 *
 * @package PicUp
 * @author LHL
 * @version 1.0.0
 */

namespace TypechoPlugin\PicUp\extensions;

interface ExtensionInterface
{
    /**
     * 获取扩展名称
     *
     * @return string
     */
    public static function getName(): string;

    /**
     * 获取扩展简短描述
     *
     * @return string
     */
    public static function getDescription(): string;

    /**
     * 获取扩展执行优先级（数值越小越先执行）
     * 建议范围：10-99，压缩=10，水印=20，WebP转换=30
     *
     * @return int
     */
    public static function getOrder(): int;

    /**
     * 获取扩展所需的 PHP 扩展列表（如 ['gd', 'imagick']）
     *
     * @return string[]
     */
    public static function getRequiredPhpExtensions(): array;

    /**
     * 检测当前环境是否满足扩展运行条件（PHP 扩展已加载等）
     *
     * @return bool
     */
    public static function isAvailable(): bool;

    /**
     * 获取扩展的配置字段定义（格式与 DriverInterface::getConfigFields 相同）
     *
     * @return array
     */
    public static function getConfigFields(): array;

    /**
     * 对本地文件执行处理
     *
     * 处理完毕后：
     * - 若返回与 $localFile 相同的路径，则未产生新临时文件，调用者无需额外清理
     * - 若返回不同路径（新临时文件），调用者在上传完成后应删除该临时文件
     * - 若格式发生变化（如转为 WebP），应同时更新并返回新的 $mimeType
     *
     * @param string $localFile  当前本地文件路径（可能是上一个扩展产生的临时文件）
     * @param string $mimeType   当前文件 MIME 类型
     * @param array  $config     本扩展的配置（来自 Profile 中 _extensions.{key}）
     * @return array             [新本地文件路径, 新 MIME 类型]
     */
    public function process(string $localFile, string $mimeType, array $config): array;
}
