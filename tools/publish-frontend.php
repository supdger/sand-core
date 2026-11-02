<?php

declare(strict_types=1);

/**
 * sand:publish 幂等入口：手工或 `composer run-script sand:publish` 触发。
 *
 * 全部发布语义委托 SandAdmin\Core\FrontendPublisher / SandAdmin\Package\FrontendPublisher，
 * 不再保留第三套实现；重复执行对已一致的目标为 no-op。
 *
 * 目标目录：argv[1] 优先；否则读取宿主 composer.json 的
 * extra.sandadmin.frontend-path（相对该文件所在目录）。
 */

function fail(string $message): never
{
    fwrite(STDERR, '[sandadmin] ' . $message . PHP_EOL);
    exit(1);
}

// —— 加载 autoload：宿主 vendor/ 或包内直接 require ——
$autoload = dirname(__DIR__, 3) . '/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists(\SandAdmin\Core\FrontendPublisher::class)) {
    $server = dirname(__DIR__) . '/server';
    foreach (['PublishExclusions.php', 'FrontendPublisher.php'] as $file) {
        if (!is_file($server . '/' . $file)) {
            fail("找不到 Sand Core 前端发布实现：{$server}/{$file}");
        }
        require_once $server . '/' . $file;
    }
}

// —— 解析目标目录 ——
$target = $argv[1] ?? '';
if ($target === '') {
    // 宿主内运行时：server 目录 = vendor 的父目录
    $serverRoot = dirname(__DIR__, 4);
    $composer = $serverRoot . '/composer.json';
    $decoded = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
    $configured = $decoded['extra']['sandadmin']['frontend-path'] ?? null;
    if (is_string($configured) && $configured !== '') {
        $target = $serverRoot . '/' . $configured;
    } elseif (is_dir($serverRoot . '/../sandadmin-artd')) {
        $target = $serverRoot . '/../sandadmin-artd';
    }
}
if ($target === '') {
    fail('用法：php tools/publish-frontend.php [目标目录]；未给参数时读取宿主 composer.json extra.sandadmin.frontend-path');
}

// —— 发布：core 先行，package 存在则跟随；失败不互相连累 ——
$failed = 0;
foreach ([\SandAdmin\Core\FrontendPublisher::class, \SandAdmin\Package\FrontendPublisher::class] as $publisher) {
    if (!class_exists($publisher)) {
        continue;
    }
    try {
        $report = $publisher::publish($target);
        fwrite(STDOUT, sprintf(
            "[sandadmin] %s: %s（target=%s files=%d published=%d removed=%d skipped=%d retained=%d）\n",
            $publisher,
            (string) ($report['status'] ?? 'done'),
            (string) ($report['target'] ?? $target),
            (int) ($report['files'] ?? 0),
            (int) ($report['published'] ?? 0),
            (int) ($report['removed'] ?? 0),
            count((array) ($report['skipped'] ?? [])),
            count((array) ($report['retained'] ?? []))
        ));
    } catch (\Throwable $e) {
        $failed = 1;
        fwrite(STDERR, "[sandadmin] {$publisher} 发布失败：{$e->getMessage()}" . PHP_EOL);
    }
}

exit($failed);
