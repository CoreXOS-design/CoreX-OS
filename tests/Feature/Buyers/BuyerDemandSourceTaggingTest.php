<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\User;
use App\Services\Buyers\BuyerLeadCascadeService;
use App\Services\Prospecting\BuyerMatchTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Buyer-lifecycle loop:
 *  - Part 6 hard rule: MIC demand is SOURCE-TAGGED and counted SEPARATELY (portal-lead
 *    vs other) and never blended — the displayed demand equals the visible sum of parts.
 *  - tagBuyerSource is first-write-wins (a buyer's primary entry origin is preserved).
 */
final class BuyerDemandSourceTaggingTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'Loop Test Agency', 'slug' => 'loop-test-agency']);
        $this->agencyId = (int) $agency->id;

        $this->branchId = (int) DB::table('branches')->insertGetId([
            'name' => 'Loop Branch', 'agency_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = (int) User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
        ])->id;
    }

    public function test_demand_is_split_by_source_and_parts_sum_to_the_displayed_total(): void
    {
        $listingId = $this->makeListing();

        // Two portal-lead buyers + one manual + one null/legacy, all strong-tier.
        $this->seedMatch($listingId, BuyerLeadCascadeService::SOURCE_PORTAL_P24, 90);
        $this->seedMatch($listingId, BuyerLeadCascadeService::SOURCE_PORTAL_PP, 88);
        $this->seedMatch($listingId, BuyerLeadCascadeService::SOURCE_MANUAL, 85);
        $this->seedMatch($listingId, null, 82);

        $tiers = app(BuyerMatchTierService::class)->tiersForListings([$listingId], $this->agencyId);
        $row = $tiers[$listingId] ?? null;

        $this->assertNotNull($row, 'listing should have demand');
        $this->assertArrayHasKey('sources', $row);

        // Portal-lead stream counted on its own (the 2 portal buyers)...
        $this->assertSame(2, $row['sources']['portal_lead'], 'portal-lead demand');
        // ...and everything else (manual + null) is the separate "other" stream.
        $this->assertSame(2, $row['sources']['other'], 'other demand');

        // The two source parts are a VISIBLE SUM of the displayed demand (strong+mid),
        // never a single blended figure.
        $displayedDemand = $row['strong'] + $row['mid'];
        $this->assertSame(
            $displayedDemand,
            $row['sources']['portal_lead'] + $row['sources']['other'],
            'portal-lead + other must equal the displayed strong+mid demand'
        );
    }

    public function test_tag_buyer_source_is_first_write_wins(): void
    {
        $contact = $this->makeContact();
        $svc = app(BuyerLeadCascadeService::class);

        $svc->tagBuyerSource($contact, BuyerLeadCascadeService::SOURCE_PORTAL_P24);
        $contact->refresh();
        $this->assertTrue((bool) $contact->is_buyer);
        $this->assertSame(BuyerLeadCascadeService::SOURCE_PORTAL_P24, $contact->buyer_source);

        // A later manual touch must NOT overwrite the primary (portal) origin.
        $svc->tagBuyerSource($contact, BuyerLeadCascadeService::SOURCE_MANUAL);
        $contact->refresh();
        $this->assertSame(BuyerLeadCascadeService::SOURCE_PORTAL_P24, $contact->buyer_source);
    }

    private function makeListing(): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'          => $this->agencyId,
            'captured_by_user_id' => $this->userId,
            'portal_source'      => 'p24',
            'portal_ref'         => 'LOOP-' . random_int(1000, 9999),
            'portal_url'         => 'https://example.test/l',
            'address'            => '1 Test Road',
            'suburb'             => 'Testville',
            'price'              => 1000000,
            'first_seen_at'      => now(),
            'last_seen_at'       => now(),
            'is_active'          => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function makeContact(): Contact
    {
        return Contact::create([
            'first_name' => 'Lead',
            'last_name'  => 'Buyer' . random_int(1000, 9999),
            'phone'      => '076' . random_int(1000000, 9999999),
            'branch_id'  => $this->branchId,
            'agency_id'  => $this->agencyId,
        ]);
    }

    private function seedMatch(int $listingId, ?string $source, int $score): void
    {
        $contact = $this->makeContact();

        DB::table('prospecting_buyer_matches')->insert([
            'prospecting_listing_id' => $listingId,
            'contact_id'             => $contact->id,
            'agency_id'              => $this->agencyId,
            'score'                  => $score,
            'tier'                   => 'strong',
            'source'                 => $source,
            'matched_at'             => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }
}
