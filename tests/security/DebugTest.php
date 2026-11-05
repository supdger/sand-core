<?php
require __DIR__ . '/stubs.php';

use plugin\sandadmin\app\bootstrap\SecurityBootstrap;
use plugin\sandadmin\utils\DebugGuard;
use plugin\sandadmin\utils\ErrorPayload;
use support\exception\BusinessException;

// 启动期
$threw = fn (callable $f) => (function () use ($f) { try { $f(); return false; } catch (RuntimeException) { return true; } })();
check($threw(fn () => DebugGuard::assertSafe('production', true)), 'production + debug=true 拒绝');
check($threw(fn () => DebugGuard::assertSafe('prod', 'true')), 'prod + "true" 拒绝');
check(!$threw(fn () => DebugGuard::assertSafe('production', false)), 'production + debug=false 通过');
check(!$threw(fn () => DebugGuard::assertSafe('dev', true)), 'dev + debug=true 通过（开发区分）');
check(!$threw(fn () => DebugGuard::assertSafe(null, true)), '未设 APP_ENV + debug=true 通过（非生产）');

putenv('APP_ENV=production');
$GLOBALS['__cfg']['app.debug'] = true;
$halted = false;
$stderr = fopen('php://memory', 'w');
try { SecurityBootstrap::start(new stdClass(), function () use (&$halted) { $halted = true; }); $ok = false; }
catch (RuntimeException $e) { $ok = str_contains($e->getMessage(), 'app.debug'); }
check($ok && $halted, 'SecurityBootstrap：生产 + debug 抛错并停止主进程');
check(!DebugGuard::exposeDetails(), '生产即使 debug=true 也不暴露细节');

// 响应体
$GLOBALS['__cfg']['app.debug'] = false;
$pdo = new RuntimeException("SQLSTATE[42P01]: relation \"sa_x\" does not exist SELECT * FROM sa_x at /srv/app/x.php", 7);
$body = json_encode(ErrorPayload::build($pdo, 'abc123'), JSON_UNESCAPED_UNICODE);
$keys = array_keys(ErrorPayload::build($pdo, 'abc123'));
check($keys === ['code', 'message', 'fields', 'request_id', 'type'], '生产字段集仅 code/message/fields/request_id/type');
check(!preg_match('/SQLSTATE|SELECT|\/srv\/|\.php|#0|trace|file|line/i', $body), '生产响应不含 SQL/路径/栈: ' . $body);
$biz = ErrorPayload::build(new BusinessException('请选择要生成的表'), 'r');
check($biz['message'] === '请选择要生成的表', '业务异常文案保留');
$biz500 = ErrorPayload::build(new BusinessException('boom', 500), 'r');
check($biz500['message'] === ErrorPayload::GENERIC_MESSAGE, 'code=500 通用文案');

putenv('APP_ENV=dev');
$GLOBALS['__cfg']['app.debug'] = true;
check(DebugGuard::exposeDetails(), '开发 + debug 允许细节');
putenv('APP_ENV');
$GLOBALS['__cfg'] = [];
check(!DebugGuard::exposeDetails(), '未配置 app.debug 默认不暴露');
done();
