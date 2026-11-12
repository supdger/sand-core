<?php
// 同名桩类必须先声明（早于 stubs.php 注册的 autoload 被触发），
// 覆盖插件真实实现，以便在无数据库、无 vendor 的环境走通控制器与逻辑层。

namespace Tinywan\Jwt {
    class JwtToken {
        public static array $issued = [];
        public static function generateToken(array $payload): array {
            self::$issued[] = $payload;
            return [
                'token_type' => 'Bearer',
                'expires_in' => $payload['access_exp'] ?? 0,
                'access_token' => 'dev-access-token',
                'refresh_token' => 'dev-refresh-token',
            ];
        }
    }
}

namespace Webman\Event {
    class Event {
        public static array $emitted = [];
        public static function emit(string $name, mixed $data = null): void {
            self::$emitted[] = [$name, $data];
        }
    }
}

namespace plugin\sandadmin\app\model\system {
    // 假用户表模型：where()->findOrEmpty() 返回 $found 预设的用户，否则回空模型。
    class SystemUser {
        public static ?SystemUser $found = null;
        public int $id = 0;
        public string $username = '';
        public int $status = 1;
        public string $login_time = '';
        public string $login_ip = '';
        public bool $saved = false;
        public function __construct(private bool $empty = false) {}
        public function where(string $field, mixed $value): static { return $this; }
        public function findOrEmpty(): static { return self::$found ?? new static(true); }
        public function isEmpty(): bool { return $this->empty; }
        public function save(): bool { $this->saved = true; return true; }
        public function toArray(): array { return ['id' => $this->id, 'username' => $this->username, 'must_change_password' => 0]; }
    }
}

namespace {
    require __DIR__ . '/stubs.php';

    use plugin\sandadmin\app\controller\DevLoginController;
    use plugin\sandadmin\app\model\system\SystemUser;
    use support\Request;
    use Tinywan\Jwt\JwtToken;
    use Webman\Event\Event;

    // JwtSecretGuard 要求双密钥非空、非占位符且不少于 32 字节。
    $GLOBALS['__cfg']['plugin.tinywan.jwt.app.jwt'] = [
        'algorithms' => 'HS256',
        'access_secret_key' => str_repeat('a', 40),
        'refresh_secret_key' => str_repeat('b', 40),
    ];
    $GLOBALS['__cfg']['plugin.sandadmin.sandadmin.dev_login.username'] = 'dev-admin';
    putenv('APP_ENV=dev');

    $call = function (string $method, string $ip, array $headers = []): \support\Response {
        $request = new Request($method, $headers, $ip);
        $GLOBALS['__request'] = $request;
        $controller = new DevLoginController();
        return $method === 'GET' ? $controller->available($request) : $controller->login($request);
    };
    $body = fn (\support\Response $r): array => json_decode($r->body, true) ?? [];
    $loginEvent = function (): ?array {
        $hits = array_values(array_filter(Event::$emitted, fn ($e) => $e[0] === 'user.login'));
        return $hits[0][1] ?? null;
    };

    // 1. 开关关闭 → 拒绝
    $r = $call('POST', '127.0.0.1');
    check($r->status === 404, '开关关闭：POST /dev-login 回真实 404');
    $r = $call('GET', '127.0.0.1');
    check($r->status === 200 && ($body($r)['data'] ?? null) === ['available' => false], '开关关闭：available 只回 available=false，不带原因');

    // 2. 开关打开但非回环对端 → 拒绝；伪造 XFF 回环不能绕过
    $GLOBALS['__cfg']['plugin.sandadmin.sandadmin.dev_login.enabled'] = 'true';
    $r = $call('POST', '203.0.113.10');
    check($r->status === 404, '非本机对端：POST /dev-login 回 404');
    $r = $call('POST', '203.0.113.10', ['x-forwarded-for' => '127.0.0.1']);
    check($r->status === 404, '伪造 XFF=127.0.0.1 仍 404（不信转发头）');
    $r = $call('GET', '10.0.0.5');
    check(($body($r)['data'] ?? null) === ['available' => false], '非本机对端：available=false');

    // 3. 生产环境强制拒绝
    putenv('APP_ENV=production');
    $r = $call('POST', '127.0.0.1');
    check($r->status === 404, 'APP_ENV=production：开关打开也 404');
    $r = $call('GET', '127.0.0.1');
    check(($body($r)['data'] ?? null) === ['available' => false], '生产环境：available=false 不透出原因');
    putenv('APP_ENV=dev');

    // 4. 回环对端 + 非生产 + 开关打开 → 签发令牌
    $user = new SystemUser();
    $user->id = 2;
    $user->username = 'dev-admin';
    SystemUser::$found = $user;
    $r = $call('POST', '127.0.0.1');
    $res = $body($r);
    check($r->status === 200 && ($res['code'] ?? 0) === 200, '本机+开发：POST /dev-login 成功 200');
    $data = $res['data'] ?? [];
    $need = ['token_type', 'expires_in', 'access_token', 'refresh_token', 'must_change_password'];
    check(array_diff($need, array_keys($data)) === [], '令牌载荷与 /core/login 同构');
    check(JwtToken::$issued !== [] && JwtToken::$issued[0]['username'] === 'dev-admin' && JwtToken::$issued[0]['plat'] === 'sandadmin', '令牌按配置账号签发');
    check($user->saved && $user->login_ip === '127.0.0.1' && $user->login_time !== '', '登录足迹 login_time/login_ip 已更新');
    $event = $loginEvent();
    check($event && $event['message'] === '开发本机登录' && $event['status'] === 1 && $event['username'] === 'dev-admin' && ($event['admin_id'] ?? 0) === 2, '审计记一条「开发本机登录」');

    // 三种回环写法都可用，且 available 透出账号名
    foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1'] as $ip) {
        $r = $call('GET', $ip);
        check(($body($r)['data'] ?? null) === ['available' => true, 'username' => 'dev-admin'], "回环 {$ip}：available=true 且带开发账号");
    }

    // 5. 配置账号在 sand_system_user 不存在 → 404，同样记审计
    SystemUser::$found = null;
    Event::$emitted = [];
    $r = $call('POST', '127.0.0.1');
    check($r->status === 404, '配置账号不存在：POST /dev-login 回 404');
    $event = $loginEvent();
    check($event && $event['status'] === 0 && $event['message'] === '开发本机登录', '账号不存在记失败审计');

    // 禁用账号 → 404
    $banned = new SystemUser();
    $banned->id = 3;
    $banned->username = 'dev-admin';
    $banned->status = 2;
    SystemUser::$found = $banned;
    $r = $call('POST', '127.0.0.1');
    check($r->status === 404, '账号被禁用：POST /dev-login 回 404');

    // 开发账号未配置视同不可用
    $GLOBALS['__cfg']['plugin.sandadmin.sandadmin.dev_login.username'] = '';
    $r = $call('POST', '127.0.0.1');
    check($r->status === 404, '开发账号为空：视同不可用回 404');

    putenv('APP_ENV');
    $GLOBALS['__cfg'] = [];
    done();
}
