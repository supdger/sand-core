<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\bootstrap;

use plugin\sandadmin\utils\DebugGuard;
use plugin\sandadmin\utils\InstallGuard;
use plugin\sandadmin\utils\JwtSecretGuard;
use Throwable;

/**
 * 进程启动自检：生产环境开启 debug 或 JWT 密钥不安全时拒绝启动（worker 内同时停止主进程，避免重启风暴）；未安装时准备一次性安装令牌。
 *
 * 由 config/bootstrap.php 注册，Webman 在每个 worker 启动时调用 start()。
 */
final class SecurityBootstrap
{
    /**
     * @param null|callable(object): void $halt 测试注入：默认向 Workerman 主进程发停止信号
     */
    public static function start(?object $worker = null, ?callable $halt = null): void
    {
        try {
            DebugGuard::assertRuntimeConfig();
            JwtSecretGuard::assertRuntimeConfig();
        } catch (Throwable $error) {
            // 同时写到控制台，避免异常被 worker 重启循环淹没。
            fwrite(STDERR, '[sandadmin] ' . $error->getMessage() . PHP_EOL);
            if ($worker !== null) {
                // 仅在 worker 进程内：抛错只会让 worker 退出并被主进程无限重启，
                // 必须让主进程整体停止，服务才算真正拒绝启动。CLI 命令（无 worker）不受影响。
                ($halt ?? self::haltMaster(...))($worker);
            }
            throw $error;
        }
        if (!InstallGuard::isInstalled()) {
            InstallGuard::ensureToken();
        }
    }

    /** 让 Workerman 主进程走正常停止流程（等价于 `php start.php stop`）；非 POSIX 环境无操作。 */
    public static function haltMaster(object $worker): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_kill') || !function_exists('posix_getppid') || !defined('SIGINT')) {
            return;
        }
        set_error_handler(static fn (): bool => true);
        try {
            posix_kill(posix_getppid(), SIGINT);
        } finally {
            restore_error_handler();
        }
    }
}
