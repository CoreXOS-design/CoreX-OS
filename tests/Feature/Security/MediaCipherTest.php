<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Security\MediaCipher;
use Tests\TestCase;

/**
 * AT-173 — the crypto core. Proves the properties every other seam relies on:
 * exact round-trip (incl. binary), ciphertext ≠ plaintext, authenticated tamper
 * rejection, legacy-plaintext passthrough, and idempotent double-encrypt.
 */
final class MediaCipherTest extends TestCase
{
    private function cipher(): MediaCipher
    {
        config()->set('media-encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        config()->set('media-encryption.enabled', true);

        return new MediaCipher();
    }

    public function test_round_trips_text_and_binary_exactly(): void
    {
        $cipher = $this->cipher();

        foreach (['hello world', '', str_repeat('A', 100000), random_bytes(4096), "\x00\x01\x02binary\xff"] as $plain) {
            $env = $cipher->encrypt($plain);
            $this->assertSame($plain, $cipher->decrypt($env), 'decrypt(encrypt(x)) must equal x, byte-for-byte');
        }
    }

    public function test_ciphertext_is_not_the_plaintext_and_is_enveloped(): void
    {
        $cipher = $this->cipher();
        $plain = 'SENSITIVE client ID document bytes';
        $env = $cipher->encrypt($plain);

        $this->assertStringStartsWith('CXE1', $env);
        $this->assertStringNotContainsString('SENSITIVE', $env, 'plaintext must not survive in ciphertext');
        $this->assertTrue($cipher->isEncrypted($env));
        $this->assertFalse($cipher->isEncrypted($plain));
    }

    public function test_legacy_plaintext_passes_through_on_decrypt(): void
    {
        $cipher = $this->cipher();
        // A pre-migration plaintext file has no envelope → returned unchanged.
        $this->assertSame('plain legacy bytes', $cipher->decrypt('plain legacy bytes'));
        $this->assertNull($cipher->decrypt(null));
    }

    public function test_double_encrypt_is_idempotent(): void
    {
        $cipher = $this->cipher();
        $once = $cipher->encrypt('x');
        $twice = $cipher->encrypt($once);
        $this->assertSame($once, $twice, 'already-enveloped bytes must not be re-encrypted');
    }

    public function test_tampered_ciphertext_is_rejected_not_silently_wrong(): void
    {
        $cipher = $this->cipher();
        $env = $cipher->encrypt('the original truth');
        $tampered = substr($env, 0, -1) . chr(ord($env[-1]) ^ 0x01); // flip last byte

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $cipher = $this->cipher();
        $env = $cipher->encrypt('secret');

        // rotate to a different current key with no previous → cannot decrypt v1
        config()->set('media-encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        config()->set('media-encryption.previous_key', null);

        $this->expectException(\RuntimeException::class);
        (new MediaCipher())->decrypt($env);
    }

    public function test_round_trips_helper(): void
    {
        $this->assertTrue($this->cipher()->roundTrips(random_bytes(2048)));
    }
}
