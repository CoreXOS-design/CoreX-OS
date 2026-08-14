<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FIX 1 (Johan 2026-08-14) — "Pitch Now" must DURABLY persist the claim immediately, so an agent
 * can leave the compose screen to scrape CMA/TVA and return with the listing still theirs. The
 * durable claim (pitched_at) holds it for the agency stale window, independent of the 30-min temp
 * lock, and closes the duplication window that opened when the temp lock expired before submit.
 */
final class MicPitchNowDurableClaimTest extends TestCase
{
    use RefreshDatabase;

    /** Opening the compose screen (Pitch Now) writes a durable, pitched claim on the spot. */
    public function test_pitch_now_persists_a_durable_pitched_claim(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, ['address' => '1486 Beaumont', 'suburb' => 'Ramsgate']);

        $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]))
            ->assertStatus(200);

        $claim = ProspectingClaim::where('prospecting_listing_id', $listingId)->first();
        $this->assertNotNull($claim, 'Pitch Now must persist a claim immediately');
        $this->assertSame($userId, (int) $claim->user_id);
        $this->assertNotNull($claim->pitched_at, 'the claim must be durably pitched (pool-hidden, no 48h expiry)');
        $this->assertTrue((bool) $claim->is_active);
        $this->assertNull($claim->released_at);
    }

    /** Re-opening the screen is idempotent — the same agent never accumulates duplicate claims. */
    public function test_reopening_is_idempotent_for_the_same_agent(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, ['address' => '1486 Beaumont']);

        $route = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]);
        $this->actingAs(User::find($userId))->get($route)->assertStatus(200);
        $this->actingAs(User::find($userId))->get($route)->assertStatus(200);

        $this->assertSame(1, ProspectingClaim::where('prospecting_listing_id', $listingId)->count());
    }

    /**
     * THE anti-poaching guarantee: after the agent leaves (even once the 30-min temp lock has
     * expired), another agent cannot grab the listing — the durable claim blocks them.
     */
    public function test_claim_survives_temp_lock_expiry_and_is_not_grabbable_by_another_agent(): void
    {
        [$agencyId, $aliceId] = $this->seedAgency();
        $bob = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $listingId = $this->seedProspectingListing($agencyId, ['address' => '1486 Beaumont']);

        $route = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]);

        // Alice pitches now → durable claim + temp lock.
        $this->actingAs(User::find($aliceId))->get($route)->assertStatus(200);
        $aliceClaimId = (int) ProspectingClaim::where('prospecting_listing_id', $listingId)->value('id');
        $this->assertNotSame(0, $aliceClaimId);

        // Simulate Alice leaving to scrape long enough that her 30-min temp lock expires.
        DB::table('prospecting_pitch_locks')
            ->where('prospecting_listing_id', $listingId)
            ->update(['expires_at' => now()->subMinutes(5)]);

        // Bob tries to pitch the same listing — the temp lock is gone, but the durable claim stops him.
        $this->actingAs($bob)
            ->get($route)
            ->assertRedirect(route('market-intelligence.work'))
            ->assertSessionHas('error');

        // No claim for Bob; Alice's claim is intact and still hers.
        $this->assertSame(1, ProspectingClaim::where('prospecting_listing_id', $listingId)->where('is_active', true)->count());
        $claim = ProspectingClaim::find($aliceClaimId);
        $this->assertSame($aliceId, (int) $claim->user_id);
        $this->assertNotNull($claim->pitched_at);
        $this->assertNull($claim->released_at);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'name' => 'Agency Agent']);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);

        return [$agencyId, $user->id];
    }

    private function seedProspectingListing(int $agencyId, array $overrides): int
    {
        $defaultCapturedBy = (int) DB::table('users')->where('agency_id', $agencyId)->orderBy('id')->value('id');

        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id'           => $agencyId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $defaultCapturedBy,
            'is_active'           => true,
            'address'             => '99 Unknown',
            'suburb'              => 'Unknown',
            'price'               => 0,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));
    }
}
