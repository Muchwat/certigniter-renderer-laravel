<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use RuntimeException;

/**
 * AES-256-CBC (PKCS#7 padding) encryption for the .igniter manifest. The
 * envelope is JSON, `{"iv": base64, "value": base64}`. The key is used
 * as its raw UTF-8 bytes rather than derived through a KDF, so it must be
 * exactly 32 bytes long to select AES-256.
 *
 * This protects the `manifest.json` member inside a `.igniter` package, not
 * the package as a whole - images and fonts sit alongside it as plain
 * compressed archive members. See IgniterPackage.
 */
class Encryption
{
    private const CIPHER = 'aes-256-cbc';

    public static function decrypt(string $encryptedJson, string $key): string
    {
        $envelope = json_decode($encryptedJson, true);

        if (! is_array($envelope) || ! isset($envelope['iv'], $envelope['value'])) {
            throw new RuntimeException('Not a valid .igniter manifest: expected {"iv": ..., "value": ...} JSON.');
        }

        $iv = base64_decode($envelope['iv'], true);
        $ciphertext = base64_decode($envelope['value'], true);

        if ($iv === false || $ciphertext === false) {
            throw new RuntimeException('Not a valid .igniter manifest: iv/value are not valid base64.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException(
                'Failed to decrypt the .igniter manifest - the encryption key does not match the one used to '
                .'save this file (config("certigniter.encryption_key") / CERTIGNITER_ENCRYPTION_KEY).'
            );
        }

        return $plaintext;
    }

    public static function encrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        $envelope = json_encode([
            'iv' => base64_encode($iv),
            'value' => base64_encode($ciphertext),
        ]);

        if ($envelope === false) {
            throw new RuntimeException('Could not encode the encrypted .igniter manifest envelope as JSON.');
        }

        return $envelope;
    }
}
