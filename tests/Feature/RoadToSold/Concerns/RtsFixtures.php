<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\OnMarketStockService;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Selections\SelectionContext;
use App\Services\RoadToSold\Support\BuilderSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test data builders for the Road to Sold lane — real rows, no mocks of the database.
 * All data is created inside the test's transaction (RefreshDatabase) and vanishes with it.
 */
trait RtsFixtures
{
    protected Agency $agency;
    protected Branch $branch;
    protected User $agent;     // the agent the list is for
    protected User $mate;      // a second agent in the same agency

    protected function setUpAgency(): void
    {
        OnMarketStockService::flushCache();
        $this->agency = Agency::create(['name' => 'RTS Agency ' . uniqid(), 'slug' => 'rts-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = $this->makeAgent();
        $this->mate = $this->makeAgent();
    }

    protected function makeAgent(?Agency $agency = null, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'agency_id' => ($agency ?? $this->agency)->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'role' => 'agent', 'is_active' => true,
        ]);
    }

    protected function ctx(
        ?User $user = null,
        array $settings = [],
        array $areaIds = [],
        array $areaNames = [],
        ?SuggestionGuard $guard = null,
        array $excludeTracked = [],
        array $excludeListings = [],
        array $excludeProperties = [],
        ?Carbon $date = null,
    ): SelectionContext {
        $user ??= $this->agent;

        return new SelectionContext(
            user: $user,
            agencyId: (int) $user->agency_id,
            date: $date ?? Carbon::parse('2026-10-14'),
            track: 'sales',
            settings: (new BuilderSettings())->with($settings),
            guard: $guard ?? new NullSuggestionGuard(),
            areaSuburbIds: $areaIds,
            areaSuburbNames: $areaNames,
            areaSuburbNamesLower: array_map(fn ($n) => mb_strtolower(trim($n)), $areaNames),
            identityIds: $user->dataIdentityIds(),
            excludeTrackedIds: $excludeTracked,
            excludeListingIds: $excludeListings,
            excludePropertyIds: $excludeProperties,
        );
    }

    /** A tracked property. */
    protected function tp(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        $agency ??= $this->agency;

        return TrackedProperty::withoutAgencyStamping(fn () => TrackedProperty::create(array_merge([
            'agency_id' => $agency->id, 'suburb' => 'Margate', 'suburb_normalised' => 'margate',
            'status' => 'active', 'source_chain' => [],
        ], $attrs)));
    }

    /**
     * A live prospecting listing. $tp: a TrackedProperty to sit on, true to create a plain one, null for none.
     * Returns the listing id.
     */
    protected function listing(array $attrs = [], $tp = true, ?Agency $agency = null): int
    {
        $agency ??= $this->agency;
        if ($tp === true) {
            $tp = $this->tp(['street_name' => null], $agency);
        }
        $capturedBy = (int) DB::table('users')->where('agency_id', $agency->id)->value('id');

        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agency->id,
            'tracked_property_id' => $tp?->id,
            'captured_by_user_id' => $capturedBy,
            'portal_source' => 'p24',
            'portal_ref' => 'P24-' . random_int(100000, 999999) . Str::random(3),
            'portal_url' => 'https://example.test/' . Str::random(8),
            'address' => null,
            'suburb' => 'Margate',
            'price' => 1_500_000,
            'is_active' => 1,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    protected function buyer(int $agentId, string $state = 'new', ?int $secondAgentId = null, ?Agency $agency = null): Contact
    {
        $agency ??= $this->agency;

        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agentId,
            'agent_id' => $agentId, 'second_agent_id' => $secondAgentId,
            'is_buyer' => true, 'buyer_state' => $state,
            'first_name' => 'Buyer', 'last_name' => Str::random(5), 'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    protected function buyerMatch(int $listingId, int $contactId, string $tier = 'strong', int $score = 85, ?Agency $agency = null, bool $dismissed = false): void
    {
        DB::table('prospecting_buyer_matches')->insert([
            'prospecting_listing_id' => $listingId, 'contact_id' => $contactId,
            'agency_id' => ($agency ?? $this->agency)->id, 'score' => $score, 'tier' => $tier, 'source' => 'manual',
            'matched_at' => now(), 'last_recompute_at' => now(),
            'dismissed_at' => $dismissed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A CoreX property row. */
    protected function property(int $agentId, array $attrs = [], ?Agency $agency = null): int
    {
        $agency ??= $this->agency;

        return (int) DB::table('properties')->insertGetId(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agentId,
            'external_id' => (string) Str::uuid(), 'title' => 'Test property',
            'address' => '1 Test Road', 'suburb' => 'Margate', 'status' => 'active', 'listing_type' => 'sale',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @param Candidate[] $cands */
    protected function listingIds(array $cands): array
    {
        return array_map(fn ($c) => $c->prospectingListingId, $cands);
    }
}
