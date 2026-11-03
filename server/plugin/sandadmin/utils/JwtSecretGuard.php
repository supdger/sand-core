<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use RuntimeException;

/**
 * tinywan/jwt 密钥校验：为空、仍是安装占位符或过短即拒绝，不允许静默放行。
 *
 * 错误信息只描述问题与修复方法，绝不回显密钥内容。
 */
final class JwtSecretGuard
{
    public const MIN_LENGTH = 32;
    public const CONFIG_KEY = 'plugin.tinywan.jwt.app.jwt';
    public const CONFIG_FILE = 'config/plugin/tinywan/jwt/app.php';

    private const SYMMETRIC = ['HS256', 'HS384', 'HS512'];
    private const PLACEHOLDER = '/__JWT_[A-Z_]*__/';

    private function __construct()
    {
    }

    /**
     * @param mixed $config config('plugin.tinywan.jwt.app.jwt') 的值
     * @return list<string> 问题列表；空表示通过
     */
    public static function problems(mixed $config): array
    {
        if (!is_array($config)) {
            return ['未找到 JWT 配置（' . self::CONFIG_KEY . '）'];
        }
        $algorithm = strtoupper((string) ($config['algorithms'] ?? 'HS256'));
        $fields = in_array($algorithm, self::SYMMETRIC, true)
            ? ['access_secret_key', 'refresh_secret_key']
            : ['access_private_key', 'access_public_key', 'refresh_private_key', 'refresh_public_key'];

        $problems = [];
        foreach ($fields as $field) {
            $value = $config[$field] ?? '';
            if (!is_string($value) || trim($value) === '') {
                $problems[] = $field . ' 为空';
                continue;
            }
            if (preg_match(self::PLACEHOLDER, $value) === 1) {
                $problems[] = $field . ' 仍是安装占位符';
                continue;
            }
            if (in_array($algorithm, self::SYMMETRIC, true) && strlen($value) < self::MIN_LENGTH) {
                $problems[] = $field . ' 长度不足 ' . self::MIN_LENGTH . ' 字节';
            }
        }

        return $problems;
    }

    /** @throws RuntimeException 配置不安全时附带修复说明 */
    public static function assertValid(mixed $config): void
    {
        $problems = self::problems($config);
        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            'JWT 密钥配置不安全，已拒绝启动或处理请求：' . implode('；', $problems) . '。'
            . '占位符是公开的，任何人都能用它伪造管理员令牌。请编辑 ' . self::CONFIG_FILE
            . '，为 access 与 refresh 各设置独立的随机密钥（至少 ' . self::MIN_LENGTH . ' 字节，例如执行 '
            . 'php -r "echo bin2hex(random_bytes(32));" 生成），确保该文件对服务进程可读，然后重启服务。'
        );
    }

    /** 读取运行时配置并校验（需要 Webman config() 助手）。 */
    public static function assertRuntimeConfig(): void
    {
        self::assertValid(config(self::CONFIG_KEY));
    }
}
