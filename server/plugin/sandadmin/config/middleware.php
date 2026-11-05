<?php

use plugin\sandadmin\app\middleware\Cors;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\CheckAuth;

return [
    '' => [
        // 必须最先执行：预检请求在登录校验前由白名单直接应答。
        Cors::class,
        CheckLogin::class,
        CheckAuth::class,
        SystemLog::class,
    ]
];

