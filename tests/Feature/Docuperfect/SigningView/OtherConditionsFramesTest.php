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
