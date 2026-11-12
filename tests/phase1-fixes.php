<?php

declare(strict_types=1);

/**
 * Phase-1 host hardening assertions (development-only; never shipped).
 *
 *   php tests/phase1-fixes.php
 *
 * Covers:
 *   1. SystemException carries an explicit ?Throwable and an opt-in real HTTP
 *      status; permission denial renders HTTP 403 + plain Chinese, never 500.
 *
 * 注：原第 2 节针对 HTTP 安装控制器的 .env 保留值断言随 InstallController
 * 删除（F2：安装只走 CLI）一并移除；CLI 安装器的 .env 行为由
 * tools/assert-no-http-install.php 与 bin/sand install 覆盖。
 */

namespace Webman\Http {
    class Response
    {
        public function __construct(
            public int $status = 200,
            public array $headers = [],
            public string $body = ''
        ) {
        }
        public function getStatusCode(): int
        {
            return $this->status;
        }
        public function rawBody(): string
        {
            return $this->body;
        }
    }
    class Request
    {
        public function method(): string
        {
            return 'GET';
        }
        public function uri(): string
        {
            return '/test';
        }
        public function all(): array
        {
            return [];
        }
        public function getRealIp(): string
        {
            return '127.0.0.1';
        }
    }
}

namespace Webman\Exception {
    class ExceptionHandler
    {
        public $dontReport = [];
        protected $logger;
        public function __construct()
        {
            $this->logger = new class {
                public function error($message): void
                {
                }
            };
        }
        public function shouldntReport(\Throwable $e): bool
        {
            return in_array(get_class($e), $this->dontReport, true);
        }
    }
}

namespace support\exception {
    class BusinessException extends \RuntimeException
    {
    }
}

namespace plugin\sandadmin\utils {
    class SensitiveData
    {
        public static function maskParams(array $params): array
        {
            return $params;
        }
    }
}

namespace {
    function config(string $key, $default = null)
    {
        return $key === 'app.debug' ? false : $default;
    }
    function request()
    {
        return null;
    }
    function getCurrentInfo()
    {
        return false;
    }
    function json(array $data)
    {
        return new \Webman\Http\Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
    function base_path(): string
    {
        return sys_get_temp_dir();
    }

    require dirname(__DIR__) . '/server/plugin/sandadmin/exception/SystemException.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/exception/ApiException.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/DebugGuard.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/ErrorPayload.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/app/exception/Handler.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/basic/OpenController.php';

    use plugin\sandadmin\app\exception\Handler;
    use plugin\sandadmin\exception\SystemException;
    use Webman\Http\Request;

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };

    // 1. Explicit nullable + optional real HTTP status on SystemException.
    $e = new SystemException('权限不足，无法访问或操作', 403, null, 403);
    $assert($e->getCode() === 403 && $e->httpStatus === 403, 'SystemException carries code and declared httpStatus');
    $assert(new SystemException('x')->httpStatus === 0, 'legacy SystemException defaults keep HTTP-200 shell');

    // 2. Permission denial renders HTTP 403 + plain Chinese, never a 500 page.
    $handler = new Handler();
    $response = $handler->render(new Request(), $e);
    $assert($response->getStatusCode() === 403, 'permission denial renders HTTP 403');
    $body = (string) json_decode($response->rawBody(), true)['message'];
    $assert(str_contains($body, '权限不足'), 'response body is the plain Chinese reason');
    $assert(!str_contains($response->rawBody(), 'trace') && !str_contains($response->rawBody(), '.php'),
        'response body leaks no stack trace or PHP file path');

    // 3. Undeclared exceptions keep the historical HTTP-200 shell.
    $legacy = $handler->render(new Request(), new SystemException('普通失败', 400));
    $assert($legacy->getStatusCode() === 200, 'non-403 failures keep HTTP-200 shell');
    $assert($handler->render(new Request(), new \RuntimeException('boom', 0))->getStatusCode() === 200,
        'generic exceptions keep HTTP-200 shell');

    echo "ALL PHASE-1 ASSERTIONS PASSED\n";
}
