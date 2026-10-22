<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Contact;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 — the cached "matches for this buyer" list (read by the public buyer-portal page) follows the same
 * Won/Lost rule as every other Core Matches surface (CoreMatchBuyerGate): excluded while Won or Lost, back
 * the moment the buyer is active again. Nothing is deleted from the cache or the saved searches.
 */
final class CoreMatchWonLostBuyerPortalListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_won_or_lost_buyer_gets_no_cached_matches_and_an_active_one_does(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'is_buyer' => true, 'buyer_state' => 'warm',
            'first_name' => 'Pat', 'last_name' => 'Portal ' . Str::random(4),
        ]);
        // The cache row is all this test reads; skip the properties FK rather than build a listing (a new Property fires a matching pass).
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('property_buyer_matches')->insert([
            'property_id' => 1, 'contact_id' => $buyer->id, 'agency_id' => $agencyId,
            'score' => 80, 'tier' => 'strong', 'computed_at' => now(),
        ]);
        $svc = app(PropertyMatchScoringService::class);

        $this->assertCount(1, $svc->getMatchesForBuyer($buyer->id), 'active buyer: listed');

        foreach (['won', 'lost'] as $state) {
            $buyer->updateQuietly(['buyer_state' => $state]);
            $this->assertCount(0, $svc->getMatchesForBuyer($buyer->id), "$state buyer: no matches");
        }

        $buyer->updateQuietly(['buyer_state' => 'cold']);
        $this->assertCount(1, $svc->getMatchesForBuyer($buyer->id), 'back on the active board: listed again, nothing was deleted');
    }
}
