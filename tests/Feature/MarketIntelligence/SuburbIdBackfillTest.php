<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Observers\MicSuburbIdObserver;
use App\Services\Address\SuburbResolver;
use App\Services\MarketIntelligence\Suburbs\SuburbIdBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/mic-work-tab.md section 8, step 5.1 (AT-519): one suburb list, p24_suburb_id on the MIC tables.
 * Uvongo and Uvongo Beach are two real P24 suburbs and must never be merged; unresolvable text stays NULL.
 */
final class SuburbIdBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $user;
    private P24Suburb $uvongo;
    private P24Suburb $uvongoBeach;
    private P24Suburb $margate;

    protected function setUp(): void
    {
        parent::setUp();
        MicSuburbIdObserver::flush();
        SuburbResolver::flush();

        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $province = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 376, 'p24_province_id' => $province->id, 'name' => 'Ray Nkonyeni']);
        $this->uvongo = P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo']);
        $this->uvongoBeach = P24Suburb::create(['p24_id' => 33106, 'p24_city_id' => $city->id, 'name' => 'Uvongo Beach', 'slug' => 'uvongo-beach']);
        $this->margate = P24Suburb::create(['p24_id' => 6360, 'p24_city_id' => $city->id, 'name' => 'Margate', 'slug' => 'margate']);

        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function rawListing(Agency $agency, string $ref, string $suburb, ?int $suburbId = null): int
    {
        return DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $agency->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => $ref,
            'listing_type' => 'sale', 'suburb' => $suburb, 'p24_suburb_id' => $suburbId, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_new_listing_gets_its_own_suburbs_id_and_uvongo_is_never_uvongo_beach(): void
    {
        $l1 = ProspectingListing::create(['agency_id' => $this->a->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-1', 'suburb' => 'Uvongo', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true]);
        $l2 = ProspectingListing::create(['agency_id' => $this->a->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-2', 'suburb' => 'Uvongo Beach', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true]);
        $l3 = ProspectingListing::create(['agency_id' => $this->a->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-3', 'suburb' => 'Nowhereville', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true]);

        $this->assertSame($this->uvongo->id, (int) $l1->fresh()->p24_suburb_id);
        $this->assertSame($this->uvongoBeach->id, (int) $l2->fresh()->p24_suburb_id);
        $this->assertNull($l3->fresh()->p24_suburb_id, 'unresolvable text is never guessed');
        $this->assertNotSame($l1->fresh()->p24_suburb_id, $l2->fresh()->p24_suburb_id);
    }

    public function test_dry_run_writes_nothing_and_apply_fills_only_empty_ids_with_a_journal(): void
    {
        $empty = $this->rawListing($this->a, 'P24-10', 'Margate');
        $twin = $this->rawListing($this->a, 'P24-11', 'UVONGO BEACH');
        $unknown = $this->rawListing($this->a, 'P24-12', 'Nowhereville');
        $keep = $this->rawListing($this->a, 'P24-13', 'Margate', 999999);   // already has an id: must stay

        $svc = app(SuburbIdBackfill::class);
        $dry = $svc->run(['prospecting_listings'], $this->a->id, false);
        $this->assertSame(0, DB::table('mic_data_journals')->count());
        $this->assertNull(DB::table('prospecting_listings')->where('id', $empty)->value('p24_suburb_id'));
        $this->assertSame(2, $dry['tables']['prospecting_listings']['would_fill']);
        $this->assertSame(1, $dry['tables']['prospecting_listings']['unresolved_rows']);

        $run = $svc->run(['prospecting_listings'], $this->a->id, true);
        $this->assertSame($this->margate->id, (int) DB::table('prospecting_listings')->where('id', $empty)->value('p24_suburb_id'));
        $this->assertSame($this->uvongoBeach->id, (int) DB::table('prospecting_listings')->where('id', $twin)->value('p24_suburb_id'));
        $this->assertNull(DB::table('prospecting_listings')->where('id', $unknown)->value('p24_suburb_id'));
        $this->assertSame(999999, (int) DB::table('prospecting_listings')->where('id', $keep)->value('p24_suburb_id'));
        $this->assertSame(2, DB::table('mic_data_journals')->where('run_id', $run['run_id'])->count());
        $this->assertSame(1, $run['tables']['prospecting_listings']['after']['null_rows'], 'only the unresolvable one is left empty');

        $this->assertSame(2, $svc->rollback($run['run_id']));
        $this->assertNull(DB::table('prospecting_listings')->where('id', $empty)->value('p24_suburb_id'));
        $this->assertSame(999999, (int) DB::table('prospecting_listings')->where('id', $keep)->value('p24_suburb_id'));
    }

    public function test_the_agency_option_never_touches_another_agencys_rows(): void
    {
        $mine = $this->rawListing($this->a, 'P24-20', 'Margate');
        $theirs = $this->rawListing($this->b, 'P24-21', 'Margate');

        app(SuburbIdBackfill::class)->run(['prospecting_listings'], $this->a->id, true);

        $this->assertNotNull(DB::table('prospecting_listings')->where('id', $mine)->value('p24_suburb_id'));
        $this->assertNull(DB::table('prospecting_listings')->where('id', $theirs)->value('p24_suburb_id'));
    }
}
