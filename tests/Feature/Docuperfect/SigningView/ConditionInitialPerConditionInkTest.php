<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\InsertableBlockRenderer;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Legal-integrity: an other-condition initial must render ONLY the ink the party
 * actually drew FOR THAT CONDITION (signed_initials['{party}']['condition_{id}']) —
 * never a fallback to the party's adopted page-initial, which mirrored one drawn
 * mark onto every document's condition block (an initial where the party did not
 * sign; Johan 2026-08, the agent's "27" on all three docs).
 *
 * Pure in-memory + reflection — NO RefreshDatabase (QA1 tree runs the live DB).
 */
final class ConditionInitialPerConditionInkTest extends TestCase
{
    private const INK_A = 'data:image/png;base64,AAAA';   // condition 5 ink
    private const INK_B = 'data:image/png;base64,BBBB';   // condition 6 ink
    private const PAGE  = 'data:image/png;base64,PAGE';   // agent adopted page-initial

    private function renderCondition(int $conditionId, SignatureTemplate $tpl): string
    {
        $c = new DocumentCondition();
        $c->id = $conditionId;
        // Rows exist for both parties (proof of consent) -> FILLED branch fires for each.
        $c->setRelation('initials', new Collection([
            new ConditionInitial(['party_key' => 'agent']),
            new ConditionInitial(['party_key' => 'seller']),
        ]));

        $renderer = app(InsertableBlockRenderer::class);
        $method   = new ReflectionMethod($renderer, 'renderInitialSlotsForCondition');
        $method->setAccessible(true);

        return (string) $method->invoke($renderer, $c, $tpl, InsertableBlockRenderer::CONTEXT_PDF_RENDER, null, null);
    }

    private function template(): SignatureTemplate
    {
        $tpl = new SignatureTemplate();
        $tpl->parties_json = [
            ['name' => 'Johan Reichel', 'role' => 'agent'],
            ['name' => 'Anine Seller',  'role' => 'seller'],
        ];
        $doc = new Document();
        $doc->web_template_data = [
            'signed_initials' => [
                // Agent: ONLY a page-break initial, NO per-condition ink (the doc-523 shape).
                'agent'  => ['agent-init-0' => self::PAGE],
                // Seller: distinct ink drawn per condition.
                'seller' => ['condition_5' => self::INK_A, 'condition_6' => self::INK_B],
            ],
        ];
        $tpl->setRelation('document', $doc);
        return $tpl;
    }

    public function test_seller_condition_initial_renders_its_own_per_condition_ink(): void
    {
        $tpl = $this->template();
        $c5 = $this->renderCondition(5, $tpl);
        $c6 = $this->renderCondition(6, $tpl);

        // Each condition shows the seller's ink drawn FOR THAT condition — distinct.
        $this->assertStringContainsString(self::INK_A, $c5, 'cond 5 must show the seller ink drawn for cond 5');
        $this->assertStringNotContainsString(self::INK_B, $c5, 'cond 5 must NOT show cond 6 ink');
        $this->assertStringContainsString(self::INK_B, $c6, 'cond 6 must show the seller ink drawn for cond 6');
        $this->assertStringNotContainsString(self::INK_A, $c6, 'cond 6 must NOT show cond 5 ink');
    }

    public function test_agent_without_per_condition_ink_never_mirrors_the_adopted_page_initial(): void
    {
        $tpl = $this->template();
        $c5 = $this->renderCondition(5, $tpl);
        $c6 = $this->renderCondition(6, $tpl);

        // The agent has no condition_* ink -> his adopted page-initial must NEVER be
        // stamped onto a condition block (the mirror / legal exposure). He falls back to
        // a typed-letters token instead.
        $this->assertStringNotContainsString(self::PAGE, $c5, 'agent page-initial must not mirror onto cond 5');
        $this->assertStringNotContainsString(self::PAGE, $c6, 'agent page-initial must not mirror onto cond 6');
        // The agent slot still renders (token), proving it is the token path, not the ink path.
        $this->assertMatchesRegularExpression('/data-party-key="agent"[^>]*>\s*<strong/is', $c5, 'agent slot falls back to a typed token, not a drawn image');
    }
}
