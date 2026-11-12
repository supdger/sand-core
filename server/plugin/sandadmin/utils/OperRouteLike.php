<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

/** 操作日志「操作路由」按片段匹配，输入里的通配符按字面处理。 */
final class OperRouteLike
{
    public static function pattern(string $text): string
    {
        $escaped = addcslashes(trim($text), '%_\\');
        return '%' . $escaped . '%';
    }
}
