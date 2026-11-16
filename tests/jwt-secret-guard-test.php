<?php

declare(strict_types=1);

/**
 * JwtSecretGuard 回归断言（仅开发用，不随包发布）：php tests/jwt-secret-guard-test.php
 *
 * Covers:
 *   1. 既有判定不回归：非数组、空值、安装占位符、长度不足 32 字节都被拒绝；
 *      随机 32 字节密钥放行，assertValid 不抛。
 *   2. 已知公开默认密钥黑名单：从 vendor 里 tinywan/jwt 自带的配置样例中
 *      正则抽取 access_secret_key / refresh_secret_key 取值（不 include 执行），
 *      凡是真实历史默认值都必须被 problems() 拒绝、isKnownPublic() 命中，
 *      且 assertValid 异常信息不回显明文；占位符值只要求被拒绝。
 *      本文件不落任何默认密钥明文；vendor 文件读不到时输出 SKIP 不判失败。
 *   3. 黑名单判定对所有字段生效（含非对称算法的 private/public key 字段）。
 *   4. 必跑断言（不依赖 vendor，永不 SKIP）：夹具只存 SHA-256 字面值，
 *      测试值由固定串确定性派生、不是任何真实默认密钥；断言其哈希与夹具
 *      一致，且经 matchesBlacklist 的规范化比对路径会被拒绝。
 */

namespace {
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/JwtSecretGuard.php';

    use plugin\sandadmin\utils\JwtSecretGuard;

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };

    $skip = static function (string $message): void {
        echo "[SKIP] {$message}\n";
    };

    $goodAccess = bin2hex(random_bytes(32));
    $goodRefresh = bin2hex(random_bytes(32));

    $symmetric = static function (string $access, string $refresh): array {
        return [
            'algorithms' => 'HS256',
            'access_secret_key' => $access,
            'refresh_secret_key' => $refresh,
        ];
    };

    // ------------------------------------------------------------------
    // 1. 既有行为回归。
    // ------------------------------------------------------------------
    $assert(JwtSecretGuard::problems(null) !== [], 'non-array config rejected');
    $assert(JwtSecretGuard::problems('HS256') !== [], 'scalar config rejected');
    $assert(JwtSecretGuard::problems($symmetric('', '')) !== [], 'empty secrets rejected');
    $assert(
        JwtSecretGuard::problems($symmetric('__JWT_ACCESS_SECRET_KEY__', '__JWT_REFRESH_SECRET_KEY__')) !== [],
        'install placeholders rejected'
    );
    $assert(
        JwtSecretGuard::problems($symmetric(str_repeat('a', 31), str_repeat('b', 31))) !== [],
        '31-byte secrets rejected as too short'
    );

    $assert(JwtSecretGuard::problems($symmetric($goodAccess, $goodRefresh)) === [], 'random 64-hex secrets pass problems()');
    $thrown = false;
    try {
        JwtSecretGuard::assertValid($symmetric($goodAccess, $goodRefresh));
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert(!$thrown, 'assertValid does not throw for random secrets');
    $assert(!JwtSecretGuard::isKnownPublic($goodAccess), 'random secret is not known-public');

    // ------------------------------------------------------------------
    // 2. 从 vendor 的 tinywan/jwt 配置样例抽取取值，断言黑名单命中。
    // ------------------------------------------------------------------
    $vendorDir = dirname(__DIR__) . '/vendor/tinywan/jwt';
    $candidates = [
        $vendorDir . '/src/config/plugin/tinywan/jwt/app.php',
        $vendorDir . '/tests/config.php',
        $vendorDir . '/tests/config/plugin/tinywan/jwt/app.php',
    ];

    $publicDefaults = [];
    $readAny = false;
    foreach ($candidates as $file) {
        $text = @file_get_contents($file);
        if ($text === false) {
            $skip('vendor sample not readable: ' . str_replace(dirname(__DIR__) . '/', '', $file));
            continue;
        }
        $readAny = true;
        preg_match_all(
            '/\'((?:access|refresh)_secret_key)\'\s*=>\s*\'([^\']*)\'/',
            $text,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            [, $field, $value] = $match;
            if ($value === '') {
                continue;
            }
            $config = $symmetric($goodAccess, $goodRefresh);
            $config[$field] = $value;
            $assert(JwtSecretGuard::problems($config) !== [], "vendor sample {$field} value rejected");
            if (preg_match('/__JWT_[A-Z_]*__/', $value) === 1) {
                continue;
            }
            $publicDefaults[] = $value;
            $assert(JwtSecretGuard::isKnownPublic($value), "vendor sample {$field} value is known-public");
            try {
                JwtSecretGuard::assertValid($config);
                $assert(false, "assertValid throws for known-public {$field}");
            } catch (RuntimeException $e) {
                $assert(
                    !str_contains($e->getMessage(), $value),
                    "exception message does not echo {$field} plaintext"
                );
                $assert(
                    str_contains($e->getMessage(), $field),
                    "exception message names the offending {$field}"
                );
            }
        }
    }
    if (!$readAny) {
        $skip('no vendor tinywan/jwt config found; run composer install to enable sample assertions');
    }

    // ------------------------------------------------------------------
    // 3. 黑名单判定对所有字段生效：同一公开值放进非对称私钥字段同样被拒。
    // ------------------------------------------------------------------
    if ($publicDefaults !== []) {
        $known = $publicDefaults[0];
        $assert(
            JwtSecretGuard::problems([
                'algorithms' => 'RS256',
                'access_private_key' => $known,
                'access_public_key' => 'pub',
                'refresh_private_key' => 'priv',
                'refresh_public_key' => 'pub2',
            ]) !== [],
            'known-public value rejected in asymmetric private key field'
        );
    } else {
        $skip('no non-placeholder vendor sample; asymmetric field check skipped');
    }

    // 4. 必跑断言：夹具测试值由固定串确定性派生（不是任何真实默认密钥），
    //    夹具中只存其 SHA-256 字面值。
    $fixtureAccess = hash('sha256', 'sand-core/jwt-secret-guard-test-fixture/access');
    $fixtureHashes = [
        'ff9197a670b725311f369d85e24de5a4fa6cf39b60113f02335d9af8a7e699a0',
    ];
    $assert(
        hash('sha256', $fixtureAccess) === $fixtureHashes[0],
        'fixture value hash matches stored fixture hash'
    );
    $assert(
        !JwtSecretGuard::isKnownPublic($fixtureAccess),
        'fixture value itself is not in production blacklist'
    );

    $blacklist = new ReflectionMethod(JwtSecretGuard::class, 'matchesBlacklist');
    $assert(
        $blacklist->invoke(null, $fixtureAccess, $fixtureHashes) === true,
        'fixture value rejected via blacklist comparison path'
    );
    $assert(
        $blacklist->invoke(null, '  ' . $fixtureAccess . '  ', $fixtureHashes) === true,
        'fixture value with surrounding whitespace rejected via normalization'
    );
    $assert(
        $blacklist->invoke(null, "\r\n" . $fixtureAccess . "\r\n\r\n", $fixtureHashes) === true,
        'fixture value with CRLF and blank lines rejected via normalization'
    );
    $assert(
        $blacklist->invoke(null, 'x' . $fixtureAccess, $fixtureHashes) === false,
        'value differing inside is not matched'
    );

    echo "ALL JWT-SECRET-GUARD ASSERTIONS PASSED\n";
}
