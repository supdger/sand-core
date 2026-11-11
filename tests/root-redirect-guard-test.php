<?php

declare(strict_types=1);

/**
 * 路由守卫根路径兜底断言（开发用；不随包发布）。
 *
 *   php tests/root-redirect-guard-test.php
 *
 * 「/」是应用入口、本身没有路由记录：未注册分支必须先委托
 * handleRootPathRedirect 静默落到首页，不得误报「页面不可用」兜底提示。
 */

namespace {
    $root = dirname(__DIR__);
    $guardFile = $root . '/sandadmin-artd/src/router/guards/beforeEach.ts';
    if (!is_file($guardFile)) {
        fwrite(STDERR, "[FAIL] 前端源文件不存在：{$guardFile}\n");
        exit(1);
    }
    $guard = (string) file_get_contents($guardFile);

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };
    $has = static fn (string $haystack, string $needle): bool => str_contains($haystack, $needle);

    // 1. 「/」委托首页跳转：存在于未注册路由分支内、位于兜底提示之前。
    $delegation = "to.path === '/' && handleRootPathRedirect(to, next, router)";
    $assert($has($guard, $delegation),
        '未注册路由分支把根路径委托给 handleRootPathRedirect');
    $assert(strpos($guard, $delegation) < strpos($guard, '该页面不可用或无访问权限，已返回首页。'),
        '根路径委托位于兜底提示文案之前');

    // 2. 真值返回短路：首页跳转成功即 return，不再进入缺失路由兜底。
    $assert($has($guard, $delegation . ') {') && $has($guard, 'return'),
        'handleRootPathRedirect 真值返回时提前 return');

    // 3. 首页跳转函数契约不变：入口守卫、homePath 校验、replace 跳转。
    $assert($has($guard, "if (to.path !== '/') {\n    return false\n  }"),
        'handleRootPathRedirect 保留 to.path 守卫');
    $assert($has($guard, 'resolvableFallbackPath(router, homePath.value)'),
        '首页跳转仍先校验 homePath 可解析');
    $assert($has($guard, 'if (handleRootPathRedirect(to, next, router)) {'),
        '已注册路由分支的原有首页跳转调用保持不变');

    echo "ALL ROOT-REDIRECT-GUARD ASSERTIONS PASSED\n";
}
