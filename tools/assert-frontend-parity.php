<?php

declare(strict_types=1);

use SandAdmin\Core\FrontendPublisher;
use SandAdmin\Core\PublishExclusions;

/**
 * 包载荷 ↔ 宿主前端目录的 parity 断言。
 *
 * 用法：php tools/assert-frontend-parity.php <包内 sandadmin-artd 目录> <宿主 sandadmin-artd 目录> [白名单文件]
 * 白名单文件：每行一个相对路径或 fnmatch 通配，# 开头为注释；宿主侧匹配项不参与比对。
 * 内置豁免：发布清单、宿主 .env*、以及 sand-package 清单登记的覆盖层文件。
 */

$packageRoot = dirname(__DIR__);
require_once $packageRoot . '/server/PublishExclusions.php';
require_once $packageRoot . '/server/FrontendPublisher.php';

$sourceDirectory = (string) ($argv[1] ?? '');
$hostDirectory = (string) ($argv[2] ?? '');
$whitelistFile = (string) ($argv[3] ?? '');
if ($sourceDirectory === '' || $hostDirectory === '') {
    fwrite(STDERR, '用法：php tools/assert-frontend-parity.php <包内 sandadmin-artd 目录> <宿主 sandadmin-artd 目录> [白名单文件]' . PHP_EOL);
    exit(1);
}
foreach ([$sourceDirectory, $hostDirectory] as $directory) {
    if (!is_dir($directory)) {
        fwrite(STDERR, '目录不存在：' . $directory . PHP_EOL);
        exit(1);
    }
}

$patterns = [
    FrontendPublisher::MANIFEST,
    FrontendPublisher::PENDING,
    '.sand-package-source-manifest.json',
    '.sand-package-source-manifest.json.pending',
    '.env',
    '.env.local',
    '.env.development',
    '.env.development.local',
    '.env.production',
    '.env.production.local',
];
if ($whitelistFile !== '') {
    if (!is_file($whitelistFile)) {
        fwrite(STDERR, '白名单文件不存在：' . $whitelistFile . PHP_EOL);
        exit(1);
    }
    foreach (file($whitelistFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $patterns[] = $line;
        }
    }
}
$packageManifest = $hostDirectory . '/.sand-package-source-manifest.json';
if (is_file($packageManifest)) {
    $decoded = json_decode((string) file_get_contents($packageManifest), true);
    foreach (is_array($decoded) && is_array($decoded['files'] ?? null) ? array_keys($decoded['files']) : [] as $relative) {
        $patterns[] = (string) $relative;
    }
}

$exempt = static function (string $relative) use ($patterns): bool {
    foreach ($patterns as $pattern) {
        if ($relative === $pattern || fnmatch($pattern, $relative) || fnmatch($pattern . '/*', $relative)) {
            return true;
        }
    }

    return false;
};

$source = FrontendPublisher::sourceFiles($sourceDirectory);
$host = FrontendPublisher::sourceFiles($hostDirectory);
foreach (array_keys($host) as $relative) {
    if ($exempt((string) $relative)) {
        unset($host[$relative]);
    }
}
foreach (array_keys($source) as $relative) {
    if ($exempt((string) $relative)) {
        unset($source[$relative]);
    }
}

$missing = array_keys(array_diff_key($source, $host));
$extra = array_keys(array_diff_key($host, $source));
$changed = [];
foreach (array_intersect_key($source, $host) as $relative => $hash) {
    if (!hash_equals($hash, $host[$relative])) {
        $changed[] = $relative;
    }
}

if ($missing === [] && $extra === [] && $changed === []) {
    fwrite(STDOUT, sprintf(
        'frontend parity ok：%d 个文件一致（排除规则来自 %s）' . PHP_EOL,
        count($source),
        PublishExclusions::class
    ));
    exit(0);
}
foreach (['宿主缺少' => $missing, '宿主多出' => $extra, '内容不一致' => $changed] as $label => $list) {
    if ($list !== []) {
        fwrite(STDERR, '[fail] ' . $label . '（' . count($list) . '）：' . PHP_EOL);
        foreach ($list as $relative) {
            fwrite(STDERR, '  ' . $relative . PHP_EOL);
        }
    }
}
exit(1);
