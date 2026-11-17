<?php

declare(strict_types=1);

/**
 * 合并门禁测试 runner：跑 tests/ 全部断言脚本 + tools/ 发布断言。
 *
 * 用法：php tools/run-tests.php
 * tests/ 下的脚本为独立 PHP 断言（无 phpunit、无数据库连接），
 * 新增 *test*.php / security/*Test.php 会被自动拾取；helpers（stubs.php）除外。
 */

/**
 * @param list<string> $command
 */
function runGate(string $label, array $command): bool
{
    $parts = [];
    foreach ($command as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $cmd = implode(' ', $parts);
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $tail = trim((string) end($output));
    if ($code === 0) {
        fwrite(STDOUT, "[PASS] {$label}" . ($tail !== '' ? "（{$tail}）" : '') . PHP_EOL);
        return true;
    }
    fwrite(STDERR, "[FAIL] {$label}（exit={$code}）" . PHP_EOL);
    foreach (array_slice($output, -15) as $line) {
        fwrite(STDERR, '  ' . $line . PHP_EOL);
    }
    return false;
}

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$gate = static function (bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
};

// tests/ 断言脚本：顶层 *.php + security/*Test.php，排除 stubs.php。
$tests = array_merge(
    glob($root . '/tests/*.php') ?: [],
    glob($root . '/tests/security/*Test.php') ?: []
);
sort($tests);
foreach ($tests as $file) {
    if (basename($file) === 'stubs.php') {
        continue;
    }
    $gate(runGate('tests/' . substr($file, strlen($root . '/tests/')), [PHP_BINARY, $file]));
}

// tools/ 发布断言（assert-frontend-parity 需要宿主目录，见下方回环）。
$gate(runGate('tools/assert-no-http-install.php', [PHP_BINARY, $root . '/tools/assert-no-http-install.php']));
$gate(runGate('tools/assert-publish-exclusions.php', [PHP_BINARY, $root . '/tools/assert-publish-exclusions.php']));

// 前端发布回环：发布到临时目录，再断言包载荷与发布产物一致。
$staging = sys_get_temp_dir() . '/sand-core-gate-' . getmypid();
$hostFrontend = $staging . '/frontend';
if (!is_dir($hostFrontend) && !mkdir($hostFrontend, 0777, true)) {
    fwrite(STDERR, "[FAIL] 无法创建发布临时目录 {$hostFrontend}" . PHP_EOL);
    exit(1);
}
$gate(runGate('publish-frontend → 临时宿主目录', [PHP_BINARY, $root . '/tools/publish-frontend.php', $hostFrontend]));
$gate(runGate('tools/assert-frontend-parity.php（发布回环）', [
    PHP_BINARY, $root . '/tools/assert-frontend-parity.php',
    $root . '/sandadmin-artd', $hostFrontend,
]));
$cleanup = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
/** @var SplFileInfo $entry */
foreach ($cleanup as $entry) {
    $path = $entry->getPathname();
    $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
}
rmdir($staging);

fwrite(STDOUT, sprintf('----%s%d 项通过，%d 项失败%s', PHP_EOL, $passed, $failed, PHP_EOL));
exit($failed === 0 ? 0 : 1);
