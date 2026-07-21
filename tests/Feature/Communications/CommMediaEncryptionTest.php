<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Services\Communications\CommunicationStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-173 — communication media encrypted at rest through the one storage seam.
 * Proves the acceptance chain: write → ON-DISK BYTES ARE CIPHERTEXT → read returns
 * the exact plaintext → dedup/content_hash unchanged → legacy plaintext still reads.
 */
final class CommMediaEncryptionTest extends TestCase
{
    private function enableEncryption(): void
    {
        config()->set('media-encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        config()->set('media-encryption.enabled', true);
        config()->set('communications.disk', 'local');
    }

    public function test_stored_media_is_ciphertext_on_disk_but_reads_back_as_plaintext(): void
    {
        $this->enableEncryption();
        Storage::fake('local');
        $svc = app(CommunicationStorageService::class);

        $plain = 'CLIENT voice note ' . random_bytes(64);
        $res = $svc->store(7, 'whatsapp', $plain);

        // On disk = ciphertext envelope, NOT the plaintext.
        $raw = Storage::disk('local')->get($res['path']);
        $this->assertStringStartsWith('CXE1', $raw, 'on-disk bytes must be the encrypted envelope');
        $this->assertNotSame($plain, $raw);
        $this->assertStringNotContainsString('CLIENT voice note', $raw, 'plaintext must not be readable on disk');

        // content_hash is the PLAINTEXT hash (dedup + integrity unchanged).
        $this->assertSame(hash('sha256', $plain), $res['content_hash']);

        // The service decrypts transparently on read → exact plaintext back.
        $this->assertSame($plain, $svc->get($res['path']));
    }

    public function test_dedup_still_holds_under_encryption(): void
    {
        $this->enableEncryption();
        Storage::fake('local');
        $svc = app(CommunicationStorageService::class);

        $plain = 'same bytes';
        $a = $svc->store(7, 'whatsapp', $plain);
        $b = $svc->store(7, 'whatsapp', $plain);

        $this->assertSame($a['path'], $b['path'], 'identical plaintext dedups to one path');
        $this->assertSame($plain, $svc->get($a['path']));
    }

    public function test_encryption_off_stores_plaintext(): void
    {
        config()->set('media-encryption.enabled', false);
        config()->set('communications.disk', 'local');
        Storage::fake('local');
        $svc = app(CommunicationStorageService::class);

        $plain = 'plain when disabled';
        $res = $svc->store(7, 'whatsapp', $plain);

        $this->assertSame($plain, Storage::disk('local')->get($res['path']));
        $this->assertSame($plain, $svc->get($res['path']));
    }

    public function test_legacy_plaintext_file_still_reads_after_encryption_enabled(): void
    {
        $this->enableEncryption();
        Storage::fake('local');
        $svc = app(CommunicationStorageService::class);

        // A file written BEFORE encryption (plaintext) at a plausible path.
        $path = 'communications/7/whatsapp/ab/legacyhash';
        Storage::disk('local')->put($path, 'legacy plaintext note');

        $this->assertSame('legacy plaintext note', $svc->get($path), 'pre-migration files must still read');
    }
}
