<?php

declare(strict_types=1);

namespace SandAdmin\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class Install
{
    public const WEBMAN_PLUGIN = true;

    private const PAYLOAD_MANIFEST = '.sand-core-payload-manifest.json';
    private const PACKAGE = 'supdger/sand-core';
    private const RECOVERY = 'composer run-script sand:publish'
        . '（强制覆盖：SAND_FRONTEND_PUBLISH=force composer run-script sand:publish）';

    private const PATH_RELATION = [
        'plugin/sandadmin' => 'plugin/sandadmin',
    ];

    /** 仅首次安装写入、后续升级不覆盖宿主修改的载荷子树。 */
    private const FIRST_WRITE_ONLY = [
        'config/',
    ];

    public static function install(): void
    {
        self::installByRelation();
        self::publishFrontend();
    }

    public static function update(): void
    {
        self::installByRelation();
        self::publishFrontend();
    }

    public static function uninstall(): void
    {
        $retained = [];
        foreach (self::PATH_RELATION as $source => $destination) {
            $root = self::normalizePath(base_path()) . '/' . $destination;
            $registered = self::payloadManifest($root)
                ?? self::treeHashes(self::normalizePath(__DIR__ . '/' . $source));
            self::removeRegistered($root, $registered, $retained);
            $manifest = $root . '/' . self::PAYLOAD_MANIFEST;
            if (is_file($manifest)) {
                unlink($manifest);
            }
            self::pruneEmptyDirectories($root);
        }
        try {
            $target = self::frontendTarget();
            $manifest = $target . '/' . FrontendPublisher::MANIFEST;
            if (is_file($manifest)) {
                $decoded = json_decode((string) file_get_contents($manifest), true);
                $registered = is_array($decoded) && is_array($decoded['files'] ?? null)
                    ? array_filter($decoded['files'], 'is_string')
                    : [];
                self::removeRegistered($target, $registered, $retained);
                unlink($manifest);
                $pending = $target . '/' . FrontendPublisher::PENDING;
                if (is_file($pending)) {
                    unlink($pending);
                }
                self::pruneEmptyDirectories($target);
            }
        } catch (Throwable $e) {
            self::warn('前端载荷卸载跳过：' . $e->getMessage());
        }
        if ($retained !== []) {
            self::warn('卸载保留了未登记或已修改的文件（共 ' . count($retained) . ' 个）：'
                . implode('、', array_slice($retained, 0, 20)));
        }
    }

    private static function publishFrontend(): void
    {
        try {
            $report = FrontendPublisher::publish(self::frontendTarget());
        } catch (Throwable $e) {
            self::warn('前端源码发布失败：' . $e->getMessage());
            self::warn('后端载荷已安装，恢复前端请执行：' . self::RECOVERY);

            return;
        }
        if ($report['skipped'] !== []) {
            self::warn('已跳过宿主修改过的前端文件（共 ' . count($report['skipped']) . ' 个）：'
                . implode('、', array_slice($report['skipped'], 0, 20)));
            self::warn('需要改用发布源内容请执行：' . self::RECOVERY);
        }
        if ($report['retained'] !== []) {
            self::warn('已下线但被宿主修改的前端文件已保留（共 ' . count($report['retained']) . ' 个）：'
                . implode('、', array_slice($report['retained'], 0, 20)));
        }
    }

    private static function installByRelation(): void
    {
        foreach (self::PATH_RELATION as $source => $destination) {
            $from = self::normalizePath(__DIR__ . '/' . $source);
            $root = self::normalizePath(base_path()) . '/' . $destination;
            if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
                throw new RuntimeException("无法创建后端载荷目录：{$root}");
            }
            $recorded = [];
            foreach (self::treeHashes($from) as $relative => $hash) {
                $target = $root . '/' . $relative;
                self::assertNoLinkedSegment($root, $relative);
                if (self::firstWriteOnly($relative) && is_file($target)) {
                    // 宿主已有配置不覆盖；清单记发布源哈希，卸载时与宿主改动区分开。
                    $recorded[$relative] = $hash;
                    continue;
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                    throw new RuntimeException("无法创建目录：{$parent}");
                }
                if (!copy($from . '/' . $relative, $target)) {
                    throw new RuntimeException("无法安装后端载荷文件：{$relative}");
                }
                $recorded[$relative] = $hash;
            }
            self::writePayloadManifest($root, $recorded);
        }
    }

    public static function frontendTarget(): string
    {
        $basePath = self::normalizePath(base_path());
        $configured = self::configuredFrontendPath($basePath);
        $target = $configured ?? (basename($basePath) === 'server'
            ? dirname($basePath) . '/sandadmin-artd'
            : $basePath . '/sandadmin-artd');
        $target = self::canonical($target);
        self::assertWithinProject($basePath, $target, $configured !== null);

        return $target;
    }

    private static function configuredFrontendPath(string $basePath): ?string
    {
        $composer = $basePath . '/composer.json';
        if (!is_file($composer)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($composer), true);
        if (!is_array($decoded)) {
            return null;
        }
        $configured = $decoded['extra']['sandadmin']['frontend-path'] ?? null;
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }
        $configured = self::normalizePath(trim($configured));

        return self::absolutePath($configured) ? $configured : $basePath . '/' . ltrim($configured, '/');
    }

    private static function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /** 目标必须位于宿主项目父级之内，且逐段非符号链接。 */
    private static function assertWithinProject(string $basePath, string $target, bool $configured): void
    {
        $hint = $configured
            ? '请修正宿主 composer.json 的 extra.sandadmin.frontend-path'
            : '请在宿主 composer.json 声明 extra.sandadmin.frontend-path 指向前端目录';
        if ($target === '' || $target === '/' || $target === $basePath || $target === dirname($basePath)) {
            throw new RuntimeException("前端目标目录无效：{$target}；{$hint}");
        }
        $boundary = dirname($basePath);
        if (!str_starts_with($target, $boundary . '/')) {
            throw new RuntimeException("前端目标目录越出宿主项目父级（{$boundary}）：{$target}；{$hint}");
        }
        $path = $boundary;
        foreach (explode('/', ltrim(substr($target, strlen($boundary)), '/')) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException("前端目标路径包含符号链接：{$path}；{$hint}");
            }
        }
        $existing = $target;
        while (!file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                throw new RuntimeException("前端目标目录不可解析：{$target}；{$hint}");
            }
            $existing = $parent;
        }
        $resolved = realpath($existing);
        $realBoundary = realpath($boundary);
        if ($resolved === false || $realBoundary === false) {
            throw new RuntimeException("前端目标目录不可解析：{$target}；{$hint}");
        }
        $resolved = self::normalizePath($resolved);
        $realBoundary = self::normalizePath($realBoundary);
        if ($resolved !== $realBoundary && !str_starts_with($resolved, $realBoundary . '/')) {
            throw new RuntimeException(
                "前端目标目录实际解析到宿主项目父级（{$realBoundary}）之外：{$resolved}；{$hint}"
            );
        }
    }

    private static function firstWriteOnly(string $relative): bool
    {
        foreach (self::FIRST_WRITE_ONLY as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,string> */
    private static function treeHashes(string $root): array
    {
        if (!is_dir($root)) {
            throw new RuntimeException("后端载荷源目录不存在：{$root}");
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(substr(self::normalizePath($file->getPathname()), strlen($root)), '/');
            if ($relative === self::PAYLOAD_MANIFEST || PublishExclusions::excluded($relative)) {
                continue;
            }
            $files[$relative] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array<string,string>|null */
    private static function payloadManifest(string $root): ?array
    {
        $path = $root . '/' . self::PAYLOAD_MANIFEST;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            return null;
        }

        return array_filter($decoded['files'], 'is_string');
    }

    /** @param array<string,string> $files */
    private static function writePayloadManifest(string $root, array $files): void
    {
        $manifest = [
            'schema' => 1,
            'package' => self::PACKAGE,
            'files' => $files,
        ];
        $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($content === false
            || file_put_contents($root . '/' . self::PAYLOAD_MANIFEST, $content . PHP_EOL, LOCK_EX) === false
        ) {
            throw new RuntimeException('无法写入 Sand Core 后端载荷清单：' . $root);
        }
    }

    /**
     * @param array<string,string> $registered
     * @param list<string> $retained
     */
    private static function removeRegistered(string $root, array $registered, array &$retained): void
    {
        foreach ($registered as $relative => $hash) {
            $path = $root . '/' . $relative;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            if (!hash_equals($hash, (string) hash_file('sha256', $path))) {
                $retained[] = $relative;
                continue;
            }
            unlink($path);
        }
    }

    private static function pruneEmptyDirectories(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink() && array_diff(scandir($item->getPathname()) ?: [], ['.', '..']) === []) {
                rmdir($item->getPathname());
            }
        }
        if (array_diff(scandir($root) ?: [], ['.', '..']) === []) {
            rmdir($root);
        }
    }

    private static function assertNoLinkedSegment(string $root, string $relative): void
    {
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException("载荷目标路径包含符号链接，拒绝写入：{$path}");
            }
        }
    }

    private static function warn(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, '[sandadmin] ' . $message . PHP_EOL);
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** 词法展开 . 与 ..（符号链接另由 assertWithinProject 拒绝）。 */
    private static function canonical(string $path): string
    {
        $normalized = self::normalizePath($path);
        $prefix = '';
        if (str_starts_with($normalized, '/')) {
            $prefix = '/';
        } elseif (preg_match('#^([A-Za-z]:)/#', $normalized, $match) === 1) {
            $prefix = $match[1] . '/';
        }
        $segments = [];
        foreach (explode('/', substr($normalized, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);
                continue;
            }
            if ($segment === '..' && $prefix !== '') {
                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }
}
