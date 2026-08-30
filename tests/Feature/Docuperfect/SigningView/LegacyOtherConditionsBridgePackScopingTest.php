<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use App\Services\Docuperfect\LegacyOtherConditionsBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cc2, 2026-08-30 — the plain-text ("other_conditions_text") sync path
 * (syncToStructuredRows()) had no pack scoping at all: it always resolved
 * the BARE `other_conditions` block id via resolveOtherConditionsBlockId(),
 * unlike its sibling syncFramesToStructuredRows() which already routes
 * through resolvePackDocKeys()/blockIdForFrame(). On a real multi-document
 * pack the renderer looks conditions up by the SCOPED id
 * (`other_conditions__<docKey>`, taken from the actual merged_html) — the
 * mismatch meant a condition an agent typed into the plain textarea (or any
 * caller posting other_conditions_text rather than frames) landed under a
 * block id nothing renders, and the finished, signed, initialled document
 * printed "No conditions yet." Confirmed against real evidence: Staging
 * document 568 (a genuine 2-template pack) had exactly this shape —
 * DocumentCondition.block_id = "other_conditions" (bare) while the rendered
 * block's own data-block-id was "other_conditions__ycjhkgqenq" (scoped).
 *
 * The fix routes syncToStructuredRows() through the SAME resolver frames
 * already use (blockIdForFrame(null, resolvePackDocKeys($doc), ...)) —
 * untagged text defaults to the pack's first document, matching
 * blockIdForFrame()'s own "untagged frame -> first document" convention.
 * Single documents are unaffected: resolvePackDocKeys() returns [] for
 * them, so the call collapses back to the bare id exactly as before.
 */
final class LegacyOtherConditionsBridgePackScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pack_document_condition_text_renders_in_the_finished_document(): void
    {
        [$sigTpl] = $this->makePackTemplate();

        $sigTpl->update(['other_conditions_text' => 'Seller to leave the built-in braai.']);
        $written = app(LegacyOtherConditionsBridge::class)->syncToStructuredRows($sigTpl);
        $this->assertSame(1, $written);

        // Stored under the SCOPED block id for the pack's first document, not the bare one.
        $row = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->firstOrFail();
        $this->assertSame('other_conditions__kmandate', $row->block_id);

        // The finished document actually shows it — this is what cc1's evidence found missing.
        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            $this->packHtml(),
            $sigTpl->fresh('document'),
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
        $mandateBlock = $this->extractBlock($rendered, 'other_conditions__kmandate');
        $this->assertStringContainsString('Seller to leave the built-in braai.', $mandateBlock);
        $this->assertStringNotContainsString('No conditions yet', $mandateBlock);
        // And it never bleeds onto the pack's other document.
        $discBlock = $this->extractBlock($rendered, 'other_conditions__kdisc');
        $this->assertStringNotContainsString('braai', $discBlock);
    }

    public function test_single_document_condition_text_still_renders_unchanged(): void
    {
        // Regression guard — the single-document path must be byte-for-byte
        // unaffected by routing through the pack resolver.
        [$sigTpl] = $this->makeTemplate(); // no merged_html -> resolvePackDocKeys() returns []

        $sigTpl->update(['other_conditions_text' => 'Legacy single-doc condition text.']);
        $written = app(LegacyOtherConditionsBridge::class)->syncToStructuredRows($sigTpl);
        $this->assertSame(1, $written);

        $row = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->firstOrFail();
        $this->assertSame('other_conditions', $row->block_id, 'single document keeps the bare block id');

        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>',
            $sigTpl->fresh('document'),
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
        $this->assertStringContainsString('Legacy single-doc condition text.', $rendered);
        $this->assertStringNotContainsString('No conditions yet', $rendered);
    }

    public function test_pack_document_with_no_conditions_renders_clean_empty_state(): void
    {
        [$sigTpl] = $this->makePackTemplate();
        // No other_conditions_text set at all.

        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            $this->packHtml(),
            $sigTpl->fresh('document'),
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );

        $mandateBlock = $this->extractBlock($rendered, 'other_conditions__kmandate');
        $discBlock = $this->extractBlock($rendered, 'other_conditions__kdisc');
        $this->assertStringContainsString('No conditions yet', $mandateBlock);
        $this->assertStringContainsString('No conditions yet', $discBlock);
        // No stray markup — the legacy flat-text fallback must not fire for a
        // scoped block even when empty (per the existing, unchanged rule).
        $this->assertStringNotContainsString('conditions-legacy-text', $mandateBlock);
        $this->assertStringNotContainsString('conditions-legacy-text', $discBlock);
    }

    public function test_pack_document_two_conditions_both_present(): void
    {
        [$sigTpl] = $this->makePackTemplate();

        // syncToStructuredRows() splits on blank lines -> two structured rows
        // from one flat textarea, both on the same (untagged -> first) document.
        $sigTpl->update(['other_conditions_text' => "Seller to leave the built-in braai.\n\nSeller to leave the pool pump."]);
        $written = app(LegacyOtherConditionsBridge::class)->syncToStructuredRows($sigTpl);
        $this->assertSame(2, $written);

        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            $this->packHtml(),
            $sigTpl->fresh('document'),
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
        $mandateBlock = $this->extractBlock($rendered, 'other_conditions__kmandate');
        $this->assertStringContainsString('Seller to leave the built-in braai.', $mandateBlock);
        $this->assertStringContainsString('Seller to leave the pool pump.', $mandateBlock);
        $this->assertSame(2, substr_count($mandateBlock, 'class="condition-row"'));
    }

    /** Extract one insertable-block's own HTML by its data-block-id, via DOM (not substring search, which would also match the OTHER block). */
    private function extractBlock(string $rendered, string $blockId): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $rendered . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $nodes = $xp->query('//*[@data-block-id="' . $blockId . '"]');
        $this->assertGreaterThan(0, $nodes->length, "block {$blockId} not found in rendered output");

        return $dom->saveHTML($nodes->item(0)) ?: '';
    }

    private function packHtml(): string
    {
        return '<div data-disclosure-doc="kmandate" class="corex-document-wrapper">M ~~~~OTHER_CONDITIONS__kmandate~~~~</div>'
             . '<div data-disclosure-doc="kdisc" class="corex-document-wrapper">D ~~~~OTHER_CONDITIONS__kdisc~~~~</div>';
    }

    /** Same base fixture as makeTemplate(), plus the pack's merged_html so resolvePackDocKeys() finds 2 documents. */
    private function makePackTemplate(): array
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $sigTpl->document->update(['web_template_data' => ['merged_html' => $this->packHtml()]]);
        $sigTpl->load('document');

        return [$sigTpl, $userId];
    }

    /**
     * @return array{0: SignatureTemplate, 1: int}
     */
    private function makeTemplate(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent', 'email' => 'a-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agencySlug = 'pack-scoping-test-' . strtolower(Str::random(6));
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Pack Scoping Test Agency ' . Str::random(6), 'slug' => $agencySlug,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tpl = DocuperfectTemplate::create([
            'name' => 'Pack scoping test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Pack scoping Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id, 'agency_id' => $agencyId,
            'web_template_data' => ['merged_html' => ''],
        ]);
        $sigTpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
            'parties_json' => [
                ['role' => 'seller', 'name' => 'Sam Seller'],
                ['role' => 'agent', 'name' => 'Ana Agent'],
            ],
        ]);

        return [$sigTpl, $userId];
    }
}
