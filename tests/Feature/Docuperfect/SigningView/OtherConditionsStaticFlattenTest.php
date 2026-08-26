<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Bug 3 (universal, NOT per-template) — the STATIC / print-from-approved form of an
 * Other-Conditions insertable block (CONTEXT_PDF_RENDER) must render as plain
 * document content: NO peach editing panel (tinted background + coloured left rule),
 * NO uppercase block-header label, NO "+ Add condition" affordance. The condition
 * clause itself still prints — it IS the legal content.
 *
 * The INTERACTIVE surfaces (recipient signing / agent preparation) KEEP the
 * affordance so a party can still see where to add a condition — the flatten must
 * not bleed there. This makes universal the flatten that per-template CSS
 * (templates 120 / 123) already carried, so EATS / template-111 (which lacked that
 * CSS and printed the peach panel) render clean too.
 *
 * Pure in-memory + reflection — NO RefreshDatabase (the QA1 worktree points at the
 * live DB; this asserts pure string composition and touches no tables).
 */
final class OtherConditionsStaticFlattenTest extends TestCase
{
    private function render(string $context): string
    {
        $tpl = new SignatureTemplate();
        $tpl->parties_json = [
            ['name' => 'Anine Seller', 'role' => 'seller'],
            ['name' => 'Rec Two',      'role' => 'seller_2'],
        ];

        $c = new DocumentCondition();
        $c->id = 501;
        $c->condition_number = 1;
        $c->content = 'SALE SUBJECT TO BOND APPROVAL';
        $c->setRelation('initials', new Collection());

        $block = [
            'id'          => 'other_conditions__eats',
            'purpose'     => 'other_conditions',
            'label'       => 'Other Conditions',
            'auto_number' => true,
        ];

        $method = new ReflectionMethod(InsertableBlockRenderer::class, 'renderBlockPartialInner');
        $method->setAccessible(true);

        return (string) $method->invoke(
            app(InsertableBlockRenderer::class),
            $block,
            new Collection([$c]),
            $tpl,
            $context,
            null,
            null,
        );
    }

    public function test_static_pdf_form_prints_clean_content_with_no_editing_chrome(): void
    {
        $html = $this->render(InsertableBlockRenderer::CONTEXT_PDF_RENDER);

        // The clause still prints — it is the legal content.
        $this->assertStringContainsString('SALE SUBJECT TO BOND APPROVAL', $html);
        $this->assertStringContainsString('class="insertable-block"', $html);

        // ...but none of the peach editing chrome reaches the final artifact.
        $this->assertStringNotContainsString('color-mix', $html, 'tinted "peach" background leaked to the static form');
        $this->assertStringNotContainsString('border-left: 3px', $html, 'coloured left rule leaked to the static form');
        $this->assertStringNotContainsString('class="block-header"', $html, 'uppercase block-header label leaked to the static form');
        $this->assertStringNotContainsString('btn-add-condition', $html, '"+ Add condition" affordance leaked to the static form');
    }

    public function test_interactive_signing_form_keeps_the_add_affordance(): void
    {
        $html = $this->render(InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING);

        // The flatten must NOT bleed onto the interactive surface: a signer must
        // still see the panel + header + add button to contribute a condition.
        $this->assertStringContainsString('color-mix', $html, 'interactive block lost its panel styling');
        $this->assertStringContainsString('class="block-header"', $html, 'interactive block lost its header');
        $this->assertStringContainsString('btn-add-condition', $html, 'interactive block lost its add affordance');
    }
}
