#!/usr/bin/env php
<?php

declare(strict_types=1);

// 开发专用符号引用门禁。
//   php tools/check-dev-refs.php            按 git ls-files 口径扫仓库源码
//   php tools/check-dev-refs.php --dir DIR  改扫文件系统目录（安装树/产物复检用）
// 规则：清单 rm 条目整棵跳过；strip 条目按哨兵剔除后的内容再扫；
// 白名单文件跳过；其余文件出现 dev-only 符号即失败。
// rm 条目仓库口径零命中也失败（清单过期）；--dir 复检已剔除树不算过期。

require_once __DIR__ . '/dev-manifest.php';

/** dev-only 符号表：类名、方法名、路由路径、配置键、环境变量、哨兵字面量。 */
const DEV_REF_PATTERNS = [
    'SANDADMIN_DEV_LOGIN',
    'DevLoginController',
    'DevLoginGuard',
    'devLogin',
    'dev-login',
    'dev_login',
    'SAND-DEV-BEGIN',
    'SAND-DEV-END',
];

/**
 * 显式豁免：这些文件允许出现 dev 符号，理由逐条写在注释里。
 * 路径语法与清单相同（精确文件或目录前缀）。
 */
const DEV_REF_WHITELIST = [
    // 机制文件自身：清单、哨兵剔除与门禁实现含 dev 符号字面量，属工具逻辑
    'tools/dev-only-paths.txt',
    'tools/dev-manifest.php',
    'tools/strip-dev.php',
    'tools/check-dev-refs.php',
    // PHPStan 基线逐字登记存量报错文本，dev-only 符号以报错信息字面量
    // 出现，属门禁工具数据而非代码引用
    'phpstan-baseline.neon',
    // tests/ 不进发布面（export-ignore 与 archive.exclude 双通道排除），
    // 测试代码合法引用 dev 符号，不需要登记
    'tests',
];

/** 不扫的第三方/运行目录。 */
const DEV_REF_SKIP = [
    'vendor',
    'node_modules',
    'dist',
    'server/vendor',
    'server/runtime',
    'sandadmin-artd/node_modules',
];

/** @return list<string> 命中说明，形如 "<pattern>@<行号>" */
function findDevRefs(string $content): array
{
    $hits = [];
    $lines = explode("\n", $content);
    foreach ($lines as $number => $line) {
        foreach (DEV_REF_PATTERNS as $pattern) {
            if (str_contains($line, $pattern)) {
                $hits[] = $pattern . '@' . ($number + 1);
            }
        }
    }

    return $hits;
}

/**
 * 按清单与白名单口径检查一批文件。
 *
 * @param list<string>         $files     相对 root 的文件路径
 * @param array{rm:list<string>,strip:list<string>} $manifest
 * @param callable(string):?string $read  读文件内容，不可读返回 null
 * @param bool $requireSentinels strip 文件缺哨兵块是否算违规；
 *        源码口径为 true，已剔除过的产物复检为 false
 * @return list<string> 违规说明，形如 "<path>:<行号> <pattern>"
 */
function scanDevRefs(array $files, array $manifest, callable $read, bool $requireSentinels = true): array
{
    $violations = [];
    foreach ($files as $path) {
        if (matchAnyDevPath($manifest['rm'], $path)
            || matchAnyDevPath(DEV_REF_WHITELIST, $path)
            || matchAnyDevPath(DEV_REF_SKIP, $path)) {
            continue;
        }
        $content = $read($path);
        if ($content === null) {
            continue;
        }
        if (matchAnyDevPath($manifest['strip'], $path)) {
            try {
                $content = stripDevBlocks($content, $path, $requireSentinels);
            } catch (RuntimeException $error) {
                $violations[] = $path . ': ' . $error->getMessage();
                continue;
            }
        }
        foreach (findDevRefs($content) as $hit) {
            $violations[] = $path . ':' . $hit;
        }
    }

    return $violations;
}

/**
 * 清单 rm 条目在给定文件集合里必须仍有命中，否则视为清单过期。
 *
 * @param list<string> $files
 * @param list<string> $rm
 * @return list<string> 零命中的清单条目
 */
function findStaleRmEntries(array $files, array $rm): array
{
    $stale = [];
    foreach ($rm as $pattern) {
        $hit = false;
        foreach ($files as $path) {
            if (matchDevPath($pattern, $path)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $stale[] = $pattern;
        }
    }

    return $stale;
}

$argv = $_SERVER['argv'] ?? [];
if (!is_array($argv)) {
    $argv = [];
}
$argv = array_values(array_filter($argv, 'is_string'));

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $pos = array_search('--dir', $argv, true);
    $dir = $pos === false ? null : ($argv[$pos + 1] ?? null);
    if ($pos !== false && ($dir === null || str_starts_with($dir, '--'))) {
        fwrite(STDERR, "用法：php tools/check-dev-refs.php [--dir <目录>]\n");
        exit(2);
    }

    $files = [];
    $base = $root;
    if ($dir !== null) {
        $base = realpath($dir);
        if ($base === false || !is_dir($base)) {
            fwrite(STDERR, "FAIL --dir 不存在：{$dir}\n");
            exit(2);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }
    } else {
        $listed = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files', $listed, $code);
        if ($code !== 0) {
            fwrite(STDERR, "FAIL git ls-files 执行失败\n");
            exit(2);
        }
        $files = $listed;
    }
    sort($files);

    $manifest = parseDevManifest($root . '/tools/dev-only-paths.txt');
    $violations = scanDevRefs(
        $files,
        $manifest,
        requireSentinels: $dir === null,
        read: static function (string $path) use ($base): ?string {
            $full = $base . '/' . $path;
            if (!is_file($full)) {
                return null;
            }
            $content = file_get_contents($full);

            return $content === false ? null : $content;
        },
    );
    if ($dir === null) {
        foreach (findStaleRmEntries($files, $manifest['rm']) as $pattern) {
            $violations[] = "清单过期 rm 条目无命中：{$pattern}";
        }
    }

    if ($violations !== []) {
        fwrite(STDERR, "FAIL 发现未登记的 dev-only 引用或过期清单条目：\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, '  ' . $violation . "\n");
        }
        exit(1);
    }
    echo 'PASS 未发现未登记的 dev-only 引用（' . count($files) . " 个文件）\n";
    exit(0);
}
