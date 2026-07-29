<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\DocumentCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-303 BUG-4 — other-condition initials must be identity-scoped per recipient.
 *
 * InsertableBlockRenderer emits one initial slot per parties_json[].role
 * (bare-first: `seller`, then `seller_2`). Before the fix, initialCondition saved
 * the BARE party_role (`seller`) for BOTH sellers, so seller 2's slot could never
 * be filled (it collided with seller 1 on the insert-only 409 guard). Each
 * recipient must own their own slot.
 */
final class ConditionInitialPartyKeyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private function makeCondition(int $templateId): DocumentCondition
    {
        return DocumentCondition::create([
            'signature_template_id' => $templateId,
            'agency_id'             => null,
            'block_id'              => 'other_conditions',
            'block_purpose'         => 'other_conditions',
            'condition_number'      => 1,
            'content'               => 'The sale is subject to a compliant electrical CoC.',
            'is_override'           => false,
            'added_via'             => 'recipient_signing',
            'source'                => 'custom',
        ]);
    }

    public function test_each_same_role_recipient_owns_their_own_condition_initial(): void
    {
        $session   = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $template  = $session['signatureTemplate'];
        $seller1   = $this->recipient($session['recipients'], 'seller', 1);
        $seller2   = $this->recipient($session['recipients'], 'seller', 2);
        $condition = $this->makeCondition($template->id);

        // Seller 1 initials the condition.
        $this->postJson('/sign/' . $seller1->token . '/conditions/' . $condition->id . '/initial')
            ->assertStatus(201);

        // Seller 2 MUST be able to initial the SAME condition (before the fix this
        // 409'd because both wrote party_key='seller').
        $this->postJson('/sign/' . $seller2->token . '/conditions/' . $condition->id . '/initial')
            ->assertStatus(201);

        // Two distinct, identity-scoped initials exist.
        $keys = ConditionInitial::where('initialable_type', DocumentCondition::class)
            ->where('initialable_id', $condition->id)
            ->pluck('party_key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['seller', 'seller_2'], $keys,
            'each same-role recipient must own an identity-scoped condition initial');
    }
}
