<?php

declare(strict_types=1);

/**
 * 登录图表 PostgreSQL 方言断言（开发用；不随包发布）。
 * php tests/login-chart-pgsql-test.php
 * SystemLoginLogLogic 的 loginChart/loginBarChart 曾按 env('DB_TYPE')
 * 选方言，未设时默认 MySQL 写法导致 PostgreSQL 报 500；服务端只支持
 * PostgreSQL，已固定为 generate_series 写法并删掉 MySQL 分支。本测试对
 * 逻辑层源文件做静态契约断言。
 */

namespace {
    $root = dirname(__DIR__);
    $logic = $root . '/server/plugin/sandadmin/app/logic/system/SystemLoginLogLogic.php';
    if (!is_file($logic)) {
        fwrite(STDERR, "[FAIL] 逻辑层文件不存在：{$logic}\n");
        exit(1);
    }
    $src = (string) file_get_contents($logic);

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };
    $has = static fn (string $haystack, string $needle): bool => str_contains($haystack, $needle);

    // 1. 不再按环境变量选方言。
    $assert(!$has($src, "env('DB_TYPE'") && !$has($src, 'env("DB_TYPE"'),
        'loginChart/loginBarChart 不再读取 env(DB_TYPE)');

    // 2. MySQL 专有写法已全部移除。
    foreach (['CURDATE', 'LPAD', 'UNION ALL SELECT'] as $mysqlOnly) {
        $assert(!$has($src, $mysqlOnly), "不含 MySQL 写法 {$mysqlOnly}");
    }
    foreach (['DATE(l.login_time)', 'YEAR(l.login_time)', 'MONTH(l.login_time)'] as $mysqlFunc) {
        $assert(!$has($src, $mysqlFunc), "不含 MySQL 日期函数 {$mysqlFunc}");
    }

    // 3. PostgreSQL 写法：generate_series 序列 + 区间 LEFT JOIN。
    $assert(substr_count($src, 'FROM generate_series(') === 2,
        '两条 SQL 各用一次 generate_series');
    $assert($has($src, "INTERVAL '9 days'") && $has($src, "INTERVAL '1 day'"),
        'loginChart 用最近十天的日序列');
    $assert($has($src, "DATE_TRUNC('year', CURRENT_DATE)") && $has($src, "INTERVAL '1 month'")
        && $has($src, "TO_CHAR(m.month_start, 'MM') || '月'"),
        'loginBarChart 用当年月序列并按 MM 输出月份');
    $assert(substr_count($src, 'LEFT JOIN sand_system_login_log') === 2,
        '两条 SQL 都 LEFT JOIN 登录日志表，无缺数据的日期/月份');
    $assert($has($src, 'l.login_time >= d.login_date') && $has($src, "d.login_date + INTERVAL '1 day'"),
        'loginChart 按 [当日, 次日) 区间匹配登录时间');
    $assert($has($src, 'l.login_time >= m.month_start') && $has($src, "m.month_start + INTERVAL '1 month'"),
        'loginBarChart 按 [月初, 次月) 区间匹配登录时间');

    // 4. 返回结构不变。
    $assert($has($src, "array_column(\$data, 'login_count')"),
        '返回 login_count 列');
    $assert($has($src, "array_column(\$data, 'login_date')"),
        'loginChart 返回 login_date 列');
    $assert($has($src, "array_column(\$data, 'login_month')"),
        'loginBarChart 返回 login_month 列');
    $assert(substr_count($src, 'Db::query($sql)') === 2,
        '两条 SQL 都经 Db::query 执行');

    echo "ALL LOGIN-CHART-PGSQL ASSERTIONS PASSED\n";
}
