<?php

declare(strict_types=1);

use plugin\sandadmin\app\controller\InstallController;
use support\Request;

// Reuse installed Webman dependencies without bootstrapping an application or service.
$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Usage: php tests/install-database-errors-test.php [installed vendor/autoload.php]\n");
    exit(1);
}
$root = sys_get_temp_dir() . '/sand-core-install-errors-' . bin2hex(random_bytes(6));
function base_path(): string { return $GLOBALS['root']; }
require $autoload;
require dirname(__DIR__) . '/server/plugin/sandadmin/basic/OpenController.php';
require dirname(__DIR__) . '/server/plugin/sandadmin/app/controller/InstallController.php';

class FailedInstallController extends InstallController
{
    public function __construct(private Throwable $error, private ?PDO $database = null) {}
    protected function getPdo($driver, $host, $username, $password, $port, $database = null): PDO
    {
        if ($this->database !== null) { return $this->database; }
        throw $this->error;
    }
    protected function generateConfig(string $driver): void { throw new RuntimeException('Failure reached configuration write'); }
}

class EmptyTableStatement extends PDOStatement
{
    public function __construct() {}
    public function fetchColumn(int $column = 0): mixed { return null; }
}

class FailedDatabase extends PDO
{
    public bool $rolledBack = false;
    private bool $transaction = false;
    public function __construct(private PDOException $error, private string $stage) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->stage === 'query') { throw $this->error; }
        return new EmptyTableStatement();
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function exec(string $statement): int|false { throw $this->error; }
    public function inTransaction(): bool { return $this->transaction; }
    public function rollBack(): bool { $this->transaction = false; $this->rolledBack = true; return true; }
}

function pdoError(string $message, string $state = '08006'): PDOException
{
    $error = new PDOException($message);
    $error->errorInfo = [$state, 7, $message];
    return $error;
}
function checkFailure(InstallController $controller, string $expected): void
{
    $body = http_build_query([
        'databaseType' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
        'database' => 'sand', 'username' => 'postgres', 'password' => 'private-test-secret',
    ]);
    $request = new Request("POST /core/install/install HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    $response = $controller->install($request);
    $data = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== 200 || $response->getHeader('Content-Type') !== 'application/json') {
        throw new RuntimeException('Failure broke the installation HTTP JSON contract');
    }
    if ($data['code'] !== 400 || !str_contains($data['message'], $expected)) {
        throw new RuntimeException('Unexpected installation failure: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    if (str_contains($response->rawBody(), 'private-test-secret') || is_file(base_path() . '/.env')) {
        throw new RuntimeException('Failure exposed a credential or wrote installation configuration');
    }
}

try {
    mkdir($root . '/runtime', 0777, true);
    mkdir($root . '/plugin/sandadmin/db', 0777, true);
    file_put_contents($root . '/plugin/sandadmin/db/sandadmin-6.0.pgsql', '-- simulated import');
    $cases = [
        'missing database SQLSTATE' => [pdoError('localized message', '3D000'), '数据库不存在'],
        'missing database Chinese' => [pdoError('致命错误: 数据库 "sand" 不存在'), '数据库不存在'],
        'missing role Chinese' => [pdoError('致命错误: 角色 "postgres" 不存在'), '数据库用户不存在'],
        'authentication Chinese' => [pdoError('致命错误: 用户 "postgres" Password 认证失败'), '检查用户名和密码'],
        'authentication Chinese password' => [pdoError('密码认证失败'), '检查用户名和密码'],
        'missing database libpq' => [pdoError('FATAL: database "sand" does not exist'), '数据库不存在'],
        'missing role' => [pdoError('FATAL: role "postgres" does not exist'), '数据库用户不存在'],
        'bad credentials SQLSTATE' => [pdoError('localized message', '28P01'), '检查用户名和密码'],
        'bad credentials at offset zero' => [pdoError('password authentication failed for user "postgres"'), '检查用户名和密码'],
        'missing password' => [pdoError('fe_sendauth: no password supplied'), '检查用户名和密码'],
        'host authentication rule' => [pdoError('FATAL: no pg_hba.conf entry for host', '28000'), 'pg_hba.conf'],
        'other authentication rule' => [pdoError('localized message', '28000'), '认证配置'],
        'privilege SQLSTATE' => [pdoError('localized message', '42501'), '数据库用户权限不足'],
        'privilege libpq' => [pdoError('FATAL: permission denied for database sand'), '数据库用户权限不足'],
        'missing driver' => [pdoError('could not find driver'), 'pdo_pgsql'],
        'DNS libpq' => [pdoError('could not translate host name "db.invalid" to address'), '主机名无法解析'],
        'DNS Windows' => [pdoError('No such host is known'), '主机名无法解析'],
        'connection refused at offset zero' => [pdoError('Connection refused'), '连接被拒绝'],
        'connection refused Windows' => [pdoError('target machine actively refused it'), '连接被拒绝'],
        'connection timeout' => [pdoError('connection timed out'), '连接超时'],
        'timeout expired' => [pdoError('timeout expired'), '连接超时'],
        'SQLSTATE takes priority' => [pdoError('password authentication failed', '3D000'), '数据库不存在'],
        'unknown safe fallback' => [pdoError('dsn secret private-test-secret'), '数据库操作失败'],
    ];
    foreach ([
        ['数据库 "sand" 不存在', '数据库不存在'],
        ['角色 "postgres" 不存在', '数据库用户不存在'],
        ['用户 "postgres" Password 认证失败', '检查用户名和密码'],
    ] as $index => [$message, $expected]) {
        $gbk = iconv('UTF-8', 'CP936', $message);
        if ($gbk === false) { throw new RuntimeException('Unable to create Windows GBK regression fixture'); }
        $cases['Windows GBK ' . $index] = [pdoError($gbk), $expected];
    }
    $cases['invalid encoding safe fallback'] = [pdoError("private-test-secret\xff"), '数据库操作失败'];
    foreach ($cases as $name => [$error, $expected]) {
        checkFailure(new FailedInstallController($error), $expected);
        fwrite(STDOUT, "PASS {$name}\n");
    }
    foreach (['query', 'import'] as $stage) {
        $error = pdoError('permission denied for schema public', '42501');
        $database = new FailedDatabase($error, $stage);
        checkFailure(new FailedInstallController($error, $database), '数据库用户权限不足');
        if ($stage === 'import' && !$database->rolledBack) {
            throw new RuntimeException('Failed import was not rolled back');
        }
        fwrite(STDOUT, "PASS {$stage} permission failure" . ($database->rolledBack ? ' with rollback' : '') . "\n");
    }
    fwrite(STDOUT, 'Installation database errors: ' . (count($cases) + 2) . " checks passed; real Webman HTTP JSON responses; no database connection or configuration write\n");
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
