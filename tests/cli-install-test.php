<?php

declare(strict_types=1);

/**
 * CLI 安装器回归断言（仅开发用，不随包发布）：php tests/cli-install-test.php
 *
 * Covers: writeEnv 保留运维自定义 .env 值（自定义端口与未管理键，含
 * --force 重装不覆盖）；安装种子满足 A6（根部门存在，首个超管
 * dept_id 指向根部门，provisionAdmin 不改写 dept_id）。
 */

namespace {
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/InstallGuard.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/JwtSecretGuard.php';
    require dirname(__DIR__) . '/server/plugin/sandadmin/service/DatabaseInstaller.php';

    use plugin\sandadmin\service\DatabaseInstaller;
    use plugin\sandadmin\service\InstallOptions;

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };

    $makeOptions = static fn (string $database = 'appdb'): InstallOptions => new InstallOptions(
        host: 'db.internal',
        port: 5432,
        database: $database,
        user: 'sand',
        password: 's3cr3t"pa#ss',
        dataType: 'pure',
        adminUsername: 'admin',
        adminPassword: '',
        force: true,
        confirm: 'INSTALL',
        allowEmptyPassword: false,
    );

    $writeEnv = static function (string $base, InstallOptions $options): void {
        $installer = new DatabaseInstaller(dirname(__DIR__) . '/server/plugin/sandadmin');
        $method = new ReflectionMethod(DatabaseInstaller::class, 'writeEnv');
        $method->setAccessible(true);
        $method->invoke($installer, $base, $options);
    };

    $readEnv = static function (string $path): array {
        $installer = new DatabaseInstaller(dirname(__DIR__) . '/server/plugin/sandadmin');
        $method = new ReflectionMethod(DatabaseInstaller::class, 'readEnvAssignments');
        $method->setAccessible(true);
        /** @var array<string,string> $values */
        $values = $method->invoke($installer, $path);

        return $values;
    };

    $makeBase = static function (): string {
        $base = sys_get_temp_dir() . '/sand-cli-env-' . bin2hex(random_bytes(4));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            fwrite(STDERR, "[FAIL] 无法创建临时宿主目录\n");
            exit(1);
        }

        return $base;
    };

    // 1. 首次安装：无既有 .env，写入安装器默认值。
    $freshBase = $makeBase();
    $writeEnv($freshBase, $makeOptions());
    $freshEnv = (string) file_get_contents($freshBase . '/.env');
    $assert(str_contains($freshEnv, 'DB_HOST = "db.internal"')
        && str_contains($freshEnv, 'DB_NAME = "appdb"')
        && str_contains($freshEnv, 'DB_TYPE = "pgsql"'),
        '首次安装写入数据库连接配置');
    $assert(str_contains($freshEnv, 'SANDADMIN_SERVER_PORT = "8787"')
        && str_contains($freshEnv, 'SANDADMIN_CHANNEL_PORT = "2206"'),
        '首次安装写入默认服务端口');
    $assert(!str_contains($freshEnv, '已有用户配置'),
        '首次安装不产生保留段');
    $assert((fileperms($freshBase . '/.env') & 0777) === 0600,
        '写出的 .env 权限为 0600');

    // 2. 已有自定义 .env：自定义端口与未管理键保留，受管键取新值。
    $customBase = $makeBase();
    file_put_contents($customBase . '/.env', implode("\n", [
        '# 运维自定义配置',
        'SANDADMIN_SERVER_PORT = 25879',
        'SANDADMIN_CHANNEL_PORT = "25206"',
        'EXTRA_FLAG = keep-me',
        'export lower_key = lower-val',
        'DB_NAME = olddb',
        'NOTES = "带 # 号的说明"',
        '',
    ]));
    $writeEnv($customBase, $makeOptions('newdb'));
    $customEnv = (string) file_get_contents($customBase . '/.env');
    $assert(str_contains($customEnv, 'SANDADMIN_SERVER_PORT = "25879"')
        && str_contains($customEnv, 'SANDADMIN_CHANNEL_PORT = "25206"'),
        '自定义服务端口在重写时保留');
    $assert(!str_contains($customEnv, 'SANDADMIN_SERVER_PORT = "8787"')
        && !str_contains($customEnv, 'SANDADMIN_CHANNEL_PORT = "2206"'),
        '默认服务端口不回写覆盖已配置值');
    $assert(str_contains($customEnv, 'EXTRA_FLAG = "keep-me"')
        && str_contains($customEnv, 'lower_key = "lower-val"')
        && str_contains($customEnv, 'NOTES = "带 # 号的说明"'),
        '安装器不管理的键原样并入（含 export 前缀与引号内 #）');
    $assert(str_contains($customEnv, '# 已有用户配置'),
        '保留段带说明注释');
    $assert(str_contains($customEnv, 'DB_NAME = "newdb"')
        && !str_contains($customEnv, 'DB_NAME = "olddb"'),
        '受管键取本次安装的新值');

    $parsed = $readEnv($customBase . '/.env');
    $assert(($parsed['DB_PASSWORD'] ?? null) === 's3cr3t"pa#ss',
        '含引号与 # 的密码可被解析器原样读回');
    $assert(($parsed['SANDADMIN_SERVER_PORT'] ?? null) === '25879'
        && ($parsed['EXTRA_FLAG'] ?? null) === 'keep-me',
        '写出文件再次解析后自定义值仍在');

    // 3. --force 重装：再次写入仍保留自定义值且不重复追加。
    $writeEnv($customBase, $makeOptions('newdb2'));
    $forcedEnv = (string) file_get_contents($customBase . '/.env');
    $assert(str_contains($forcedEnv, 'SANDADMIN_SERVER_PORT = "25879"')
        && str_contains($forcedEnv, 'SANDADMIN_CHANNEL_PORT = "25206"')
        && str_contains($forcedEnv, 'EXTRA_FLAG = "keep-me"'),
        '--force 重装仍保留自定义端口与未管理键');
    $assert(substr_count($forcedEnv, 'EXTRA_FLAG') === 1
        && substr_count($forcedEnv, 'SANDADMIN_SERVER_PORT') === 1,
        '重复安装不产生重复键');
    $assert(str_contains($forcedEnv, 'DB_NAME = "newdb2"'),
        '重装后受管键更新为新值');

    // 4. A6：安装种子含根部门，首个超管 dept_id 指向根部门。
    $splitSeedValues = static function (string $tuple): array {
        $values = [];
        $current = '';
        $quoted = false;
        $length = strlen($tuple);
        for ($index = 0; $index < $length; $index++) {
            $char = $tuple[$index];
            if ($quoted) {
                $current .= $char;
                if ($char === "'") {
                    if ($index + 1 < $length && $tuple[$index + 1] === "'") {
                        $current .= "'";
                        $index++;
                    } else {
                        $quoted = false;
                    }
                }
                continue;
            }
            if ($char === "'") {
                $quoted = true;
                $current .= $char;
                continue;
            }
            if ($char === ',') {
                $values[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $values[] = trim($current);

        return $values;
    };

    $seedRows = static function (string $sql, string $table) use ($splitSeedValues): array {
        $rows = [];
        preg_match_all('/INSERT INTO "' . $table . '" VALUES \((.*?)\);/s', $sql, $matches);
        foreach ($matches[1] as $tuple) {
            $rows[] = $splitSeedValues($tuple);
        }

        return $rows;
    };

    foreach (['sandadmin-pure.pgsql', 'sandadmin-6.0.pgsql'] as $schemaFile) {
        $schema = (string) file_get_contents(
            dirname(__DIR__) . '/server/plugin/sandadmin/db/' . $schemaFile
        );
        $rootDept = null;
        foreach ($seedRows($schema, 'sand_system_dept') as $row) {
            if (($row[0] ?? null) === '1') {
                $rootDept = $row;
            }
        }
        $assert($rootDept !== null && ($rootDept[1] ?? null) === '0',
            $schemaFile . ' 存在根部门 id=1 且 parent_id=0');

        $adminDeptId = null;
        foreach ($seedRows($schema, 'sand_system_user') as $row) {
            if (($row[0] ?? null) === '1' && ($row[1] ?? null) === "'admin'") {
                $adminDeptId = $row[10] ?? null;
            }
        }
        $assert($adminDeptId === '1',
            $schemaFile . ' 首个超管 dept_id=1 指向根部门');
    }

    $installerSource = (string) file_get_contents(
        dirname(__DIR__) . '/server/plugin/sandadmin/service/DatabaseInstaller.php'
    );
    preg_match('/UPDATE "sand_system_user" SET (.*?) WHERE "id" = 1/s', $installerSource, $provisionMatch);
    $assert(isset($provisionMatch[1]) && !str_contains($provisionMatch[1], 'dept_id'),
        'provisionAdmin 不改写 dept_id，种子值保持指向根部门');

    foreach ([$freshBase, $customBase] as $base) {
        foreach (glob($base . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($base);
    }

    echo "ALL CLI INSTALL ASSERTIONS PASSED\n";
}
