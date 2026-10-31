<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\Matching\AdvertLinkAudit;
use App\Services\Prospecting\Matching\MatchingRules;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.8 (conductor walk 31 Oct, 13 Newport Road): an advert whose own address / suburb contradicts the file it sits on goes back to its own
 * file (journalled); text-identical complex-style lines are not contradictions; R3 never moves an advert onto a file its own address contradicts.
 */
final class AdvertLinkAuditTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;
    private int $n = 112000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'role' => 'agent']);
    }

    private function file(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now()], $attrs));
    }

    private function advert(TrackedProperty $tp, ?string $address, string $suburb, array $extra = []): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(array_merge(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'address' => $address, 'suburb' => $suburb, 'price' => 1_500_000, 'bedrooms' => 3, 'bathrooms' => 2, 'property_type' => 'House',
            'agency_name' => 'Pam Golding Properties - North Durban', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'], $extra))->save();

        return $l->fresh();
    }

    private function fileOf(ProspectingListing $l): int
    {
        return (int) DB::table('prospecting_listings')->where('id', $l->id)->value('tracked_property_id');
    }

    private function province(): int
    {
        static $id = null;

        return $id ??= (int) DB::table('p24_provinces')->insertGetId(['name' => 'KwaZulu-Natal', 'p24_id' => 2, 'p24_country_id' => DB::table('p24_countries')->insertGetId(['name' => 'South Africa', 'p24_id' => 1, 'created_at' => now(), 'updated_at' => now()]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function newport(): TrackedProperty
    {
        return $this->file(['street_number' => '13', 'street_name' => 'Newport Road', 'suburb' => 'Port Edward']);
    }

    public function test_a_sibaya_advert_on_a_port_edward_house_is_found_and_moved_to_its_own_file(): void
    {
        $house = $this->newport();
        $ok = $this->advert($house, '13 Newport Road', 'Port Edward');
        $bad = $this->advert($house, '46 Heleza Boulevard', 'Sibaya Precinct');

        $dry = app(AdvertLinkAudit::class)->run($this->a->id, false);
        $this->assertSame(1, $dry['contradictions']);
        $this->assertSame($house->id, $this->fileOf($bad), 'a dry run moves nothing');

        $path = sys_get_temp_dir() . '/audit-journal-' . uniqid() . '.jsonl';
        app(TrackedPropertyMerger::class)->journalTo($path);
        $r = app(AdvertLinkAudit::class)->run($this->a->id, true);
        app(TrackedPropertyMerger::class)->journalTo(null);

        $this->assertSame(1, $r['moved_to_new']);
        $this->assertSame($house->id, $this->fileOf($ok));
        $new = TrackedProperty::queryWithoutAgencyScope()->find($this->fileOf($bad));
        $this->assertNotSame($house->id, (int) $new->id);
        $this->assertSame('Sibaya Precinct', $new->suburb);
        $this->assertSame('active', TrackedProperty::queryWithoutAgencyScope()->find($house->id)->status, 'the house keeps its other advert');
        $this->assertStringContainsString('"step":"link-audit","table":"prospecting_listings","id":' . $bad->id, (string) file_get_contents($path), 'the move is journalled');
        @unlink($path);
    }

    public function test_an_advert_goes_to_the_existing_file_at_its_address_when_there_is_one(): void
    {
        $house = $this->newport();
        $heleza = $this->file(['street_number' => '46', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Sibaya Precinct']);
        $this->advert($house, '13 Newport Road', 'Port Edward');
        $bad = $this->advert($house, '46 Heleza Boulevard', 'Sibaya Precinct');

        $r = app(AdvertLinkAudit::class)->run($this->a->id, true);

        $this->assertSame(1, $r['moved_to_existing']);
        $this->assertSame($heleza->id, $this->fileOf($bad));
    }

    public function test_the_only_advert_of_a_file_moving_out_flags_the_empty_file_duplicate_never_deletes_it(): void
    {
        $house = $this->newport();
        $this->advert($house, '46 Heleza Boulevard', 'Sibaya Precinct');

        $r = app(AdvertLinkAudit::class)->run($this->a->id, true);

        $this->assertSame(1, $r['files_emptied']);
        $this->assertSame('duplicate', TrackedProperty::queryWithoutAgencyScope()->find($house->id)->status);
    }

    public function test_adverts_that_say_what_the_file_says_are_not_contradictions(): void
    {
        $complex = $this->file(['street_number' => '71', 'street_name' => 'Butleigh, 2099 West Drive', 'suburb' => 'Uvongo Beach']);
        $this->advert($complex, '71 Butleigh, 2099 West Drive', 'Uvongo Beach');          // the text is the file's own line
        $unit = $this->file(['street_number' => '5', 'street_name' => 'Collison Street', 'suburb' => 'Manaba Beach']);
        $this->advert($unit, '5 Ss La Mouette, 28 Collison Street', 'Manaba Beach');        // the file holds the flat's unit as the number
        $spelled = $this->file(['street_number' => '8', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $this->advert($spelled, '8 Marine Dr', 'Uvongo');                                    // the street type spelled differently

        $this->assertSame(0, app(AdvertLinkAudit::class)->run($this->a->id, false)['contradictions']);
    }

    public function test_genuine_contradictions_of_number_street_and_suburb_are_all_found_and_another_agency_is_untouched(): void
    {
        $alpine = $this->file(['street_number' => '17', 'street_name' => 'Alpine Way', 'suburb' => 'Uvongo']);
        $this->advert($alpine, '19 Alpine Way', 'Uvongo');                                    // another house on the same street
        $cityA = (int) DB::table('p24_cities')->insertGetId(['name' => 'Margate', 'p24_id' => 376, 'p24_province_id' => $this->province(), 'created_at' => now(), 'updated_at' => now()]);
        $cityB = (int) DB::table('p24_cities')->insertGetId(['name' => 'Umhlanga', 'p24_id' => 400, 'p24_province_id' => $this->province(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('p24_suburbs')->insert([['name' => 'Port Edward', 'slug' => 'port-edward', 'p24_id' => 1, 'p24_city_id' => $cityA, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sibaya Precinct', 'slug' => 'sibaya-precinct', 'p24_id' => 2, 'p24_city_id' => $cityB, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ekubo Coastal Estate', 'slug' => 'ekubo', 'p24_id' => 3, 'p24_city_id' => $cityA, 'created_at' => now(), 'updated_at' => now()]]);
        $margate = $this->file(['street_number' => '3', 'street_name' => 'Beach Road', 'suburb' => 'Port Edward']);
        $this->advert($margate, '3 Beach Road', 'Sibaya Precinct');                           // another TOWN
        $estate = $this->file(['street_number' => '4', 'street_name' => 'Beach Road', 'suburb' => 'Ekubo Coastal Estate']);
        $this->advert($estate, '4 Beach Road', 'Port Edward');                                // an estate inside the same town: naming, not a contradiction
        $street = $this->file(['street_number' => '9', 'street_name' => 'Duke Road', 'suburb' => 'Margate']);
        $this->advert($street, '9 Victoria Street', 'Margate');                               // another street
        $theirs = $this->file(['street_number' => '17', 'street_name' => 'Alpine Way', 'suburb' => 'Uvongo'], $this->b);
        $this->advert($theirs, '19 Alpine Way', 'Uvongo');

        $r = app(AdvertLinkAudit::class)->run($this->a->id, false);

        $this->assertSame(3, $r['contradictions']);
        $this->assertEqualsCanonicalizing(['number' => 1, 'street' => 1, 'region' => 1], $r['by_kind']);
        $this->assertSame(0, app(AdvertLinkAudit::class)->run($this->b->id, false)['contradictions'] - 1, "agency B's own contradiction is found only in agency B's run");
    }

    public function test_p24_group_never_moves_an_advert_onto_a_file_its_own_address_contradicts(): void
    {
        $anchor = $this->file(['street_number' => '13', 'street_name' => 'Newport Road', 'suburb' => 'Port Edward']);
        $shell = $this->file([]);
        $x = $this->advert($anchor, '13 Newport Road', 'Port Edward');
        $y = $this->advert($shell, '46 Heleza Boulevard', 'Sibaya Precinct');
        foreach ([$x, $y] as $l) {
            DB::table('p24_group_members')->insert(['p24_group_id' => 'G9', 'listing_number' => substr((string) $l->portal_ref, 4), 'group_size' => 2, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $r = app(MatchingRules::class)->run($this->a->id, true, ['portal-group'])['portal-group'];

        $this->assertSame(0, $r['adverts_moved']);
        $this->assertSame($shell->id, $this->fileOf($y), 'the Sibaya advert stays off the Port Edward house');
        $this->assertSame(1, $r['review_pairs']);
        $this->assertStringContainsString('Sibaya', (string) DB::table('property_match_decisions')->where('subject_key', 'like', 'portal_group:%')->value('reason'));
    }
}
