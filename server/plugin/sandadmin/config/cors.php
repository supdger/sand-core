<?php

/**
 * CORS 白名单（启动期读取，请求内只读）。
 *
 * 默认空 = 同源部署，不发送任何 Access-Control-* 头。
 * 跨源部署时设置 SANDADMIN_CORS_ALLOWED_ORIGINS，逗号分隔精确来源，例如：
 *   SANDADMIN_CORS_ALLOWED_ORIGINS=https://admin.example.com,https://ops.example.com
 * 不支持 "*"。
 */
return [
    'allowed_origins' => env('SANDADMIN_CORS_ALLOWED_ORIGINS', ''),
    'allow_credentials' => (bool) env('SANDADMIN_CORS_ALLOW_CREDENTIALS', false),
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With'],
    'max_age' => 600,
];
