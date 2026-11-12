<?php
// 最小桩：仓库不带 vendor，测试只验证本包逻辑，不加载/修改 webman。
namespace Webman\Http {
    class Request {
        public function __construct(private string $method, private array $headers = [], private string $ip = '') {}
        public function method() { return $this->method; }
        public function header($name = null, $default = null) { return $this->headers[strtolower($name)] ?? $default; }
        public function post($name = null, $default = null) { return $default; }
        public function getRemoteIp() { return $this->ip; }
        public function getRealIp() { return $this->ip; }
    }
    class Response {
        public array $headers = [];
        public function __construct(public int $status = 200, array $h = [], public string $body = '') { $this->headers = $h; }
        public function withHeaders(array $h) { foreach ($h as $k => $v) { $this->headers[$k] = $v; } return $this; }
    }
}
namespace Webman { interface MiddlewareInterface { public function process(\Webman\Http\Request $r, callable $h): \Webman\Http\Response; } }
namespace support {
    class Request extends \Webman\Http\Request {}
    class Response extends \Webman\Http\Response {}
}
namespace support\exception { class BusinessException extends \RuntimeException {} }
namespace {
    $GLOBALS['__cfg'] = [];
    function config($k, $d = null) { return array_key_exists($k, $GLOBALS['__cfg']) ? $GLOBALS['__cfg'][$k] : $d; }
    function request() { return $GLOBALS['__request'] ?? null; }
    function response(string $body = '', int $status = 200, array $headers = []) { return new \support\Response($status, $headers, $body); }
    function json($data = [], int $options = JSON_UNESCAPED_UNICODE) { return new \support\Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options)); }
    $base = dirname(__DIR__, 2) . '/server/plugin/sandadmin/';
    spl_autoload_register(function ($c) use ($base) {
        if (str_starts_with($c, 'plugin\\sandadmin\\')) {
            $f = $base . str_replace('\\', '/', substr($c, 17)) . '.php';
            if (is_file($f)) require $f;
        }
    });
    $GLOBALS['__fail'] = 0;
    function check(bool $ok, string $name) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $GLOBALS['__fail']++; }
    function done() { echo $GLOBALS['__fail'] ? "{$GLOBALS['__fail']} failed\n" : "all passed\n"; exit($GLOBALS['__fail'] ? 1 : 0); }
}
