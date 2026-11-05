<?php

declare(strict_types=1);

/**
 * 生产构建路由探针。只认 SAND_ROUTE_MANIFEST 里的 "METHOD /path"。
 * 不读取 .env，不读取 APP_DEBUG，也不把未登记路径当成安装入口。
 */

$manifest = getenv('SAND_ROUTE_MANIFEST');
if (!is_string($manifest) || !is_file($manifest)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'manifest missing';
    return true;
}

$routes = [];
foreach (file($manifest, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $routes[$line] = true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
if ($path !== '/') {
    $path = rtrim($path, '/');
}
if (isset($routes[$method . ' ' . $path])) {
    http_response_code(204);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
return true;
