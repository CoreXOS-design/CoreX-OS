<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cc6, 2026-09-14 — Rental Pipeline screen audit, tasks 1-3:
 *  - date-range filter (BUILD_STANDARD.md §1b, the one confirmed missing piece)
 *  - per-card Sale/Rental badge, shown ONLY on the mixed ("All") board — a
 *    badge on a single-type-locked/filtered view carries no information
 *  - the "Rentals only" status label reads as a caption, not a control
 */
final class BuyerPipelineScreenStandardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function scenario(): array
    {
        $agency = Agency::create([
            'name' => 'THROWAWAY Screen Standard ' . Str::random(6),
            'slug' => 'throwaway-screen-' . Str::random(8),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'THROWAWAY Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch, 'role' => 'admin',
        ]);
        DB::table('role_permissions')->insert([
            'role' => 'admin', 'permission_key' => 'buyer_pipeline.view',
            'agency_id' => $agency->id, 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $makeBuyer = function (string $listingType, \DateTimeInterface $enteredAt, string $lastName) use ($agency, $branch, $admin) {
            $c = Contact::withoutGlobalScopes()->create([
                'agency_id' => $agency->id, 'branch_id' => $branch,
                'is_buyer' => true, 'buyer_state' => 'new',
                'buyer_pipeline_entered_at' => $enteredAt,
                'first_name' => 'THROWAWAY', 'last_name' => $lastName,
                'phone' => '083' . random_int(1000000, 9999999),
                'email' => 'throwaway-' . Str::random(6) . '@example.co.za',
                'agent_id' => $admin->id, 'created_by_user_id' => $admin->id,
            ]);
            ContactMatch::create(['contact_id' => $c->id, 'listing_type' => $listingType, 'is_primary' => true]);

            return $c;
        };

        $oldRental = $makeBuyer('rental', now()->subDays(90), 'OldRentalMar');
        $recentSale = $makeBuyer('sale', now()->subDays(2), 'RecentSaleJun');

        return [$agency, $admin, $oldRental, $recentSale];
    }

    public function test_date_range_filter_narrows_by_buyer_pipeline_entered_at(): void
    {
        [$agency, $admin, $oldRental, $recentSale] = $this->scenario();

        // Only the recent one should survive a from-filter of 7 days ago.
        $response = $this->actingAs($admin)->get(route('command-center.buyers.pipeline', [
            'view' => 'list', 'scope' => 'agency', 'entered_from' => now()->subDays(7)->toDateString(),
        ]));
        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('RecentSaleJun', $body);
        $this->assertStringNotContainsString('OldRentalMar', $body);

        // Malformed date input is absorbed, not a 500.
        $bad = $this->actingAs($admin)->get(route('command-center.buyers.pipeline', [
            'view' => 'list', 'scope' => 'agency', 'entered_from' => 'not-a-date',
        ]));
        $bad->assertOk();

        $oldRental->delete();
        $recentSale->delete();
        $agency->delete();
    }

    public function test_mixed_type_badge_shows_only_on_the_all_board_not_on_locked_or_filtered_views(): void
    {
        [$agency, $admin, $oldRental, $recentSale] = $this->scenario();

        // General board, no lead_type filter — genuinely mixed, badge expected.
        $mixed = $this->actingAs($admin)->get(route('command-center.buyers.pipeline', ['view' => 'list', 'scope' => 'agency']));
        $mixed->assertOk();
        $this->assertStringContainsString('>RENTAL<', $mixed->getContent());
        $this->assertStringContainsString('>SALE<', $mixed->getContent());

        // Same board filtered to Sales only — every visible row is already
        // sale by construction, badge would be pure noise.
        $salesOnly = $this->actingAs($admin)->get(route('command-center.buyers.pipeline', [
            'view' => 'list', 'scope' => 'agency', 'lead_type' => 'sale',
        ]));
        $salesOnly->assertOk();
        $this->assertStringNotContainsString('>SALE<', $salesOnly->getContent());
        $this->assertStringNotContainsString('>RENTAL<', $salesOnly->getContent());

        $oldRental->delete();
        $recentSale->delete();
        $agency->delete();
    }

    public function test_rentals_only_label_is_a_caption_not_a_pill_control(): void
    {
        [$agency, $admin, $oldRental, $recentSale] = $this->scenario();

        $response = $this->actingAs($admin)->get(route('corex.rentals.pipeline.index', ['view' => 'list', 'scope' => 'agency']));
        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('Showing rentals only', $body);
        // The old pill markup (bordered div styled like the working toggles) is gone.
        $this->assertStringNotContainsString('>Rentals only<', $body);

        // Locked entry is single-type by construction — no per-card badge needed.
        $this->assertStringNotContainsString('>RENTAL<', $body);
        $this->assertStringNotContainsString('>SALE<', $body);

        $oldRental->delete();
        $recentSale->delete();
        $agency->delete();
    }
}
