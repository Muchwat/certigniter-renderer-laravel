<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\Encryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncryptionTest extends TestCase
{
    private const KEY = 'nA9bC5eD1fG7hJ3kL2pQ8rT6vW0yZ4xU';

    public function test_encrypt_then_decrypt_round_trips(): void
    {
        $plaintext = (string) json_encode(['title' => 'Round trip test']);

        $envelope = Encryption::encrypt($plaintext, self::KEY);

        $this->assertSame($plaintext, Encryption::decrypt($envelope, self::KEY));
    }

    public function test_the_envelope_shape_is_iv_and_value_base64(): void
    {
        $envelope = json_decode(Encryption::encrypt('hello', self::KEY), true);

        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('value', $envelope);
        $this->assertNotFalse(base64_decode($envelope['iv'], true));
        $this->assertNotFalse(base64_decode($envelope['value'], true));
    }

    public function test_decrypt_throws_a_clear_message_when_the_key_is_wrong(): void
    {
        $envelope = Encryption::encrypt('hello', self::KEY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the one used to save this file');

        Encryption::decrypt($envelope, 'a-completely-different-32-byte-key');
    }

    public function test_decrypt_throws_on_malformed_envelope_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not a valid .igniter manifest');

        Encryption::decrypt('{"not":"an envelope"}', self::KEY);
    }

    public function test_decrypt_throws_on_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);

        Encryption::decrypt('this is not json at all', self::KEY);
    }
}
