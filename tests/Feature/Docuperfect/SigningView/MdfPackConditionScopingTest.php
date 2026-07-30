<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pack condition→document scoping (Johan 2026-07-30).
 *
 * other_conditions_text is a SINGLE flat whole-template field; in a pack it holds
 * EVERY segment's conditions concatenated. A per-document pack block is keyed
 * `other_conditions__<docKey>` and its own conditions are the structured
 * DocumentCondition rows filtered by block_id. A scoped block with no structured
 * rows (e.g. Addendum B, to which no condition was linked) must render EMPTY —
 * never the flat blob, which bled EATS's + MDF's clauses onto Addendum B (foreign
 * legal clauses on a document). The unscoped single-doc block keeps the legacy
 * fallback for genuine pre-structured documents.
 *
 * Pure in-memory + reflection — NO RefreshDatabase (QA1 tree runs the live DB).
 */
final class MdfPackConditionScopingTest extends TestCase
{
    private function render(string $blockId, SignatureTemplate $doc): string
    {
        $renderer = app(InsertableBlockRenderer::class);
        $method   = new ReflectionMethod($renderer, 'renderBlockPartialInner');
        $method->setAccessible(true);

        return (string) $method->invoke(
            $renderer,
            ['id' => $blockId, 'purpose' => 'other_conditions', 'label' => 'Other Conditions'],
            new Collection(),                 // no structured conditions on this block
            $doc,
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'FAKE-TOKEN',
            'seller',
        );
    }

    public function test_scoped_pack_block_never_renders_the_flat_legacy_blob(): void
    {
        $doc = new SignatureTemplate();
        $doc->other_conditions_text = "This is a condition on the eats\nThis is a condition on the smd";

        // Addendum B's scoped block — no condition was linked to it.
        $html = $this->render('other_conditions__eiwrvitcdy', $doc);

        $this->assertStringNotContainsString('condition on the eats', $html, 'EATS clause must not bleed onto a scoped block with no conditions');
        $this->assertStringNotContainsString('condition on the smd', $html, 'MDF clause must not bleed onto a scoped block with no conditions');
        $this->assertStringNotContainsString('conditions-legacy-text', $html, 'the flat legacy fallback must not fire for a scoped pack block');
        $this->assertStringContainsString('No conditions yet', $html, 'a scoped block with no conditions renders the empty state');
    }

    public function test_unscoped_single_document_block_still_renders_legacy_text(): void
    {
        $doc = new SignatureTemplate();
        $doc->other_conditions_text = 'Legacy single-doc condition text';

        // Bare block id = genuine pre-structured single-document legacy case.
        $html = $this->render('other_conditions', $doc);

        $this->assertStringContainsString('conditions-legacy-text', $html, 'the unscoped single-doc block keeps the legacy fallback');
        $this->assertStringContainsString('Legacy single-doc condition text', $html);
    }
}
