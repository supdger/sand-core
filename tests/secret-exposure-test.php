<?php

declare(strict_types=1);

/**
 * Secret-exposure regression assertions (development-only; never shipped).
 *
 *   php tests/secret-exposure-test.php
 *
 * Covers the GET /core/config/index hardening contract:
 *   1. Read side: sensitive config keys (cloud storage secrets, SMTP password,
 *      s3_key, SMTP Username, etc.) are masked to '******' in list output, with
 *      value_masked / value_configured flags; both paginated and saiType=all
 *      flat structures. The same config-key scope masks soft-deleted
 *      sand_system_config rows in the recycle bin.
 *   2. Write side: the '******' placeholder means "keep existing value" and
 *      must never be persisted; empty string '' stays a real "clear value".
 *   3. Log side: maskParams masks {key,value} config pairs (batchUpdate
 *      config[].value, save/update top-level key/value) so oper/exception
 *      logs never persist newly entered secrets; the widened config-key scope
 *      (username, *_key) stays confined to config paths and does not leak
 *      into the global request-param/column criteria.
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

    // ------------------------------------------------------------------
    // 6. Config-key scope: storage/mail credential keys (s3_key, Username,
    //    *_key, username 形态) are masked only on config paths.
    // ------------------------------------------------------------------
    foreach (['s3_key', 'Username', 'smtp_username', 'user_name', 'api_key', 'app_key', 'access_key', 'public_key', 'key', 'Password', 's3_secret', 'oss_accessKeyId', 'qiniu_secretKey', 'cos_secretId'] as $ck) {
        $assert(SensitiveData::isSensitiveConfigKey($ck) === true, "isSensitiveConfigKey('{$ck}') === true");
    }
    foreach (['site_name', 's3_bucket', 's3_domain', 's3_region', 's3_endpoint', 'FromName', 'Host', 'Port', 'CharSet', 'upload_size', 'local_root', 'user', 'monkey', ''] as $ck) {
        $assert(SensitiveData::isSensitiveConfigKey($ck) === false, "isSensitiveConfigKey('{$ck}') === false");
    }

    // Global criteria must NOT widen: keys newly covered only by the config
    // scope (s3_key, Username, app_key, public_key ...) stay non-sensitive for
    // plain request params and table columns outside config paths.
    // (api_key/access_key/private_key already hit global apikey/accesskey/
    // privatekey fragments — pre-existing, asserted for regression.)
    foreach (['s3_key', 'Username', 'app_key', 'public_key', 'encryption_key', 'username'] as $gk) {
        $assert(SensitiveData::isSensitiveKey($gk) === false, "isSensitiveKey('{$gk}') stays false (global scope unchanged)");
    }
    foreach (['api_key', 'access_key', 'private_key'] as $gk) {
        $assert(SensitiveData::isSensitiveKey($gk) === true, "isSensitiveKey('{$gk}') already true via global fragments");
    }
    $assert(SensitiveData::isSensitiveColumn('username') === false, "isSensitiveColumn('username') stays false (columns unaffected)");

    // maskConfigRows picks up the widened config-key scope (list + recycle bin share it).
    $widened = SensitiveData::maskConfigRows([
        ['id' => 30, 'key' => 's3_key', 'value' => 'AKIA-FAKE-EXAMPLE', 'name' => 'S3 key'],
        ['id' => 31, 'key' => 'Username', 'value' => 'smtp-user@example.com', 'name' => 'SMTP用户名'],
        ['id' => 32, 'key' => 's3_bucket', 'value' => 'my-bucket', 'name' => 'S3 bucket'],
        ['id' => 33, 'key' => 'FromName', 'value' => '账户注册', 'name' => '发件名称'],
        ['id' => 34, 'key' => 'Username', 'value' => '', 'name' => 'SMTP用户名(空)'],
    ]);
    $assert($widened[0]['value'] === SensitiveData::MASK && $widened[0]['value_masked'] === true,
        'config row s3_key masked');
    $assert($widened[1]['value'] === SensitiveData::MASK && $widened[1]['value_masked'] === true,
        'config row Username masked');
    $assert($widened[2]['value'] === 'my-bucket' && $widened[2]['value_masked'] === false,
        'config row s3_bucket keeps plaintext');
    $assert($widened[3]['value'] === '账户注册' && $widened[3]['value_masked'] === false,
        'config row FromName keeps plaintext');
    $assert($widened[4]['value'] === '' && $widened[4]['value_masked'] === false && $widened[4]['value_configured'] === false,
        'empty Username value not masked, configured=false');

    // Recycle-bin shaped page (paginate()->toArray() with data[]) uses the same scope.
    $recyclePage = [
        'total' => 2,
        'per_page' => 10,
        'current_page' => 1,
        'last_page' => 1,
        'data' => [
            ['id' => 40, 'key' => 's3_secret', 'value' => 'deleted-s3-secret', 'delete_time' => '2026-01-02 00:00:00'],
            ['id' => 41, 'key' => 'site_name', 'value' => 'SandAdmin', 'delete_time' => '2026-01-02 00:00:00'],
        ],
    ];
    $maskedRecycle = SensitiveData::maskConfigRows($recyclePage);
    $assert($maskedRecycle['data'][0]['value'] === SensitiveData::MASK
        && $maskedRecycle['data'][0]['value_masked'] === true,
        'recycle-bin config row s3_secret masked inside data[]');
    $assert($maskedRecycle['data'][1]['value'] === 'SandAdmin'
        && $maskedRecycle['data'][1]['value_masked'] === false,
        'recycle-bin non-sensitive row keeps plaintext inside data[]');
    $assert($maskedRecycle['data'][0]['delete_time'] === '2026-01-02 00:00:00',
        'recycle-bin non-value columns preserved');

    // ------------------------------------------------------------------
    // 7. Log side: maskParams masks {key,value} config pairs so oper log
    //    request_data and exception-log request_param never persist secrets.
    // ------------------------------------------------------------------
    // batchUpdate shape: secrets nested under config[].value.
    $batchParams = [
        'group_id' => 2,
        'config' => [
            ['id' => 1, 'name' => 'S3 key', 'key' => 's3_key', 'value' => 'AKIA-FAKE-EXAMPLE'],
            ['id' => 2, 'name' => '站点名称', 'key' => 'site_name', 'value' => 'SandAdmin'],
            ['id' => 3, 'name' => 'SMTP用户名', 'key' => 'Username', 'value' => 'smtp-user@example.com'],
            ['id' => 4, 'name' => 'SMTP密码', 'key' => 'Password', 'value' => 'smtp-pass-fake'],
            ['id' => 5, 'name' => 'S3密钥', 'key' => 's3_secret', 'value' => SensitiveData::MASK],
        ],
    ];
    $maskedBatch = SensitiveData::maskParams($batchParams);
    $assert($maskedBatch['group_id'] === 2, 'batchUpdate group_id preserved');
    $assert($maskedBatch['config'][0]['value'] === SensitiveData::MASK
        && $maskedBatch['config'][0]['key'] === 's3_key',
        'batchUpdate config[0] s3_key value masked, key name preserved');
    $assert($maskedBatch['config'][1]['value'] === 'SandAdmin',
        'batchUpdate config[1] non-sensitive site_name value kept');
    $assert($maskedBatch['config'][2]['value'] === SensitiveData::MASK
        && $maskedBatch['config'][2]['key'] === 'Username',
        'batchUpdate config[2] Username value masked');
    $assert($maskedBatch['config'][3]['value'] === SensitiveData::MASK,
        'batchUpdate config[3] Password value masked');
    $assert($maskedBatch['config'][4]['value'] === SensitiveData::MASK,
        'batchUpdate config[4] placeholder ****** stays ******');

    // save/update single-config shape: top-level key/value pair.
    $single = SensitiveData::maskParams([
        'id' => 7, 'group_id' => 3, 'name' => 'SMTP密码', 'key' => 'Password',
        'value' => 'smtp-pass-fake', 'input_type' => 'input',
    ]);
    $assert($single['value'] === SensitiveData::MASK && $single['key'] === 'Password' && $single['name'] === 'SMTP密码',
        'single config save/update params masked, other fields preserved');

    // Non-sensitive {key,value} pairs and partial pairs are untouched.
    $plainPair = SensitiveData::maskParams(['key' => 'site_name', 'value' => 'SandAdmin']);
    $assert($plainPair['value'] === 'SandAdmin', 'non-sensitive {key,value} pair value kept');
    $noValue = SensitiveData::maskParams(['key' => 's3_key', 'name' => 'x']);
    $assert($noValue['key'] === 's3_key' && !array_key_exists('value', $noValue),
        'pair without value field untouched');
    $keyNotScalar = SensitiveData::maskParams(['key' => ['nested' => 's3_key'], 'value' => 'v']);
    $assert($keyNotScalar['value'] === 'v', 'non-scalar key field does not trigger pair masking');

    // Deep nesting still reaches config pairs.
    $deep = SensitiveData::maskParams(['a' => ['b' => ['config' => [
        ['key' => 'api_key', 'value' => 'fake-api-key'],
    ]]]]);
    $assert($deep['a']['b']['config'][0]['value'] === SensitiveData::MASK,
        'deeply nested config pair masked');

    // Global key-name masking unchanged; plain username param NOT masked.
    $regress = SensitiveData::maskParams([
        'password' => 'p', 'username' => 'admin', 'token' => 't',
        'page' => ['key' => 's3_key'],
    ]);
    $assert($regress['password'] === SensitiveData::MASK && $regress['token'] === SensitiveData::MASK,
        'key-name masking (password/token) unchanged');
    $assert($regress['username'] === 'admin', 'plain username param not masked (no global widening)');
    $assert($regress['page']['key'] === 's3_key', 'bare key field without value sibling kept');

    echo "ALL SECRET-EXPOSURE ASSERTIONS PASSED\n";
}
