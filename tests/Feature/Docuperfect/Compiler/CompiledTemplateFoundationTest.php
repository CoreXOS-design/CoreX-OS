<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler;

use App\Events\Esign\TemplatePublished;
use App\Models\Agency;
use App\Models\Docuperfect\CompiledTemplate;
use App\Models\Docuperfect\DataDictionaryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * WS0 — the immutable versioned artifact model (§5) + Data Dictionary resolution (§2.1).
 * Proves: draft mutability, publish gate on lint, content-hash pin, versioning + supersede,
 * published-row immutability, field-binding index sync, agency-override + point-in-time
 * dictionary resolution.
 */
final class CompiledTemplateFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function structure(string $binding = 'purchase_price'): array
    {
        return [
            'family' => '116',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1],
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 2],
            ],
            'blocks' => [
                ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'only', 'party_keys' => ['agent']], 'condition' => ['kind' => 'always'],
                    'fields' => [['field_id' => 'f1', 'label' => 'Purchase Price', 'binding' => $binding, 'source' => 'agent_input', 'required' => true]]],
                ['block_id' => 'sig', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'],
                    'anchors' => [['anchor_id' => 'a1', 'kind' => 'signature', 'party_key' => 'seller']]],
            ],
            'assets' => [],
        ];
    }

    private function draft(array $overrides = []): CompiledTemplate
    {
        return CompiledTemplate::create(array_merge([
            'agency_id' => null,
            'family' => '116',
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'structure' => $this->structure(),
            'lint_status' => CompiledTemplate::LINT_PASSED,
        ], $overrides));
    }

    public function test_draft_is_mutable_and_exposes_typed_cds(): void
    {
        $draft = $this->draft();

        $this->assertSame(CompiledTemplate::STATUS_DRAFT, $draft->status);
        $this->assertSame('116', $draft->cds()->family);

        $draft->update(['family' => '116-rev']); // drafts freely editable
        $this->assertSame('116-rev', $draft->fresh()->family);
    }

    public function test_publish_requires_lint_passed(): void
    {
        $draft = $this->draft(['lint_status' => CompiledTemplate::LINT_PENDING]);

        $this->expectException(RuntimeException::class);
        $draft->publishAsNewVersion();
    }

    public function test_publish_stamps_hash_version_and_syncs_bindings_and_emits_event(): void
    {
        Event::fake([TemplatePublished::class]);
        $draft = $this->draft();

        $published = $draft->publishAsNewVersion();

        $this->assertSame(CompiledTemplate::STATUS_PUBLISHED, $published->status);
        $this->assertSame(1, $published->version);
        $this->assertSame($draft->cds()->contentHash(), $published->content_hash);
        $this->assertNotNull($published->published_at);

        // Field-binding index rebuilt from the CDS structure.
        $this->assertDatabaseHas('compiled_template_field_bindings', [
            'compiled_template_id' => $published->id,
            'block_id' => 'fg',
            'field_id' => 'f1',
            'dictionary_key' => 'purchase_price',
        ]);

        Event::assertDispatched(TemplatePublished::class);
    }

    public function test_second_publish_creates_version_2_and_supersedes_version_1(): void
    {
        $v1 = $this->draft()->publishAsNewVersion();

        // A new draft with DIFFERENT content (else duplicate-hash guard trips).
        $v2 = $this->draft(['structure' => $this->structure('deposit')])->publishAsNewVersion();

        $this->assertSame(2, $v2->version);
        $this->assertSame(CompiledTemplate::STATUS_SUPERSEDED, $v1->fresh()->status);
        $this->assertSame($v2->id, $v1->fresh()->superseded_by_id);
    }

    public function test_published_row_is_immutable(): void
    {
        $published = $this->draft()->publishAsNewVersion();

        $this->expectException(RuntimeException::class);
        $published->update(['structure' => $this->structure('deposit')]);
    }

    public function test_published_row_may_transition_to_superseded(): void
    {
        $published = $this->draft()->publishAsNewVersion();

        $published->update([
            'status' => CompiledTemplate::STATUS_SUPERSEDED,
            'superseded_by_id' => null,
        ]);

        $this->assertSame(CompiledTemplate::STATUS_SUPERSEDED, $published->fresh()->status);
    }

    public function test_duplicate_content_cannot_be_published(): void
    {
        $this->draft()->publishAsNewVersion();

        $this->expectException(RuntimeException::class);
        $this->draft()->publishAsNewVersion(); // identical structure → identical hash
    }

    public function test_for_agency_scope_returns_standard_plus_own(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);

        $this->draft(['agency_id' => null]);                 // CoreX standard
        $this->draft(['agency_id' => $agencyA->id, 'family' => 'A']);
        $this->draft(['agency_id' => $agencyB->id, 'family' => 'B']);

        $forA = CompiledTemplate::forAgency($agencyA->id)->get();
        $this->assertCount(2, $forA); // standard + own, not B's
        $this->assertTrue($forA->pluck('agency_id')->contains(null));
        $this->assertFalse($forA->pluck('agency_id')->contains($agencyB->id));
    }

    // ── Data Dictionary resolution ────────────────────────────────────────────

    public function test_resolution_prefers_agency_override_then_falls_back_to_standard(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);

        DataDictionaryEntry::create(['agency_id' => null, 'key' => 'purchase_price', 'version' => 1, 'category' => 'money', 'label' => 'Purchase Price', 'data_type' => 'zar_money', 'is_active' => true]);
        DataDictionaryEntry::create(['agency_id' => $agency->id, 'key' => 'purchase_price', 'version' => 1, 'category' => 'money', 'label' => 'Koopprys', 'data_type' => 'zar_money', 'is_active' => true]);
        DataDictionaryEntry::create(['agency_id' => null, 'key' => 'deposit', 'version' => 1, 'category' => 'money', 'label' => 'Deposit', 'data_type' => 'zar_money', 'is_active' => true]);

        // Override wins for the agency.
        $this->assertSame('Koopprys', DataDictionaryEntry::resolve('purchase_price', $agency->id)->label);
        // Standard for an agency without an override.
        $this->assertSame('Purchase Price', DataDictionaryEntry::resolve('purchase_price', null)->label);
        // Falls back to standard when the agency has no override for that key.
        $this->assertSame('Deposit', DataDictionaryEntry::resolve('deposit', $agency->id)->label);
    }

    public function test_resolution_is_point_in_time_by_version(): void
    {
        DataDictionaryEntry::create(['agency_id' => null, 'key' => 'commission_incl_vat', 'version' => 1, 'category' => 'money', 'label' => 'Commission v1', 'data_type' => 'zar_money', 'is_active' => true]);
        DataDictionaryEntry::create(['agency_id' => null, 'key' => 'commission_incl_vat', 'version' => 2, 'category' => 'money', 'label' => 'Commission v2', 'data_type' => 'zar_money', 'is_active' => true]);

        // Pinning version 1 must resolve the v1 entry (a later change never alters a pinned template).
        $this->assertSame('Commission v1', DataDictionaryEntry::resolve('commission_incl_vat', null, 1)->label);
        // Unpinned resolves latest.
        $this->assertSame('Commission v2', DataDictionaryEntry::resolve('commission_incl_vat', null)->label);
        $this->assertSame(2, DataDictionaryEntry::currentVersion());
    }
}
