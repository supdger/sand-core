<?php
// 开发专用代码清单机制的回归网：清单解析、路径通配、哨兵剔除，
// 以及清单对当前源码树可应用（rm 条目存在、strip 条目含哨兵）。
// 直接执行：php tests/dev-strip-test.php

declare(strict_types=1);

$fail = 0;
function check(bool $ok, string $name): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$tools = dirname(__DIR__) . '/tools';
require $tools . '/dev-manifest.php';

// —— parseDevManifest ——
$manifest = parseDevManifest($tools . '/dev-only-paths.txt');
check($manifest['rm'] !== [] && $manifest['strip'] !== [], '清单解析出 rm 与 strip 条目');
check(
    in_array('server/plugin/sandadmin/app/controller/DevLoginController.php', $manifest['rm'], true),
    '清单登记 DevLoginController 为 rm'
);
check(
    in_array('server/plugin/sandadmin/config/route.php', $manifest['strip'], true),
    '清单登记 route.php 为 strip'
);

$fixturePath = tempnam(sys_get_temp_dir(), 'dev-manifest-');
file_put_contents($fixturePath, "rm ok/path.php\nbogus line\n");
try {
    parseDevManifest($fixturePath);
    check(false, '清单非法行报错');
} catch (RuntimeException) {
    check(true, '清单非法行报错');
}
file_put_contents($fixturePath, "rm ../escape.php\n");
try {
    parseDevManifest($fixturePath);
    check(false, '清单越界路径报错');
} catch (RuntimeException) {
    check(true, '清单越界路径报错');
}
unlink($fixturePath);

// —— matchDevPath ——
check(matchDevPath('a/b.php', 'a/b.php'), '精确匹配文件');
check(matchDevPath('a/b', 'a/b/c.php'), '目录前缀匹配子文件');
check(!matchDevPath('a/b', 'a/bc.php'), '目录前缀不误配同前缀文件');
check(matchDevPath('x/*.test.ts', 'x/y.test.ts'), '单段通配命中');
check(!matchDevPath('x/*.test.ts', 'x/d/y.test.ts'), '单段通配不跨目录');
check(matchDevPath('x/**/*.test.ts', 'x/y.test.ts'), '跨段通配兼容零层目录');
check(matchDevPath('x/**/*.test.ts', 'x/a/b/y.test.ts'), '跨段通配命中多层');

// —— stripDevBlocks ——
$src = "a\n// SAND-DEV-BEGIN\nb\n// SAND-DEV-END\nc\n";
check(stripDevBlocks($src, 't') === "a\nc\n", '哨兵块整段剔除');
$two = "a\n// SAND-DEV-BEGIN\nb\n// SAND-DEV-END\nc\n// SAND-DEV-BEGIN\nd\n// SAND-DEV-END\ne\n";
check(stripDevBlocks($two, 't') === "a\nc\ne\n", '同文件多个哨兵块都剔除');
try {
    stripDevBlocks("a\n// SAND-DEV-BEGIN\nb\n", 't');
    check(false, '未配对哨兵报错');
} catch (RuntimeException) {
    check(true, '未配对哨兵报错');
}
try {
    stripDevBlocks("a\nb\n", 't');
    check(false, '登记 strip 但无哨兵报错');
} catch (RuntimeException) {
    check(true, '登记 strip 但无哨兵报错');
}
check(stripDevBlocks("a\nb\n", 't', false) === "a\nb\n", '宽容模式无哨兵原样返回');

// —— 清单对当前源码树可应用 ——
$root = dirname(__DIR__);
foreach ($manifest['rm'] as $rel) {
    check(file_exists($root . '/' . $rel), "rm 条目存在：{$rel}");
}
foreach ($manifest['strip'] as $rel) {
    $content = file_get_contents($root . '/' . $rel);
    check(
        $content !== false && str_contains($content, 'SAND-DEV-BEGIN'),
        "strip 条目含哨兵：{$rel}"
    );
}

echo $fail ? "{$fail} failed\n" : "all passed\n";
exit($fail ? 1 : 0);
