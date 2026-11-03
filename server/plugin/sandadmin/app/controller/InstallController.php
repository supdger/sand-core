<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\controller;

use Throwable;
use support\Request;
use support\Response;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\basic\OpenController;
use plugin\sandadmin\utils\InstallGuard;
use plugin\sandadmin\utils\JwtSecretGuard;

/**
 * 安装控制器
 */
class InstallController extends OpenController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'install'];

    /**
     * 应用名称
     * @var string
     */
    protected string $app = 'sandadmin';

    protected string $version = '6.0.0';

    /**
     * 安装首页
     */
    public function index()
    {
        // 已安装（.env 或安装锁存在）一律 404，不进入任何安装逻辑。
        if (InstallGuard::isInstalled()) {
            return $this->notFound();
        }
        // 首次访问即确保令牌存在；仅首次创建时在服务器控制台输出，不进入页面。
        InstallGuard::ensureToken();

        $data['app'] = $this->app;
        $data['version'] = config('plugin.sandadmin.app.version', $this->version);

        if (!is_writable(base_path() . DIRECTORY_SEPARATOR . 'config')) {
            $data['error'] = '权限认证失败';
            return view('install/error', $data);
        }

        return view('install/index', array_merge($data, $this->frontendEntrySettings()));
    }

    protected function notFound(): Response
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found');
    }

    /**
     * Read the same development env precedence as Vite. Public deployments may
     * select their own browser-facing URL; no server-side connection is made.
     */
    protected function frontendEntrySettings(): array
    {
        try {
            $frontendRoot = \SandAdmin\Core\Install::frontendTarget();
        } catch (Throwable) {
            // 目标路径越界或不可解析时只影响端口探测，不阻塞安装页渲染。
            $frontendRoot = '';
        }
        $configuredUrl = (string) config('plugin.sandadmin.app.frontend_url', '');
        $data = [
            'frontendPort' => 3006,
            'frontendBase' => '/',
            'frontendUrl' => '',
            'frontendConfigured' => $configuredUrl !== '',
            'frontendError' => '',
        ];
        if ($configuredUrl !== '') {
            if ($this->validFrontendUrl($configuredUrl)) {
                $data['frontendUrl'] = $configuredUrl;
            } else {
                // Never render the rejected value: it may contain a password.
                $data['frontendError'] = 'SANDADMIN_FRONTEND_URL 配置无效。请填写不含用户名、密码或控制字符的 HTTP(S) 地址或同源路径。';
            }
        }
        foreach ($frontendRoot === '' ? [] : ['.env', '.env.local', '.env.development', '.env.development.local'] as $name) {
            $frontendEnv = $frontendRoot . DIRECTORY_SEPARATOR . $name;
            if (is_file($frontendEnv)) {
                foreach (file($frontendEnv, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match("/^\s*VITE_PORT\s*=\s*(?:\"(\d+)\"|'(\d+)'|(\d+))\s*(?:#.*)?$/", $line, $match)) {
                        $port = (int) (($match[1] ?? '') ?: (($match[2] ?? '') ?: ($match[3] ?? '')));
                        if ($port >= 1 && $port <= 65535) {
                            $data['frontendPort'] = $port;
                        }
                    }
                    if (preg_match('/^\s*VITE_BASE_URL\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s#]+))\s*(?:#.*)?$/', $line, $match)) {
                        $base = ($match[1] ?? '') ?: (($match[2] ?? '') ?: ($match[3] ?? ''));
                        if (str_starts_with($base, '/') && !str_starts_with($base, '//')) {
                            $data['frontendBase'] = $base;
                        }
                    }
                }
            }
        }

        return $data;
    }

    protected function validFrontendUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return !isset($parts['scheme']) && !isset($parts['host']);
        }
        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && (!isset($parts['port']) || $parts['port'] >= 1);
    }

    /**
     * 执行安装
     *
     * 校验顺序固定：已安装锁定 → 安装令牌 → 参数 → 数据库。令牌通过前不访问数据库，
     * 避免未授权方借安装接口探测内网数据库。
     */
    public function install(Request $request)
    {
        if (InstallGuard::isInstalled()) {
            return $this->notFound();
        }
        if (!InstallGuard::verifyToken((string) $request->post('installToken', ''))) {
            // 令牌文件被误删时重建并重新公告，操作者可据此重试。
            InstallGuard::ensureToken();
            return $this->fail('安装令牌缺失或无效。请在服务器控制台/日志中查找启动时输出的一次性安装令牌（也可读取服务器 runtime/install-token 文件）。');
        }
        try {
            JwtSecretGuard::assertRuntimeConfig();
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $user = $request->post('username');
        $password = $request->post('password');
        $database = trim((string) $request->post('database'));
        $host = trim((string) $request->post('host'));
        $driver = 'pgsql';
        if ((string) $request->post('databaseType', 'pgsql') !== 'pgsql') {
            return $this->fail('SandAdmin 仅支持 PostgreSQL');
        }
        if ($host === '') {
            return $this->fail('数据库主机不能为空');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $database)) {
            return $this->fail('数据库名只能包含字母、数字和下划线，且必须以字母或下划线开头');
        }
        $port = (int) $request->post('port') ?: 5432;
        $dataType = (string) $request->post('dataType', 'pure');
        if (!in_array($dataType, ['pure', 'demo'], true)) {
            return $this->fail('数据类型只能是 pure（纯净数据）或 demo（演示数据）');
        }

        $adminUsername = trim((string) $request->post('adminUsername', ''));
        if ($adminUsername === '') {
            $adminUsername = InstallGuard::DEFAULT_ADMIN_USERNAME;
        }
        $adminPassword = (string) $request->post('adminPassword', '');
        $adminError = InstallGuard::validateAdminCredentials(
            $adminUsername,
            $adminPassword,
            (string) $request->post('adminPasswordConfirm', '')
        );
        if ($adminError !== null) {
            return $this->fail($adminError);
        }
        $passwordGenerated = $adminPassword === '';
        if ($passwordGenerated) {
            $adminPassword = InstallGuard::generatePassword();
        }
        $mustChangePassword = InstallGuard::requiresPasswordChange($passwordGenerated, $adminPassword);

        try {
            $db = $this->getPdo($driver, $host, $user, $password, $port, $database);
        } catch (\Throwable $e) {
            return $this->fail($this->databaseErrorMessage($e));
        }

        try {
            $table = $db->query("SELECT to_regclass('public.sand_system_menu')")->fetchColumn();
        } catch (\PDOException $e) {
            return $this->fail($this->databaseErrorMessage($e));
        }
        $installed = $table !== false && $table !== null;
        if ($installed) {
            return $this->fail('数据库已经安装，请勿重复安装');
        }

        $sqlName = $dataType === 'demo' ? 'sandadmin-6.0' : 'sandadmin-pure';
        $sql_file = base_path() . '/plugin/sandadmin/db/' . $sqlName . '.pgsql';

        if (!is_file($sql_file)) {
            return $this->fail('数据库SQL文件不存在');
        }

        $sql_query = file_get_contents($sql_file);

        try {
            $db->beginTransaction();
            $db->exec($sql_query);
            $platformMenuSql = base_path() . '/plugin/sandadmin/db/sand-platform-menus.pgsql';
            if (!is_file($platformMenuSql)) {
                throw new \RuntimeException('SandAdmin platform menu SQL file does not exist');
            }
            $db->exec((string) file_get_contents($platformMenuSql));
            $this->installPgsqlOrmMetadata($db);
            $this->provisionAdmin($db, $adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT), $mustChangePassword);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof \PDOException) {
                return $this->fail($this->databaseErrorMessage($e));
            }
            throw $e;
        }

        $this->generateConfig($driver);

        $env_config = $this->renderEnv([
            '# 数据库配置',
            'DB_TYPE' => $driver,
            'DB_HOST' => (string) $host,
            'DB_PORT' => (string) $port,
            'DB_NAME' => $database,
            'DB_USER' => (string) $user,
            'DB_PASSWORD' => (string) $password,
            'DB_PREFIX' => '',
            'DB_CHARSET' => $this->getCharset($driver),
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
            'SANDADMIN_SERVER_PORT' => '8787',
            'SANDADMIN_CHANNEL_PORT' => '2206',
        ]);
        file_put_contents(base_path() . DIRECTORY_SEPARATOR . '.env', $env_config);

        $this->reloadWorkers();

        // 安装锁与令牌清理放在 .env 写入之后：任一失败都不会留下可再次安装的状态。
        InstallGuard::markInstalled();

        return $this->success([
            'initial_admin' => [
                'username' => $adminUsername,
                // 仅随机生成时回显一次；操作者自行填写的密码不回显。
                'password' => $passwordGenerated ? $adminPassword : null,
                'password_generated' => $passwordGenerated,
                'must_change_password' => $mustChangePassword,
                'notice' => $passwordGenerated
                    ? '已生成随机密码且首次登录必须修改密码。该密码只显示这一次，请立即保存。'
                    : ($mustChangePassword
                        ? '所填写密码命中已知旧默认口令，首次登录后必须先修改密码。'
                        : '请使用安装时填写的管理员密码登录。'),
            ],
        ], '安装成功');
    }

    /**
     * 尝试让主进程重载 worker，使新写入的 .env 与配置生效。
     */
    protected function reloadWorkers(): void
    {
        if (function_exists('posix_kill')) {
            set_error_handler(function () {});
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }
    }

    /**
     * 写入初始管理员（种子中 id=1 的占位账号）：用户名、口令哈希、启用；$mustChangePassword
     * 为真时要求首次登录改密（随机生成或命中旧默认口令时由调用方置真）。
     * 其余种子账号的占位口令统一替换为随机哈希，且保持禁用。
     */
    protected function provisionAdmin(\PDO $db, string $username, string $passwordHash, bool $mustChangePassword): void
    {
        $statement = $db->prepare(
            'UPDATE "sand_system_user" SET "username" = :username, "password" = :password, "status" = 1, "must_change_password" = :must_change WHERE "id" = 1'
        );
        $statement->execute(['username' => $username, 'password' => $passwordHash, 'must_change' => $mustChangePassword ? 1 : 0]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('初始管理员账号种子数据缺失，安装已回滚');
        }
        $disabled = $db->prepare(
            'UPDATE "sand_system_user" SET "password" = :password, "status" = 2 WHERE "id" <> 1 AND "password" = :placeholder'
        );
        $disabled->execute([
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'placeholder' => '!',
        ]);
    }

    /**
     * 渲染 .env 内容：整数键为注释或空行，字符串键为配置项。
     * 所有值双引号包裹并转义反斜杠、双引号与 $，使含空格、#、引号的值不破坏 dotenv 解析。
     */
    protected function renderEnv(array $entries): string
    {
        $lines = [];
        foreach ($entries as $key => $value) {
            if (is_int($key)) {
                $lines[] = (string) $value;
                continue;
            }
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value);
            $lines[] = $key . ' = "' . $escaped . '"';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * Think ORM's PostgreSQL driver reads table columns through the helper
     * functions shipped with the driver.  Install them together with the
     * application schema so model metadata works immediately after setup.
     */
    protected function installPgsqlOrmMetadata(\PDO $db): void
    {
        $sqlFile = base_path() . '/vendor/topthink/think-orm/src/db/connector/pgsql12.sql';
        if (!is_file($sqlFile)) {
            throw new \RuntimeException('PostgreSQL ORM metadata SQL file does not exist');
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException('Unable to read PostgreSQL ORM metadata SQL file');
        }

        $db->exec($sql);
    }

    /**
     * 生成写入配置文件的"必填环境变量"PHP 表达式：变量缺失（或不允许为空时为空）即抛出明确错误。
     * 自包含闭包，避免多个配置文件重复声明全局函数。
     */
    protected function requiredEnvExpression(string $name, bool $allowEmpty = false): string
    {
        $emptyCheck = $allowEmpty ? '' : " || \$value === ''";
        $message = "缺少必需的环境变量 {$name}：请在宿主根目录 .env 中设置（由安装向导写入），不存在默认值。";

        return "(static function (): string { \$value = env('{$name}'); "
            . "if (!is_string(\$value){$emptyCheck}) { throw new \\RuntimeException('{$message}'); } "
            . "return \$value; })()";
    }

    /**
     * 生成配置文件 
     */
    protected function generateConfig(string $driver): void
    {
        $defaultPort = $driver === 'pgsql' ? 5432 : 3306;
        $charset = $this->getCharset($driver);
        $collation = $driver === 'pgsql' ? 'utf8' : 'utf8mb4_general_ci';
        // 库名与密码没有默认值：缺失时加载配置即报明确错误，不静默回退到固定库名或弱口令。
        $dbName = $this->requiredEnvExpression('DB_NAME');
        $dbPassword = $this->requiredEnvExpression('DB_PASSWORD', true);

        // 1、think-orm配置文件
        $think_orm_config = <<<EOF
<?php

return [
    'default' => '$driver',
    'connections' => [
        '$driver' => [
            // 数据库类型
            'type' => env('DB_TYPE', '$driver'),
            // 服务器地址
            'hostname' => env('DB_HOST', '127.0.0.1'),
            // 数据库名
            'database' => {$dbName},
            // 数据库用户名
            'username' => env('DB_USER', 'root'),
            // 数据库密码
            'password' => {$dbPassword},
            // 数据库连接端口
            'hostport' => env('DB_PORT', $defaultPort),
            // 数据库连接参数
            'params' => [
                // 连接超时3秒
                \PDO::ATTR_TIMEOUT => 3,
            ],
            // 数据库编码默认采用utf8
            'charset' => env('DB_CHARSET', '$charset'),
            // 数据库表前缀
            'prefix' => env('DB_PREFIX', ''),
            // 断线重连
            'break_reconnect' => true,
            // 自定义分页类
            'bootstrap' =>  '',
            // 连接池配置
            'pool' => [
                'max_connections' => 5, // 最大连接数
                'min_connections' => 1, // 最小连接数
                'wait_timeout' => 3,    // 从连接池获取连接等待超时时间
                'idle_timeout' => 60,   // 连接最大空闲时间，超过该时间会被回收
                'heartbeat_interval' => 50, // 心跳检测间隔，需要小于60秒
            ],
        ],
    ],
];
EOF;
        file_put_contents(base_path() . '/config/think-orm.php', $think_orm_config);

        // 2、chache配置文件
        $cache_config = <<<EOF
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
        file_put_contents(base_path() . '/config/cache.php', $cache_config);

        // 3、redis配置文件
        $redis_config = <<<EOF
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
        file_put_contents(base_path() . '/config/redis.php', $redis_config);

        // 4、think-cache配置文件
        $think_cache_config = <<<EOF
<?php
return [
    // 默认缓存驱动
    'default' => env('CACHE_MODE', 'file'),
    // 缓存连接方式配置
    'stores'  => [
        // redis缓存
        'redis' => [
            // 驱动方式
            'type' => 'redis',
            // 服务器地址
            'host' => env('REDIS_HOST', '127.0.0.1'),
            // 服务器端口
            'port' => env('REDIS_PORT', 6379),
            // 服务器密码
            'password' => env('REDIS_PASSWORD', ''),
            // 数据库
            'select' => env('REDIS_DB', 0),
            // 缓存前缀
            'prefix' => 'cache:',
            // 默认缓存有效期 0表示永久缓存
            'expire'     => 0,
            // Thinkphp官方没有这个参数，由于生成的tag键默认不过期，如果tag键数量很大，避免长时间占用内存，可以设置一个超过其他缓存的过期时间，0为不设置
            'tag_expire' => 86400 * 30,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 连接池配置
            'pool' => [
                'max_connections' => 5, // 最大连接数
                'min_connections' => 1, // 最小连接数
                'wait_timeout' => 3,    // 从连接池获取连接等待超时时间
                'idle_timeout' => 60,   // 连接最大空闲时间，超过该时间会被回收
                'heartbeat_interval' => 50, // 心跳检测间隔，需要小于60秒
            ],
        ],
        // 文件缓存
        'file' => [
            // 驱动方式
            'type' => 'file',
            // 设置不同的缓存保存目录
            'path' => runtime_path() . '/file/',
        ],
    ],
];
EOF;
        file_put_contents(base_path() . '/config/think-cache.php', $think_cache_config);

        // 5、database配置文件
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
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
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
        file_put_contents(base_path() . '/config/database.php', $database);

    }

    /**
     * 连接阶段的 libpq 错误可能只有通用 SQLSTATE，补充识别其明确原因。
     * 仅返回固定提示，避免向安装页面暴露连接信息或密码。
     */
    protected function databaseErrorMessage(Throwable $error): string
    {
        $state = $error instanceof \PDOException
            ? (string) ($error->errorInfo[0] ?? $error->getCode())
            : '';
        $message = $error->getMessage();
        // Windows libpq 的本地化诊断可能采用 CP936/GBK，转换仅用于分类。
        if (preg_match('//u', $message) !== 1) {
            $message = function_exists('iconv') ? (@iconv('GB18030', 'UTF-8', $message) ?: '') : '';
        }
        $message = strtolower($message);

        if ($state === '3D000') {
            return '数据库不存在，请检查数据库名，并先创建 PostgreSQL 数据库后再安装';
        }
        if ($state === '42501') {
            return '数据库用户权限不足，请确认该用户具有目标数据库的连接权限，以及 public 模式中创建表、写入数据和创建函数的权限';
        }
        if ($state === '28P01') {
            return '数据库身份认证失败，请检查用户名和密码';
        }
        if (str_contains($message, 'no pg_hba.conf entry')) {
            return '数据库拒绝此连接，请检查 pg_hba.conf 是否允许当前主机、用户和数据库，以及 SSL 配置是否匹配';
        }
        if (preg_match('/(?:database|数据库)\s*["“][^"”]*["”]\s*(?:does not exist|不存在)/u', $message)) {
            return '数据库不存在，请检查数据库名，并先创建 PostgreSQL 数据库后再安装';
        }
        if (preg_match('/(?:role|角色)\s*["“][^"”]*["”]\s*(?:does not exist|不存在)/u', $message)) {
            return '数据库用户不存在，请检查用户名';
        }
        if (preg_match('/(?:password|密码)\s*(?:authentication|认证|验证)\s*(?:failed|失败)/u', $message) || str_contains($message, 'no password supplied')) {
            return '数据库身份认证失败，请检查用户名和密码';
        }
        if (str_contains($message, 'permission denied')) {
            return '数据库用户权限不足，请确认该用户具有目标数据库的连接权限，以及 public 模式中创建表、写入数据和创建函数的权限';
        }
        if ($state === '28000') {
            return '数据库拒绝身份认证，请检查用户登录权限和 PostgreSQL 认证配置';
        }
        if (str_contains($message, 'could not find driver')) {
            return 'PHP 未安装或启用 PostgreSQL PDO 驱动，请启用 pdo_pgsql 扩展后重启 PHP 服务';
        }
        if (str_contains($message, 'could not translate host name') || str_contains($message, 'name or service not known') || str_contains($message, 'no such host is known')) {
            return '数据库主机名无法解析，请检查主机地址和 DNS 配置';
        }
        if (str_contains($message, 'connection refused') || str_contains($message, 'actively refused')) {
            return '数据库连接被拒绝，请检查主机和端口，并确认 PostgreSQL 已启动且允许远程连接';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout expired')) {
            return '数据库连接超时，请检查主机、端口、网络以及防火墙是否放行';
        }
        return '数据库操作失败，请检查数据库配置及 PostgreSQL 服务日志';
    }

    /**
     * 获取pdo连接
     * @param $host
     * @param $username
     * @param $password
     * @param $port
     * @param $database
     * @return \PDO
     */
    protected function getPdo($driver, $host, $username, $password, $port, $database = null): \PDO
    {
        if ($driver !== 'pgsql') {
            throw new \InvalidArgumentException('SandAdmin only supports PostgreSQL');
        }
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        return new \PDO($dsn, $username, $password, [
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function getCharset(string $driver): string
    {
        return 'utf8';
    }
}
