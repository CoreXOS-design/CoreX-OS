<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Communications\CommunicationStorageService;
use App\Services\Security\MediaCipher;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-173 — the retroactive backfill. Proves plaintext media is encrypted in place,
 * round-trips byte-for-byte, is idempotent, and that --dry-run writes nothing.
 */
final class EncryptMediaBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('media-encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        config()->set('media-encryption.enabled', true);
        config()->set('communications.disk', 'local');
        Storage::fake('local');
    }

    private function seedPlaintext(): array
    {
        // Files as they sit pre-migration: plaintext, under communications/.
        $files = [
            'communications/1/whatsapp/aa/hash1' => 'client voice note ONE ' . random_bytes(32),
            'communications/1/attachment/bb/hash2' => "binary\x00\x01\xff id copy",
            'communications/2/email/cc/hash3' => 'an email body',
        ];
        foreach ($files as $path => $bytes) {
            Storage::disk('local')->put($path, $bytes);
        }

        return $files;
    }

    public function test_dry_run_writes_nothing(): void
    {
        $files = $this->seedPlaintext();

        $this->artisan('media:encrypt-backfill', ['--scope' => 'comms', '--dry-run' => true])
            ->assertExitCode(0);

        foreach ($files as $path => $bytes) {
            $this->assertSame($bytes, Storage::disk('local')->get($path), 'dry-run must not modify files');
        }
    }

    public function test_backfill_encrypts_in_place_and_round_trips(): void
    {
        $files = $this->seedPlaintext();
        $cipher = app(MediaCipher::class);
        $svc = app(CommunicationStorageService::class);

        $this->artisan('media:encrypt-backfill', ['--scope' => 'comms'])->assertExitCode(0);

        foreach ($files as $path => $bytes) {
            $raw = Storage::disk('local')->get($path);
            $this->assertTrue($cipher->isEncrypted($raw), "on-disk must be ciphertext: {$path}");
            $this->assertNotSame($bytes, $raw);
            // Round-trips back to the exact original bytes, via the service read path.
            $this->assertSame($bytes, $svc->get($path), "must decrypt to original: {$path}");
        }
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seedPlaintext();

        $this->artisan('media:encrypt-backfill', ['--scope' => 'comms'])->assertExitCode(0);
        // Second run: everything already encrypted → 0 newly encrypted, still exit 0.
        $this->artisan('media:encrypt-backfill', ['--scope' => 'comms'])
            ->expectsOutputToContain('already-encrypted=3')
            ->assertExitCode(0);
    }
}
