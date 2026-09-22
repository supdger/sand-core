<?php

/*
 * Workerman Channel's task client is unstable on the local macOS runtime:
 * the task worker can terminate with status 11 and be respawned endlessly.
 * Keep scheduled tasks enabled by default on server platforms, while allowing
 * local development to opt in explicitly after the runtime is verified.
 */
$crontabEnabled = filter_var(
    env('SANDADMIN_CRONTAB_ENABLED', PHP_OS_FAMILY !== 'Darwin'),
    FILTER_VALIDATE_BOOL
);

return $crontabEnabled ? [
    'task' => [
        'handler' => plugin\sandadmin\process\Task::class,
    ],
] : [];
