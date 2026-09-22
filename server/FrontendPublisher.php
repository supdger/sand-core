<?php

declare(strict_types=1);

namespace SandAdmin\Core;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FrontendPublisher
{
    private const MANIFEST = '.sand-core-source-manifest.json';

    public static function publish(string $target): void
    {
        $source = realpath(dirname(__DIR__) . '/sandadmin-artd');
        if ($source === false || !is_dir($source)) {
            throw new RuntimeException('sandadmin-artd 源码目录不存在');
        }

        $target = self::normalizePath($target);
        if ($target === '' || $target === '/' || $target === self::normalizePath($source)) {
            throw new RuntimeException('sandadmin-artd 目标目录无效');
        }
        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException("无法创建 sandadmin-artd 目录：{$target}");
        }

        $installed = self::readManifest($target);
        $entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
        if ($installed === null && $entries !== []) {
            throw new RuntimeException('sandadmin-artd 目录非空且没有 Sand Core 发布清单，拒绝覆盖');
        }
        if ($installed !== null) {
            self::assertUnmodified($target, $installed['files']);
        }

        $files = self::sourceManifest($source);
        self::copyTree($source, $target);
        self::writeManifest($target, $files);
    }

    /** @return array<string, string> */
    private static function sourceManifest(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(
                substr(self::normalizePath($file->getPathname()), strlen(self::normalizePath($root))),
                '/'
            );
            if (self::excluded($relative)) {
                continue;
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array{version:string,files:array<string,string>}|null */
    private static function readManifest(string $target): ?array
    {
        $path = $target . '/' . self::MANIFEST;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)
            || !is_string($decoded['version'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            throw new RuntimeException("Sand Core 前端发布清单无效：{$path}");
        }

        return $decoded;
    }

    /** @param array<string,string> $files */
    private static function assertUnmodified(string $target, array $files): void
    {
        foreach ($files as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException("拒绝覆盖已修改的 Sand Core 前端源码：{$relative}");
            }
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = ltrim(
                substr(self::normalizePath($item->getPathname()), strlen(self::normalizePath($source))),
                '/'
            );
            if (self::excluded($relative)) {
                continue;
            }
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                    throw new RuntimeException("无法创建目录：{$destination}");
                }
                continue;
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException("无法发布 Sand Core 前端文件：{$relative}");
            }
        }
    }

    /** @param array<string,string> $files */
    private static function writeManifest(string $target, array $files): void
    {
        $manifest = [
            'schema' => 1,
            'package' => 'supdger/sand-core',
            'version' => class_exists(InstalledVersions::class)
                ? (InstalledVersions::getPrettyVersion('supdger/sand-core') ?? 'dev-main')
                : 'dev-main',
            'files' => $files,
        ];
        $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($content === false
            || file_put_contents($target . '/' . self::MANIFEST, $content . PHP_EOL, LOCK_EX) === false
        ) {
            throw new RuntimeException('无法写入 Sand Core 前端发布清单');
        }
    }

    private static function excluded(string $relative): bool
    {
        return str_starts_with($relative, 'node_modules/')
            || str_starts_with($relative, 'dist/')
            || $relative === '.DS_Store'
            || preg_match('#(^|/)\.(idea|vscode)(/|$)#', $relative) === 1;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
