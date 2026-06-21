<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-74 hotfix — MIC "Buyer matched" read 0 because the prospecting recompute
 * CRASHED. A broad wishlist matches thousands of canvass listings; the single
 * upsert of all matched rows exceeded MySQL's 65,535-placeholder limit
 * (SQLSTATE 1390) and the whole write threw — caught + swallowed by
 * RegenerateBuyerMatchesJob, leaving prospecting_buyer_matches empty. The write
 * is now chunked. This guards that >500 matches (multiple chunks) persist fully.
 */
final class ProspectingMatchChunkedUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
    }

    public function test_buyer_matching_many_listings_persists_all_without_placeholder_crash(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agent->id,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Broad', 'last_name' => 'Buyer',
            'phone' => '0820000000', 'email' => 'broad-' . Str::random(5) . '@example.co.za',
        ]);
        // A broad-but-countable wishlist: a wide price band that every listing falls into.
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 100_000, 'price_max' => 10_000_000,
        ]);

        // 600 active canvass listings (> the 500 chunk size → forces ≥2 chunks).
        $LISTINGS = 600;
        $rows = [];
        for ($i = 0; $i < $LISTINGS; $i++) {
            $rows[] = [
                'agency_id'           => $agencyId,
                'captured_by_user_id' => $agent->id,
                'portal_source'       => 'p24',
                'portal_ref'          => 'ref-' . $i . '-' . Str::random(6),
                'portal_url'          => 'https://example.com/' . $i,
                'address'             => $i . ' Test Road',
                'suburb'              => 'Uvongo',
                'price'               => 1_500_000,
                'is_active'           => 1,
                'first_seen_at'       => now(),
                'last_seen_at'        => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $batch) {
            DB::table('prospecting_listings')->insert($batch);
        }

        // Before the fix this threw SQLSTATE 1390 and wrote nothing.
        $written = app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $persisted = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->count();

        $this->assertSame($LISTINGS, $written, 'recompute should report all matches written');
        $this->assertSame($LISTINGS, $persisted, 'every match must persist across chunks (no 1390 crash)');

        // MIC "Buyer matched" reads distinct prospecting_listing_id with a non-dismissed row.
        $micMatched = DB::table('prospecting_buyer_matches')
            ->where('agency_id', $agencyId)->whereNull('dismissed_at')
            ->distinct()->count('prospecting_listing_id');
        $this->assertSame($LISTINGS, $micMatched, 'MIC buyer-matched listings now reflect the real matches');
    }
}
