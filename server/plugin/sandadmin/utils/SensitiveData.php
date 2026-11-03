<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

/**
 * 敏感数据遮盖：请求参数键与数据表列名共用一套口径，供异常日志、debug 回显、
 * 操作日志和回收站直读（不经模型 $hidden）使用。
 */
final class SensitiveData
{
    public const MASK = '******';

    private const MAX_DEPTH = 16;

    /**
     * 敏感片段（匹配前统一转小写并去掉 `_` `-` 空格，故 oldPassword、old_password 等价）。
     * 命中任一片段即视为敏感，包含 password/passwd/pwd、secret、token、私钥与各类 API/Access 密钥、凭证。
     */
    private const FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'privatekey',
        'apikey',
        'accesskey',
        'secretkey',
        'credential',
        'authorization',
        'cookie',
    ];

    /**
     * 列名额外口径：邮箱/短信等验证码列。
     * 裸 `code`（如邮件记录的验证码）与 `verify_code`/`captcha`/`otp` 等整列名匹配即视为敏感，
     * 因此名为 `code` 的业务编码列（部门、角色、字典等表）在回收站输出中也会被剔除；
     * 仅限这些确切列名，不泛化到其他 `*_code`（如 `dept_code`）。
     * 请求参数键口径（isSensitiveKey）不含裸 `code`。
     */
    private const COLUMN_EXACT = ['code', 'otp', 'captcha', 'verifycode', 'verificationcode', 'smscode', 'emailcode', 'authcode'];

    private function __construct()
    {
    }

    private static function normalize(string $name): string
    {
        return str_replace(['_', '-', ' '], '', strtolower($name));
    }

    /** 请求参数键是否敏感（不含裸 `code`：业务参数常叫 code）。 */
    public static function isSensitiveKey(string|int $key): bool
    {
        $name = self::normalize((string) $key);
        if ($name === '') {
            return false;
        }
        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /** 数据表列名是否敏感：请求键口径 + 验证码类列。 */
    public static function isSensitiveColumn(string|int $column): bool
    {
        return self::isSensitiveKey($column) || in_array(self::normalize((string) $column), self::COLUMN_EXACT, true);
    }

    /** 递归遮盖请求参数中的敏感键值（含嵌套数组），其余原样保留。 */
    public static function maskParams(mixed $params, int $depth = 0): mixed
    {
        if (!is_array($params)) {
            return $params;
        }
        if ($depth >= self::MAX_DEPTH) {
            return self::MASK;
        }
        foreach ($params as $key => $value) {
            $params[$key] = self::isSensitiveKey($key) ? self::MASK : self::maskParams($value, $depth + 1);
        }
        return $params;
    }

    /**
     * 从直读数据库得到的分页结果里剔除敏感列。
     * 兼容 paginate()->toArray() 结构（含 data 行集）与普通行集。
     *
     * @param array<int|string, mixed> $result
     * @return array<int|string, mixed>
     */
    public static function stripSensitiveColumns(array $result): array
    {
        if (isset($result['data']) && is_array($result['data'])) {
            $result['data'] = self::stripRows($result['data']);
            return $result;
        }
        return self::stripRows($result);
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<int|string, mixed>
     */
    private static function stripRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $column) {
                if (self::isSensitiveColumn($column)) {
                    unset($row[$column]);
                }
            }
            $rows[$index] = $row;
        }
        return $rows;
    }
}
