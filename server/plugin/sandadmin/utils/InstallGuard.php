<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use RuntimeException;

/**
 * 安装向导的访问控制：已安装锁定、一次性安装令牌、初始管理员凭据校验。
 *
 * 不依赖 Webman 运行时，所有路径以宿主根目录（base_path()）为基准，便于隔离测试。
 */
final class InstallGuard
{
    public const TOKEN_FILE = 'runtime/install-token';
    public const LOCK_FILE = 'runtime/install.lock';
    public const MIN_PASSWORD_LENGTH = 12;
    public const DEFAULT_ADMIN_USERNAME = 'admin';

    /**
     * 0.2.7 之前种子数据的全部去重默认口令哈希（均为弱默认口令的 bcrypt）。
     * 与 db/update-0.2.7.pgsql 中 UPDATE 的 IN 列表保持一致，由测试校验。
     */
    public const LEGACY_PASSWORD_HASHES = [
        '$2y$10$wnixh48uDnaW/6D9EygDd.OHJK0vQY/4nHaTjMKBCVDBP2NiTatqS',
        '$2y$10$J3EkwRH8rNkveaanx1.j.ebRiBpnnVUGWa.i2MS3aNpb9ydAOolmm',
        '$2y$10$H8d7riOjOiwPSopguEQ1fuKZz.fA0A54OvuzTqgJlbG1N3uOxEwM.',
        '$2y$10$sY/4StKVV.N/8Ock8J8kdeIOK4jS4tAUoYjkzvB8Tzy0fLh.wA2KS',
    ];

    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/D';
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_.@-]{3,64}$/D';
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*-_=+';

    private function __construct()
    {
    }

    public static function basePath(?string $base = null): string
    {
        $base ??= base_path();

        return rtrim($base, '/\\');
    }

    public static function envPath(?string $base = null): string
    {
        return self::basePath($base) . '/.env';
    }

    public static function tokenPath(?string $base = null): string
    {
        return self::basePath($base) . '/' . self::TOKEN_FILE;
    }

    public static function lockPath(?string $base = null): string
    {
        return self::basePath($base) . '/' . self::LOCK_FILE;
    }

    /** 根 .env 或安装锁文件任一存在即视为已安装。 */
    public static function isInstalled(?string $base = null): bool
    {
        clearstatcache();

        return is_file(self::envPath($base)) || is_file(self::lockPath($base));
    }

    /**
     * 确保存在一次性安装令牌并返回。
     *
     * 仅在本次调用首次创建令牌时调用 $announce（默认写到服务器标准输出），令牌不进入任何前端可读位置。
     *
     * @param null|callable(string, string): void $announce 参数：令牌、令牌文件路径
     */
    public static function ensureToken(?string $base = null, ?callable $announce = null): string
    {
        $path = self::tokenPath($base);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建安装令牌目录：' . $directory);
        }

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $existing = self::readToken($path);
            if ($existing !== null) {
                return $existing;
            }
            if (is_file($path)) {
                // 刚创建的文件可能仍在被另一个进程写入，稍候重读；否则视为损坏，移除后重建。
                if (time() - (int) @filemtime($path) < 2) {
                    usleep(200000);
                    continue;
                }
                @unlink($path);
            }
            $token = bin2hex(random_bytes(32));
            // x 模式保证多进程并发时只有一个创建者；创建后立即收紧为仅属主可读。
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                continue;
            }
            @chmod($path, 0600);
            $written = fwrite($handle, $token);
            fclose($handle);
            if ($written !== strlen($token)) {
                @unlink($path);
                throw new RuntimeException('无法写入安装令牌文件：' . $path);
            }
            ($announce ?? [self::class, 'announceToConsole'])($token, $path);

            return $token;
        }

        throw new RuntimeException('无法创建安装令牌文件：' . $path);
    }

    /** 默认公告：向服务器控制台/日志输出一次提示与令牌。 */
    public static function announceToConsole(string $token, string $path): void
    {
        echo PHP_EOL
            . '[sandadmin] 安装向导已启用。一次性安装令牌（仅在服务器控制台/日志显示，请勿外传）：'
            . PHP_EOL . $token . PHP_EOL
            . '[sandadmin] 令牌同时保存在仅属主可读的文件 ' . $path . '，安装成功后自动删除。'
            . PHP_EOL;
    }

    /** 常量时间比较；缺失令牌文件、空值或格式不符一律拒绝。不创建令牌、不访问数据库。 */
    public static function verifyToken(string $given, ?string $base = null): bool
    {
        $expected = self::readToken(self::tokenPath($base));
        if ($expected === null || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }

    /** 安装成功后：写入安装锁并删除令牌。 */
    public static function markInstalled(?string $base = null): void
    {
        $lock = self::lockPath($base);
        $directory = dirname($lock);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建安装锁目录：' . $directory);
        }
        $written = file_put_contents($lock, 'installed_at=' . gmdate('Y-m-d\TH:i:s\Z') . PHP_EOL);
        if ($written === false) {
            throw new RuntimeException('无法写入安装锁文件：' . $lock);
        }
        @chmod($lock, 0600);
        $token = self::tokenPath($base);
        if (is_file($token) && !@unlink($token)) {
            throw new RuntimeException('无法删除安装令牌文件：' . $token);
        }
    }

    /** 生成强随机初始管理员密码（默认 20 位，随机字符集）。 */
    public static function generatePassword(int $length = 20): string
    {
        $length = max($length, self::MIN_PASSWORD_LENGTH);
        $max = strlen(self::PASSWORD_ALPHABET) - 1;
        $password = '';
        for ($index = 0; $index < $length; $index++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }

        return $password;
    }

    /**
     * 校验操作者提交的初始管理员凭据。密码留空表示随机生成（此时不要求确认）。
     *
     * @return null|string 错误信息；通过时返回 null
     */
    public static function validateAdminCredentials(string $username, string $password, string $confirm): ?string
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return '管理员用户名只能包含字母、数字及 _ . @ -，长度 3-64 位';
        }
        if ($password === '') {
            return null;
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return '管理员密码至少 ' . self::MIN_PASSWORD_LENGTH . ' 位；留空则自动生成强密码';
        }
        if (!hash_equals($password, $confirm)) {
            return '两次输入的管理员密码不一致';
        }

        return null;
    }

    /**
     * 初始管理员是否要求首次登录改密：随机生成的密码必须改；
     * 操作者自行填写但命中旧默认口令（哈希等值匹配）同样必须改。
     */
    public static function requiresPasswordChange(bool $generated, string $password): bool
    {
        if ($generated) {
            return true;
        }
        foreach (self::LEGACY_PASSWORD_HASHES as $hash) {
            if (password_verify($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    private static function readToken(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return null;
        }
        $content = trim($content);

        return preg_match(self::TOKEN_PATTERN, $content) === 1 ? $content : null;
    }
}
