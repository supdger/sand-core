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
        $data['app'] = $this->app;
        $data['version'] = config('plugin.sandadmin.app.version', $this->version);

        $env = base_path() . DIRECTORY_SEPARATOR . '.env';

        clearstatcache();
        if (is_file($env)) {
            $data['error'] = '程序已经安装';
            return view('install/error', $data);
        }

        if (!is_writable(base_path() . DIRECTORY_SEPARATOR . 'config')) {
            $data['error'] = '权限认证失败';
            return view('install/error', $data);
        }

        $frontendRoot = dirname(base_path()) . DIRECTORY_SEPARATOR . 'sandadmin-artd';
        $data['frontendPort'] = 3006;
        foreach (['.env', '.env.local', '.env.development', '.env.development.local'] as $name) {
            $frontendEnv = $frontendRoot . DIRECTORY_SEPARATOR . $name;
            if (is_file($frontendEnv)) {
                foreach (file($frontendEnv, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match("/^\s*VITE_PORT\s*=\s*(?:\"(\d+)\"|'(\d+)'|(\d+))\s*(?:#.*)?$/", $line, $match)) {
                        $port = (int) (($match[1] ?? '') ?: (($match[2] ?? '') ?: ($match[3] ?? '')));
                        if ($port >= 1 && $port <= 65535) {
                            $data['frontendPort'] = $port;
                        }
                    }
                }
            }
        }

        return view('install/index', $data);
    }

    /**
     * 执行安装
     */
    public function install(Request $request)
    {
        $env = base_path() . DIRECTORY_SEPARATOR . '.env';

        clearstatcache();
        if (is_file($env)) {
            return $this->fail('管理后台已经安装！如需重新安装，请删除根目录env配置文件并重启');
        }

        $user = $request->post('username');
        $password = $request->post('password');
        $database = trim((string) $request->post('database'));
        $host = $request->post('host');
        $driver = 'pgsql';
        if ((string) $request->post('databaseType', 'pgsql') !== 'pgsql') {
            return $this->fail('SandAdmin 仅支持 PostgreSQL');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $database)) {
            return $this->fail('数据库名只能包含字母、数字和下划线，且必须以字母或下划线开头');
        }
        $port = (int) $request->post('port') ?: 5432;
        $dataType = $request->post('dataType', 'demo');

        try {
            $db = $this->getPdo($driver, $host, $user, $password, $port, $database);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Access denied for user') || stripos($message, 'password authentication failed')) {
                return $this->fail('数据库用户名或密码错误');
            }
            if (stripos($message, 'Connection refused')) {
                return $this->fail('Connection refused. 请确认数据库IP端口是否正确，数据库已经启动');
            }
            if (stripos($message, 'timed out')) {
                return $this->fail('数据库连接超时，请确认数据库IP端口是否正确，安全组及防火墙已经放行端口');
            }
            throw $e;
        }

        $table = $db->query("SELECT to_regclass('public.sand_system_menu')")->fetchColumn();
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
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->generateConfig($driver);

        $env_config = <<<EOF
# 数据库配置
DB_TYPE = $driver
DB_HOST = $host
DB_PORT = $port
DB_NAME = $database
DB_USER = $user
DB_PASSWORD = $password
DB_PREFIX = 

# 缓存方式
CACHE_MODE = file

# Redis配置
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_PASSWORD = ''
REDIS_DB = 0
DB_CHARSET = {$this->getCharset($driver)}

# 验证码配置
CAPTCHA_MODE = cache

#前端目录
FRONTEND_DIR = sandadmin-artd

# 服务端口（默认值可按项目环境调整）
SANDADMIN_SERVER_PORT = 8787
SANDADMIN_CHANNEL_PORT = 2206
EOF;
        file_put_contents(base_path() . DIRECTORY_SEPARATOR . '.env', $env_config);

        // 尝试reload
        if (function_exists('posix_kill')) {
            set_error_handler(function () {});
            posix_kill(posix_getppid(), SIGUSR1);
            restore_error_handler();
        }

        return $this->success([
            'initial_admin' => [
                'username' => 'admin',
                'password' => '123456',
                'notice' => '请在首次登录后立即修改默认密码。',
            ],
        ], '安装成功');
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
     * 生成配置文件 
     */
    protected function generateConfig(string $driver): void
    {
        $defaultPort = $driver === 'pgsql' ? 5432 : 3306;
        $charset = $this->getCharset($driver);
        $collation = $driver === 'pgsql' ? 'utf8' : 'utf8mb4_general_ci';

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
            'database' => env('DB_NAME', 'sandadmin'),
            // 数据库用户名
            'username' => env('DB_USER', 'root'),
            // 数据库密码
            'password' => env('DB_PASSWORD', '123456'),
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
            'database' => env('DB_NAME', 'sandadmin'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASSWORD', '123456'),
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
