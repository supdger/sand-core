<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$publisher = $packageRoot . '/tools/publish-frontend.php';
$root = sys_get_temp_dir() . '/sand-core-publish-' . bin2hex(random_bytes(6));
$first = $root . '/first';
$modified = $root . '/modified';

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

    fwrite(STDOUT, "publish frontend tests passed\n");
} finally {
    removeTree($root);
}
