<?php

declare(strict_types=1);

/**
 * Secret-exposure regression assertions (development-only; never shipped).
 *
 *   php tests/secret-exposure-test.php
 *
 * Covers the GET /core/config/index hardening contract:
 *   1. Read side: sensitive config keys (cloud storage secrets, SMTP password,
 *      etc.) are masked to '******' in list output, with value_masked /
 *      value_configured flags; both paginated and saiType=all flat structures.
 *   2. Write side: the '******' placeholder means "keep existing value" and
 *      must never be persisted; empty string '' stays a real "clear value".
 *
 * Only depends on utils/SensitiveData.php (no ThinkPHP/Db imports), so this
 * script runs standalone without composer install.
 */

namespace {
    require dirname(__DIR__) . '/server/plugin/sandadmin/utils/SensitiveData.php';

    use plugin\sandadmin\utils\SensitiveData;

    $assert = static function (bool $cond, string $message): void {
        if (!$cond) {
            fwrite(STDERR, "[FAIL] {$message}\n");
            exit(1);
        }
        echo "[PASS] {$message}\n";
    };

    // ------------------------------------------------------------------
    // 1. Read side: maskConfigRows on a flat (saiType=all) row set.
    // ------------------------------------------------------------------
    $flatRows = [
        ['id' => 1, 'key' => 'qiniu_secretKey', 'value' => 'real-qiniu-secret', 'name' => '七牛私钥'],
        ['id' => 2, 'key' => 'Password', 'value' => 'smtp-pass-123', 'name' => 'SMTP密码'],
        ['id' => 3, 'key' => 's3_secret', 'value' => 's3-secret-value', 'name' => 'S3密钥'],
        ['id' => 4, 'key' => 'cos_secretId', 'value' => 'cos-secret-id', 'name' => 'COS秘钥ID'],
        ['id' => 5, 'key' => 'site_name', 'value' => 'SandAdmin', 'name' => '站点名称'],
        ['id' => 6, 'key' => 'upload_size', 'value' => '10', 'name' => '上传大小'],
        ['id' => 7, 'key' => 'qiniu_accessKey', 'value' => '', 'name' => '七牛公钥(空)'],
        ['id' => 8, 'key' => 'oss_secret', 'value' => null, 'name' => 'OSS密钥(null)'],
    ];
    $masked = SensitiveData::maskConfigRows($flatRows);

    foreach ([1, 2, 3, 4] as $i) {
        $row = $masked[$i - 1];
        $assert($row['value'] === SensitiveData::MASK, "sensitive row {$i} value masked to '******'");
        $assert($row['value_masked'] === true, "sensitive row {$i} value_masked === true");
        $assert($row['value_configured'] === true, "sensitive row {$i} value_configured === true");
    }

    $siteName = $masked[4];
    $assert($siteName['value'] === 'SandAdmin', 'non-sensitive site_name keeps plaintext value');
    $assert($siteName['value_masked'] === false, 'non-sensitive site_name value_masked === false');
    $assert($siteName['value_configured'] === true, 'non-sensitive site_name value_configured === true');

    $uploadSize = $masked[5];
    $assert($uploadSize['value'] === '10', 'non-sensitive upload_size keeps plaintext value');
    $assert($uploadSize['value_masked'] === false, 'non-sensitive upload_size value_masked === false');
    $assert($uploadSize['value_configured'] === true, 'non-sensitive upload_size value_configured === true');

    // Sensitive key with empty value: no mask, configured=false, value untouched.
    $emptySecret = $masked[6];
    $assert($emptySecret['value'] === '', 'sensitive key with empty value stays empty (not masked)');
    $assert($emptySecret['value_masked'] === false, 'sensitive empty value value_masked === false');
    $assert($emptySecret['value_configured'] === false, 'sensitive empty value value_configured === false');

    $nullSecret = $masked[7];
    $assert($nullSecret['value'] === null, 'sensitive key with null value stays null (not masked)');
    $assert($nullSecret['value_masked'] === false, 'sensitive null value value_masked === false');
    $assert($nullSecret['value_configured'] === false, 'sensitive null value value_configured === false');

    // Non-sensitive row with empty value: configured=false as well.
    $emptyPlain = SensitiveData::maskConfigRows([
        ['id' => 9, 'key' => 'site_name', 'value' => '', 'name' => '站点名称(空)'],
    ]);
    $assert($emptyPlain[0]['value'] === ''
        && $emptyPlain[0]['value_masked'] === false
        && $emptyPlain[0]['value_configured'] === false,
        'non-sensitive empty value: unchanged, masked=false, configured=false');

    // ------------------------------------------------------------------
    // 2. Read side: paginated structure (paginate()->toArray() shape).
    // ------------------------------------------------------------------
    $paginated = [
        'total' => 2,
        'per_page' => 10,
        'current_page' => 1,
        'last_page' => 1,
        'data' => [
            ['id' => 10, 'key' => 'cos_secretKey', 'value' => 'cos-real-key', 'name' => 'COS密钥'],
            ['id' => 11, 'key' => 'site_url', 'value' => 'https://example.com', 'name' => '站点地址'],
        ],
    ];
    $maskedPage = SensitiveData::maskConfigRows($paginated);
    $assert($maskedPage['total'] === 2 && $maskedPage['current_page'] === 1,
        'paginated wrapper keys (total/current_page) preserved');
    $assert($maskedPage['data'][0]['value'] === SensitiveData::MASK
        && $maskedPage['data'][0]['value_masked'] === true
        && $maskedPage['data'][0]['value_configured'] === true,
        'paginated sensitive row masked inside data[]');
    $assert($maskedPage['data'][1]['value'] === 'https://example.com'
        && $maskedPage['data'][1]['value_masked'] === false
        && $maskedPage['data'][1]['value_configured'] === true,
        'paginated non-sensitive row keeps plaintext inside data[]');

    // Empty structures stay intact.
    $assert(SensitiveData::maskConfigRows([]) === [], 'empty flat result stays empty');
    $assert(SensitiveData::maskConfigRows(['data' => [], 'total' => 0])['data'] === [],
        'empty paginated data stays empty');

    // ------------------------------------------------------------------
    // 3. Write side: placeholder recognition.
    // ------------------------------------------------------------------
    $assert(SensitiveData::isMaskedPlaceholder('******') === true, "isMaskedPlaceholder('******') === true");
    $assert(SensitiveData::isMaskedPlaceholder('real-secret-pass') === false, 'real password is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder('') === false, 'empty string is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder(null) === false, 'null is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder(0) === false, 'int 0 is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder(['x']) === false, 'array is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder('****** ') === false, 'MASK with trailing space is not a placeholder');
    $assert(SensitiveData::isMaskedPlaceholder('****') === false, 'shorter asterisk run is not a placeholder');

    // ------------------------------------------------------------------
    // 4. Write side: resolveWriteValue keeps existing value on placeholder.
    // ------------------------------------------------------------------
    $assert(SensitiveData::resolveWriteValue('******', 'real-secret') === 'real-secret',
        "resolveWriteValue('******', 'real-secret') keeps existing value");
    $assert(SensitiveData::resolveWriteValue('******', null) === '',
        "resolveWriteValue('******', null) falls back to empty string");
    $assert(SensitiveData::resolveWriteValue('******', '') === '',
        "resolveWriteValue('******', '') keeps empty existing value");

    // ------------------------------------------------------------------
    // 5. Write side: normal edits and explicit clears are unaffected.
    // ------------------------------------------------------------------
    $assert(SensitiveData::resolveWriteValue('new-secret', 'old') === 'new-secret',
        'normal new value overwrites existing');
    $assert(SensitiveData::resolveWriteValue('', 'old') === '',
        "explicit empty string '' still clears the value (not a placeholder)");
    $assert(SensitiveData::resolveWriteValue(null, 'old') === null,
        'null incoming passes through unchanged');
    $assert(SensitiveData::resolveWriteValue('plain', null) === 'plain',
        'non-sensitive normal value unaffected by missing existing');

    // Non-config-shaped arrays inside a row set are left untouched.
    $nested = SensitiveData::maskConfigRows([
        ['id' => 20, 'key' => 'site_name', 'value' => 'x', 'config_select_data' => ['foo' => 'bar']],
    ]);
    $assert($nested[0]['config_select_data'] === ['foo' => 'bar'],
        'nested non-config arrays inside a row are not mutated');

    echo "ALL SECRET-EXPOSURE ASSERTIONS PASSED\n";
}
