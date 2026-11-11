<?php

declare(strict_types=1);

namespace plugin\sandadmin\service;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use plugin\sandadmin\utils\InstallGuard;
use plugin\sandadmin\utils\JwtSecretGuard;

/**
 * 仅供 CLI 调用的 PostgreSQL 安装与升级 SQL 应用。
 *
 * 库名只在通过格式校验后写入 PDO DSN，并用 current_database() 复核。
 * 不把库名、主机或请求参数拼进 exec / shell。exec 的参数只来自包内固定 SQL 文件。
 */
final class DatabaseInstaller
{
    public const CONFIRM = 'INSTALL';

    public function __construct(private readonly string $pluginRoot)
    {
    }

    public function install(string $base, InstallOptions $options): InstallResult
    {
        $base = rtrim($base, '/\\');
        $this->assertOptions($options);
        if (InstallGuard::isInstalled($base) && !$options->force) {
            throw new RuntimeException('宿主已安装（存在 .env 或 runtime/install.lock）。CLI 重装必须同时传入 --force 与 --confirm=' . self::CONFIRM . '。不存在 HTTP 重装入口。');
        }
        if ($options->force && $options->confirm !== self::CONFIRM) {
            throw new RuntimeException('重装必须显式传入 --confirm=' . self::CONFIRM);
        }
        if (!$options->force && $options->confirm !== self::CONFIRM) {
            throw new RuntimeException('安装必须显式传入 --confirm=' . self::CONFIRM);
        }
        $this->assertJwtFile($base);

        $adminPassword = $options->adminPassword;
        $generated = $adminPassword === '';
        if ($generated) {
            $adminPassword = InstallGuard::generatePassword();
        }
        $adminError = InstallGuard::validateAdminCredentials($options->adminUsername, $generated ? '' : $adminPassword, $generated ? '' : $adminPassword);
        if ($adminError !== null) {
            throw new RuntimeException($adminError);
        }
        $mustChange = InstallGuard::requiresPasswordChange($generated, $adminPassword);

        $pdo = $this->connect($options->host, $options->port, $options->database, $options->user, $options->password, $options->allowEmptyPassword);
        try {
            $table = $pdo->query("SELECT to_regclass('public.sand_system_menu')")->fetchColumn();
        } catch (PDOException $error) {
            throw new RuntimeException($this->databaseErrorMessage($error));
        }
        if ($table !== false && $table !== null) {
            throw new RuntimeException('目标库已有 SandAdmin 表，已停止。请换一个空库；本命令不会通过 HTTP 重装。');
        }

        $schemaName = $options->dataType === 'demo' ? 'sandadmin-6.0' : 'sandadmin-pure';
        $schemaSql = $this->readSql($this->pluginRoot . '/db/' . $schemaName . '.pgsql');
        $menuSql = $this->readSql($this->pluginRoot . '/db/sand-platform-menus.pgsql');
        $ormFile = $base . '/vendor/topthink/think-orm/src/db/connector/pgsql12.sql';
        $ormSql = $this->readSql($ormFile);

        try {
            $pdo->beginTransaction();
            $pdo->exec($schemaSql);
            $pdo->exec($menuSql);
            $pdo->exec($ormSql);
            $this->provisionAdmin($pdo, $options->adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT), $mustChange);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException) {
                throw new RuntimeException($this->databaseErrorMessage($error));
            }
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('安装失败，数据库事务已回滚。');
        }

        $this->generateConfig($base, 'pgsql');
        $this->writeEnv($base, $options);
        InstallGuard::markInstalled($base);

        return new InstallResult($options->adminUsername, $generated ? $adminPassword : null, $mustChange);
    }

    public function migrate(string $base): int
    {
        $base = rtrim($base, '/\\');
        $envPath = InstallGuard::envPath($base);
        if (!is_file($envPath)) {
            throw new RuntimeException('没有 .env，无法迁移。请先执行 bin/sand install。删除 .env 不会启用 HTTP 安装。');
        }
        $env = $this->readEnvFile($envPath);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $name) {
            if (!isset($env[$name]) || $env[$name] === '') {
                throw new RuntimeException('.env 缺少 ' . $name . '，已停止迁移。');
            }
        }
        if (!array_key_exists('DB_PASSWORD', $env)) {
            throw new RuntimeException('.env 缺少 DB_PASSWORD，已停止迁移。');
        }
        $port = (int) $env['DB_PORT'];
        $pdo = $this->connect($env['DB_HOST'], $port, $env['DB_NAME'], $env['DB_USER'], $env['DB_PASSWORD'], false);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sand_schema_migration ('
            . 'filename varchar(255) PRIMARY KEY, '
            . 'applied_at timestamp(0) without time zone NOT NULL)'
        );
        $files = glob($this->pluginRoot . '/db/update-*.pgsql') ?: [];
        sort($files, SORT_STRING);
        $applied = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^update-[0-9.]+\.pgsql$/', $name) !== 1) {
                continue;
            }
            $seen = $pdo->prepare('SELECT 1 FROM sand_schema_migration WHERE filename = ?');
            $seen->execute([$name]);
            if ($seen->fetchColumn()) {
                continue;
            }
            $migrationSql = $this->readSql($file);
            try {
                $pdo->beginTransaction();
                $pdo->exec($migrationSql);
                $insert = $pdo->prepare('INSERT INTO sand_schema_migration (filename, applied_at) VALUES (?, ?)');
                $insert->execute([$name, gmdate('Y-m-d H:i:s')]);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new RuntimeException('迁移失败：' . $name . ($error instanceof PDOException ? '（' . $this->databaseErrorMessage($error) . '）' : ''));
            }
            $applied++;
        }

        return $applied;
    }

    private function assertOptions(InstallOptions $options): void
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/D', $options->host) !== 1) {
            throw new RuntimeException('数据库主机格式无效');
        }
        if ($options->port < 1 || $options->port > 65535) {
            throw new RuntimeException('数据库端口无效');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $options->database) !== 1) {
            throw new RuntimeException('数据库名只能包含字母、数字和下划线，且必须以字母或下划线开头');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $options->user) !== 1) {
            throw new RuntimeException('数据库用户名格式无效');
        }
        if (!in_array($options->dataType, ['pure', 'demo'], true)) {
            throw new RuntimeException('数据类型只能是 pure（纯净数据）或 demo（演示数据）');
        }
        if ($options->password === '' && !$options->allowEmptyPassword) {
            throw new RuntimeException('必须设置环境变量 SAND_DB_PASSWORD。空密码仅允许 --allow-empty-password=local 且主机为回环地址。');
        }
        if ($options->password === '' && !in_array($options->host, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('空密码只可配合回环地址使用');
        }
        if ($options->password !== '' && $options->allowEmptyPassword) {
            throw new RuntimeException('非空密码不可指定 --allow-empty-password=local');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $options->password) === 1) {
            throw new RuntimeException('数据库密码含无法安全写入 .env 的控制字符');
        }
    }

    private function assertJwtFile(string $base): void
    {
        $file = $base . '/' . JwtSecretGuard::CONFIG_FILE;
        if (!is_file($file)) {
            throw new RuntimeException('缺少 JWT 配置，已拒绝安装：' . JwtSecretGuard::CONFIG_FILE);
        }
        $body = file_get_contents($file);
        if (!is_string($body) || preg_match('/__JWT_[A-Z_]*__/', $body) === 1) {
            throw new RuntimeException('JWT 配置仍含占位符或不可读，已拒绝安装。请先写入随机密钥后再执行 bin/sand install。');
        }
    }

    private function connect(string $host, int $port, string $database, string $user, string $password, bool $allowEmptyPassword): PDO
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/D', $host) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $database) !== 1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $user) !== 1
            || $port < 1 || $port > 65535) {
            throw new RuntimeException('连接参数未通过校验，已拒绝连接');
        }
        if ($allowEmptyPassword) {
            foreach (['PGPASSWORD', 'PGPASSFILE', 'PGSERVICE', 'PGSERVICEFILE', 'PGHOST', 'PGHOSTADDR', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGOPTIONS'] as $name) {
                if (getenv($name) !== false) {
                    throw new RuntimeException('空密码模式不接受已有 libpq 环境变量：' . $name);
                }
            }
            if (!putenv('PGPASSFILE=/dev/null')) {
                throw new RuntimeException('当前系统无法可靠禁用 libpq 密码文件，拒绝空密码模式');
            }
        }
        $dsnHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $dsn = 'pgsql:host=' . $dsnHost . ';port=' . (string) $port . ';dbname=' . $database;
        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $current = $pdo->query('SELECT current_database(), current_user')->fetch(PDO::FETCH_NUM);
        } catch (Throwable $error) {
            throw new RuntimeException($this->databaseErrorMessage($error));
        }
        if (!is_array($current) || $current[0] !== $database || $current[1] !== $user) {
            throw new RuntimeException('连接身份或目标数据库与显式指定值不符');
        }

        return $pdo;
    }

    private function readSql(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('SQL 文件不存在：' . basename($path));
        }
        $sql = file_get_contents($path);
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('SQL 文件不可读：' . basename($path));
        }

        return $sql;
    }

    private function provisionAdmin(PDO $pdo, string $username, string $passwordHash, bool $mustChangePassword): void
    {
        $statement = $pdo->prepare(
            'UPDATE "sand_system_user" SET "username" = :username, "password" = :password, "status" = 1, "must_change_password" = :must_change WHERE "id" = 1'
        );
        $statement->execute([
            'username' => $username,
            'password' => $passwordHash,
            'must_change' => $mustChangePassword ? 1 : 0,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('初始管理员账号种子数据缺失，安装已回滚');
        }
        $disabled = $pdo->prepare(
            'UPDATE "sand_system_user" SET "password" = :password, "status" = 2 WHERE "id" <> 1 AND "password" = :placeholder'
        );
        $disabled->execute([
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'placeholder' => '!',
        ]);
    }

    private function writeEnv(string $base, InstallOptions $options): void
    {
        $envPath = InstallGuard::envPath($base);
        // 已有 .env 中的用户配置值一律保留，仅缺失项写默认：服务端口是部署者
        // 常改项，--force 重装也不能回写为默认 8787/2206；安装器不管理的键
        // 原样并入，防止重写时丢掉运维自定义配置。
        $existingEnv = $this->readEnvAssignments($envPath);
        $body = $this->renderEnvKeepExisting([
            '# 数据库配置',
            'DB_TYPE' => 'pgsql',
            'DB_HOST' => $options->host,
            'DB_PORT' => (string) $options->port,
            'DB_NAME' => $options->database,
            'DB_USER' => $options->user,
            'DB_PASSWORD' => $options->password,
            'DB_PREFIX' => '',
            'DB_CHARSET' => 'utf8',
            '',
            '# 缓存方式',
            'CACHE_MODE' => 'file',
            '',
            '# Redis配置',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => '',
            'REDIS_DB' => '0',
            '',
            '# 验证码配置',
            'CAPTCHA_MODE' => 'cache',
            '',
            '# 前端目录',
            'FRONTEND_DIR' => 'sandadmin-artd',
            '',
            '# 服务端口（默认值可按项目环境调整）',
            'SANDADMIN_SERVER_PORT' => $existingEnv['SANDADMIN_SERVER_PORT'] ?? '8787',
            'SANDADMIN_CHANNEL_PORT' => $existingEnv['SANDADMIN_CHANNEL_PORT'] ?? '2206',
        ], $existingEnv);
        $temporary = tempnam($base, '.sandadmin-env-');
        if ($temporary === false) {
            throw new RuntimeException('无法创建临时配置');
        }
        $written = file_put_contents($temporary, $body);
        $moved = $written === strlen($body) && chmod($temporary, 0600) && rename($temporary, $envPath);
        if (is_file($temporary)) {
            unlink($temporary);
        }
        if (!$moved) {
            throw new RuntimeException('数据库已初始化，但 .env 写入失败。请不要对同一库重复执行安装。若管理员密码是自动生成的且终端里还没有看到它，请在数据库中重置 id=1 的密码。');
        }
    }

    /**
     * 渲染安装器管理的 .env 条目，并原样并入既有文件中安装器不管理的键，
     * 保证已有用户配置（含自定义服务端口）不被回写覆盖。
     *
     * @param array<int|string,string> $entries 安装器条目（整数键为注释/空行）
     * @param array<string,string> $existing 既有 .env 键值
     */
    private function renderEnvKeepExisting(array $entries, array $existing): string
    {
        $managed = [];
        foreach ($entries as $key => $value) {
            if (!is_int($key)) {
                $managed[$key] = true;
            }
        }
        $content = $this->renderEnv($entries);
        $preserved = [];
        foreach ($existing as $existingKey => $existingValue) {
            if (!isset($managed[$existingKey])) {
                $preserved[$existingKey] = $existingValue;
            }
        }
        if ($preserved !== []) {
            $content .= PHP_EOL . '# 已有用户配置（安装器未管理的键，原样保留）' . PHP_EOL
                . $this->renderEnv($preserved);
        }

        return $content;
    }

    /**
     * 读取既有 .env 的键值（不存在时返回空表）。仅解析 `KEY = value` 形式的
     * 赋值行，单双引号按 phpdotenv 语义去壳；注释、export 前缀与非赋值行忽略。
     *
     * @return array<string,string>
     */
    private function readEnvAssignments(string $envPath): array
    {
        if (!is_file($envPath)) {
            return [];
        }
        $values = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $raw = trim((string) $match[2]);
            if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
                $raw = stripcslashes(substr($raw, 1, -1));
            } elseif (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
                $raw = substr($raw, 1, -1);
            } else {
                $raw = trim(explode('#', $raw, 2)[0]);
            }
            $values[$match[1]] = $raw;
        }

        return $values;
    }

    /**
     * @param array<int|string, string> $entries
     */
    private function renderEnv(array $entries): string
    {
        $lines = [];
        foreach ($entries as $key => $value) {
            if (is_int($key)) {
                $lines[] = $value;
                continue;
            }
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
            $lines[] = $key . ' = "' . $escaped . '"';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('无法读取 .env');
        }
        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $vars[$match[1]] = $this->unquoteEnv($match[2]);
        }

        return $vars;
    }

    private function unquoteEnv(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            $inner = substr($value, 1, -1);
            if ($quote === '"') {
                return str_replace(['\\"', '\\\\', '\\$'], ['"', '\\', '$'], $inner);
            }

            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        return trim($value);
    }

    private function generateConfig(string $base, string $driver): void
    {
        $directory = $base . '/config';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建配置目录');
        }
        $defaultPort = 5432;
        $charset = 'utf8';
        $collation = 'utf8';
        $dbName = $this->requiredEnvExpression('DB_NAME');
        $dbPassword = $this->requiredEnvExpression('DB_PASSWORD', true);
        $thinkOrm = <<<EOF
<?php

return [
    'default' => '$driver',
    'connections' => [
        '$driver' => [
            'type' => env('DB_TYPE', '$driver'),
            'hostname' => env('DB_HOST', '127.0.0.1'),
            'database' => {$dbName},
            'username' => env('DB_USER', 'root'),
            'password' => {$dbPassword},
            'hostport' => env('DB_PORT', $defaultPort),
            'params' => [
                \PDO::ATTR_TIMEOUT => 3,
            ],
            'charset' => env('DB_CHARSET', '$charset'),
            'prefix' => env('DB_PREFIX', ''),
            'break_reconnect' => true,
            'bootstrap' =>  '',
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
EOF;
        $cache = <<<'EOF'
<?php

return [
    'default' => env('CACHE_MODE', 'file'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => runtime_path('cache')
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default'
        ],
        'array' => [
            'driver' => 'array'
        ]
    ]
];
EOF;
        $redis = <<<'EOF'
<?php

return [
    'default' => [
        'password' => env('REDIS_PASSWORD', ''),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ]
];
EOF;
        $thinkCache = <<<'EOF'
<?php
return [
    'default' => env('CACHE_MODE', 'file'),
    'stores'  => [
        'redis' => [
            'type' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', ''),
            'select' => env('REDIS_DB', 0),
            'prefix' => 'cache:',
            'expire'     => 0,
            'tag_expire' => 86400 * 30,
            'tag_prefix' => 'tag:',
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
        'file' => [
            'type' => 'file',
            'path' => runtime_path() . '/file/',
        ],
    ],
];
EOF;
        $database = <<<EOF
<?php
return [
    'default' => '$driver',
    'connections' => [
        '$driver' => [
            'driver' => env('DB_TYPE', '$driver'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', $defaultPort),
            'database' => {$dbName},
            'username' => env('DB_USER', 'root'),
            'password' => {$dbPassword},
            'charset' => env('DB_CHARSET', '$charset'),
            'collation' => env('DB_COLLATION', '$collation'),
            'prefix' => env('DB_PREFIX', ''),
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
EOF;
        $files = [
            'think-orm.php' => $thinkOrm,
            'cache.php' => $cache,
            'redis.php' => $redis,
            'think-cache.php' => $thinkCache,
            'database.php' => $database,
        ];
        foreach ($files as $name => $contents) {
            if (file_put_contents($directory . '/' . $name, $contents) === false) {
                throw new RuntimeException('无法写入配置文件：' . $name);
            }
        }
    }

    private function requiredEnvExpression(string $name, bool $allowEmpty = false): string
    {
        $emptyCheck = $allowEmpty ? '' : " || \$value === ''";
        $message = "缺少必需的环境变量 {$name}：请在宿主根目录 .env 中设置（由 bin/sand install 写入），不存在默认值。";

        return "(static function (): string { \$value = env('{$name}'); "
            . "if (!is_string(\$value){$emptyCheck}) { throw new \\RuntimeException('{$message}'); } "
            . "return \$value; })()";
    }

    private function databaseErrorMessage(Throwable $error): string
    {
        $state = $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? $error->getCode()) : '';
        $message = $error->getMessage();
        if (preg_match('//u', $message) !== 1) {
            $message = function_exists('iconv') ? (@iconv('GB18030', 'UTF-8', $message) ?: '') : '';
        }
        $message = strtolower($message);
        if ($state === '3D000') {
            return '数据库不存在，请先创建 PostgreSQL 数据库后再安装';
        }
        if ($state === '42501' || str_contains($message, 'permission denied')) {
            return '数据库用户权限不足';
        }
        if ($state === '28P01' || str_contains($message, 'no password supplied')) {
            return '数据库身份认证失败，请检查用户名和密码';
        }
        if (str_contains($message, 'could not find driver')) {
            return 'PHP 未启用 pdo_pgsql，请安装扩展后重试';
        }
        if (str_contains($message, 'connection refused') || str_contains($message, 'timed out') || str_contains($message, 'timeout expired')) {
            return '数据库连接失败，请检查主机、端口和 PostgreSQL 是否已启动';
        }

        return '数据库操作失败，请检查数据库配置及 PostgreSQL 服务日志';
    }
}

final readonly class InstallOptions
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public string $dataType,
        public string $adminUsername,
        public string $adminPassword,
        public bool $force,
        public string $confirm,
        public bool $allowEmptyPassword,
    ) {
    }
}

final readonly class InstallResult
{
    public function __construct(
        public string $username,
        public ?string $generatedPassword,
        public bool $mustChangePassword,
    ) {
    }
}
