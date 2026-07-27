<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Clause;
use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use App\Services\Docuperfect\LegacyOtherConditionsBridge;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step 2 (Johan) — reusable "other conditions" FRAME model.
 *
 * Pins the contract for the discrete row-per-condition build:
 *   • the screen-only "one condition at a time" guidance is an OVERLAY
 *     (never baked into the canonical) + never survives to the PDF;
 *   • agent frames persist as one document_conditions row each, with
 *     clause-library provenance, idempotently, without clobbering
 *     recipient-added rows;
 *   • each frame renders as its own row with a per-party initial slot.
 */
final class OtherConditionsFramesTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_condition_guidance_injected_before_button_and_idempotent(): void
    {
        $renderer = app(InsertableBlockRenderer::class);
        $html = '<div class="insertable-block"><button type="button" class="btn-add-condition" data-block-id="other_conditions">+ Add condition</button></div>';

        $once  = $renderer->injectAddConditionGuidance($html);
        $twice = $renderer->injectAddConditionGuidance($once);

        // Exactly one guidance element after one or two passes (idempotent).
        $this->assertSame(1, substr_count($once, 'condition-add-guidance'));
        $this->assertSame(1, substr_count($twice, 'condition-add-guidance'));
        // Screen-only markers present.
        $this->assertStringContainsString('no-print', $once);
        $this->assertStringContainsString('data-screen-only="1"', $once);
        // Placed BEFORE the button so it reads as guidance for the control.
        $this->assertLessThan(
            strpos($once, 'btn-add-condition'),
            strpos($once, 'condition-add-guidance'),
        );
    }

    public function test_add_condition_guidance_is_noop_without_button(): void
    {
        $renderer = app(InsertableBlockRenderer::class);
        $html = '<p>no add-condition control here</p>';
        $this->assertSame($html, $renderer->injectAddConditionGuidance($html));
    }

    public function test_guidance_is_overlay_only_and_not_baked_into_block_render(): void
    {
        [$sigTpl] = $this->makeTemplate();
        // The block render itself (what composes into the canonical) must NOT
        // contain the guidance — it is applied only as a show()-time overlay.
        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>',
            $sigTpl,
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
        $this->assertStringContainsString('btn-add-condition', $rendered);
        $this->assertStringNotContainsString('condition-add-guidance', $rendered);
    }

    public function test_frames_sync_creates_one_row_per_frame_with_provenance(): void
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $clauseId = Clause::create([
            'name' => 'Bond clause', 'text' => 'Subject to bond approval.', 'is_global' => true, 'owner_id' => $userId,
        ])->id;

        $frames = [
            ['content' => 'Cash sale, no bond.', 'source' => 'custom', 'library_clause_id' => null],
            ['content' => 'Subject to bond approval.', 'source' => 'library', 'library_clause_id' => $clauseId, 'clause_name' => 'Bond clause'],
            ['content' => '   ', 'source' => 'custom'], // blank → dropped
        ];

        $written = app(LegacyOtherConditionsBridge::class)->syncFramesToStructuredRows($sigTpl, $frames);
        $this->assertSame(2, $written);

        $rows = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')
            ->orderBy('condition_number')->get();
        $this->assertCount(2, $rows);

        $this->assertSame('Cash sale, no bond.', $rows[0]->content);
        $this->assertSame('custom', $rows[0]->source);
        $this->assertNull($rows[0]->library_clause_id);

        $this->assertSame('Subject to bond approval.', $rows[1]->content);
        $this->assertSame('library', $rows[1]->source);
        $this->assertSame($clauseId, (int) $rows[1]->library_clause_id);

        // condition_number is sequential.
        $this->assertSame(1, (int) $rows[0]->condition_number);
        $this->assertSame(2, (int) $rows[1]->condition_number);
    }

    public function test_frames_sync_is_idempotent_replaces_prior_and_spares_recipient_rows(): void
    {
        [$sigTpl] = $this->makeTemplate();

        // A recipient-added row must never be touched by the agent frame sync.
        $recipientRow = DocumentCondition::create([
            'signature_template_id' => $sigTpl->id,
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 99, 'content' => 'Recipient condition',
            'added_via' => 'recipient_signing', 'source' => 'custom',
        ]);

        $bridge = app(LegacyOtherConditionsBridge::class);
        $frames = [['content' => 'A', 'source' => 'custom'], ['content' => 'B', 'source' => 'custom']];

        $this->assertSame(2, $bridge->syncFramesToStructuredRows($sigTpl, $frames));
        // Re-running the identical set is a no-op (idempotent).
        $this->assertSame(0, $bridge->syncFramesToStructuredRows($sigTpl, $frames));
        $this->assertSame(2, DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->count());

        // A changed set REPLACES the prior agent rows (one row now).
        $bridge->syncFramesToStructuredRows($sigTpl, [['content' => 'Only one now', 'source' => 'custom']]);
        $this->assertSame(1, DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->count());

        // Recipient row survived every sync.
        $this->assertDatabaseHas('document_conditions', [
            'id' => $recipientRow->id, 'added_via' => 'recipient_signing',
        ]);
    }

    public function test_each_frame_renders_as_own_row_with_party_initial_slots(): void
    {
        [$sigTpl] = $this->makeTemplate();
        app(LegacyOtherConditionsBridge::class)->syncFramesToStructuredRows($sigTpl, [
            ['content' => 'Frame one', 'source' => 'custom'],
            ['content' => 'Frame two', 'source' => 'custom'],
        ]);

        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>',
            $sigTpl,
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );

        // Two discrete rows.
        $this->assertSame(2, substr_count($rendered, 'class="condition-row"'));
        $this->assertStringContainsString('Frame one', $rendered);
        $this->assertStringContainsString('Frame two', $rendered);
        // Each row carries a per-party initial affordance for BOTH parties.
        $this->assertStringContainsString('condition-initials', $rendered);
        $this->assertStringContainsString('data-party-key="seller"', $rendered);
        $this->assertStringContainsString('data-party-key="agent"', $rendered);
    }

    public function test_pdf_render_html_strips_screen_only_chrome(): void
    {
        [$sigTpl] = $this->makeTemplate();
        // Give the doc a canonical body so the PDF pipeline has something to wrap.
        $doc = $sigTpl->document;
        $doc->update(['web_template_data' => array_merge($doc->web_template_data ?? [], [
            'canonical_html' => '<div class="insertable-block"><button class="btn-add-condition">+ Add condition</button>'
                . '<div class="condition-add-guidance no-print" data-screen-only="1">Please add only one condition at a time.</div></div>',
            'canonical_version' => 1,
        ])]);

        $out = app(SignaturePdfService::class)->buildInjectedRenderHtml($sigTpl->fresh('document'));

        // The print DOM boot must strip interactive chrome + screen-only nodes.
        $this->assertStringContainsString('.btn-add-condition', $out);
        $this->assertStringContainsString('.condition-add-guidance', $out);
        $this->assertStringContainsString('[data-screen-only]', $out);
        $this->assertStringContainsString('.forEach(function(e){e.remove();})', $out);
    }

    public function test_refresh_bakes_late_initial_into_raw_marker_canonical(): void
    {
        // The real mandate/disclosure templates carry NO insertable_blocks
        // metadata (raw ~~~~OTHER_CONDITIONS~~~~ marker). This pins that
        // refreshInsertableBlocks still bakes a late-captured per-frame initial
        // into the stored canonical for such templates (the KICKER path).
        [$sigTpl, $userId] = $this->makeTemplate();
        $doc = $sigTpl->document;
        $doc->update(['web_template_data' => array_merge($doc->web_template_data ?? [], [
            'merged_html'     => '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p><div class="corex-signature-section">sign</div>',
            'signed_initials' => ['seller' => ['data:image/png;base64,SELLERINK'], 'agent' => ['data:image/png;base64,AGENTINK']],
        ])]);
        // One agent frame + ONE party initial, then compose (bakes 1 ink).
        app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
            ->syncFramesToStructuredRows($sigTpl, [['content' => 'Frame X', 'source' => 'custom']]);
        $cond = DocumentCondition::where('signature_template_id', $sigTpl->id)->first();
        ConditionInitial::create(['initialable_type' => DocumentCondition::class, 'initialable_id' => $cond->id, 'party_key' => 'seller', 'initialed_at' => now()]);
        app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)->composeAndStore($sigTpl);
        $doc->refresh();
        $before = substr_count((string) ($doc->web_template_data['canonical_html'] ?? ''), 'condition-initial-ink');

        // A LATE initial arrives (the KICKER: another party initials after the
        // canonical was baked). refreshInsertableBlocks must fold it in even
        // though the template has no insertable_blocks metadata.
        ConditionInitial::create(['initialable_type' => DocumentCondition::class, 'initialable_id' => $cond->id, 'party_key' => 'agent', 'initialed_at' => now()]);
        app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)->refreshInsertableBlocks($sigTpl->fresh());
        $doc->refresh();
        $after = substr_count((string) ($doc->web_template_data['canonical_html'] ?? ''), 'condition-initial-ink');

        $this->assertSame(1, $before, 'compose baked exactly the first party ink');
        $this->assertSame(2, $after, 'refresh folded the late second-party ink into the raw-marker canonical');
    }

    public function test_pack_other_conditions_are_scoped_per_document(): void
    {
        // In a pack each segment's OTHER_CONDITIONS marker is scoped to its wrapper
        // docKey (~~~~OTHER_CONDITIONS__<key>~~~~) → an independent block_id per
        // document. A condition on doc A must never render in doc B.
        [$sigTpl] = $this->makeTemplate();
        $mk = fn (string $blockId, string $content, int $n) => DocumentCondition::create([
            'signature_template_id' => $sigTpl->id,
            'block_id' => $blockId, 'block_purpose' => 'other_conditions',
            'condition_number' => $n, 'content' => $content,
            'added_via' => 'agent_preparation', 'source' => 'custom',
        ]);
        $mk('other_conditions__doca', 'ALPHA condition on document A', 1);
        $mk('other_conditions__docb', 'BETA condition on document B', 1);

        $html = '<div data-disclosure-doc="doca" class="corex-document-wrapper"><p>A ~~~~OTHER_CONDITIONS__doca~~~~</p></div>'
              . '<div data-disclosure-doc="docb" class="corex-document-wrapper"><p>B ~~~~OTHER_CONDITIONS__docb~~~~</p></div>';
        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            $html, $sigTpl, [], InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, 'tok', 'seller',
        );

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $rendered . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($dom);
        $byId = [];
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " insertable-block ")][@data-block-id]') as $b) {
            $byId[$b->getAttribute('data-block-id')] = $b->textContent;
        }
        $this->assertArrayHasKey('other_conditions__doca', $byId);
        $this->assertArrayHasKey('other_conditions__docb', $byId);
        // Each document's block carries ONLY its own condition — no cross-doc bleed.
        $this->assertStringContainsString('ALPHA', $byId['other_conditions__doca']);
        $this->assertStringNotContainsString('BETA', $byId['other_conditions__doca']);
        $this->assertStringContainsString('BETA', $byId['other_conditions__docb']);
        $this->assertStringNotContainsString('ALPHA', $byId['other_conditions__docb']);
    }

    public function test_pack_frames_route_to_target_document_scoped_block(): void
    {
        // Fill-and-review tags each frame with target_doc_index (0-based, in
        // document order). The bridge reads the pack's merged_html docKeys and
        // routes each frame's row to that document's scoped block — so a
        // condition tagged "document 2" persists ONLY on document 2's block.
        [$sigTpl] = $this->makeTemplate();
        $sigTpl->document->update(['web_template_data' => ['merged_html' =>
            '<div data-disclosure-doc="kmandate" class="corex-document-wrapper">M ~~~~OTHER_CONDITIONS__kmandate~~~~</div>'
          . '<div data-disclosure-doc="kdisc" class="corex-document-wrapper">D ~~~~OTHER_CONDITIONS__kdisc~~~~</div>'
          . '<div data-disclosure-doc="kadd" class="corex-document-wrapper">A ~~~~OTHER_CONDITIONS__kadd~~~~</div>',
        ]]);
        $sigTpl->load('document');

        $written = app(LegacyOtherConditionsBridge::class)->syncFramesToStructuredRows($sigTpl, [
            ['content' => 'MANDATE cond',   'source' => 'custom', 'target_doc_index' => 0],
            ['content' => 'DISCLOSURE cond','source' => 'custom', 'target_doc_index' => 1],
            ['content' => 'ADDENDUM cond',  'source' => 'custom', 'target_doc_index' => 2],
        ]);
        $this->assertSame(3, $written);

        $byBlock = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->get()->keyBy('block_id');

        $this->assertSame('MANDATE cond',    $byBlock['other_conditions__kmandate']->content);
        $this->assertSame('DISCLOSURE cond', $byBlock['other_conditions__kdisc']->content);
        $this->assertSame('ADDENDUM cond',   $byBlock['other_conditions__kadd']->content);
        // No frame leaked onto the bare (un-rendered) block.
        $this->assertArrayNotHasKey('other_conditions', $byBlock->toArray());
    }

    public function test_pack_untagged_frame_defaults_to_first_document(): void
    {
        // A pack frame with no target_doc_index must never land on the bare
        // block (which no pack marker renders) — it defaults to document 1.
        [$sigTpl] = $this->makeTemplate();
        $sigTpl->document->update(['web_template_data' => ['merged_html' =>
            '<div data-disclosure-doc="kone" class="corex-document-wrapper">~~~~OTHER_CONDITIONS__kone~~~~</div>'
          . '<div data-disclosure-doc="ktwo" class="corex-document-wrapper">~~~~OTHER_CONDITIONS__ktwo~~~~</div>',
        ]]);
        $sigTpl->load('document');

        app(LegacyOtherConditionsBridge::class)->syncFramesToStructuredRows($sigTpl, [
            ['content' => 'No target here', 'source' => 'custom'],
        ]);

        $row = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->firstOrFail();
        $this->assertSame('other_conditions__kone', $row->block_id);
    }

    public function test_single_doc_frames_stay_on_bare_block(): void
    {
        // No pack (single document → no data-disclosure-doc) keeps the bare
        // `other_conditions` block_id — backward-compatible.
        [$sigTpl] = $this->makeTemplate(); // merged_html '' → no docKeys
        app(LegacyOtherConditionsBridge::class)->syncFramesToStructuredRows($sigTpl, [
            ['content' => 'Only condition', 'source' => 'custom', 'target_doc_index' => 0],
        ]);
        $row = DocumentCondition::where('signature_template_id', $sigTpl->id)
            ->where('added_via', 'agent_preparation')->firstOrFail();
        $this->assertSame('other_conditions', $row->block_id);
    }

    /**
     * Minimal template + document + signing template with a 2-party set
     * (seller + agent) so the renderer paints per-party initial slots.
     *
     * @return array{0: SignatureTemplate, 1: int}
     */
    private function makeTemplate(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent', 'email' => 'a-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tpl = DocuperfectTemplate::create([
            'name' => 'Frames test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Frames Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id,
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
