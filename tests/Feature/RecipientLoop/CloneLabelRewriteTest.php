<?php

declare(strict_types=1);

namespace Tests\Feature\RecipientLoop;

use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * B3 — Case D.2 static-label rewriter. Closes the known B2.5 limitation
 * where cloned blocks carried the source block's label text into the
 * duplicate (e.g. "Seller 2" verbatim in a "Seller 4" instance).
 */
final class CloneLabelRewriteTest extends TestCase
{
    public function test_case_d2_clone_3_inner_labels_rewritten_to_seller_3(): void
    {
        $html = $this->twoBlockTemplate();
        $recipients = $this->fakeRecipients(['seller', 'seller', 'seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        // Source blocks intact.
        $this->assertStringContainsString('>Seller 1</h4>', $out);
        $this->assertStringContainsString('>Seller 2</h4>', $out);
        // Clone for instance 3 carries rewritten labels (h4 + paragraph).
        $this->assertStringContainsString('>Seller 3</h4>', $out);
        $this->assertStringContainsString('The Seller 3 acknowledges', $out);
    }

    public function test_case_d2_clone_4_inner_labels_rewritten_to_seller_4(): void
    {
        $html = $this->twoBlockTemplate();
        $recipients = $this->fakeRecipients(['seller', 'seller', 'seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertStringContainsString('>Seller 4</h4>', $out);
        $this->assertStringContainsString('The Seller 4 acknowledges', $out);
    }

    public function test_rentals_lessor_clones_carry_indexed_labels(): void
    {
        // 2-block hardcoded rentals + 3 lessor recipients (Case D.2).
        $html = '<div class="contract">'
              . '<div class="lessor-block"><h4>Lessor 1</h4><p>Lessor 1 details</p><span data-field="lessor_1_phone">P</span></div>'
              . '<div class="lessor-block"><h4>Lessor 2</h4><p>Lessor 2 details</p><span data-field="lessor_2_phone">P</span></div>'
              . '</div>';
        $recipients = $this->fakeRecipients(['lessor', 'lessor', 'lessor']);
        $template = $this->buildRentalTemplate();
        $out = app(RoleBlockExpansionService::class)->expandWithLooping($template, $html, $recipients);

        $this->assertStringContainsString('>Lessor 1</h4>', $out);
        $this->assertStringContainsString('>Lessor 2</h4>', $out);
        $this->assertStringContainsString('>Lessor 3</h4>', $out);
        $this->assertStringContainsString('Lessor 3 details', $out);
        $this->assertStringNotContainsString('>Lessor 4</h4>', $out);
    }

    public function test_no_inner_labels_means_no_false_positives(): void
    {
        // Source block has NO numeric-labelled text. Clones add the
        // prepended header but nothing else changes.
        $html = '<div class="contract">'
              . '<div class="seller-block"><span data-field="seller_1_phone">P1</span></div>'
              . '<div class="seller-block"><span data-field="seller_2_phone">P2</span></div>'
              . '</div>';
        $recipients = $this->fakeRecipients(['seller', 'seller', 'seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        // No spurious "Seller 3" / "Seller 4" tokens injected anywhere
        // except the prepended block headers.
        $countSeller3 = substr_count($out, 'Seller 3');
        $countSeller4 = substr_count($out, 'Seller 4');
        // Contract-driven renderer (968d5338) uses numberless "Seller - {name}"
        // headers, so no "Seller N" token is injected anywhere in the body.
        $this->assertSame(0, $countSeller3);
        $this->assertSame(0, $countSeller4);
    }

    public function test_input_values_with_seller_word_are_not_rewritten(): void
    {
        // Defensive: an existing <input value="The Seller 2 firm"> inside
        // a cloned block must NOT have its value mangled. Only text node
        // children outside form controls are eligible.
        $html = '<div class="contract">'
              . '<div class="seller-block"><h4>Seller 1</h4><span data-field="seller_1_phone">P1</span></div>'
              . '<div class="seller-block"><h4>Seller 2</h4>'
              . '<input type="text" value="The Seller 2 firm chose this">'
              . '<span data-field="seller_2_phone">P2</span>'
              . '</div>'
              . '</div>';
        $recipients = $this->fakeRecipients(['seller', 'seller', 'seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        // Cloned input value is left intact even though clone is "Seller 3".
        $this->assertStringContainsString('value="The Seller 2 firm chose this"', $out);
    }

    // ── Helpers ──

    private function twoBlockTemplate(): string
    {
        return '<div class="contract">'
            . '<div class="seller-block"><h4>Seller 1</h4><p>The Seller 1 acknowledges.</p><span data-field="seller_1_phone">P1</span></div>'
            . '<div class="seller-block"><h4>Seller 2</h4><p>The Seller 2 acknowledges.</p><span data-field="seller_2_phone">P2</span></div>'
            . '</div>';
    }

    /**
     * @param  list<string> $roles
     * @return Collection<int, SignatureRequest>
     */
    private function fakeRecipients(array $roles): Collection
    {
        $out = collect();
        $counts = [];
        foreach ($roles as $i => $role) {
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $r = new SignatureRequest();
            $r->party_role = $role;
            $r->role_index = $counts[$role];
            $r->signer_name = strtoupper(substr($role, 0, 1)) . $counts[$role];
            $r->contact_id = null;
            $out->push($r);
        }
        return $out;
    }

    private function buildRentalTemplate(): \App\Models\Docuperfect\Template
    {
        // Minimal in-memory Template (no DB hit) — only category +
        // signing_parties matter for isSalesDocument().
        $t = new \App\Models\Docuperfect\Template();
        $t->category = 'rentals';
        $t->signing_parties = ['owner_party', 'acquiring_party', 'agent'];
        return $t;
    }
}
