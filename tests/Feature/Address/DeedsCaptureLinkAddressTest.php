<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Services\Address\SuburbResolver;
use App\Services\Prospecting\DeedsCaptureLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured address matching, step 6 — DeedsCaptureLinkService (owner lookup for a property being
 * pitched). Its "possible deed" tier compared the FIRST WORD of the suburb ("Port Edward" ==
 * "Port Shepstone"); its confirmed tier compared erf / street NAME columns exactly. Real shapes:
 * Ramsgate / Ramsgate Beach (the deed vs portal case it exists for), Port Edward vs Port Shepstone.
 */
final class DeedsCaptureLinkAddressTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private DeedsCaptureLinkService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbResolver::flush();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->svc = app(DeedsCaptureLinkService::class);

        $c = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $p = P24Province::create(['p24_id' => 2, 'p24_country_id' => $c->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 10, 'p24_province_id' => $p->id, 'name' => 'Margate']);
        P24Suburb::create(['p24_id' => 501, 'p24_city_id' => $city->id, 'name' => 'Ramsgate', 'slug' => 'ramsgate', 'surrounding_ids' => [502]]);
        P24Suburb::create(['p24_id' => 502, 'p24_city_id' => $city->id, 'name' => 'Ramsgate Beach', 'slug' => 'ramsgate-beach', 'surrounding_ids' => [501]]);
        P24Suburb::create(['p24_id' => 601, 'p24_city_id' => $city->id, 'name' => 'Port Edward', 'slug' => 'port-edward']);
        P24Suburb::create(['p24_id' => 602, 'p24_city_id' => $city->id, 'name' => 'Port Shepstone', 'slug' => 'port-shepstone']);
    }

    private function deed(array $over): TrackedProperty
    {
        $tp = TrackedProperty::create(array_merge(['agency_id' => $this->agency->id, 'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'source_chain' => []], $over));
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Owner', 'last_name' => 'Of ' . $tp->id, 'phone' => '', 'id_number' => (string) random_int(1000000000000, 9999999999999)]);
        TrackedPropertyOwner::create(['tracked_property_id' => $tp->id, 'contact_id' => $contact->id, 'name' => 'Owner Of ' . $tp->id, 'is_primary' => true, 'role' => 'owner', 'ownership_status' => 'current']);

        return $tp;
    }

    private function tracked(array $over): TrackedProperty
    {
        return TrackedProperty::create(array_merge(['agency_id' => $this->agency->id, 'source_chain' => []], $over));
    }

    public function test_a_ramsgate_portal_address_still_finds_the_ramsgate_beach_deed_as_a_candidate(): void
    {
        $deed = $this->deed(['street_number' => '516', 'street_name' => 'Bidstone', 'suburb' => 'Ramsgate Beach']);
        $listing = $this->tracked(['street_number' => '516', 'street_name' => 'Bream Crescent', 'suburb' => 'Ramsgate']);

        $r = $this->svc->ownersForTrackedProperty($this->agency->id, $listing);

        $this->assertSame([], $r['owners'], 'a bare number + suburb is never an automatic owner link');
        $this->assertSame([$deed->id], array_column($r['candidates'], 'tracked_property_id'), 'but it is offered to the agent to verify');
    }

    public function test_port_edward_is_not_port_shepstone(): void
    {
        $this->deed(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Port Shepstone']);
        $listing = $this->tracked(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Port Edward']);

        $r = $this->svc->ownersForTrackedProperty($this->agency->id, $listing);

        $this->assertSame([], $r['owners']);
        $this->assertSame([], $r['candidates'], 'the first word of the suburb no longer makes two towns the same');
    }

    public function test_an_unknown_to_property24_suburb_keeps_the_old_first_word_rule_so_the_real_case_cannot_vanish(): void
    {
        $deed = $this->deed(['street_number' => '516', 'street_name' => 'Bidstone', 'suburb' => 'Ramsgate Beach Extension']);
        $listing = $this->tracked(['street_number' => '516', 'street_name' => 'Bream Crescent', 'suburb' => 'Ramsgate Extension 3']);

        $r = $this->svc->ownersForTrackedProperty($this->agency->id, $listing);

        $this->assertSame([$deed->id], array_column($r['candidates'], 'tracked_property_id'));
    }

    public function test_the_same_street_number_street_and_suburb_with_the_type_missing_is_a_confirmed_owner_link(): void
    {
        $deed = $this->deed(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);
        $listing = $this->tracked(['street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo']);

        $r = $this->svc->ownersForTrackedProperty($this->agency->id, $listing);

        $this->assertSame($deed->id, $r['tracked_property_id']);
        $this->assertNotEmpty($r['owners']);
    }

    public function test_a_different_street_number_is_never_a_confirmed_owner_link(): void
    {
        $this->deed(['street_number' => '29', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);
        $listing = $this->tracked(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $r = $this->svc->ownersForTrackedProperty($this->agency->id, $listing);

        $this->assertSame([], $r['owners']);
    }

    public function test_the_same_erf_in_the_same_suburb_with_a_different_portion_is_not_the_same_deed(): void
    {
        $deed = $this->deed(['erf_number' => '1166', 'erf_portion' => '1', 'suburb' => 'Ramsgate', 'street_name' => 'Lynne Avenue']);
        $other = $this->tracked(['erf_number' => '1166', 'erf_portion' => '2', 'suburb' => 'Ramsgate', 'street_name' => 'Lynne Avenue']);
        $same = $this->tracked(['erf_number' => '1166', 'erf_portion' => '1', 'suburb' => 'Ramsgate', 'street_name' => 'Lynne Avenue']);

        $this->assertSame([], $this->svc->ownersForTrackedProperty($this->agency->id, $other)['owners']);
        $this->assertSame($deed->id, $this->svc->ownersForTrackedProperty($this->agency->id, $same)['tracked_property_id']);
    }

    public function test_another_agencys_deed_is_never_linked(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $tp = TrackedProperty::create(['agency_id' => $other->id, 'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo', 'source_chain' => []]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $contact = Contact::create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'X', 'last_name' => 'Y', 'phone' => '', 'id_number' => '8001015009087']);
        TrackedPropertyOwner::create(['tracked_property_id' => $tp->id, 'contact_id' => $contact->id, 'name' => 'X Y', 'is_primary' => true, 'role' => 'owner', 'ownership_status' => 'current']);

        $listing = $this->tracked(['street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo']);

        $this->assertSame([], $this->svc->ownersForTrackedProperty($this->agency->id, $listing)['owners']);
    }
}
