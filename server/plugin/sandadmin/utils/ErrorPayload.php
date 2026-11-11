<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use plugin\sandadmin\exception\SystemException;
use support\exception\BusinessException;
use Throwable;

/**
 * 异常 → HTTP 响应体映射（纯函数）。
 *
 * 非调试（含生产）只返回 {code, message, fields, request_id}：
 * 非业务异常的 message 一律替换为通用文案，不回显路径、SQL、栈。
 * 调试细节仅在 $debugDetails 非空时附加（由调用方在非生产 + debug 时传入）。
 */
final class ErrorPayload
{
    public const GENERIC_MESSAGE = 'Server internal error';

    private function __construct()
    {
    }

    public static function newRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $debugDetails
     * @return array<string, mixed>
     */
    public static function build(Throwable $exception, string $requestId, array $debugDetails = []): array
    {
        $rawCode = $exception->getCode();
        $code = is_int($rawCode) && $rawCode !== 0 ? $rawCode : 500;
        // 只有业务异常与 SystemException（插件对外错误通道，文案即面向用户）才透出
        // message；PDO/运行时异常可能带 SQL 或路径。
        $business = $exception instanceof BusinessException || $exception instanceof SystemException;
        $message = ($business || $debugDetails !== []) && $rawCode !== 500
            ? $exception->getMessage()
            : self::GENERIC_MESSAGE;
        $json = [
            'code' => $code,
            'message' => $message,
            'fields' => [],
            'request_id' => $requestId,
        ];
        return $debugDetails === [] ? $json : $json + $debugDetails;
    }
}
