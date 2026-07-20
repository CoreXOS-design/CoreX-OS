<?php

namespace App\Services\Security;

use RuntimeException;

/**
 * AT-173 — the one canonical primitive for application-level media encryption at
 * rest. AES-256-GCM (authenticated: tamper is detected, not silently served as
 * garbage). Every reader/writer of client-sensitive files goes through this class
 * so encryption is a single seam, never re-implemented per feature.
 *
 * On-disk envelope (binary):
 *
 *     MAGIC(4 "CXE1") | keyVersion(1) | nonce(12) | tag(16) | ciphertext(...)
 *
 * The MAGIC header lets read paths distinguish an encrypted file from a legacy
 * plaintext one during migration (mixed state is safe): decrypt() of non-envelope
 * bytes returns them unchanged, so a half-migrated store still reads correctly.
 */
class MediaCipher
{
    private const KEY_CURRENT = 1;
    private const KEY_PREVIOUS = 2;
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    private string $magic;
    private string $cipher;

    public function __construct()
    {
        $this->magic = (string) config('media-encryption.magic', 'CXE1');
        $this->cipher = (string) config('media-encryption.cipher', 'aes-256-gcm');
    }

    /** Encryption is active (a key is configured AND the switch is on). */
    public function enabled(): bool
    {
        return (bool) config('media-encryption.enabled') && $this->rawKey(self::KEY_CURRENT) !== null;
    }

    /** True when $bytes carry the CXE envelope header (i.e. are already encrypted). */
    public function isEncrypted(?string $bytes): bool
    {
        return $bytes !== null && strncmp($bytes, $this->magic, strlen($this->magic)) === 0;
    }

    /**
     * Encrypt plaintext into the envelope. Idempotent-safe: bytes already in
     * envelope form are returned unchanged (never double-encrypt).
     */
    public function encrypt(string $plaintext): string
    {
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }
        $key = $this->rawKey(self::KEY_CURRENT)
            ?? throw new RuntimeException('MEDIA_ENCRYPTION_KEY is not configured — cannot encrypt.');

        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, $this->cipher, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('Media encryption failed (openssl_encrypt).');
        }

        return $this->magic . chr(self::KEY_CURRENT) . $nonce . $tag . $ct;
    }

    /**
     * Decrypt envelope bytes back to plaintext. Bytes WITHOUT the envelope header
     * are returned unchanged (legacy plaintext passthrough during migration).
     * A tamper/wrong-key failure throws — never returns wrong bytes silently.
     */
    public function decrypt(?string $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }
        if (! $this->isEncrypted($bytes)) {
            return $bytes; // legacy plaintext
        }

        $offset = strlen($this->magic);
        $keyVersion = ord($bytes[$offset]);
        $offset += 1;
        $nonce = substr($bytes, $offset, self::NONCE_LEN);
        $offset += self::NONCE_LEN;
        $tag = substr($bytes, $offset, self::TAG_LEN);
        $offset += self::TAG_LEN;
        $ct = substr($bytes, $offset);

        $key = $this->rawKey($keyVersion)
            ?? throw new RuntimeException("Media decryption key (version {$keyVersion}) is not configured.");

        $pt = openssl_decrypt($ct, $this->cipher, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            throw new RuntimeException('Media decryption failed — wrong key or tampered ciphertext (GCM tag mismatch).');
        }

        return $pt;
    }

    /** Prove a round-trip for a given plaintext without persisting anything. */
    public function roundTrips(string $plaintext): bool
    {
        try {
            return $this->decrypt($this->encrypt($plaintext)) === $plaintext;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Resolve the 32-byte raw key for a version, or null if not configured. */
    private function rawKey(int $version): ?string
    {
        $configured = $version === self::KEY_PREVIOUS
            ? config('media-encryption.previous_key')
            : config('media-encryption.key');

        if (! $configured) {
            return null;
        }

        $raw = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('MEDIA_ENCRYPTION_KEY must decode to exactly 32 bytes (256-bit).');
        }

        return $raw;
    }
}
