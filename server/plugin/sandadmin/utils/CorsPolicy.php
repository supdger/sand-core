<?php

declare(strict_types=1);

namespace plugin\sandadmin\utils;

use InvalidArgumentException;

/**
 * 白名单 CORS 策略（不可变，仅由启动期配置构造）。
 *
 * 语义参考 nelmio/cors-bundle：Origin 精确匹配；命中才回写该精确源并带 Vary: Origin；
 * 未配置白名单或未命中时不发送任何 Access-Control-* 头（视为同源）。
 * 不允许 "*"：通配与凭证不能同时出现，1.x 直接禁止通配。
 */
final class CorsPolicy
{
    /** @var array<string, true> */
    private readonly array $origins;
    /** @var list<string> */
    private readonly array $methods;
    /** @var list<string> */
    private readonly array $headers;

    /**
     * @param list<string> $allowedOrigins 形如 https://admin.example.com（scheme://host[:port]，无路径）
     */
    public function __construct(
        array $allowedOrigins = [],
        private readonly bool $allowCredentials = false,
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Authorization', 'Content-Type', 'X-Requested-With'],
        private readonly int $maxAge = 600,
    ) {
        $origins = [];
        foreach ($allowedOrigins as $origin) {
            $origin = rtrim(trim((string) $origin), '/');
            if ($origin === '') {
                continue;
            }
            if ($origin === '*' || str_contains($origin, '*')) {
                throw new InvalidArgumentException('CORS 白名单不允许通配 "*"，请列出精确的来源');
            }
            if (!preg_match('#^https?://[^/\s?\#]+$#i', $origin)) {
                throw new InvalidArgumentException('CORS 白名单来源格式无效，应为 scheme://host[:port]');
            }
            $origins[strtolower($origin)] = true;
        }
        $this->origins = $origins;
        $this->methods = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
        $this->headers = array_values(array_unique($allowedHeaders));
    }

    /** @param mixed $config config('plugin.sandadmin.cors') */
    public static function fromConfig(mixed $config): self
    {
        $config = is_array($config) ? $config : [];
        $origins = $config['allowed_origins'] ?? [];
        if (is_string($origins)) {
            $origins = explode(',', $origins);
        }
        return new self(
            allowedOrigins: array_values((array) $origins),
            allowCredentials: (bool) ($config['allow_credentials'] ?? false),
            allowedMethods: (array) ($config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']),
            allowedHeaders: (array) ($config['allowed_headers'] ?? ['Authorization', 'Content-Type', 'X-Requested-With']),
            maxAge: (int) ($config['max_age'] ?? 600),
        );
    }

    public function isEnabled(): bool
    {
        return $this->origins !== [];
    }

    public function allows(?string $origin): bool
    {
        return $origin !== null && $origin !== '' && isset($this->origins[strtolower(rtrim($origin, '/'))]);
    }

    public static function isPreflight(string $method, ?string $requestMethodHeader): bool
    {
        return strtoupper($method) === 'OPTIONS' && $requestMethodHeader !== null && $requestMethodHeader !== '';
    }

    /**
     * 计算要追加的响应头；纯函数，只依赖入参与不可变配置。
     *
     * @return array<string, string>
     */
    public function headersFor(?string $origin, bool $preflight): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        // 白名单启用时总带 Vary: Origin，防止缓存把某源的响应给其他源。
        $headers = ['Vary' => 'Origin'];
        if (!$this->allows($origin)) {
            return $headers;
        }
        $headers['Access-Control-Allow-Origin'] = (string) $origin;
        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        if ($preflight) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $this->methods);
            $headers['Access-Control-Allow-Headers'] = implode(', ', $this->headers);
            $headers['Access-Control-Max-Age'] = (string) $this->maxAge;
        }
        return $headers;
    }
}
