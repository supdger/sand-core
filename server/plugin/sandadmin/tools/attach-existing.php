<?php
/**
 * Bind an unconfigured SandAdmin host to an existing PostgreSQL installation.
 * This file deliberately uses only PDO and PHP: loading Webman here could open
 * the host's default database before the explicit target has been checked.
 */
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "失败：$message\n");
    exit(1);
}

$help = "用法：php plugin/sandadmin/tools/attach-existing.php preflight|confirm --host=HOST --port=PORT --database=DB --user=USER [--allow-empty-password=local] [--fingerprint=SHA256 --confirm=ATTACH]\n密码只从 SAND_ATTACH_DB_PASSWORD 环境变量读取；空密码仅在显式本机回环选项下允许。\n";
if ($argc < 2 || in_array($argv[1], ['--help', '-h'], true)) {
    echo $help;
    exit($argc < 2 ? 1 : 0);
}
$stage = $argv[1];
if (!in_array($stage, ['preflight', 'confirm'], true)) {
    fail('阶段只能是 preflight 或 confirm');
}
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (!preg_match('/^--([a-z-]+)=(.*)$/D', $arg, $match) || isset($options[$match[1]])) {
        fail('参数格式错误或重复；参见 --help');
    }
    $options[$match[1]] = $match[2];
}
$allowed = ['host', 'port', 'database', 'user', 'allow-empty-password'];
if ($stage === 'confirm') {
    array_push($allowed, 'fingerprint', 'confirm');
}
foreach ($options as $name => $_) {
    if (!in_array($name, $allowed, true)) {
        fail('未知参数：' . $name);
    }
}
foreach (['host', 'port', 'database', 'user'] as $name) {
    if (!isset($options[$name]) || $options[$name] === '') {
        fail('必须显式指定 --' . $name);
    }
}
if (isset($options['allow-empty-password']) && $options['allow-empty-password'] !== 'local') {
    fail('空密码选项只能是 --allow-empty-password=local');
}
if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $options['host']) ||
    !preg_match('/^[1-9][0-9]{0,4}$/D', $options['port']) || (int) $options['port'] > 65535 ||
    !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $options['database']) ||
    !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $options['user'])) {
    fail('目标地址、端口、库名或用户名格式无效');
}
if ($stage === 'confirm' &&
    (!isset($options['fingerprint'], $options['confirm']) ||
        !preg_match('/^[a-f0-9]{64}$/D', $options['fingerprint']) || $options['confirm'] !== 'ATTACH')) {
    fail('确认需提供预检的 --fingerprint=SHA256 和 --confirm=ATTACH');
}
$password = getenv('SAND_ATTACH_DB_PASSWORD');
if ($password === false) {
    fail('必须显式设置 SAND_ATTACH_DB_PASSWORD');
}
$allowEmptyPassword = isset($options['allow-empty-password']);
if ($password === '' && (!$allowEmptyPassword || !in_array($options['host'], ['127.0.0.1', '::1'], true))) {
    fail('空密码只可配合 --allow-empty-password=local 及回环地址使用');
}
if ($password !== '' && $allowEmptyPassword) {
    fail('非空密码不可指定 --allow-empty-password=local');
}
if ($allowEmptyPassword) {
    // libpq treats an empty password as absent and may read ~/.pgpass.
    // A local empty-password attach must never inherit another credential
    // source or a connection target override from the process environment.
    foreach (['PGPASSWORD', 'PGPASSFILE', 'PGSERVICE', 'PGSERVICEFILE',
        'PGHOST', 'PGHOSTADDR', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGOPTIONS'] as $name) {
        if (getenv($name) !== false) {
            fail('空密码模式不接受已有 libpq 环境变量：' . $name);
        }
    }
    if (DIRECTORY_SEPARATOR !== '/' || !file_exists('/dev/null') ||
        !putenv('PGPASSFILE=/dev/null')) {
        fail('当前系统无法可靠禁用 libpq 密码文件，拒绝空密码模式');
    }
}
// Dotenv single quotes preserve # and $, but apostrophes/newlines/backslashes
// are rejected until a verified serializer supports them without ambiguity.
if (preg_match('/[\x00-\x1f\x7f\x27\\\\]/', $password)) {
    fail('密码含当前 .env 写入器不能安全表示的字符');
}
$base = realpath(dirname(__DIR__, 3));
if ($base === false) {
    fail('无法确认宿主绝对路径');
}
$envPath = $base . '/.env';
if (file_exists($envPath) || is_link($envPath)) {
    fail('宿主 .env 已存在；不会覆盖现有配置');
}
if (!is_dir($base . '/config') || !is_writable($base) || !is_file($base . '/config/database.php') ||
    !is_file($base . '/config/think-orm.php')) {
    fail('宿主配置文件或 .env 目标目录不可用');
}
$configHashes = [];
foreach (['database.php', 'think-orm.php'] as $file) {
    $body = file_get_contents($base . '/config/' . $file);
    if ($body === false || !str_contains($body, "env('DB_NAME'") || !str_contains($body, "'pgsql'")) {
        fail('宿主 ' . $file . ' 不是可识别的 PostgreSQL 环境变量配置');
    }
    $configHashes[$file] = hash('sha256', $body);
}
$sqlPath = dirname(__DIR__) . '/db/sandadmin-6.0.pgsql';
$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fail('随包的核心结构定义不可读');
}
$expected = [];
preg_match_all('/CREATE TABLE "(sand_(?:system|tool)_[a-z_]+)" \(\s*(.*?)\n\);/s', $sql, $tables, PREG_SET_ORDER);
foreach ($tables as $table) {
    $columns = [];
    foreach (explode("\n", $table[2]) as $line) {
        if (preg_match('/^\s*"[a-z_]+" /', $line) && !preg_match('/^\s*"([a-z_]+)" (integer|bigint|smallint|text|varchar\([0-9]+\)|timestamp\(0\) without time zone) (NOT NULL|NULL)(?: |,|$)/', $line, $column)) {
            fail('核心结构定义存在无法识别的列：' . $table[1]);
        }
        if (!isset($column[1])) {
            continue;
        }
        $type = str_replace('varchar', 'character varying', $column[2]);
        $columns[$column[1]] = [$type, $column[3] === 'NOT NULL',
            str_contains($line, 'GENERATED BY DEFAULT AS IDENTITY') ? 'd' : ''];
    }
    if (!preg_match('/PRIMARY KEY \(([^)]+)\)/', $table[2], $primaryKey)) {
        fail('核心结构定义缺少主键：' . $table[1]);
    }
    $primaryKeys[$table[1]] = str_replace(['"', ' '], '', $primaryKey[1]);
    if (!$columns || !isset($columns['id'])) {
        fail('核心结构定义无法解析：' . $table[1]);
    }
    $expected[$table[1]] = $columns;
}
if (count($expected) !== 22) {
    fail('核心结构定义不是预期的 22 张表');
}
echo "阶段 1/3：核对宿主配置和 22 张核心表定义\n";
$lockPath = $base . '/runtime/sandadmin-install.lock';
if (!is_dir(dirname($lockPath))) {
    fail('宿主 runtime 目录不存在，无法使用安装互斥锁');
}
$lock = null;
if ($stage === 'confirm') {
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        fail('无法取得安装互斥锁');
    }
}
try {
    if (file_exists($envPath) || is_link($envPath)) {
        fail('宿主 .env 已存在；不会覆盖现有配置');
    }
    try {
        $db = new PDO('pgsql:host=' . $options['host'] . ';port=' . $options['port'] .
            ';dbname=' . $options['database'] . ';user=' . $options['user'],
            $options['user'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]);
        $db->exec('BEGIN READ ONLY');
        $identity = $db->query('SELECT current_database(), current_user, host(inet_server_addr()), inet_server_port(), host(inet_client_addr())')->fetch(PDO::FETCH_NUM);
        if ($identity[0] !== $options['database'] || $identity[1] !== $options['user'] || $identity[2] === null) {
            fail('连接身份或目标数据库与显式指定值不符');
        }
        if ($allowEmptyPassword && (!in_array($identity[2], ['127.0.0.1', '::1'], true) ||
            !in_array($identity[4], ['127.0.0.1', '::1'], true))) {
            fail('空密码仅允许实际服务端和客户端均为回环地址');
        }
        echo "阶段 2/3：只读核对目标库结构和 ORM 辅助对象\n";
        $actual = [];
        $query = $db->query("SELECT c.relname, a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod), a.attnotnull, a.attidentity
            FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = 'public' AND c.relkind IN ('r','p') AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY c.relname, a.attnum");
        foreach ($query as $row) {
            $actual[$row[0]][$row[1]] = [$row[2], $row[3] === true || $row[3] === 't' || $row[3] === 1 || $row[3] === '1', $row[4]];
        }
        $missing = [];
        foreach ($expected as $table => $columns) {
            if (!isset($actual[$table])) {
                $missing[] = $table . ' 缺表';
                continue;
            }
            foreach ($columns as $name => $definition) {
                if (!isset($actual[$table][$name]) || $actual[$table][$name] !== $definition) {
                    $missing[] = $table . '.' . $name . ' 列类型或非空约束不匹配';
                }
            }
        }
        $keys = [];
        $keyQuery = $db->query("SELECT t.relname, string_agg(a.attname, ',' ORDER BY k.ord)
            FROM pg_catalog.pg_constraint c
            JOIN pg_catalog.pg_class t ON t.oid = c.conrelid
            JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
            JOIN LATERAL unnest(c.conkey) WITH ORDINALITY k(attnum, ord) ON true
            JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
            WHERE n.nspname = 'public' AND c.contype = 'p' GROUP BY t.relname");
        foreach ($keyQuery as $row) {
            if (isset($expected[$row[0]])) {
                $keys[$row[0]] = $row[1];
            }
        }
        ksort($keys);
        foreach ($primaryKeys as $table => $key) {
            $sequence = $db->prepare('SELECT pg_get_serial_sequence(?, \'id\')');
            $sequence->execute(['public.' . $table]);
            if (!$sequence->fetchColumn()) {
                $missing[] = $table . ' 缺少 identity 序列';
            }
            if (($keys[$table] ?? null) !== $key) {
                $missing[] = $table . ' 主键不匹配';
            }
            $lookup = $db->prepare('SELECT to_regclass(?) = to_regclass(?),
                has_table_privilege(current_user, ?, \'SELECT\'),
                has_table_privilege(current_user, ?, \'INSERT\'),
                has_table_privilege(current_user, ?, \'UPDATE\'),
                has_table_privilege(current_user, ?, \'DELETE\')');
            $qualified = 'public.' . $table;
            $lookup->execute([$table, $qualified, $qualified, $qualified, $qualified, $qualified]);
            $access = $lookup->fetch(PDO::FETCH_NUM);
            if (!$access || count(array_filter($access, static fn ($value) => in_array($value, [true, 't', 1, '1'], true))) !== 5) {
                $missing[] = $table . ' 搜索路径或运行权限不匹配';
            }
        }
        if ($missing) {
            fail('结构不兼容：' . implode('；', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '；另有 ' . (count($missing) - 8) . ' 项' : ''));
        }
        $schemas = $db->query('SELECT current_schemas(true)')->fetchColumn();
        if (!str_contains((string) $schemas, 'public') ||
            !$db->query("SELECT has_schema_privilege(current_user, 'public', 'USAGE')")->fetchColumn()) {
            fail('运行时搜索路径或 public schema 权限不满足');
        }
        $helperAttributes = $db->query("SELECT a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod)
            FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
            JOIN pg_catalog.pg_class c ON c.oid = t.typrelid
            JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = 'public' AND t.typname = 'tablestruct'
              AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum")->fetchAll(PDO::FETCH_NUM);
        if ($helperAttributes !== [['fields_key_name', 'character varying(100)'],
            ['fields_name', 'character varying(200)'], ['fields_type', 'character varying(20)'],
            ['fields_length', 'bigint'], ['fields_not_null', 'character varying(10)'],
            ['fields_default', 'character varying(500)'], ['fields_comment', 'character varying(1000)']]) {
            fail('ORM tablestruct 类型不兼容');
        }
        $helperSignatures = [];
        foreach (['public.pgsql_type(character varying)', 'public.table_msg(character varying)',
            'public.table_msg(character varying,character varying)'] as $object) {
            $statement = $db->prepare('SELECT to_regprocedure(?)::oid');
            $statement->execute([$object]);
            $oid = $statement->fetchColumn();
            if (!$oid) {
                fail('缺少 ORM 辅助函数：' . $object);
            }
            $check = $db->prepare('SELECT has_function_privilege(current_user, ?::oid, \'EXECUTE\'),
                prorettype = to_regtype(\'public.tablestruct\'), proretset,
                pg_catalog.format_type(prorettype, NULL)
                FROM pg_catalog.pg_proc WHERE oid = ?::oid');
            $check->execute([$oid, $oid]);
            $function = $check->fetch(PDO::FETCH_NUM);
            $helperSignatures[$object] = $function;
            if (!$function || !in_array($function[0], [true, 't', 1, '1'], true) ||
                (str_contains($object, 'table_msg') &&
                    (!in_array($function[1], [true, 't', 1, '1'], true) ||
                        !in_array($function[2], [true, 't', 1, '1'], true))) ||
                (!str_contains($object, 'table_msg') &&
                    (in_array($function[2], [true, 't', 1, '1'], true) || $function[3] !== 'character varying'))) {
                fail('ORM 辅助函数签名或执行权限不兼容：' . $object);
            }
        }
        $ready = $db->query("SELECT
            EXISTS(SELECT 1 FROM public.sand_system_user u
              JOIN public.sand_system_user_role ur ON ur.user_id = u.id
              JOIN public.sand_system_role r ON r.id = ur.role_id
              WHERE u.is_super = 1 AND u.status = 1 AND u.password IS NOT NULL AND u.password <> ''
                AND u.delete_time IS NULL AND r.code = 'super_admin' AND r.status = 1
                AND r.delete_time IS NULL),
            EXISTS(SELECT 1 FROM public.sand_system_menu
              WHERE slug = 'core:user:index' AND delete_time IS NULL)")->fetch(PDO::FETCH_NUM);
        if (in_array(false, array_map(static fn ($v) => $v === true || $v === 't' || $v === 1, $ready), true)) {
            fail('缺少可用管理员、超级管理员角色、核心权限菜单或角色关联');
        }
        $db->exec('ROLLBACK');
    } catch (Throwable $e) {
        // PostgreSQL messages may include connection details. Keep them out of CLI output.
        fail('只读数据库预检失败或目标不可访问；请核对目标、权限和结构');
    }
    $fingerprint = hash('sha256', json_encode([$base, $allowEmptyPassword, $options['host'], $options['port'], $identity,
        $configHashes, hash('sha256', $sql), $expected, $primaryKeys, array_intersect_key($actual, $expected),
        $keys, $helperAttributes, $helperSignatures, $ready], JSON_THROW_ON_ERROR));
    echo "阶段 3/3：目标结构兼容，预检指纹：$fingerprint\n";
    if ($stage === 'preflight') {
        echo "确认绑定时显式传入相同目标参数、--fingerprint=$fingerprint 和 --confirm=ATTACH。\n";
        exit(0);
    }
    if (!hash_equals($fingerprint, $options['fingerprint'])) {
        fail('目标或宿主状态已变化，请重新预检');
    }
    if (file_exists($envPath) || is_link($envPath)) {
        fail('宿主 .env 已存在；不会覆盖现有配置');
    }
    foreach ($configHashes as $file => $hash) {
        if (hash_file('sha256', $base . '/config/' . $file) !== $hash) {
            fail('宿主配置在检查期间发生变化，请重新预检');
        }
    }
    $contents = "# Existing SandAdmin schema: attached without initialization\n"
        . "DB_TYPE = pgsql\nDB_HOST = {$options['host']}\nDB_PORT = {$options['port']}\n"
        . "DB_NAME = {$options['database']}\nDB_USER = {$options['user']}\nDB_PASSWORD = '$password'\n"
        . "DB_PREFIX = \nDB_CHARSET = utf8\nCACHE_MODE = file\nCAPTCHA_MODE = cache\nFRONTEND_DIR = sandadmin-artd\n"
        . "SANDADMIN_SERVER_PORT = 8787\nSANDADMIN_CHANNEL_PORT = 2206\n";
    $temporary = tempnam($base, '.sandadmin-env-');
    if ($temporary === false) {
        fail('无法创建临时配置');
    }
    $created = chmod($temporary, 0600) &&
        file_put_contents($temporary, $contents) === strlen($contents) &&
        @link($temporary, $envPath);
    unlink($temporary);
    if (!$created) {
        fail('无法原子创建 .env；未覆盖现有配置');
    }
    echo "绑定完成：仅创建宿主 .env；未初始化数据库、重置凭据或重载服务。\n";
} finally {
    if ($lock !== null) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
