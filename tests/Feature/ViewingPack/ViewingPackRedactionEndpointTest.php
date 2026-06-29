<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AT-110 Bug 2 — the redaction tool shipped with ZERO controller coverage (only
 * the flatten SERVICE had tests), so a browser-dead tool went unnoticed. This
 * test locks the HTTP contract the on-screen tool depends on end-to-end:
 *
 *   GET  …/redaction-data → JSON page previews (what the tool draws on)
 *   POST …/redact (boxes) → flattened artifact persisted + text layer destroyed
 *
 * It is the closest the stack supports to a browser test without Dusk. If the
 * preview endpoint or the redact endpoint regresses, this fails — the tool can
 * no longer be silently broken on the server side.
 */
final class ViewingPackRedactionEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_redaction_data_returns_previews_and_redact_flattens_and_destroys_text(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // --- agency + acting admin -------------------------------------------------
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'admin']);

        // --- a real PDF on the source disk (the redaction service rasterizes this) --
        $fixture = base_path('tests/Fixtures/viewing_pack/one_page_text.pdf');
        $this->assertFileExists($fixture, 'Fixture PDF must exist for the redaction test.');
        $storagePath = 'properties/test/files/rates.pdf';
        Storage::disk('public')->put($storagePath, (string) file_get_contents($fixture));

        $docTypeId = (int) DB::table('document_types')->insertGetId([
            'slug' => 'rates-and-taxes', 'label' => 'Rates & Taxes', 'is_active' => 1,
            'buyer_pack_eligible' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'original_name' => 'rates.pdf', 'storage_path' => $storagePath, 'disk' => 'public',
            'mime_type' => 'application/pdf', 'size' => Storage::disk('public')->size($storagePath),
            'document_type_id' => $docTypeId, 'source_type' => 'property', 'source_id' => 0,
            'uploaded_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // --- a property + pack + pack-property + pack-document ----------------------
        $propertyId = (int) DB::table('properties')->insertGetId([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'agent_id' => $user->id,
            'external_id' => 'T-' . Str::random(6), 'title' => '8 Beatty Drive', 'address' => '8 Beatty Drive',
            'suburb' => 'Margate', 'price' => 1500000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $packId = (int) DB::table('viewing_packs')->insertGetId([
            'agency_id' => $agencyId, 'contact_id' => null, 'agent_id' => $user->id,
            'status' => 'draft', 'title' => 'Test pack', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vppId = (int) DB::table('viewing_pack_properties')->insertGetId([
            'agency_id' => $agencyId, 'viewing_pack_id' => $packId, 'property_id' => $propertyId,
            'sort_order' => 1, 'source' => 'ad_hoc', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vpdId = (int) DB::table('viewing_pack_documents')->insertGetId([
            'agency_id' => $agencyId, 'viewing_pack_property_id' => $vppId, 'document_id' => $documentId,
            'document_type_slug' => 'rates-and-taxes', 'redacted_file_path' => null, 'included' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user);

        // --- 1) preview endpoint returns drawable page images ----------------------
        $preview = $this->getJson(route('corex.viewing-packs.properties.documents.redaction-data', [$packId, $vppId, $vpdId]));
        $preview->assertOk();
        $this->assertNotEmpty($preview->json('pages'), 'Preview must return at least one page.');
        $this->assertStringStartsWith('data:image/png;base64,', $preview->json('pages.0.data_uri'));
        $this->assertGreaterThan(0, $preview->json('pages.0.width'));

        // --- 2) redact endpoint burns boxes, flattens, persists --------------------
        $redact = $this->postJson(
            route('corex.viewing-packs.properties.documents.redact', [$packId, $vppId, $vpdId]),
            ['boxes' => [0 => [0 => ['x' => 100, 'y' => 120, 'w' => 300, 'h' => 40]]]],
        );
        $redact->assertOk();
        $redact->assertJson(['ok' => true]);

        $rel = DB::table('viewing_pack_documents')->where('id', $vpdId)->value('redacted_file_path');
        $this->assertNotNull($rel, 'redacted_file_path must be set after redaction.');
        $this->assertTrue(Storage::disk('local')->exists($rel), 'Flattened artifact must exist on disk.');

        // --- 3) POPIA: the flattened artifact has NO recoverable text layer --------
        $abs = Storage::disk('local')->path($rel);
        $proc = new Process(['pdftotext', $abs, '-']);
        $proc->run();
        $text = $proc->isSuccessful() ? preg_replace('/[^A-Za-z0-9]/', '', (string) $proc->getOutput()) : '';
        $this->assertSame('', $text, 'Redacted artifact must contain no recoverable text (flatten/rasterize).');
    }
}
