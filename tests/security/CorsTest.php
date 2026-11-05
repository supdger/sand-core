<?php
require __DIR__ . '/stubs.php';

use plugin\sandadmin\app\middleware\Cors;
use plugin\sandadmin\utils\CorsPolicy;
use Webman\Http\Request;
use Webman\Http\Response;

$ok = fn () => new Response(200, [], 'ok');
$acHeaders = fn (Response $r) => array_filter($r->headers, fn ($k) => stripos($k, 'Access-Control-') === 0, ARRAY_FILTER_USE_KEY);
$wl = new Cors(CorsPolicy::fromConfig(['allowed_origins' => 'https://admin.example.com, https://ops.example.com', 'allow_credentials' => true]));
$none = new Cors(CorsPolicy::fromConfig([]));

// evil Origin
$r = $wl->process(new Request('GET', ['origin' => 'https://evil.example']), $ok);
check(!isset($r->headers['Access-Control-Allow-Origin']) && $acHeaders($r) === [], 'evil Origin 无任何 Access-Control-*');
check(($r->headers['Vary'] ?? '') === 'Origin', 'evil Origin 仍带 Vary: Origin');
$r = $wl->process(new Request('OPTIONS', ['origin' => 'https://evil.example', 'access-control-request-method' => 'POST']), $ok);
check($acHeaders($r) === [], 'evil Origin 预检无 Access-Control-*');
$r = $wl->process(new Request('GET', ['origin' => 'https://admin.example.com.evil.example']), $ok);
check($acHeaders($r) === [], '后缀伪装源不命中');
$r = $wl->process(new Request('GET', ['origin' => 'null']), $ok);
check($acHeaders($r) === [], 'Origin: null 不命中');

// 白名单
$r = $wl->process(new Request('GET', ['origin' => 'https://admin.example.com']), $ok);
check(($r->headers['Access-Control-Allow-Origin'] ?? null) === 'https://admin.example.com', '白名单 ACAO 为精确源');
check(($r->headers['Vary'] ?? '') === 'Origin', '白名单带 Vary: Origin');
check(($r->headers['Access-Control-Allow-Credentials'] ?? null) === 'true' && $r->headers['Access-Control-Allow-Origin'] !== '*', 'credentials 时 ACAO 非 *');
check($r->body === 'ok', '实际请求继续进入业务');
$r = $wl->process(new Request('OPTIONS', ['origin' => 'https://ops.example.com', 'access-control-request-method' => 'PUT']), fn () => throw new RuntimeException('预检不应进业务'));
check($r->status === 204 && ($r->headers['Access-Control-Allow-Origin'] ?? '') === 'https://ops.example.com' && isset($r->headers['Access-Control-Allow-Methods']), '白名单预检 204 + 精确源 + methods');
$noCred = new Cors(CorsPolicy::fromConfig(['allowed_origins' => ['https://admin.example.com']]));
$r = $noCred->process(new Request('GET', ['origin' => 'https://admin.example.com']), $ok);
check(!isset($r->headers['Access-Control-Allow-Credentials']), '未开凭证不发 Allow-Credentials');

// 未配置
foreach (['https://evil.example', 'https://admin.example.com'] as $o) {
    $r = $none->process(new Request('GET', ['origin' => $o]), $ok);
    check($r->headers === [], "未配置：{$o} 无任何 CORS 头");
    $r = $none->process(new Request('OPTIONS', ['origin' => $o, 'access-control-request-method' => 'POST']), $ok);
    check($r->headers === [] && $r->body === 'ok', "未配置：{$o} 预检无 CORS 头且不短路");
}
$GLOBALS['__cfg'] = [];
$r = (new Cors())->process(new Request('GET', ['origin' => 'https://evil.example']), $ok);
check($r->headers === [], '默认配置（无 config）不放行');

// * 与 credentials 不能同时出现
foreach (['*', 'https://*.example.com'] as $bad) {
    try { CorsPolicy::fromConfig(['allowed_origins' => $bad, 'allow_credentials' => true]); check(false, "拒绝通配 {$bad}"); }
    catch (InvalidArgumentException) { check(true, "拒绝通配 {$bad}"); }
}

// 静态检查：反射式中间件已删除且无旁路注册
$root = dirname(__DIR__, 2) . '/server/plugin/sandadmin';
check(!is_file("$root/app/middleware/CrossDomain.php"), 'CrossDomain.php 已删除');
$hits = trim((string) shell_exec('grep -rnE "CrossDomain|header\\(.origin.,\\s*.\\*.\\)" ' . escapeshellarg($root) . ' 2>/dev/null'));
check($hits === '', '无 CrossDomain 引用/反射 Origin 写法' . ($hits ? ": $hits" : ''));
$mw = require "$root/config/middleware.php";
check(($mw[''][0] ?? null) === Cors::class, 'Cors 为插件首个中间件');
done();
