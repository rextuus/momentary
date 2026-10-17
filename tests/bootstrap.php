<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

// OAuth2-Schlüsselpaar nur für Tests, damit keine echten Keys nötig sind
$oauthKeyDir = dirname(__DIR__) . '/var/test-oauth2';
if (!is_file($oauthKeyDir . '/private.key')) {
    if (!is_dir($oauthKeyDir)) {
        mkdir($oauthKeyDir, 0o700, true);
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    file_put_contents($oauthKeyDir . '/private.key', $privateKey);
    file_put_contents($oauthKeyDir . '/public.key', openssl_pkey_get_details($key)['key']);
    chmod($oauthKeyDir . '/private.key', 0o600);
}
