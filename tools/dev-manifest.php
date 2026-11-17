<?php

declare(strict_types=1);

// 开发专用代码清单与哨兵剔除的共享实现，供 check-dev-refs.php、strip-dev.php
// 与 tests/dev-strip-test.php require；不单独执行。

/**
 * 清单条目集合。
 *
 * @return array{rm: list<string>, strip: list<string>}
 */
function parseDevManifest(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("无法读取清单 {$path}");
    }
    $rm = [];
    $strip = [];
    foreach ($lines as $number => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(rm|strip)\s+(\S+)$/', $line, $match) !== 1) {
            throw new RuntimeException("清单 {$path}:" . ($number + 1) . " 非法行：{$line}");
        }
        [, $verb, $target] = $match;
        if (str_starts_with($target, '/') || str_contains($target, '..') || str_contains($target, '\\')) {
            throw new RuntimeException("清单 {$path}:" . ($number + 1) . " 路径非法：{$target}");
        }
        $target = rtrim($target, '/');
        if ($verb === 'rm') {
            $rm[] = $target;
        } else {
            $strip[] = $target;
        }
    }

    return ['rm' => $rm, 'strip' => $strip];
}

/**
 * 清单路径匹配：不带通配符时按「精确文件或目录前缀」匹配；
 * 带 *（单段任意字符）与 **（跨段任意字符）时按通配匹配；
 * 跨段通配兼容零层目录：清单 web/src 加跨段段加 *.test.ts 形条目
 * 同时命中 src 直属文件与子目录文件，否则会比注释语义少匹配一层。
 */
function matchDevPath(string $pattern, string $path): bool
{
    if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
        return $path === $pattern || str_starts_with($path, $pattern . '/');
    }
    $regex = preg_quote($pattern, '#');
    $regex = str_replace('\*\*/', "\x00", $regex);
    $regex = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $regex);
    $regex = str_replace("\x00", '(?:.*/)?', $regex);

    return preg_match('#^' . $regex . '$#', $path) === 1;
}

/** @param list<string> $patterns */
function matchAnyDevPath(array $patterns, string $path): bool
{
    foreach ($patterns as $pattern) {
        if (matchDevPath($pattern, $path)) {
            return true;
        }
    }

    return false;
}

/**
 * 校验清单 rm 条目在给定目录树下全部不存在；返回仍残留的相对路径。
 *
 * @param array{rm:list<string>,strip:list<string>} $manifest
 * @return list<string>
 */
function verifyDevManifest(string $root, array $manifest): array
{
    $left = [];
    $wildcard = array_values(array_filter(
        $manifest['rm'],
        static fn (string $entry): bool => str_contains($entry, '*') || str_contains($entry, '?'),
    ));
    foreach ($manifest['rm'] as $pattern) {
        if (in_array($pattern, $wildcard, true)) {
            continue;
        }
        if (file_exists($root . '/' . $pattern)) {
            $left[] = $pattern;
        }
    }
    if ($wildcard !== []) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (matchAnyDevPath($wildcard, $rel)) {
                $left[] = $rel;
            }
        }
    }

    return $left;
}

/**
 * 剔除 SAND-DEV-BEGIN 到 SAND-DEV-END 的哨兵块（含标记行）。
 * 未配对、嵌套或文件内无哨兵块都视为清单过期，直接报错。
 */
function stripDevBlocks(string $content, string $path, bool $requireBlock = true): string
{
    $lines = explode("\n", $content);
    $kept = [];
    $inside = false;
    $blocks = 0;
    foreach ($lines as $number => $line) {
        if (str_contains($line, 'SAND-DEV-BEGIN')) {
            if ($inside) {
                throw new RuntimeException("{$path}:" . ($number + 1) . ' 哨兵块嵌套');
            }
            $inside = true;
            $blocks++;
            continue;
        }
        if (str_contains($line, 'SAND-DEV-END')) {
            if (!$inside) {
                throw new RuntimeException("{$path}:" . ($number + 1) . ' SAND-DEV-END 没有配对 BEGIN');
            }
            $inside = false;
            continue;
        }
        if (!$inside) {
            $kept[] = $line;
        }
    }
    if ($inside) {
        throw new RuntimeException("{$path}: SAND-DEV-BEGIN 没有配对 END");
    }
    if ($blocks === 0 && $requireBlock) {
        throw new RuntimeException("{$path}: 清单登记为 strip 但文件内没有哨兵块");
    }

    return implode("\n", $kept);
}
