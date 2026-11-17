#!/usr/bin/env php
<?php

declare(strict_types=1);

// 把开发专用代码清单应用到一份源码导出树上。
//   php tools/strip-dev.php <导出根目录> [--verify|--reapply] [--manifest <清单路径>]
// rm 条目删除文件/目录（目录递归），strip 条目剔除哨兵块；
// 登记了 rm 但树里找不到、登记了 strip 但文件内无哨兵块，都算清单过期，报错退出。
// --manifest 默认取本包 tools/dev-only-paths.txt，对宿主 plugin/sandadmin/
// 载荷复剔时传换算前缀后的清单；--reapply 宽容复剔（缺 rm 条目跳过）。

require_once __DIR__ . '/dev-manifest.php';

/**
 * @param array{rm:list<string>,strip:list<string>} $manifest
 * @return list<string> 实际处理过的路径说明
 */
function applyDevManifest(string $root, array $manifest, bool $reapply = false): array
{
    $done = [];
    foreach ($manifest['rm'] as $pattern) {
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            $matched = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (matchDevPath($pattern, $rel)) {
                    $matched[] = $rel;
                }
            }
            if ($matched === []) {
                if ($reapply) {
                    continue;
                }
                throw new RuntimeException("清单 rm 通配无命中：{$pattern}");
            }
            foreach ($matched as $rel) {
                unlink($root . '/' . $rel);
                $done[] = "rm {$rel}";
            }
            continue;
        }
        $full = $root . '/' . $pattern;
        if (is_dir($full)) {
            removeDevTree($full);
            $done[] = "rm {$pattern}/";
            continue;
        }
        if (is_file($full)) {
            unlink($full);
            $done[] = "rm {$pattern}";
            continue;
        }
        if ($reapply) {
            continue;
        }
        throw new RuntimeException("清单 rm 路径不存在：{$pattern}");
    }
    foreach ($manifest['strip'] as $rel) {
        $full = $root . '/' . $rel;
        if (!is_file($full)) {
            throw new RuntimeException("清单 strip 路径不存在：{$rel}");
        }
        $content = file_get_contents($full);
        if ($content === false) {
            throw new RuntimeException("无法读取 {$rel}");
        }
        file_put_contents($full, stripDevBlocks($content, $rel, !$reapply));
        $done[] = "strip {$rel}";
    }

    return $done;
}

function removeDevTree(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($dir);
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    $root = realpath((string) ($_SERVER['argv'][1] ?? ''));
    $verifyOnly = in_array('--verify', $_SERVER['argv'], true);
    $reapply = in_array('--reapply', $_SERVER['argv'], true);
    $manifestPos = array_search('--manifest', $_SERVER['argv'], true);
    $manifestPath = $manifestPos === false
        ? dirname(__DIR__) . '/tools/dev-only-paths.txt'
        : ($_SERVER['argv'][$manifestPos + 1] ?? null);
    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, "用法：php tools/strip-dev.php <导出根目录> [--verify|--reapply] [--manifest <清单路径>]\n");
        exit(2);
    }
    if (!is_string($manifestPath) || !is_file($manifestPath)) {
        fwrite(STDERR, "FAIL 清单文件不存在：{$manifestPath}\n");
        exit(2);
    }
    try {
        $manifest = parseDevManifest($manifestPath);
        if ($verifyOnly) {
            $left = verifyDevManifest($root, $manifest);
            if ($left !== []) {
                fwrite(STDERR, "FAIL 清单路径仍在树中：\n");
                foreach ($left as $rel) {
                    fwrite(STDERR, '  ' . $rel . "\n");
                }
                exit(1);
            }
            echo "PASS 清单所列路径均不存在\n";
            exit(0);
        }
        $done = applyDevManifest($root, $manifest, $reapply);
    } catch (RuntimeException $error) {
        fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
        exit(1);
    }
    foreach ($done as $line) {
        echo '  ' . $line . "\n";
    }
    echo 'PASS 清单已应用（' . count($done) . " 项）\n";
    exit(0);
}
