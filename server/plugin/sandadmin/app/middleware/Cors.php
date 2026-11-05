<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\middleware;

use plugin\sandadmin\utils\CorsPolicy;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 白名单 CORS 中间件（替代已删除的旧反射式跨域中间件）。
 *
 * 常驻进程安全：策略为不可变对象，只由启动期配置构造；不在静态属性中缓存任何请求数据。
 */
final class Cors implements MiddlewareInterface
{
    private readonly CorsPolicy $policy;

    public function __construct(?CorsPolicy $policy = null)
    {
        $this->policy = $policy ?? CorsPolicy::fromConfig(config('plugin.sandadmin.cors', []));
    }

    public function process(Request $request, callable $handler): Response
    {
        $origin = $request->header('origin');
        $origin = is_string($origin) ? $origin : null;
        $preflight = CorsPolicy::isPreflight($request->method(), $request->header('access-control-request-method'));

        $response = ($preflight && $this->policy->isEnabled()) ? new Response(204) : $handler($request);

        $headers = $this->policy->headersFor($origin, $preflight);
        if ($headers !== []) {
            $response->withHeaders($headers);
        }
        return $response;
    }
}
