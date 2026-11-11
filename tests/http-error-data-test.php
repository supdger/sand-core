<?php

declare(strict_types=1);

/**
 * HTTP 业务错误 data 透传断言（开发用；不随包发布）。
 *
 *   php tests/http-error-data-test.php
 *
 * sandadmin-artd 前端无 vitest/jest 基建，HTTP 层无法在本仓直接执行，
 * 因此沿用本仓 tests/ 既有约定，对前端源文件做静态契约断言：
 *
 *   1. 业务错误路径（HTTP 200 但 body code!==200）把响应信封的业务
 *      负载 response.data.data 透传进 HttpError（error.data），供
 *      sand-ai egressBlockedDetails 等结构化消费方识别出站拦截等场景。
 *   2. createHttpError 扩展为可选第三参 data 并透传给 HttpError options；
 *      401 防抖路径与请求拦截器保持原有调用（最小改动）。
 *   3. HttpError 构造函数支持 options.data；handleError 的 HTTP 状态码
 *      路径仍透传 error.response.data（回归不破坏）。
 *   4. isRecoverableInitializationError 仍要求 method==='GET' 且带
 *      transport 标记：业务错误 HttpError 无 method/transport，新增
 *      data 不会被误判为可重试初始化错误。
 */

namespace {
    $root = dirname(__DIR__);
    $httpIndex = $root . '/sandadmin-artd/src/utils/http/index.ts';
    $httpError = $root . '/sandadmin-artd/src/utils/http/error.ts';
    foreach ([$httpIndex, $httpError] as $file) {
        if (!is_file($file)) {
            fwrite(STDERR, "[FAIL] 前端源文件不存在：{$file}\n");
            exit(1);
        }
    }
    $index = (string) file_get_contents($httpIndex);
    $error = (string) file_get_contents($httpError);

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };
    $has = static fn (string $haystack, string $needle): bool => str_contains($haystack, $needle);

    // 1. 业务错误路径透传信封内层 data（BaseResponse.data），而非整个信封。
    $assert($has($index, 'const { code, message, data } = response.data'),
        '响应拦截器解构出响应信封的 data 字段');
    $assert($has($index, "throw createHttpError(message || \$t('httpMsg.requestFailed'), code, data)"),
        '业务错误路径把业务负载作为第三参传入 createHttpError');
    $assert(!$has($index, "createHttpError(message || \$t('httpMsg.requestFailed'), code, response.data)"),
        '业务错误路径不透传整个响应信封');

    // 2. createHttpError 签名扩展并转发 HttpError options.data。
    $assert($has($index, 'function createHttpError(message: string, code: number, data?: unknown)'),
        'createHttpError 签名含可选第三参 data?: unknown');
    $assert($has($index, 'return new HttpError(message, code, { data })'),
        'createHttpError 把 data 放入 HttpError options');
    $assert($has($index, "const error = createHttpError(message || \$t('httpMsg.unauthorized'), ApiStatus.unauthorized)"),
        '401 防抖路径保持两参调用（最小改动，不透传 data）');

    // 3. HttpError 支持 options.data；handleError HTTP 状态码路径回归不破坏。
    $assert($has($error, 'data?: unknown') && $has($error, 'this.data = options?.data'),
        'HttpError 构造函数支持 options.data');
    $assert($has($error, 'data: error.response.data'),
        'handleError HTTP 状态码路径仍透传 error.response.data');

    // 4. isRecoverableInitializationError 判定不受新增 data 影响：
    //    业务错误 HttpError 无 method/transport，第一道守卫即排除。
    $assert($has($error, "if (!isHttpError(error) || error.method !== 'GET') return false"),
        '可恢复初始化错误仍要求 method===GET（业务错误无 method，天然排除）');
    $assert($has($error, "error.transport === 'http'")
        && $has($error, "(error.data === '' || error.data === null || error.data === undefined)"),
        'http transport 的空 body 判定保持原口径');

    echo "ALL HTTP-ERROR-DATA ASSERTIONS PASSED\n";
}
