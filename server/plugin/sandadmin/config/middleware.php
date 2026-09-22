<?php

use plugin\sandadmin\app\middleware\SystemLog;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\CheckAuth;

return [
    '' => [
        CheckLogin::class,
        CheckAuth::class,
        SystemLog::class,
    ]
];

