<?php
declare(strict_types=1);

use plugin\sandadmin\app\controller\InstallController;

$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
$root = sys_get_temp_dir() . '/sand-core-entry-' . bin2hex(random_bytes(6));
$configuration = [];
function base_path(): string { return $GLOBALS['basePath'] ?? $GLOBALS['root'] . '/server'; }
function config(string $name, mixed $default = null): mixed { return $GLOBALS['configuration'][$name] ?? $default; }
function view(string $template, array $data): array { return ['template' => $template, 'data' => $data]; }
require $autoload;
require dirname(__DIR__) . '/server/Install.php';
require dirname(__DIR__) . '/server/plugin/sandadmin/basic/OpenController.php';
require dirname(__DIR__) . '/server/plugin/sandadmin/app/controller/InstallController.php';
class EntryController extends InstallController { public function __construct() {} }
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}

try {
    mkdir($root . '/server/config', 0777, true);
    mkdir($root . '/sandadmin-artd', 0777, true);
    file_put_contents($root . '/sandadmin-artd/.env', "VITE_PORT=3006\nVITE_BASE_URL=/\n");
    file_put_contents($root . '/sandadmin-artd/.env.local', "VITE_PORT=3007\n");
    file_put_contents($root . '/sandadmin-artd/.env.development', "VITE_PORT='3011'\nVITE_BASE_URL='/admin/'\n");
    file_put_contents($root . '/sandadmin-artd/.env.development.local', "VITE_PORT=\"3012\" # final\n");
    $controller = new EntryController();
    $fresh = $controller->index();
    check($fresh['data']['frontendPort'] === 3012 && $fresh['data']['frontendBase'] === '/admin/', 'Vite env precedence and subpath');
    check($fresh['data']['installed'] === false, 'fresh installer remains available');
    $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/server/plugin/sandadmin/app/view'));
    $freshHtml = $twig->render('install/index.html', $fresh['data']);
    check(str_contains($freshHtml, 'id="dbHost"'), 'fresh database form renders');
    check(str_contains($freshHtml, '22.12.0') && str_contains($freshHtml, 'pnpm 11.19.0') && !str_contains($freshHtml, 'pnpm 9'), 'recovery help renders the verified Node and pnpm prerequisites');

    file_put_contents($root . '/server/.env', 'installed-test');
    $configuration['plugin.sandadmin.app.frontend_url'] = 'https://admin.example/panel/?x="><script>alert(1)</script>';
    $installed = $controller->index();
    check($installed['template'] === 'install/index' && $installed['data']['installed'] === true, 'installed GET resumes completion');
    $html = $twig->render('install/index.html', $installed['data']);
    check(!str_contains($html, 'id="initialAdminPassword"') && !str_contains($html, '123456'), 'installed HTML contains no initial credential block or default password');
    check(!str_contains($html, '><script>alert(1)</script>'), 'configured URL cannot inject HTML or script');
    check(str_contains($html, 'frontendEntry.check(false)'), 'completion can resume entry checks');
    $request = new support\Request("POST /core/install/install HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");
    $before = file_get_contents($root . '/server/.env');
    $response = json_decode($controller->install($request)->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    check($response['code'] === 400 && file_get_contents($root . '/server/.env') === $before, 'installed POST is still rejected without changing configuration');
    $GLOBALS['basePath'] = '/opt/plain-webman';
    check(SandAdmin\Core\Install::frontendTarget() === '/opt/plain-webman/sandadmin-artd', 'plain Webman uses the Composer publisher frontend directory');
    $GLOBALS['basePath'] = 'D:\\htdocs\\sandadmin\\server';
    check(SandAdmin\Core\Install::frontendTarget() === 'D:/htdocs/sandadmin/sandadmin-artd', 'Windows server layout matches Composer publisher');
    unset($GLOBALS['basePath']);
} finally {
    foreach (glob($root . '/sandadmin-artd/.env*') ?: [] as $file) unlink($file);
    if (is_file($root . '/server/.env')) unlink($root . '/server/.env');
    foreach ([$root . '/sandadmin-artd', $root . '/server/config', $root . '/server', $root] as $directory) {
        if (is_dir($directory)) rmdir($directory);
    }
}
