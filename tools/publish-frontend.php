<?php

declare(strict_types=1);

const SAND_CORE_MANIFEST = '.sand-core-source-manifest.json';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function packageVersion(string $package): string
{
    if (!class_exists(\Composer\InstalledVersions::class, false)) {
        $autoload = dirname(__DIR__, 3) . '/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    return class_exists(\Composer\InstalledVersions::class)
        ? (\Composer\InstalledVersions::getPrettyVersion($package) ?? 'dev-main')
        : 'dev-main';
}

function normalizePath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    return rtrim($normalized, '/');
}

/** @return array<string, string> */
function sourceManifest(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = ltrim(substr(normalizePath($file->getPathname()), strlen(normalizePath($root))), '/');
        if (str_starts_with($relative, 'node_modules/')
            || str_starts_with($relative, 'dist/')
            || $relative === '.DS_Store'
            || preg_match('#(^|/)\.(idea|vscode)(/|$)#', $relative)
        ) {
            continue;
        }
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}

/** @return array{version:string,files:array<string,string>}|null */
function readInstalledManifest(string $target): ?array
{
    $path = $target . '/' . SAND_CORE_MANIFEST;
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_string($decoded['version'] ?? null) || !is_array($decoded['files'] ?? null)) {
        fail("目标发布清单无效：{$path}");
    }
    return $decoded;
}

/** @param array<string,string> $files */
function assertUnmodified(string $target, array $files): void
{
    foreach ($files as $relative => $hash) {
        $path = $target . '/' . $relative;
        if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
            fail("拒绝覆盖已修改的前端源码：{$relative}");
        }
    }
}

function copyTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = ltrim(substr(normalizePath($item->getPathname()), strlen(normalizePath($source))), '/');
        if (str_starts_with($relative, 'node_modules/')
            || str_starts_with($relative, 'dist/')
            || $relative === '.DS_Store'
            || preg_match('#(^|/)\.(idea|vscode)(/|$)#', $relative)
        ) {
            continue;
        }
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                fail("无法创建目录：{$destination}");
            }
            continue;
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            fail("无法创建目录：{$parent}");
        }
        if (!copy($item->getPathname(), $destination)) {
            fail("无法发布文件：{$relative}");
        }
    }
}

$targetArgument = $argv[1] ?? '';
if ($targetArgument === '') {
    fail('用法：php tools/publish-frontend.php <目标目录>');
}

$source = realpath(__DIR__ . '/../sandadmin-artd');
if ($source === false || !is_dir($source)) {
    fail('sandadmin-artd 源码目录不存在');
}

$target = normalizePath($targetArgument);
if ($target === '' || $target === '/' || $target === normalizePath(__DIR__ . '/../sandadmin-artd')) {
    fail('目标目录无效');
}

if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
    fail("无法创建目标目录：{$target}");
}

$installed = readInstalledManifest($target);
$entries = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
if ($installed === null && $entries !== []) {
    fail('目标目录非空且没有 Sand Core 发布清单，拒绝覆盖');
}
if ($installed !== null) {
    assertUnmodified($target, $installed['files']);
}

$files = sourceManifest($source);
copyTree($source, $target);
$version = packageVersion('supdger/sand-core');
$manifest = [
    'schema' => 1,
    'package' => 'supdger/sand-core',
    'version' => $version,
    'files' => $files,
];
$manifestPath = $target . '/' . SAND_CORE_MANIFEST;
if (file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    LOCK_EX
) === false) {
    fail("无法写入发布清单：{$manifestPath}");
}

fwrite(STDOUT, "SandAdmin 前端源码已发布到 {$target}（" . count($files) . " 个文件）" . PHP_EOL);
