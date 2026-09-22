<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$publisher = $packageRoot . '/tools/publish-frontend.php';
$root = sys_get_temp_dir() . '/sand-core-publish-' . bin2hex(random_bytes(6));
$first = $root . '/first';
$modified = $root . '/modified';
$installedRoot = $root . '/installed';

function runPublisher(string $publisher, string $target): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($publisher) . ' ' . escapeshellarg($target);
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

try {
    [$code, $output] = runPublisher($publisher, $first);
    if ($code !== 0 || !is_file($first . '/.sand-core-source-manifest.json')) {
        throw new RuntimeException("首次发布失败：{$output}");
    }

    [$code, $output] = runPublisher($publisher, $first);
    if ($code !== 0) {
        throw new RuntimeException("幂等发布失败：{$output}");
    }

    [$code, $output] = runPublisher($publisher, $modified);
    if ($code !== 0) {
        throw new RuntimeException("修改场景首次发布失败：{$output}");
    }
    file_put_contents($modified . '/package.json', "\n", FILE_APPEND);
    [$code, $output] = runPublisher($publisher, $modified);
    if ($code === 0 || !str_contains($output, '拒绝覆盖已修改的前端源码')) {
        throw new RuntimeException("未知修改未被拒绝：{$output}");
    }

    $installedPackage = $installedRoot . '/vendor/supdger/sand-core';
    mkdir($installedPackage . '/tools', 0777, true);
    mkdir($installedPackage . '/sandadmin-artd', 0777, true);
    copy($publisher, $installedPackage . '/tools/publish-frontend.php');
    file_put_contents($installedPackage . '/sandadmin-artd/package.json', "{}\n");
    mkdir($installedRoot . '/vendor/composer', 0777, true);
    file_put_contents(
        $installedRoot . '/vendor/composer/InstalledVersions.php',
        "<?php\nnamespace Composer;\nfinal class InstalledVersions { public static function getPrettyVersion(string \$package): ?string { return \$package === 'supdger/sand-core' ? '0.1.2' : null; } }\n"
    );
    file_put_contents(
        $installedRoot . '/vendor/autoload.php',
        "<?php\nspl_autoload_register(static function (string \$class): void { if (\$class === 'Composer\\\\InstalledVersions') { require __DIR__ . '/composer/InstalledVersions.php'; } });\n"
    );
    [$code, $output] = runPublisher(
        $installedPackage . '/tools/publish-frontend.php',
        $installedRoot . '/published'
    );
    $installedManifest = json_decode(
        (string) file_get_contents($installedRoot . '/published/.sand-core-source-manifest.json'),
        true
    );
    if ($code !== 0 || ($installedManifest['version'] ?? null) !== '0.1.2') {
        throw new RuntimeException("安装形态未记录 Composer 版本：{$output}");
    }

    fwrite(STDOUT, "publish frontend tests passed\n");
} finally {
    removeTree($root);
}
