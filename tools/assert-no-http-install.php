<?php

declare(strict_types=1);

/**
 * F2 门禁：生产构建后 /install* 为 404，且删除 .env 不会恢复 HTTP 安装。
 *
 * 对齐 1x-ci-gates.md 第 10 节里「生产构建后安装路由不可用」的可运行检查。
 * sand-core 没有 bin/sand build 以外的独立产物管道；本脚本会执行
 * `bin/sand build --production`，再用该清单做 HTTP 探针。
 *
 * 用法：php tools/assert-no-http-install.php
 */

$root = dirname(__DIR__);
require $root . '/server/plugin/sandadmin/service/RouteManifest.php';

$plugin = $root . '/server/plugin/sandadmin';
$failures = [];

$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        fwrite(STDOUT, "通过 {$message}\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "失败 {$message}\n");
};

$phpFiles = plugin_php_files($plugin);
$check($phpFiles !== [], '插件 PHP 文件可枚举');

foreach ($phpFiles as $file) {
    $code = (string) file_get_contents($file);
    $relative = substr($file, strlen($plugin) + 1);
    $check(preg_match('/class\s+InstallController\b/', $code) !== 1, $relative . ' 无 InstallController');
    $check(
        preg_match('/noNeedLogin\s*=\s*\[[^\]]*["\']install["\']/i', $code) !== 1,
        $relative . ' 的 noNeedLogin 不含 install'
    );
    $check(
        preg_match('/Route::(?:get|post|put|delete|patch|head|options|any|add)\s*\(\s*[\'"][^\'"]*install/i', $code) !== 1,
        $relative . ' 无 /install 路由注册'
    );
    $check(preg_match('/fastRoute\s*\(\s*[\'"]install/i', $code) !== 1, $relative . ' 无 fastRoute(install)');
    foreach (exec_arguments($code) as $argument) {
        $leaks = preg_match('/\$(?:database|dbName|dbname|db_name)\b/i', $argument) === 1
            || preg_match('/\$_(GET|POST|REQUEST|COOKIE)\b/', $argument) === 1;
        $check(!$leaks, $relative . ' 的 exec 参数不含用户可控库名');
    }
}

foreach (['app', 'config'] as $directory) {
    foreach (plugin_php_files($plugin . '/' . $directory) as $file) {
        $code = (string) file_get_contents($file);
        $relative = substr($file, strlen($plugin) + 1);
        $check(preg_match('/\bensureToken\s*\(/', $code) !== 1, $relative . ' 不在 HTTP/启动路径创建安装令牌');
    }
}

$surfaceNeedles = ['/core/install', '/install/install'];
$surfaceFiles = array_merge(
    glob_tree($plugin, '/\.(html|js|vue|htm)$/'),
    glob_tree($root . '/sandadmin-artd/src', '/\.(vue|ts|js|html)$/')
);
foreach ($surfaceFiles as $file) {
    $code = (string) file_get_contents($file);
    foreach ($surfaceNeedles as $needle) {
        $check(!str_contains($code, $needle), substr($file, strlen($root) + 1) . ' 不含 ' . $needle);
    }
}

$check(!is_file($plugin . '/app/controller/InstallController.php'), 'InstallController.php 已删除');
$check(!is_file($plugin . '/app/view/install/index.html'), '安装页视图已删除');
$check(!is_file($plugin . '/public/assets/install-entry.js'), '安装页脚本已删除');

$routeFile = $plugin . '/config/route.php';
$compiled = \plugin\sandadmin\service\RouteManifest::compile($routeFile);
$check(\plugin\sandadmin\service\RouteManifest::installRoutes($compiled) === [], '源码路由编译结果不含 /install*');
$check(in_array('GET /core/captcha', $compiled, true), '对照路由 GET /core/captcha 仍在清单中');

$fixture = <<<'PHP'
<?php
$db->exec('CREATE DATABASE ' . $database);
$db->exec("CREATE DATABASE {$dbName}");
shell_exec('createdb ' . $dbname);
$pdo->exec($schemaSql);
$pdo->exec('BEGIN READ ONLY');
PHP;
$leaking = [];
$safe = [];
foreach (exec_arguments($fixture) as $argument) {
    $leaks = preg_match('/\$(?:database|dbName|dbname|db_name)\b/i', $argument) === 1;
    if ($leaks) {
        $leaking[] = $argument;
    } else {
        $safe[] = $argument;
    }
}
$check(count($leaking) === 3, '扫描器能识别把库名拼进 exec 的样本');
$check(count($safe) === 2, '扫描器放行固定 SQL 与包内 schema 变量');

$dirty = tempnam(sys_get_temp_dir(), 'sand-route-');
if ($dirty === false) {
    fwrite(STDERR, "无法创建临时路由文件\n");
    exit(1);
}
file_put_contents($dirty, (string) file_get_contents($routeFile) . "\nRoute::post('/install/install', [stdClass::class, 'install']);\n");
$dirtyCaught = false;
try {
    \plugin\sandadmin\service\RouteManifest::write($dirty, sys_get_temp_dir() . '/sand-route-rejected-' . bin2hex(random_bytes(4)));
} catch (RuntimeException $error) {
    $dirtyCaught = str_contains($error->getMessage(), '/install/install');
}
unlink($dirty);
$check($dirtyCaught, '生产构建在源码重新出现 /install 时失败');

$outWithEnv = sys_get_temp_dir() . '/sand-f2-with-env-' . bin2hex(random_bytes(4));
$outWithoutEnv = sys_get_temp_dir() . '/sand-f2-without-env-' . bin2hex(random_bytes(4));
$buildWith = run_build($root, $outWithEnv, true);
$buildWithout = run_build($root, $outWithoutEnv, false);
$check($buildWith['code'] === 0, '存在临时 .env 且 APP_DEBUG=1 时生产构建成功');
$check($buildWithout['code'] === 0, '删除 .env 且 APP_DEBUG=0 时生产构建成功');
$manifestWith = $outWithEnv . '/routes.txt';
$manifestWithout = $outWithoutEnv . '/routes.txt';
$check(is_file($manifestWith) && is_file($manifestWithout), '两份生产路由清单都已写出');
if (is_file($manifestWith) && is_file($manifestWithout)) {
    $check(hash_file('sha256', $manifestWith) === hash_file('sha256', $manifestWithout), '清单不随 .env 或 APP_DEBUG 变化');
    $body = (string) file_get_contents($manifestWithout);
    $check(preg_match('/(?:^|\s)\/install(?:\/|\s|$)/m', $body) !== 1, '生产清单正文无 /install 路径');
    $check(str_contains($body, '# env_consulted=0'), '清单标明未读取环境');
}

$httpOk = probe_install_surface($root, $manifestWithout);
$check($httpOk, '生产清单下 GET/POST /install* 为 404，删除 .env 后仍为 404，对照路由仍可识别');

$help = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sand') . ' install --help');
$check(is_string($help) && str_contains($help, '--confirm=INSTALL') && str_contains($help, 'SAND_DB_PASSWORD'), 'bin/sand install --help 说明 CLI 参数');

$refused = proc_result(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sand') . ' install --confirm=NO');
$check($refused['code'] !== 0, '缺少连接参数时 install 拒绝执行');
$check(!str_contains($refused['stderr'] . $refused['stdout'], 'SAND_DB_PASSWORD='), '拒绝信息未回显数据库密码');

$emptyBase = sys_get_temp_dir() . '/sand-f2-empty-' . bin2hex(random_bytes(4));
mkdir($emptyBase, 0700, true);
$migrate = proc_result(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sand') . ' migrate --base=' . escapeshellarg($emptyBase));
$check($migrate['code'] !== 0 && str_contains($migrate['stderr'], '没有 .env'), '没有 .env 时 migrate 拒绝执行且不打开 HTTP 安装');

if ($failures !== []) {
    fwrite(STDERR, 'HTTP 安装面门禁未通过：' . count($failures) . " 项\n");
    exit(1);
}

fwrite(STDOUT, "HTTP 安装面门禁通过\n");
exit(0);

/**
 * @return list<string>
 */
function plugin_php_files(string $directory): array
{
    return glob_tree($directory, '/\.php$/');
}

/**
 * @return list<string>
 */
function glob_tree(string $directory, string $pattern): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match($pattern, $file->getPathname()) === 1) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function exec_arguments(string $code): array
{
    $tokens = token_get_all($code);
    $arguments = [];
    $count = count($tokens);
    $names = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'];
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), $names, true)) {
            continue;
        }
        $previous = $index - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
            $previous--;
        }
        if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_FUNCTION) {
            continue;
        }
        $paren = $index + 1;
        while ($paren < $count && is_array($tokens[$paren]) && $tokens[$paren][0] === T_WHITESPACE) {
            $paren++;
        }
        if ($paren >= $count || $tokens[$paren] !== '(') {
            continue;
        }
        $depth = 0;
        $argument = '';
        for ($cursor = $paren; $cursor < $count; $cursor++) {
            $piece = is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
            $argument .= $piece;
            if ($tokens[$cursor] === '(') {
                $depth++;
            } elseif ($tokens[$cursor] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        $arguments[] = $argument;
    }

    return $arguments;
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function run_build(string $root, string $outDir, bool $withEnv): array
{
    $cwd = sys_get_temp_dir() . '/sand-f2-cwd-' . bin2hex(random_bytes(4));
    if (!mkdir($cwd, 0700, true) && !is_dir($cwd)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => '无法创建临时目录'];
    }
    $envFile = $cwd . '/.env';
    if ($withEnv) {
        file_put_contents($envFile, "APP_DEBUG=true\nDB_PASSWORD=\"not-a-real-secret\"\n");
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sand')
        . ' build --production --out=' . escapeshellarg($outDir);
    $result = proc_result($command, $cwd, [
        'APP_DEBUG' => $withEnv ? '1' : '0',
    ]);
    if (is_file($envFile)) {
        unlink($envFile);
    }

    return $result;
}

function probe_install_surface(string $root, string $manifest): bool
{
    if (!is_file($manifest)) {
        fwrite(STDERR, "缺少生产路由清单，跳过 HTTP 探针\n");
        return false;
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        fwrite(STDERR, "无法分配探针端口\n");
        return false;
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $match)) {
        return false;
    }
    $port = (int) $match[1];
    $cwd = sys_get_temp_dir() . '/sand-f2-http-' . bin2hex(random_bytes(4));
    if (!mkdir($cwd, 0700, true) && !is_dir($cwd)) {
        return false;
    }
    $envPath = $cwd . '/.env';
    file_put_contents($envPath, "DB_PASSWORD=\"probe\"\nINSTALL_HTTP=1\n");
    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/tools/production-router.php');
    $log = $cwd . '/server.log';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['SAND_ROUTE_MANIFEST'] = $manifest;
    $environment['APP_DEBUG'] = '1';
    $process = proc_open($command, $descriptors, $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        proc_terminate($process);
        proc_close($process);
        fwrite(STDERR, "探针服务未启动\n" . (string) @file_get_contents($log) . "\n");
        return false;
    }

    $paths = [
        '/install',
        '/install/',
        '/install/index',
        '/install/install',
        '/core/install',
        '/core/install/',
        '/core/install/index',
        '/core/install/install',
        '/app/sandadmin/install',
        '/app/sandadmin/install/index',
        '/app/sandadmin/install/install',
    ];
    $ok = true;
    foreach ([true, false] as $envExists) {
        if (!$envExists && is_file($envPath)) {
            unlink($envPath);
        }
        foreach (['GET', 'POST'] as $method) {
            foreach ($paths as $path) {
                $status = http_status($method, 'http://127.0.0.1:' . $port . $path);
                if ($status !== 404) {
                    fwrite(STDERR, ($envExists ? '有 .env' : '已删除 .env') . " {$method} {$path} => {$status}\n");
                    $ok = false;
                }
            }
        }
        $control = http_status('GET', 'http://127.0.0.1:' . $port . '/core/captcha');
        if ($control !== 204) {
            fwrite(STDERR, '对照路由 GET /core/captcha => ' . $control . "\n");
            $ok = false;
        }
    }
    proc_terminate($process);
    proc_close($process);

    return $ok;
}

function http_status(string $method, string $url): int
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 3,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $method === 'POST' ? 'database=not_used' : '',
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false && !isset($http_response_header)) {
        return 0;
    }
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match) === 1) {
            return (int) $match[1];
        }
    }

    return 0;
}

/**
 * @param array<string, string> $extra
 * @return array{code:int,stdout:string,stderr:string}
 */
function proc_result(string $command, ?string $cwd = null, array $extra = []): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($extra as $name => $value) {
        $environment[$name] = $value;
    }
    unset($environment['SAND_DB_PASSWORD'], $environment['SAND_ADMIN_PASSWORD']);
    $process = proc_open($command, $descriptors, $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => '无法启动进程'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'code' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}
