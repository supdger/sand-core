<?php

declare(strict_types=1);

use SandAdmin\Core\PublishExclusions;

/**
 * 发布排除规则的一致性断言。
 *
 * 口径来源：SandAdmin\Core\PublishExclusions。
 * 被校验通道：FrontendPublisher、composer.json archive.exclude、.gitattributes export-ignore，
 * 以及 sibling 包 sand-package 的 PublishExclusions / FrontendPublisher / SystemUpdateReleaseTools。
 */

$packageRoot = dirname(__DIR__);
require_once $packageRoot . '/server/PublishExclusions.php';
require_once $packageRoot . '/server/FrontendPublisher.php';

$siblingRoot = (string) ($argv[1] ?? dirname($packageRoot) . '/sand-package');

/** @var list<string> $failures */
$failures = [];
/** @var list<string> $notes */
$notes = [];

function diffReport(string $channel, array $expected, array $actual): string
{
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));
    $report = $channel . ' 与 PublishExclusions 不一致';
    if ($missing !== []) {
        $report .= PHP_EOL . '  缺少：' . implode(', ', $missing);
    }
    if ($extra !== []) {
        $report .= PHP_EOL . '  多出：' . implode(', ', $extra);
    }

    return $report;
}

// 1. composer.json archive.exclude
$composerPath = $packageRoot . '/composer.json';
$composer = json_decode((string) file_get_contents($composerPath), true);
$archive = is_array($composer) && is_array($composer['archive']['exclude'] ?? null)
    ? array_values(array_map('strval', $composer['archive']['exclude']))
    : [];
$expectedArchive = PublishExclusions::archiveExclude();
sort($archive);
if ($archive !== $expectedArchive) {
    $failures[] = diffReport('composer.json archive.exclude', $expectedArchive, $archive);
}

// 2. .gitattributes export-ignore
$gitattributes = $packageRoot . '/.gitattributes';
$exportIgnore = [];
foreach (is_file($gitattributes) ? (file($gitattributes, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, 'export-ignore')) {
        continue;
    }
    $exportIgnore[] = $line;
}
sort($exportIgnore);
$expectedExportIgnore = PublishExclusions::exportIgnore();
if (!is_file($gitattributes)) {
    $failures[] = '缺少 .gitattributes：export-ignore 通道无法与 PublishExclusions 对齐';
} elseif ($exportIgnore !== $expectedExportIgnore) {
    $failures[] = diffReport('.gitattributes export-ignore', $expectedExportIgnore, $exportIgnore);
}

// 3. FrontendPublisher 必须由 PublishExclusions 驱动，且探针判定一致
$publisherSource = (string) file_get_contents($packageRoot . '/server/FrontendPublisher.php');
if (!str_contains($publisherSource, 'PublishExclusions::publishExcluded')) {
    $failures[] = 'server/FrontendPublisher.php 未经 PublishExclusions::publishExcluded() 过滤发布面';
}
$probes = [];
foreach (PublishExclusions::DIRECTORIES as $directory) {
    $probes['src/' . $directory . '/probe.ts'] = true;
    $probes[$directory . '/probe.ts'] = true;
}
foreach (PublishExclusions::FILES as $file) {
    $probes['src/' . $file] = true;
    $probes[$file] = true;
}
foreach (PublishExclusions::FILE_PREFIXES as $prefix) {
    $probes['src/' . $prefix . 'probe'] = true;
}
foreach (PublishExclusions::sourcePaths() as $path) {
    $probes[$path] = true;
}
foreach (['package.json', 'src/main.ts', '.env.example', '.env.development.example', 'src/views/index.vue'] as $keep) {
    $probes[$keep] = false;
}
foreach ($probes as $relative => $expected) {
    if (PublishExclusions::publishExcluded((string) $relative) !== $expected) {
        $failures[] = sprintf(
            '发布面判定与规则不符：%s 期望 %s',
            $relative,
            $expected ? '排除' : '发布'
        );
    }
}

// 4. sibling 包：共享常量必须同步
$siblingExclusions = $siblingRoot . '/server/PublishExclusions.php';
if (is_file($siblingExclusions)) {
    require_once $siblingExclusions;
    $siblingClass = 'SandAdmin\\Package\\PublishExclusions';
    if (!class_exists($siblingClass)) {
        $failures[] = $siblingExclusions . ' 未声明 ' . $siblingClass;
    } else {
        foreach (['DIRECTORIES', 'FILES', 'FILE_PREFIXES', 'TRANSIENT_FILES', 'TRANSIENT_FILE_PREFIXES'] as $name) {
            $expected = (array) constant(PublishExclusions::class . '::' . $name);
            $actual = (array) constant($siblingClass . '::' . $name);
            if ($expected !== $actual) {
                $failures[] = diffReport('sand-package PublishExclusions::' . $name, $expected, $actual);
            }
        }
    }
} else {
    $notes[] = '跳过 sand-package PublishExclusions 比对：' . $siblingExclusions . ' 不存在';
}

// 5. sibling 包的发布器与发布工具必须复用同一口径
foreach ([
    '/server/FrontendPublisher.php',
    '/tools/SystemUpdateReleaseTools.php',
] as $relative) {
    $path = $siblingRoot . $relative;
    if (!is_file($path)) {
        $notes[] = '跳过 ' . $relative . ' 比对：' . $path . ' 不存在';
        continue;
    }
    $source = (string) file_get_contents($path);
    if (str_contains($source, 'PublishExclusions')) {
        continue;
    }
    $literals = [];
    foreach (array_merge(PublishExclusions::DIRECTORIES, PublishExclusions::FILES) as $rule) {
        if (str_contains($source, "'" . $rule . "'")) {
            $literals[] = $rule;
        }
    }
    if ($literals !== []) {
        $failures[] = 'sand-package' . $relative . ' 自带排除字面量而未引用 PublishExclusions：'
            . implode(', ', $literals);
    } else {
        $notes[] = 'sand-package' . $relative . ' 不含排除规则（无发布面过滤）';
    }
}

// 6. sibling 包 composer.json 至少覆盖共享规则
$siblingComposerPath = $siblingRoot . '/composer.json';
if (is_file($siblingComposerPath)) {
    $siblingComposer = json_decode((string) file_get_contents($siblingComposerPath), true);
    $siblingArchive = is_array($siblingComposer) && is_array($siblingComposer['archive']['exclude'] ?? null)
        ? array_map('strval', $siblingComposer['archive']['exclude'])
        : [];
    $shared = [];
    foreach (array_merge(PublishExclusions::DIRECTORIES, PublishExclusions::FILES) as $rule) {
        $shared[] = '/**/' . $rule;
    }
    foreach (PublishExclusions::FILE_PREFIXES as $prefix) {
        $shared[] = '/**/' . $prefix . '*';
    }
    $missing = array_values(array_diff($shared, $siblingArchive));
    if ($missing !== []) {
        $failures[] = 'sand-package composer.json archive.exclude 缺少共享规则：' . implode(', ', $missing);
    }
} else {
    $notes[] = '跳过 sand-package composer.json 比对：' . $siblingComposerPath . ' 不存在';
}

foreach ($notes as $note) {
    fwrite(STDOUT, '[note] ' . $note . PHP_EOL);
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[fail] ' . $failure . PHP_EOL);
    }
    exit(1);
}
fwrite(STDOUT, 'publish exclusions are consistent' . PHP_EOL);
