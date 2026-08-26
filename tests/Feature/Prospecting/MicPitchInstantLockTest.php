<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\ProspectingListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC funnel phase 1 — INSTANT LOCK on Pitch Now (Johan 2026-08-13).
 *
 * The moment an agent clicks "Pitch now", a temp lock is written
 * (EntryPointController::fromProspecting → ProspectingClaimService::createTempLock)
 * BEFORE the composer opens. From that instant the listing must drop out of every
 * OTHER agent's canvassing pool so a second agent can't click it in parallel — not
 * after the pitch is saved. The locking agent still sees their own row.
 *
 * NOTE: RefreshDatabase — validated on Johan's dev-check machine (schema snapshot).
 * Not runnable on the shared QA1 checkout (live DB) or corex_dev2 (migrate blocked);
 * the same behaviour is additionally verified against the live QA1 runtime by hand.
 */
final class MicPitchInstantLockTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:int,1:User,2:User} [agencyId, adminViewer, otherAgent] */
    private function seedAgencyWithUsers(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Admin viewer — bypasses the access_prospecting permission gate.
        $viewer = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $other  = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        return [$agencyId, $viewer, $other];
    }

    private function seedListing(int $agencyId, string $address, string $suburb): ProspectingListing
    {
        $capturedBy = (int) DB::table('users')->where('agency_id', $agencyId)->value('id');
        $id = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $agencyId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $capturedBy,
            'is_active'           => true,
            'address'             => $address,
            'suburb'              => $suburb,
            'normalized_address'  => ProspectingListing::normalizeAddress($address, $suburb),
            'price'               => 1000000,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);
        return ProspectingListing::findOrFail($id);
    }

    private function tempLock(int $agencyId, int $listingId, int $userId, ?\DateTimeInterface $expiresAt = null): void
    {
        DB::table('prospecting_pitch_locks')->insert([
            'agency_id'              => $agencyId,
            'prospecting_listing_id' => $listingId,
            'user_id'                => $userId,
            'locked_at'              => now(),
            'expires_at'             => $expiresAt ?? now()->addMinutes(30),
            'created_at'             => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int> the ids present in the work-list */
    private function workListingIds($response): array
    {
        $listings = $response->viewData('listings');
        return collect($listings->items())->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_a_listing_another_agent_is_pitching_is_hidden_from_the_pool(): void
    {
        [$agencyId, $viewer, $other] = $this->seedAgencyWithUsers();
        $locked   = $this->seedListing($agencyId, '10 Locked Road', 'Margate');
        $unlocked = $this->seedListing($agencyId, '20 Open Road', 'Margate');

        // Another agent clicked Pitch Now on $locked → active temp lock.
        $this->tempLock($agencyId, $locked->id, $other->id);

        $response = $this->actingAs($viewer)->get(route('market-intelligence.work'));
        $response->assertOk();
        $ids = $this->workListingIds($response);

        $this->assertNotContains($locked->id, $ids, 'a listing another agent is actively pitching is hidden');
        $this->assertContains($unlocked->id, $ids, 'an unlocked listing stays in the pool');
    }

    public function test_the_locking_agent_still_sees_their_own_locked_listing(): void
    {
        [$agencyId, , $other] = $this->seedAgencyWithUsers();
        // Make the locker an admin too so they pass the gate and we test the user_id carve-out.
        $other->update(['role' => 'admin']);
        $locked = $this->seedListing($agencyId, '10 Mine Road', 'Margate');
        $this->tempLock($agencyId, $locked->id, $other->id);

        $response = $this->actingAs($other)->get(route('market-intelligence.work'));
        $response->assertOk();

        $this->assertContains($locked->id, $this->workListingIds($response), 'the pitching agent still sees their own row');
    }

    public function test_an_expired_lock_does_not_hide_the_listing(): void
    {
        [$agencyId, $viewer, $other] = $this->seedAgencyWithUsers();
        $listing = $this->seedListing($agencyId, '30 Expired Road', 'Margate');
        $this->tempLock($agencyId, $listing->id, $other->id, now()->subMinute()); // already expired

        $response = $this->actingAs($viewer)->get(route('market-intelligence.work'));
        $response->assertOk();

        $this->assertContains($listing->id, $this->workListingIds($response), 'an expired lock releases the listing back to the pool');
    }
}
