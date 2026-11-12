<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use RuntimeException;

/**
 * 生产环境 debug 护栏：APP_ENV=production|prod 时 app.debug 必须为 false，否则拒绝启动。
 *
 * 只在启动期判定；worker 内不得中途修改 debug。
 */
final class DebugGuard
{
    private const PRODUCTION = ['production', 'prod'];

    private function __construct()
    {
    }

    public static function isProduction(mixed $appEnv): bool
    {
        return is_string($appEnv) && in_array(strtolower(trim($appEnv)), self::PRODUCTION, true);
    }

    public static function isDebugOn(mixed $debug): bool
    {
        if (is_string($debug)) {
            return in_array(strtolower(trim($debug)), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $debug;
    }

    /** @throws RuntimeException 生产环境开启了 debug */
    public static function assertSafe(mixed $appEnv, mixed $debug): void
    {
        if (self::isProduction($appEnv) && self::isDebugOn($debug)) {
            throw new RuntimeException('生产环境（APP_ENV=production）禁止开启 app.debug，请在 .env 设置 APP_DEBUG=false 后再启动');
        }
    }

    public static function currentEnv(): mixed
    {
        $value = getenv('APP_ENV');
        return $value === false ? ($_ENV['APP_ENV'] ?? null) : $value;
    }

    public static function assertRuntimeConfig(): void
    {
        self::assertSafe(self::currentEnv(), config('app.debug', false));
    }

    /** 异常响应是否允许携带调试细节：仅非生产且 debug 明确开启。默认 false。 */
    public static function exposeDetails(): bool
    {
        return !self::isProduction(self::currentEnv()) && self::isDebugOn(config('app.debug', false));
    }
}
