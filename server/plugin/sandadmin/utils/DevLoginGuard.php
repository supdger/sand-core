<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

/**
 * 开发本机登录门禁：配置显式开启、非生产环境、对端为回环地址三者同时满足才可用。
 *
 * 对端 ip 由调用方传入裸 TCP 来源（$request->getRemoteIp()），不信任
 * X-Forwarded-For 等转发头，避免经前置代理伪造回环来源。生产环境
 * （APP_ENV=production|prod）即使配置开启也强制不可用。
 */
final class DevLoginGuard
{
    private const LOOPBACK_IPS = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    private function __construct()
    {
    }

    public static function isAvailable(mixed $remoteIp): bool
    {
        return self::isEnabled() && self::isLoopback($remoteIp);
    }

    public static function isEnabled(): bool
    {
        if (DebugGuard::isProduction(DebugGuard::currentEnv())) {
            return false;
        }
        if (self::username() === '') {
            return false;
        }
        return DebugGuard::isDebugOn(config('plugin.sandadmin.sandadmin.dev_login.enabled', false));
    }

    public static function username(): string
    {
        return trim((string) config('plugin.sandadmin.sandadmin.dev_login.username', ''));
    }

    private static function isLoopback(mixed $ip): bool
    {
        return is_string($ip) && in_array(strtolower(trim($ip)), self::LOOPBACK_IPS, true);
    }
}
