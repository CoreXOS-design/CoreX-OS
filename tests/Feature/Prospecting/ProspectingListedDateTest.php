<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\ProspectingListingDateCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-454 (Road to Sold) piece 2 — a REAL listed date for MIC listings.
 *
 *  - the capture API accepts and stores the advert's listed date, apart from first_seen_at
 *  - a malformed / future date never fails the batch (absorbed to null)
 *  - daysOnMarket / "new" / "long" use the listed date when present, else first sighting
 *  - an agent can correct the date with a reason; history kept; later captures never overwrite it
 */
final class ProspectingListedDateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
    }

    private function capture(array $listing)
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/v1/prospecting/import', [
            'source'         => 'p24',
            'search_context' => [
                'url' => 'https://www.property24.com/for-sale/margate/1/p1',
                'search_term' => 'Margate for sale', 'total_results' => 1, 'pages_captured' => 1,
            ],
            'listings' => [array_merge([
                'portal_ref' => 'P24-5001', 'address' => '12 Marine Drive', 'suburb' => 'Margate',
                'price' => 1850000, 'portal_url' => 'https://www.property24.com/listing/5001',
            ], $listing)],
        ]);
    }

    private function row(string $ref = 'P24-5001'): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->where('portal_ref', $ref)->firstOrFail();
    }

    // ── capture ───────────────────────────────────────────────────────────

    public function test_capture_stores_the_real_listed_date_apart_from_first_seen(): void
    {
        $this->capture(['listed_date' => '2026-07-17'])->assertOk()->assertJson(['imported' => 1]);

        $l = $this->row();
        $this->assertSame('2026-07-17', $l->listed_date->toDateString());
        $this->assertSame(ProspectingListing::LISTED_DATE_SOURCE_PORTAL, $l->listed_date_source);
        $this->assertTrue($l->first_seen_at->isToday(), 'first_seen_at stays the capture time');
    }

    public function test_capture_without_a_date_leaves_it_null(): void
    {
        $this->capture([])->assertOk();

        $l = $this->row();
        $this->assertNull($l->listed_date);
        $this->assertNull($l->listed_date_source);
        $this->assertFalse($l->hasRealListedDate());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableDates')]
    public function test_a_malformed_or_future_date_never_fails_the_batch(string $raw): void
    {
        $this->capture(['listed_date' => $raw])->assertOk()->assertJson(['imported' => 1]);

        $this->assertNull($this->row()->listed_date, "'{$raw}' must be absorbed to null");
    }

    public static function unusableDates(): array
    {
        return [
            'zero'         => ['0'],
            'dash'         => ['-'],
            'words'        => ['Listed soon'],
            'future'       => [now()->addDays(10)->toDateString()],
            'before 2000'  => ['1989-03-01'],
            'impossible'   => ['2026-02-31'],
        ];
    }

    public function test_a_portal_date_in_the_portals_own_format_is_understood(): void
    {
        $this->capture(['listed_date' => '17 July 2026'])->assertOk();
        $this->assertSame('2026-07-17', $this->row()->listed_date->toDateString());
    }

    public function test_a_recapture_updates_a_portal_date_but_never_an_agent_correction(): void
    {
        $this->capture(['listed_date' => '2026-07-17'])->assertOk();
        $this->capture(['listed_date' => '2026-08-01'])->assertOk();
        $this->assertSame('2026-08-01', $this->row()->listed_date->toDateString());

        // an agent corrects it; a later capture must not undo that
        $this->row()->forceFill([
            'listed_date' => '2026-05-05', 'listed_date_source' => ProspectingListing::LISTED_DATE_SOURCE_AGENT,
        ])->save();
        $this->capture(['listed_date' => '2026-09-09'])->assertOk();

        $l = $this->row();
        $this->assertSame('2026-05-05', $l->listed_date->toDateString());
        $this->assertSame(ProspectingListing::LISTED_DATE_SOURCE_AGENT, $l->listed_date_source);
    }

    public function test_a_recapture_without_a_date_keeps_the_stored_one(): void
    {
        $this->capture(['listed_date' => '2026-07-17'])->assertOk();
        $this->capture([])->assertOk();

        $this->assertSame('2026-07-17', $this->row()->listed_date->toDateString());
    }

    // ── days on market, new, long ────────────────────────────────────────

    public function test_days_on_market_uses_the_listed_date_else_first_seen(): void
    {
        $withDate = $this->listing('P24-A', listed: now()->subDays(40), firstSeen: now()->subDays(5));
        $without  = $this->listing('P24-B', listed: null, firstSeen: now()->subDays(5));

        $this->assertSame(40, $withDate->daysOnMarket());
        $this->assertSame(5, $without->daysOnMarket());
        $this->assertTrue($withDate->hasRealListedDate());
        $this->assertFalse($without->hasRealListedDate());
    }

    public function test_days_on_market_stops_at_the_off_market_date(): void
    {
        $l = $this->listing('P24-C', listed: now()->subDays(30), firstSeen: now()->subDays(2));
        $l->off_market_at = now()->subDays(10);

        $this->assertSame(20, $l->daysOnMarket());
    }

    public function test_new_and_long_on_market_scopes_prefer_the_real_date(): void
    {
        // captured yesterday, but the portal says it has been up 100 days -> LONG, not new
        $old      = $this->listing('P24-OLD', listed: now()->subDays(100), firstSeen: now()->subDay());
        // captured long ago, portal date is last week -> NEW
        $fresh    = $this->listing('P24-FRESH', listed: now()->subDays(3), firstSeen: now()->subDays(200));
        // no real date: first sighting decides (the legacy behaviour)
        $legacyNew  = $this->listing('P24-LNEW', listed: null, firstSeen: now()->subDays(2));
        $legacyLong = $this->listing('P24-LLONG', listed: null, firstSeen: now()->subDays(150));

        $new  = ProspectingListing::withoutGlobalScopes()->listedWithinDays(14)->pluck('id')->all();
        $long = ProspectingListing::withoutGlobalScopes()->listedBeforeDays(90)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$fresh->id, $legacyNew->id], $new);
        $this->assertEqualsCanonicalizing([$old->id, $legacyLong->id], $long);
    }

    // ── correction ───────────────────────────────────────────────────────

    public function test_an_agent_can_correct_the_date_with_a_reason_and_history_is_kept(): void
    {
        $l = $this->listing('P24-D', listed: null, firstSeen: now()->subDays(5));

        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(60)->toDateString(),
                'reason'      => 'Seller confirmed it went on in August',
            ])->assertRedirect();

        $l->refresh();
        $this->assertSame(now()->subDays(60)->toDateString(), $l->listed_date->toDateString());
        $this->assertSame(ProspectingListing::LISTED_DATE_SOURCE_AGENT, $l->listed_date_source);

        $h = ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->get();
        $this->assertCount(1, $h);
        $this->assertNull($h[0]->old_date);
        $this->assertSame((int) $this->user->id, (int) $h[0]->user_id);
        $this->assertSame((int) $this->agency->id, (int) $h[0]->agency_id);
        $this->assertStringContainsString('August', $h[0]->reason);

        // a second correction keeps the first as history
        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(45)->toDateString(),
                'reason'      => 'Re-checked the mandate paperwork',
            ])->assertRedirect();

        $this->assertSame(2, ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->count());
        $second = ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->orderByDesc('id')->first();
        $this->assertSame(now()->subDays(60)->toDateString(), $second->old_date->toDateString());
    }

    public function test_a_correction_needs_a_reason_and_a_sane_date(): void
    {
        $l = $this->listing('P24-E', listed: null, firstSeen: now()->subDays(5));
        $url = route('market-intelligence.listing.listed-date.update', $l->id);

        $this->actingAs($this->user)->put($url, ['listed_date' => now()->subDays(3)->toDateString(), 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->user)->put($url, ['listed_date' => now()->addDay()->toDateString(), 'reason' => 'Because I said so'])
            ->assertSessionHasErrors('listed_date');
        $this->actingAs($this->user)->put($url, ['listed_date' => 'not a date', 'reason' => 'Because I said so'])
            ->assertSessionHasErrors('listed_date');

        $this->assertNull($l->fresh()->listed_date);
        $this->assertSame(0, ProspectingListingDateCorrection::withoutGlobalScopes()->count());
    }

    public function test_another_agencys_listing_is_a_404_not_a_403(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherUser = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $theirs = $this->listing('P24-THEIRS', listed: null, firstSeen: now()->subDay(), agencyId: $other->id, branchId: $otherBranch->id, capturedBy: $otherUser->id);

        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $theirs->id), [
                'listed_date' => now()->subDays(3)->toDateString(), 'reason' => 'Trying another agency',
            ])->assertNotFound();

        $this->assertNull($theirs->fresh()->listed_date);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        // Make the role grants non-empty so the "no grants = permit all" fallback does not apply.
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'access_prospecting', 'scope' => null,
            'agency_id' => $this->agency->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $l = $this->listing('P24-F', listed: null, firstSeen: now()->subDay());

        $this->actingAs($agent)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(3)->toDateString(), 'reason' => 'No permission for this',
            ])->assertForbidden();

        $this->assertNull($l->fresh()->listed_date);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function listing(
        string $ref,
        $listed,
        $firstSeen,
        ?int $agencyId = null,
        ?int $branchId = null,
        ?int $capturedBy = null,
    ): ProspectingListing {
        $id = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $agencyId ?? $this->agency->id,
            'branch_id'           => $branchId ?? $this->branch->id,
            'captured_by_user_id' => $capturedBy ?? $this->user->id,
            'portal_source'       => 'p24',
            'portal_ref'          => $ref,
            'portal_url'          => 'https://example.test/' . $ref,
            'address'             => '1 Test Road',
            'suburb'              => 'Margate',
            'price'               => 1000000,
            'is_active'           => true,
            'listed_date'         => $listed?->toDateString(),
            'listed_date_source'  => $listed ? 'portal' : null,
            'first_seen_at'       => $firstSeen,
            'last_seen_at'        => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        return ProspectingListing::withoutGlobalScopes()->findOrFail($id);
    }
}
