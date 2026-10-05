<?php

use support\Request;

return [
    'debug' => true,
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'version' => '0.2.4',
    // Browser-facing management URL, e.g. /admin/ or https://admin.example.com/.
    'frontend_url' => env('SANDADMIN_FRONTEND_URL', ''),
];
