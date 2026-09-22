<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['autoload']['psr-4']['SandAdmin\\Core\\'] ?? null) !== 'server/') {
    throw new RuntimeException('SandAdmin Core installer namespace mapping is missing');
}

require $root . '/server/Install.php';
if (!defined(\SandAdmin\Core\Install::class . '::WEBMAN_PLUGIN')) {
    throw new RuntimeException('SandAdmin Core Webman plugin marker is missing');
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'plugin\\sandadmin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $root . '/server/plugin/sandadmin/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (!class_exists(\plugin\sandadmin\utils\Arr::class)) {
    throw new RuntimeException('SandAdmin runtime namespace is not loadable');
}
$app = require $root . '/server/plugin/sandadmin/config/app.php';
if (($app['version'] ?? null) !== '0.1.0') {
    throw new RuntimeException('SandAdmin app config is not discoverable');
}
foreach (['route.php', 'middleware.php', 'process.php', 'autoload.php'] as $config) {
    if (!is_file($root . '/server/plugin/sandadmin/config/' . $config)) {
        throw new RuntimeException("SandAdmin config payload is missing: {$config}");
    }
}

fwrite(STDOUT, "sand-core package probe passed\n");
