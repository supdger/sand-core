<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use plugin\sandadmin\exception\ApiException;
use Throwable;

/**
 * 首次登录强制改密：标志为真时，除改密、获取用户信息外的受保护接口一律拒绝。
 */
final class ForcedPasswordChange
{
    public const COLUMN = 'must_change_password';
    public const MIN_PASSWORD_LENGTH = 12;
    public const MESSAGE = '当前账号必须先修改初始密码，修改成功前无法访问其他功能。';

    /** 放行接口：controller 类名 => action 列表（均小写）。退出登录在前端完成，无后端接口。 */
    private const ALLOWED = [
        'plugin\\sandadmin\\app\\controller\\system\\systemusercontroller' => ['modifypassword'],
        'plugin\\sandadmin\\app\\controller\\systemcontroller' => ['userinfo'],
    ];

    private function __construct()
    {
    }

    public static function isAllowed(string $controller, string $action): bool
    {
        $controller = strtolower(ltrim($controller, '\\'));

        return in_array(strtolower($action), self::ALLOWED[$controller] ?? [], true);
    }

    /** @throws ApiException 标志为真且接口不在放行名单内 */
    public static function assertAllowed(bool $mustChange, string $controller, string $action): void
    {
        if ($mustChange && !self::isAllowed($controller, $action)) {
            throw new ApiException(self::MESSAGE, 400);
        }
    }

    /**
     * 读取用户当前标志（以数据库为准，不使用缓存）。
     * 升级前的旧库没有该列时按未置位处理，其余数据库错误向上抛出，不静默放行。
     *
     * @param null|callable(int): mixed $reader 测试注入：返回列值
     */
    public static function mustChange(int $userId, ?callable $reader = null): bool
    {
        try {
            $value = $reader !== null
                ? $reader($userId)
                : \plugin\sandadmin\app\model\system\SystemUser::where('id', $userId)->value(self::COLUMN);
        } catch (Throwable $error) {
            if (self::isMissingColumnError($error)) {
                return false;
            }
            throw $error;
        }

        return (int) $value === 1;
    }

    public static function isMissingColumnError(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, self::COLUMN)
            && (str_contains($message, '42703') || str_contains($message, 'does not exist') || str_contains($message, 'undefined column'));
    }

    /** 强制改密时对新密码的要求；返回错误信息，通过返回 null。 */
    public static function validateNewPassword(string $oldPassword, string $newPassword): ?string
    {
        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return '新密码至少 ' . self::MIN_PASSWORD_LENGTH . ' 位';
        }
        if (hash_equals($oldPassword, $newPassword)) {
            return '新密码不能与原密码相同';
        }

        return null;
    }
}
