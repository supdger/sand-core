<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use RuntimeException;

/**
 * tinywan/jwt 密钥校验：为空、占位符、已公开默认值或过短即拒绝，不允许静默放行。
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

    /**
     * tinywan/jwt 仓库历史版本、误提交 tag 与示例配置中公开过的默认密钥，
     * 只存 SHA-256，避免把明文再扩散进本仓。
     */
    private const KNOWN_PUBLIC_SHA256 = [
        'd88a122ae4b60e531e1ddad0e98b14a8fb2269860a0e3a36dc8744cbfb80abf1',
        'a2185df4029d801e6c1879d04b41fd77ccda30d048c2d5f52213cc66e77f2180',
        '69cf26c258590a4f52632dcaa7d475989cc7d2f01ed6fcd098ec2ad736648145',
        '3bfa930e7a56ec44e250122b2084ff341a5798e2041a0a6009a84fbf652e7f22',
        '4c3676d203dc76b23a861971a36b44a1ef5ba66107fdf8432b1ebdf838c78aa8',
        '91de891faef0df4df94beef74a1774045543e72de867875c6a50828f418ac51a',
        '4a5541deeeb13175d8c625b76b2e0a9456e2c8732fcc1cb775f17a73c73befc1',
        '45041c1385e4a4640df75dd020638e77470b2f89d524f772fef0f8fa25602905',
        '0b8bcd8173de8911b597a1a41c43bc5fc8e8ec3b7372a5eb5f41b1d64758b37e',
        'bf4a33428185ebe9f701db9831aa9d6bf742847fa5c42466f89bba93f0d50cb6',
    ];

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
            if (self::isKnownPublic($value)) {
                $problems[] = $field . ' 是已公开的默认密钥';
                continue;
            }
            if (in_array($algorithm, self::SYMMETRIC, true) && strlen($value) < self::MIN_LENGTH) {
                $problems[] = $field . ' 长度不足 ' . self::MIN_LENGTH . ' 字节';
            }
        }

        return $problems;
    }

    /** 命中即不可用：该值已被公开，任何人都能用它伪造令牌。 */
    public static function isKnownPublic(string $secret): bool
    {
        return in_array(hash('sha256', $secret), self::KNOWN_PUBLIC_SHA256, true);
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
