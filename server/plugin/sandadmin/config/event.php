<?php
return [
    'user.login' => [
        [plugin\sandadmin\app\event\SystemUser::class, 'login'],
    ],
    'user.operateLog' => [
        [plugin\sandadmin\app\event\SystemUser::class, 'operateLog'],
    ]
];
