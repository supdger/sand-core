<?php

declare(strict_types=1);

namespace plugin\sandadmin\service;

use RuntimeException;

/**
 * 从插件 route.php 编译生产路由清单。
 *
 * 不加载 Webman、不读取 .env、不看 APP_DEBUG。清单与源码路由一一对应，
 * 发现 /install* 时拒绝写入，而不是在构建期悄悄删掉。
 */
final class RouteManifest
{
    /**
     * @return list<string> 形如 "GET /core/captcha"
     */
    public static function compile(string $routeFile): array
    {
        $code = file_get_contents($routeFile);
        if ($code === false) {
            throw new RuntimeException('无法读取路由文件：' . $routeFile);
        }

        $tokens = token_get_all($code);
        $routes = [];
        $groups = [];
        $depth = 0;
        $pendingGroup = null;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $depth++;
                if (is_string($pendingGroup)) {
                    $groups[] = ['prefix' => $pendingGroup, 'depth' => $depth];
                    $pendingGroup = null;
                }
                continue;
            }
            if ($token === '}') {
                $depth--;
                while ($groups !== [] && $groups[array_key_last($groups)]['depth'] > $depth) {
                    array_pop($groups);
                }
                continue;
            }
            if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], 'Route') !== 0) {
                continue;
            }
            $colon = self::skipTrivia($tokens, $index + 1);
            if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $name = self::skipTrivia($tokens, $colon + 1);
            if ($name === null || !is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
                continue;
            }
            $method = strtolower($tokens[$name][1]);
            $paren = self::skipTrivia($tokens, $name + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }
            $argument = self::skipTrivia($tokens, $paren + 1);
            $path = $argument === null ? null : self::literal($tokens[$argument]);
            if ($path === null) {
                continue;
            }
            if ($method === 'group') {
                $pendingGroup = $path;
                continue;
            }
            if (!in_array($method, ['get', 'post', 'put', 'delete', 'patch', 'head', 'options', 'any', 'add'], true)) {
                continue;
            }
            $prefix = '';
            foreach ($groups as $group) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
            $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
            $verb = $method === 'any' || $method === 'add' ? 'ANY' : strtoupper($method);
            $routes[] = $verb . ' ' . $full;
        }

        return $routes;
    }

    /**
     * @param list<string> $routes
     * @return list<string>
     */
    public static function installRoutes(array $routes): array
    {
        return array_values(array_filter($routes, self::isInstallRoute(...)));
    }

    public static function isInstallRoute(string $line): bool
    {
        $path = strtolower(trim((string) preg_replace('/^[A-Z]+\s+/', '', $line)));

        return preg_match('#(?:^|/)install(?:/|$)#', $path) === 1;
    }

    /** 生产构建：源码里有安装路由则失败，成功时原样写入清单。 */
    public static function write(string $routeFile, string $outDir): string
    {
        $routes = self::compile($routeFile);
        $install = self::installRoutes($routes);
        if ($install !== []) {
            throw new RuntimeException(
                '生产构建拒绝写入：HTTP 安装路由仍在源码中：' . implode(', ', $install)
            );
        }
        if (!is_dir($outDir) && !mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            throw new RuntimeException('无法创建构建目录：' . $outDir);
        }
        $body = "# sand-core production routes\n# env_consulted=0\n" . implode("\n", $routes) . "\n";
        $path = rtrim($outDir, '/\\') . '/routes.txt';
        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException('无法写入路由清单：' . $path);
        }

        return $path;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function skipTrivia(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $index++;
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function literal(mixed $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $raw = $token[1];
        $body = substr($raw, 1, -1);
        if ($raw[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }
}
