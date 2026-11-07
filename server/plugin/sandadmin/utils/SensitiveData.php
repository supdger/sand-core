<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

/**
 * 敏感数据遮盖：请求参数键与数据表列名共用一套口径，供异常日志、debug 回显、
 * 操作日志和回收站直读（不经模型 $hidden）使用；
 * 配置键另有收窄口径 isSensitiveConfigKey，仅用于配置列表/回收站/配置形态日志。
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

    /**
     * 配置键专用口径：在通用敏感键口径之上补充存储/邮件凭据键。
     * 覆盖 SMTP 用户名（Username / smtp_username / user_name 等 username 形态）
     * 与 `*_key` 形态接入密钥（s3_key、api_key、app_key、access_key 等；
     * 无下划线驼峰写法 accessKey/secretKey/apiKey 已由通用片段覆盖）。
     *
     * 仅用于配置打码与配置形态日志遮盖（maskConfigRows、maskParams 的
     * {key,value} 配置对分支），不影响 isSensitiveKey/isSensitiveColumn
     * 全局口径，避免 username、*_key 误伤非配置场景的请求参数与表列。
     */
    public static function isSensitiveConfigKey(string|int $key): bool
    {
        if (self::isSensitiveKey($key)) {
            return true;
        }
        $raw = strtolower(trim((string) $key));
        if ($raw === '') {
            return false;
        }
        if (str_contains(self::normalize($raw), 'username')) {
            return true;
        }
        return str_ends_with($raw, '_key') || $raw === 'key';
    }

    /**
     * 数组是否为敏感配置形态 {key: <敏感配置键>, value: ...}。
     * 要求同时具备 key/value 字段且 key 为字符串/整型，避免误伤普通数组。
     *
     * @param array<int|string, mixed> $params
     */
    private static function isSensitiveConfigPair(array $params): bool
    {
        if (!array_key_exists('key', $params) || !array_key_exists('value', $params)) {
            return false;
        }
        $key = $params['key'];
        return (is_string($key) || is_int($key)) && self::isSensitiveConfigKey($key);
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
        // 配置形态 {key,value} 项：batchUpdate 等把密钥嵌在 config[].value 下，
        // 参数键名（key/value）本身不敏感，需按配置键名口径补打码。
        // maskParams 仅服务于操作日志与异常日志回显，不触达业务消费。
        if (self::isSensitiveConfigPair($params)) {
            $params['value'] = self::MASK;
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

    /**
     * 写入侧占位符识别：列表打码回显值 '******' 回传时表示"保持原值"。
     * 仅与 MASK 严格相等才算占位符；真实密码、空串、null、非字符串一律不算。
     */
    public static function isMaskedPlaceholder(mixed $value): bool
    {
        return $value === self::MASK;
    }

    /**
     * 写入侧值解析：占位符回写为 $existing ?? ''（查无记录或库存为空时置空串），其余原样返回。
     * 注意：空串 '' 是真实写入值（清空配置），与占位符语义不同，不做特殊处理。
     */
    public static function resolveWriteValue(mixed $incoming, mixed $existing): mixed
    {
        return self::isMaskedPlaceholder($incoming) ? ($existing ?? '') : $incoming;
    }

    /**
     * 配置列表出参打码：敏感配置键（isSensitiveConfigKey）且库存 value 非空
     * （非 null 且非 ''）的行，value 替换为 MASK，并补 value_masked /
     * value_configured 两个布尔字段。
     * 兼容 paginate()->toArray()（含 data 行集）与 saiType=all 平铺行集两种结构。
     * 仅用于 HTTP 出参；内部读取（getData/getGroup/ConfigCache）不得调用，须保持明文。
     *
     * @param array<int|string, mixed> $result
     * @return array<int|string, mixed>
     */
    public static function maskConfigRows(array $result): array
    {
        if (isset($result['data']) && is_array($result['data'])) {
            $result['data'] = self::maskConfigRows($result['data']);
            return $result;
        }
        foreach ($result as $index => $row) {
            if (is_array($row)) {
                $result[$index] = self::maskConfigRow($row);
            }
        }
        return $result;
    }

    /**
     * 单行配置打码：非配置形态的数组（无 key/value 字段）原样返回，避免误伤嵌套结构。
     *
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    private static function maskConfigRow(array $row): array
    {
        if (!array_key_exists('key', $row) && !array_key_exists('value', $row)) {
            return $row;
        }
        $rawKey = $row['key'] ?? null;
        $value = $row['value'] ?? null;
        $configured = $value !== null && $value !== '';
        // 配置专用口径：在通用敏感键基础上补 s3_key、Username 等存储/邮件凭据键，
        // 口径收窄在配置打码路径内，不回写 isSensitiveKey 全局判定。
        $masked = $configured
            && (is_string($rawKey) || is_int($rawKey))
            && self::isSensitiveConfigKey($rawKey);
        if ($masked) {
            $row['value'] = self::MASK;
        }
        $row['value_masked'] = $masked;
        $row['value_configured'] = $configured;
        return $row;
    }
}
