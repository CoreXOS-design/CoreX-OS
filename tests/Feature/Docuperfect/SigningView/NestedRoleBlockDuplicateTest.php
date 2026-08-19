<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * AT-291 ITEM 6 — fill & sign duplicate seller render.
 *
 * Root cause: a role-block stamped for one vocabulary of a party (e.g.
 * `data-role-block="seller"`) nested INSIDE a role-block stamped for the
 * other vocabulary of the SAME party (`data-role-block="owner_party"`) is
 * cloned once WITH its ancestor and again on its own expansion pass — so the
 * seller renders twice in the fill & review preview (which runs the same
 * RoleBlockExpansionService engine as the recipient signing surface).
 *
 * Fix: same-party nested role-blocks are excluded from independent expansion
 * (hasSamePartyRoleBlockAncestor). These tests pin the fix and guard against
 * regressing the non-nested cases.
 */
final class NestedRoleBlockDuplicateTest extends TestCase
{
    /** No DB — pure HTML transformation over in-memory recipients. */

    /**
     * @param  list<array{party_role:string,signer_name:string}> $rows
     * @return Collection<int, SignatureRequest>
     */
    private function recipients(array $rows): Collection
    {
        $out = collect();
        $counts = [];
        foreach ($rows as $row) {
            $role = $row['party_role'];
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $r = new SignatureRequest();
            $r->party_role  = $role;
            $r->role_index  = $counts[$role];
            $r->signer_name = $row['signer_name'];
            $r->contact_id  = null;
            $out->push($r);
        }
        return $out;
    }

    /**
     * ITEM 6 — a `seller` role-block nested inside an `owner_party` role-block
     * (same party, mixed vocabulary) must render the seller field EXACTLY
     * once for one seller recipient, not twice.
     */
    public function test_same_party_nested_role_block_renders_seller_once(): void
    {
        $html = '<div data-role-block="owner_party">'
              . '<p>Owner particulars</p>'
              . '<div data-role-block="seller">'
              . '<span data-field="seller_first_name">P</span>'
              . '</div>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $html,
            $this->recipients([['party_role' => 'seller', 'signer_name' => 'Thandeka Zulu']]),
        );

        // The seller's field must appear exactly once — the pre-fix bug
        // produced two copies (one inside the cloned owner_party parent, one
        // from the independent nested seller pass). The retained parent stamps
        // the canonical `owner_party` vocabulary, which is the SAME party
        // (twin of seller) — the visible header still reads "Seller - <name>".
        $this->assertSame(
            1,
            substr_count($out, 'data-field="seller_first_name'),
            'Nested same-party seller block must render once, not doubled.',
        );
        $this->assertSame(
            1,
            substr_count($out, 'Thandeka Zulu'),
            'The seller must be rendered exactly once.',
        );
        $this->assertStringContainsString('Seller - Thandeka Zulu', $out);
    }

    /**
     * ITEM 6 regression guard — a genuine multi-seller nested case still
     * expands N times (once per seller), never 2N.
     */
    public function test_same_party_nested_role_block_still_expands_per_recipient(): void
    {
        $html = '<div data-role-block="owner_party">'
              . '<div data-role-block="seller">'
              . '<span data-field="seller_first_name">P</span>'
              . '</div>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $html,
            $this->recipients([
                ['party_role' => 'seller', 'signer_name' => 'Alice Apple'],
                ['party_role' => 'seller', 'signer_name' => 'Bob Banana'],
            ]),
        );

        // Two sellers → two field copies (one per recipient), not four.
        $this->assertSame(2, substr_count($out, 'data-field="seller_first_name'));
        $this->assertSame(1, substr_count($out, 'Alice Apple'));
        $this->assertSame(1, substr_count($out, 'Bob Banana'));
    }

    /**
     * ITEM 6 non-regression — NON-nested sibling blocks of different parties
     * both render with their own role tokens (the fix only touches same-party
     * NESTED blocks, never siblings).
     */
    public function test_sibling_blocks_of_different_parties_both_render(): void
    {
        $html = '<div data-role-block="seller">'
              . '<span data-field="seller_first_name">P</span>'
              . '</div>'
              . '<div data-role-block="buyer">'
              . '<span data-field="buyer_first_name">P</span>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $html,
            $this->recipients([
                ['party_role' => 'seller', 'signer_name' => 'Sipho Seller'],
                ['party_role' => 'buyer',  'signer_name' => 'Bongi Buyer'],
            ]),
        );

        $this->assertSame(1, substr_count($out, 'data-field="seller_first_name'));
        $this->assertSame(1, substr_count($out, 'data-field="buyer_first_name'));
        $this->assertStringContainsString('data-role-token="seller"', $out);
        $this->assertStringContainsString('data-role-token="buyer"', $out);
    }
}
