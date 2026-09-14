<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, live on QA1: "rental pipeline shows all sales and rentals, not
 * only rentals." Traced to 2 of 174 real contacts — not "all" — but the
 * cause was real: BuyerPipelineController::applyLeadTypeFilter() asked
 * "does this contact have ANY match of this listing type anywhere" while
 * each card's own label (and now Contact::primaryMatchIsRental()) asks
 * "is the PRIMARY match this type." A contact with a mixed wishlist (an
 * old sale enquiry plus a newer rental one, or vice versa) could satisfy
 * one question and fail the other.
 *
 * The fault ran in BOTH directions from the same root cause, and only one
 * side was reported — a missing row is invisible, nobody notices a lead
 * that never appears:
 *   - WRONGFUL INCLUSION: a sale-primary contact with a secondary rental
 *     match was admitted to the Rental Pipeline (Johan's report).
 *   - WRONGFUL EXCLUSION: that same contact was completely absent from the
 *     sale-filtered board, because "has ANY rental match" disqualified
 *     them from 'sale' even though their primary interest genuinely is a
 *     sale (found during investigation, not reported).
 *
 * Both are fixed by the same change: the filter and the card label now
 * both read Contact::primaryMatchIsRental() — one method, not two
 * independently-maintained copies of the same rule.
 */
final class BuyerPipelineMixedWishlistPrimaryMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: User, 1: Contact} admin, and the mixed-wishlist contact (primary=sale, secondary=rental) */
    private function scenarioWithMixedWishlistContact(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin', 'name' => 'The Admin',
        ]);

        // Same real shape as contacts 16414/16620 on QA1: a genuine
        // sale-primary buyer who also picked up an older, non-primary
        // rental match somewhere along the way.
        $mixed = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Mixed', 'last_name' => 'Wishlist',
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'mixed-' . Str::random(5) . '@example.co.za',
            'agent_id' => $admin->id,
        ]);
        // Older, non-primary rental match.
        ContactMatch::create(['contact_id' => $mixed->id, 'listing_type' => 'rental', 'is_primary' => false]);
        // Current, primary match — a sale.
        ContactMatch::create(['contact_id' => $mixed->id, 'listing_type' => 'sale', 'is_primary' => true]);

        return [$admin, $mixed];
    }

    /**
     * Real HTTP-dispatched request through the real router — NOT a
     * manually-built Request object handed straight to the controller.
     * $request->route() must be populated for BuyerPipelineController::
     * index() to work at all (AT-401's route-name lock reads it on line
     * 30); a hand-built Request never has a matched route, so that pattern
     * crashes unconditionally regardless of this fix. Real routing also
     * means the rental case below genuinely exercises the SAME locked
     * entry point (corex.rentals.pipeline.index) Johan's own bug report
     * used, not just a lead_type query param on the general board.
     */
    private function rentalPipelineColumns(User $viewer): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($viewer)->get(route('corex.rentals.pipeline.index', ['view' => 'kanban', 'scope' => 'agency']));
        $response->assertOk();

        return collect($response->viewData('columns'))->flatMap(fn ($col) => $col->pluck('id'));
    }

    private function saleFilteredPipelineColumns(User $viewer): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($viewer)->get(route('command-center.buyers.pipeline', ['view' => 'kanban', 'scope' => 'agency', 'lead_type' => 'sale']));
        $response->assertOk();

        return collect($response->viewData('columns'))->flatMap(fn ($col) => $col->pluck('id'));
    }

    public function test_a_sale_primary_contact_with_a_secondary_rental_match_is_excluded_from_the_rental_board(): void
    {
        [$admin, $mixed] = $this->scenarioWithMixedWishlistContact();

        $rentalBoardIds = $this->rentalPipelineColumns($admin);

        $this->assertFalse(
            $rentalBoardIds->contains($mixed->id),
            'a contact whose PRIMARY interest is a sale must never appear on the Rental Pipeline just because they also carry an old, non-primary rental match'
        );
    }

    public function test_a_sale_primary_contact_with_a_secondary_rental_match_still_appears_on_the_sale_board(): void
    {
        [$admin, $mixed] = $this->scenarioWithMixedWishlistContact();

        $saleBoardIds = $this->saleFilteredPipelineColumns($admin);

        $this->assertTrue(
            $saleBoardIds->contains($mixed->id),
            'a contact whose PRIMARY interest is genuinely a sale must never be silently dropped from the sale-filtered board just because they also carry an old, non-primary rental match — this is the invisible half of the bug nobody reported'
        );
    }

    public function test_the_filter_and_the_card_label_now_derive_from_the_same_method(): void
    {
        [, $mixed] = $this->scenarioWithMixedWishlistContact();
        $mixed->load('matches');

        $this->assertFalse($mixed->primaryMatchIsRental(), 'the primary match is the sale one — this contact is not a rental lead');
        $this->assertSame('sale', $mixed->primaryMatch()->listing_type);
    }

    public function test_an_untyped_buyer_with_no_matches_at_all_still_defaults_to_the_sale_board(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin', 'name' => 'The Admin',
        ]);
        $untyped = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Untyped', 'last_name' => 'Manual',
            'phone' => '084' . random_int(1000000, 9999999),
            'email' => 'untyped-' . Str::random(5) . '@example.co.za',
            'agent_id' => $admin->id,
        ]);

        $rentalBoardIds = $this->rentalPipelineColumns($admin);
        $saleBoardIds = $this->saleFilteredPipelineColumns($admin);

        $this->assertFalse($rentalBoardIds->contains($untyped->id));
        $this->assertTrue($saleBoardIds->contains($untyped->id), 'a manually-added buyer with no wishlist at all must still default to the sale board — the fix must not regress this pre-existing rule');
    }
}
