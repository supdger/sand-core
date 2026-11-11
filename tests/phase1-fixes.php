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
 *   2. The installer .env writer preserves operator-configured values
 *      (custom ports) and only writes defaults for missing keys.
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
    require dirname(__DIR__) . '/server/plugin/sandadmin/app/controller/InstallController.php';

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

    // 4. Installer env merge preserves operator-configured ports and unmanaged keys.
    $controller = (new ReflectionClass(\plugin\sandadmin\app\controller\InstallController::class))
        ->newInstanceWithoutConstructor();
    $invoke = static function (string $method, array $args) use ($controller): mixed {
        $m = new ReflectionMethod($controller, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($controller, $args);
    };

    $envPath = sys_get_temp_dir() . '/sandadmin-env-fixture-' . getmypid() . '.env';
    file_put_contents($envPath, "SANDADMIN_SERVER_PORT = 25879\nSANDADMIN_CHANNEL_PORT = \"25206\"\nEXTRA_FLAG = keep-me\n# comment\nDB_NAME = olddb\n");
    $existing = $invoke('readEnvAssignments', [$envPath]);
    $assert(($existing['SANDADMIN_SERVER_PORT'] ?? null) === '25879'
        && ($existing['SANDADMIN_CHANNEL_PORT'] ?? null) === '25206'
        && ($existing['EXTRA_FLAG'] ?? null) === 'keep-me',
        'readEnvAssignments parses plain, quoted and spaced entries');

    $entries = [
        '# 数据库配置', 'DB_TYPE' => 'pgsql', 'DB_NAME' => 'newdb',
        '# 服务端口', 'SANDADMIN_SERVER_PORT' => $existing['SANDADMIN_SERVER_PORT'] ?? '8787',
        'SANDADMIN_CHANNEL_PORT' => $existing['SANDADMIN_CHANNEL_PORT'] ?? '2206',
    ];
    $rendered = $invoke('renderEnvPreservingOperatorValues', [$entries, $existing]);
    $assert(str_contains($rendered, 'SANDADMIN_SERVER_PORT = "25879"')
        && str_contains($rendered, 'SANDADMIN_CHANNEL_PORT = "25206"'),
        'operator-configured ports are preserved verbatim');
    $assert(!str_contains($rendered, '"8787"') && !str_contains($rendered, '"2206"'),
        'default ports are never written back over configured values');
    $assert(str_contains($rendered, 'EXTRA_FLAG = "keep-me"'),
        'unmanaged operator keys are merged, not clobbered');
    $assert(str_contains($rendered, 'DB_NAME = "newdb"'),
        'installer-owned keys still take the fresh form values');

    $fresh = $invoke('renderEnvPreservingOperatorValues', [[
        'SANDADMIN_SERVER_PORT' => '8787', 'SANDADMIN_CHANNEL_PORT' => '2206',
    ], []]);
    $assert(str_contains($fresh, 'SANDADMIN_SERVER_PORT = "8787"')
        && str_contains($fresh, 'SANDADMIN_CHANNEL_PORT = "2206"'),
        'missing keys still receive the defaults');

    unlink($envPath);
    echo "ALL PHASE-1 ASSERTIONS PASSED\n";
}
