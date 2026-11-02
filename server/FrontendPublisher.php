<?php

declare(strict_types=1);

namespace SandAdmin\Core;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class FrontendPublisher
{
    public const MANIFEST = '.sand-core-source-manifest.json';
    public const PENDING = '.sand-core-source-manifest.json.pending';

    private const PACKAGE = 'supdger/sand-core';
    private const RECOVERY = '确认可覆盖后执行 composer run-script sand:publish，'
        . '或 SAND_FRONTEND_PUBLISH=force composer run-script sand:publish 强制重新发布';
    private const CONFLICT_LIMIT = 20;

    /**
     * @return array{
     *     status:string,target:string,published:int,removed:int,
     *     skipped:list<string>,retained:list<string>,files:int
     * }
     */
    public static function publish(string $target): array
    {
        $mode = self::mode();
        $target = self::normalizePath($target);
        if ($mode === 'skip') {
            return self::report('skipped', $target, 0, 0, [], [], 0);
        }

        $source = realpath(dirname(__DIR__) . '/sandadmin-artd');
        if ($source === false || !is_dir($source)) {
            throw new RuntimeException('sandadmin-artd 源码目录不存在');
        }
        if ($target === '' || $target === '/') {
            throw new RuntimeException('sandadmin-artd 目标目录无效：' . $target);
        }
        if (self::samePath($target, $source)) {
            throw new RuntimeException("sandadmin-artd 目标目录不能是发布源目录：{$target}");
        }
        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException("无法创建 sandadmin-artd 目录：{$target}");
        }

        $files = self::sourceFiles($source);
        $installed = self::readManifest($target . '/' . self::MANIFEST, true);
        $pending = self::readManifest($target . '/' . self::PENDING, false);

        if ($mode === 'force') {
            return self::execute($source, $target, $files, [], true, 'forced');
        }
        if ($pending !== null && $pending['files'] === $files && self::allMatch($target, $files)) {
            self::commitManifest($target, $files);
            self::initializeEnvironment($source, $target);

            return self::report('committed', $target, 0, 0, [], [], count($files));
        }
        if ($installed !== null) {
            // 新增载荷文件与目标未受管理文件碰撞时不中止升级：copyTree 跳过并计入 skipped 报告。
            return self::execute($source, $target, $files, $installed['files'], false, 'updated');
        }
        if ($pending !== null) {
            // 中断重入：pending 登记的路径都是上一次发布正在写入的目标，重做 copy 时直接覆盖。
            return self::execute($source, $target, $files, $pending['files'], false, 'resumed', true);
        }
        if (self::persistentEntries($target) === []) {
            return self::execute($source, $target, $files, [], false, 'published');
        }

        self::assertNoConflict(
            $target,
            $files,
            'sandadmin-artd 目录非空、没有 Sand Core 发布清单，且存在与发布源内容不同的文件'
        );

        return self::execute($source, $target, $files, [], false, 'adopted');
    }

    /**
     * 发布源的相对路径到 sha256 映射。
     *
     * @return array<string,string>
     */
    public static function sourceFiles(string $root): array
    {
        $files = [];
        $normalized = self::normalizePath($root);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(
                substr(self::normalizePath($file->getPathname()), strlen($normalized)),
                '/'
            );
            if (PublishExclusions::publishExcluded($relative)) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException('无法读取发布源文件：' . $relative);
            }
            $files[$relative] = $hash;
        }
        ksort($files);

        return $files;
    }

    /** 读取环境开关：skip 跳过发布，force 强制覆盖并重建清单。 */
    public static function mode(): string
    {
        $value = getenv('SAND_FRONTEND_PUBLISH');
        if ($value === false) {
            $value = $_SERVER['SAND_FRONTEND_PUBLISH'] ?? $_ENV['SAND_FRONTEND_PUBLISH'] ?? '';
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['skip', 'force'], true) ? $value : '';
    }

    /**
     * @param array<string,string> $files
     * @param array<string,string> $baseline
     * @return array{
     *     status:string,target:string,published:int,removed:int,
     *     skipped:list<string>,retained:list<string>,files:int
     * }
     */
    private static function execute(
        string $source,
        string $target,
        array $files,
        array $baseline,
        bool $force,
        string $status,
        bool $ownsBaseline = false
    ): array {
        self::writePending($target, $files);
        [$removed, $retained] = self::removeObsolete($target, array_diff_key($baseline, $files), $force);
        [$published, $skipped] = self::copyTree($source, $target, $files, $baseline, $force, $ownsBaseline);
        self::pruneEmptyDirectories($target, $removed);
        self::commitManifest($target, $files);
        self::initializeEnvironment($source, $target);

        return self::report($status, $target, $published, count($removed), $skipped, $retained, count($files));
    }

    /**
     * @param array<string,string> $obsolete
     * @return array{0:list<string>,1:list<string>}
     */
    private static function removeObsolete(string $target, array $obsolete, bool $force): array
    {
        $removed = [];
        $retained = [];
        foreach ($obsolete as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            if (!$force && !hash_equals($hash, (string) hash_file('sha256', $path))) {
                $retained[] = $relative;
                continue;
            }
            if (!unlink($path)) {
                throw new RuntimeException("无法移除已下线的 Sand Core 前端文件：{$relative}");
            }
            $removed[] = $relative;
        }

        return [$removed, $retained];
    }

    /**
     * @param array<string,string> $files
     * @param array<string,string> $baseline
     * @return array{0:int,1:list<string>}
     */
    private static function copyTree(
        string $source,
        string $target,
        array $files,
        array $baseline,
        bool $force,
        bool $ownsBaseline
    ): array {
        $published = 0;
        $skipped = [];
        foreach ($files as $relative => $hash) {
            self::assertNoLinkedSegment($target, $relative);
            $destination = $target . '/' . $relative;
            if (is_dir($destination)) {
                throw new RuntimeException("目标同名路径是目录，无法发布 Sand Core 前端文件：{$relative}");
            }
            if (!$force && is_file($destination)) {
                $current = (string) hash_file('sha256', $destination);
                if (hash_equals($hash, $current)) {
                    continue;
                }
                $known = $baseline[$relative] ?? null;
                if ($known === null || (!$ownsBaseline && !hash_equals($known, $current))) {
                    $skipped[] = $relative;
                    continue;
                }
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!copy($source . '/' . $relative, $destination)) {
                throw new RuntimeException("无法发布 Sand Core 前端文件：{$relative}");
            }
            $published++;
        }

        return [$published, $skipped];
    }

    /** @param list<string> $removed */
    private static function pruneEmptyDirectories(string $target, array $removed): void
    {
        foreach ($removed as $relative) {
            $directory = dirname($target . '/' . $relative);
            while (self::normalizePath($directory) !== $target && is_dir($directory) && !is_link($directory)) {
                if (array_diff(scandir($directory) ?: [], ['.', '..']) !== []) {
                    break;
                }
                if (!rmdir($directory)) {
                    break;
                }
                $directory = dirname($directory);
            }
        }
    }

    /**
     * 目标与给定清单逐文件一致。
     *
     * @param array<string,string> $files
     */
    private static function allMatch(string $target, array $files): bool
    {
        foreach ($files as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path) || !is_file($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 目标中与发布源同名但内容不同的文件即冲突。
     *
     * @param array<string,string> $files
     */
    private static function assertNoConflict(string $target, array $files, string $reason): void
    {
        $conflicts = [];
        foreach ($files as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path) || is_dir($path)) {
                $conflicts[] = $relative;
                continue;
            }
            if (is_file($path) && !hash_equals($hash, (string) hash_file('sha256', $path))) {
                $conflicts[] = $relative;
            }
        }
        if ($conflicts === []) {
            return;
        }
        $listed = array_slice($conflicts, 0, self::CONFLICT_LIMIT);
        $message = $reason . '（共 ' . count($conflicts) . ' 个）：' . implode('、', $listed);
        if (count($conflicts) > count($listed)) {
            $message .= ' …';
        }

        throw new RuntimeException($message . '；' . self::RECOVERY);
    }

    /** 忽略易失文件后目标目录仍有内容。 @return list<string> */
    private static function persistentEntries(string $target): array
    {
        $entries = [];
        foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            if (!PublishExclusions::transient((string) $entry)) {
                $entries[] = (string) $entry;
            }
        }

        return $entries;
    }

    private static function assertNoLinkedSegment(string $target, string $relative): void
    {
        $path = $target;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException("发布目标路径包含符号链接，拒绝写入：{$path}");
            }
        }
    }

    private static function initializeEnvironment(string $source, string $target): void
    {
        foreach (['.env', '.env.development'] as $name) {
            $example = $source . '/' . $name . '.example';
            if (!is_file($example)) {
                continue;
            }
            $destination = $target . '/' . $name;
            if (is_link($destination)) {
                continue;
            }
            $lines = file($example, FILE_IGNORE_NEW_LINES) ?: [];
            if (!file_exists($destination)) {
                $content = [];
                foreach ($lines as $line) {
                    $content[] = self::environmentLine($line);
                }
                if (file_put_contents($destination, implode(PHP_EOL, $content) . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException("无法初始化前端环境文件：{$destination}");
                }
                continue;
            }
            if (!is_file($destination)) {
                continue;
            }
            $present = [];
            foreach (file($destination, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $key = self::environmentKey($line);
                if ($key !== null) {
                    $present[$key] = true;
                }
            }
            $missing = [];
            foreach ($lines as $line) {
                $key = self::environmentKey($line);
                if ($key !== null && !isset($present[$key])) {
                    $missing[] = self::environmentLine($line);
                }
            }
            if ($missing === []) {
                continue;
            }
            $current = (string) file_get_contents($destination);
            $prefix = ($current === '' || str_ends_with($current, PHP_EOL)) ? '' : PHP_EOL;
            $appended = $prefix . '# 由 Sand Core 发布补齐' . PHP_EOL . implode(PHP_EOL, $missing) . PHP_EOL;
            if (file_put_contents($destination, $appended, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException("无法补齐前端环境文件：{$destination}");
            }
        }
    }

    private static function environmentKey(string $line): ?string
    {
        return preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) === 1 ? $match[1] : null;
    }

    /** 安装期生成的值（锁屏密钥、版本号）不沿用 example 里的占位。 */
    private static function environmentLine(string $line): string
    {
        $key = self::environmentKey($line);
        if ($key === 'VITE_LOCK_ENCRYPT_KEY') {
            return 'VITE_LOCK_ENCRYPT_KEY = ' . bin2hex(random_bytes(16));
        }
        if ($key === 'VITE_VERSION') {
            $version = self::version();

            return $version === 'dev-main' ? $line : 'VITE_VERSION = ' . $version;
        }

        return $line;
    }

    /**
     * @return array{version:string,files:array<string,string>}|null
     */
    private static function readManifest(string $path, bool $strict): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)
            || !is_string($decoded['version'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            if (!$strict) {
                return null;
            }

            throw new RuntimeException("Sand Core 前端发布清单无效：{$path}；" . self::RECOVERY);
        }
        /** @var array<string,string> $files */
        $files = [];
        foreach ($decoded['files'] as $relative => $hash) {
            if (is_string($relative) && is_string($hash)) {
                $files[$relative] = $hash;
            }
        }
        ksort($files);

        return ['version' => (string) $decoded['version'], 'files' => $files];
    }

    /** @param array<string,string> $files */
    private static function writePending(string $target, array $files): void
    {
        self::writeJson($target . '/' . self::PENDING, self::manifest($files, true));
    }

    /** @param array<string,string> $files */
    private static function commitManifest(string $target, array $files): void
    {
        $pending = $target . '/' . self::PENDING;
        self::writeJson($pending, self::manifest($files, false));
        if (!rename($pending, $target . '/' . self::MANIFEST)) {
            throw new RuntimeException('无法生效 Sand Core 前端发布清单：' . $target . '/' . self::MANIFEST);
        }
    }

    /**
     * @param array<string,string> $files
     * @return array<string,mixed>
     */
    private static function manifest(array $files, bool $pending): array
    {
        $manifest = [
            'schema' => 1,
            'package' => self::PACKAGE,
            'version' => self::version(),
        ];
        if ($pending) {
            $manifest['pending'] = true;
        }
        $manifest['files'] = $files;

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private static function writeJson(string $path, array $manifest): void
    {
        $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($content === false || file_put_contents($path, $content . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("无法写入 Sand Core 前端发布清单：{$path}");
        }
    }

    private static function version(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'dev-main';
        }
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev-main';
        } catch (Throwable) {
            return 'dev-main';
        }
    }

    /**
     * @param list<string> $skipped
     * @param list<string> $retained
     * @return array{
     *     status:string,target:string,published:int,removed:int,
     *     skipped:list<string>,retained:list<string>,files:int
     * }
     */
    private static function report(
        string $status,
        string $target,
        int $published,
        int $removed,
        array $skipped,
        array $retained,
        int $files
    ): array {
        return [
            'status' => $status,
            'target' => $target,
            'published' => $published,
            'removed' => $removed,
            'skipped' => $skipped,
            'retained' => $retained,
            'files' => $files,
        ];
    }

    private static function samePath(string $left, string $right): bool
    {
        if (self::normalizePath($left) === self::normalizePath($right)) {
            return true;
        }
        $leftReal = realpath($left);
        $rightReal = realpath($right);

        return $leftReal !== false
            && $rightReal !== false
            && self::normalizePath($leftReal) === self::normalizePath($rightReal);
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
