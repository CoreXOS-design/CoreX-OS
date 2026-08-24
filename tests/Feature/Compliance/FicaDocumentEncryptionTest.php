<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\FicaDocument;
use App\Services\Compliance\FicaDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-173 — FICA client documents encrypted at rest.
 * Proves: uploads land as ciphertext on the PRIVATE disk (never public), decrypt on
 * read, legacy public/plaintext files still read during migration, and the backfill
 * relocates public → private + encrypts, round-trip verified, dropping the public copy.
 */
final class FicaDocumentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('media-encryption.key', 'base64:' . base64_encode(random_bytes(32)));
        config()->set('media-encryption.enabled', true);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_uploaded_fica_doc_is_encrypted_on_the_private_disk(): void
    {
        $storage = app(FicaDocumentStorage::class);
        $bytes = "PDF-ID-COPY\x00\x01\xff" . random_bytes(256);
        $file = UploadedFile::fake()->createWithContent('id_copy.pdf', $bytes);

        $path = $storage->putUploaded($file, 'fica/55');

        $raw = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('CXE1', $raw, 'must be ciphertext on the private disk');
        $this->assertStringNotContainsString('PDF-ID-COPY', $raw);
        $this->assertFalse(Storage::disk('public')->exists($path), 'must NOT be on the public disk');

        $doc = new FicaDocument(['file_path' => $path, 'file_name' => 'id_copy.pdf', 'mime_type' => 'application/pdf']);
        $this->assertSame($bytes, $storage->bytes($doc), 'decrypts back to the exact original');
    }

    public function test_legacy_public_plaintext_doc_still_reads(): void
    {
        $storage = app(FicaDocumentStorage::class);
        Storage::disk('public')->put('fica/9/legacy.pdf', 'legacy plaintext POA bytes');

        $doc = new FicaDocument(['file_path' => 'fica/9/legacy.pdf', 'file_name' => 'poa.pdf', 'mime_type' => 'application/pdf']);
        $this->assertSame('legacy plaintext POA bytes', $storage->bytes($doc), 'pre-migration public file still readable');
    }

    public function test_backfill_relocates_public_to_private_and_encrypts(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $user = \App\Models\User::factory()->create(['agency_id' => $agency->id]);
        $sub = \App\Models\FicaSubmission::create([
            'agency_id' => $agency->id, 'requested_by' => $user->id,
            'token' => \Illuminate\Support\Str::random(64), 'token_expires_at' => now()->addDay(),
        ]);

        $files = [
            'fica/1/idcopy.pdf' => 'ID COPY plaintext ' . random_bytes(64),
            'fica/1/poa.pdf' => "POA\x00binary bytes",
        ];
        foreach ($files as $path => $bytes) {
            Storage::disk('public')->put($path, $bytes); // legacy: plaintext on public
            FicaDocument::create([
                'agency_id' => $agency->id, 'fica_submission_id' => $sub->id,
                'document_type' => 'id_copy', 'file_path' => $path,
                'file_name' => basename($path), 'file_size' => strlen($bytes),
                'mime_type' => 'application/pdf', 'status' => 'uploaded',
            ]);
        }

        $this->artisan('media:encrypt-backfill', ['--scope' => 'fica'])->assertExitCode(0);

        $storage = app(FicaDocumentStorage::class);
        foreach ($files as $path => $bytes) {
            $this->assertTrue(Storage::disk('local')->exists($path), "moved to private: {$path}");
            $this->assertStringStartsWith('CXE1', Storage::disk('local')->get($path), 'ciphertext on private');
            $this->assertFalse(Storage::disk('public')->exists($path), 'legacy public copy removed');

            $doc = FicaDocument::where('file_path', $path)->first();
            $this->assertSame($bytes, $storage->bytes($doc), 'round-trips to original bytes');
        }

        // Idempotent second run.
        $this->artisan('media:encrypt-backfill', ['--scope' => 'fica'])
            ->expectsOutputToContain('already-encrypted=2')
            ->assertExitCode(0);
    }

    public function test_filed_fica_document_downloadResponse_decrypts(): void
    {
        // The durable, openable FICA copy in the contact document drive is encrypted;
        // Document::downloadResponse / decryptedContents must return the plaintext.
        $cipher = app(\App\Services\Security\MediaCipher::class);
        $plain = "ID COPY PDF bytes \x00\x01" . random_bytes(128);
        $path = 'contact-documents/16650/' . \Illuminate\Support\Str::uuid() . '.pdf';
        Storage::disk('local')->put($path, $cipher->encrypt($plain));

        $doc = new \App\Models\Document([
            'disk' => 'local', 'storage_path' => $path,
            'original_name' => 'id_copy.pdf', 'mime_type' => 'application/pdf', 'source_type' => 'fica',
        ]);

        $this->assertStringStartsWith('CXE1', Storage::disk('local')->get($path), 'stored encrypted');
        $this->assertSame($plain, $doc->decryptedContents(), 'decryptedContents returns plaintext');

        $resp = $doc->downloadResponse();
        ob_start();
        $resp->sendContent();
        $streamed = ob_get_clean();
        $this->assertSame($plain, $streamed, 'downloadResponse streams the decrypted bytes');
    }

    public function test_backfill_encrypts_filed_fica_documents_in_place(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $paths = [];
        foreach (['id.pdf' => 'ID plaintext ' . random_bytes(40), 'poa.pdf' => "POA\x00bytes"] as $name => $bytes) {
            $p = 'contact-documents/999/' . \Illuminate\Support\Str::uuid() . '.pdf';
            Storage::disk('local')->put($p, $bytes);
            \App\Models\Document::create([
                'agency_id' => $agency->id, 'original_name' => $name, 'storage_path' => $p,
                'disk' => 'local', 'mime_type' => 'application/pdf', 'source_type' => 'fica',
            ]);
            $paths[$p] = $bytes;
        }
        // a NON-fica document must be left untouched (precise scope)
        $otherPath = 'contact-documents/999/' . \Illuminate\Support\Str::uuid() . '.pdf';
        Storage::disk('local')->put($otherPath, 'NOT a fica doc');
        \App\Models\Document::create([
            'agency_id' => $agency->id, 'original_name' => 'brochure.pdf', 'storage_path' => $otherPath,
            'disk' => 'local', 'mime_type' => 'application/pdf', 'source_type' => 'upload',
        ]);

        $this->artisan('media:encrypt-backfill', ['--scope' => 'fica'])->assertExitCode(0);

        $cipher = app(\App\Services\Security\MediaCipher::class);
        foreach ($paths as $p => $bytes) {
            $raw = Storage::disk('local')->get($p);
            $this->assertStringStartsWith('CXE1', $raw, "fica Document encrypted: {$p}");
            $this->assertSame($bytes, $cipher->decrypt($raw), 'round-trips to original');
        }
        // the non-fica doc is still plaintext, untouched
        $this->assertSame('NOT a fica doc', Storage::disk('local')->get($otherPath), 'non-fica doc must NOT be encrypted');
    }
}
