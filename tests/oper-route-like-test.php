<?php

declare(strict_types=1);

/**
 * 操作路由按片段匹配，输入里的通配符按字面处理。不连数据库。
 *
 *   php tests/oper-route-like-test.php
 */

require_once dirname(__DIR__) . '/server/plugin/sandadmin/utils/OperRouteLike.php';

use plugin\sandadmin\utils\OperRouteLike;

$failed = 0;

$assert = static function (bool $ok, string $name) use (&$failed): void {
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
};

$assert(OperRouteLike::pattern('dictData') === '%dictData%', '一段路由名包上前后匹配');
$assert(OperRouteLike::pattern('a%b_c') === '%a\\%b\\_c%', '百分号和下划线按字面转义');
$assert(OperRouteLike::pattern('  /core  ') === '%/core%', '首尾空白不参与匹配');

echo "oper-route-like failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
