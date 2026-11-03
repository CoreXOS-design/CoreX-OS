<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\P24Listing;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §7: the routes. A property, CMA comparable row, presentation sold comp, email alert
 * and deal saved through Eloquent is attached to its property file in the same request. Stock find-or-creates; the
 * others find only. The test environment switches the hook off; these tests switch it on.
 */
final class PropertyIdentityLinkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['property-identity.link_on_create' => true]);
        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function property(array $attrs = []): Property
    {
        $p = new Property();
        foreach (array_merge([
            'external_id' => 'P-' . Str::random(8), 'title' => 'T', 'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'house',
            'beds' => 3, 'status' => 'active', 'is_demo' => false, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id' => $this->agent->id,
        ], $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p->fresh();
    }

    private function tpCount(): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->agency->id)->count();
    }

    // ---- own stock: find or create ---------------------------------------------------------------------------------

    public function test_a_new_property_gets_a_property_file_and_the_link_is_saved(): void
    {
        $p = $this->property(['address' => '12 Beach Road']);

        $this->assertNotNull($p->tracked_property_id);
        $this->assertSame('exact', $p->identity_confidence);
        $this->assertSame('new_file', $p->identity_method);
        $this->assertSame(1, $this->tpCount());
        $tp = TrackedProperty::queryWithoutAgencyScope()->find($p->tracked_property_id);
        $this->assertSame('12', $tp->street_number);
        $this->assertNull($tp->promoted_to_property_id, 'a match is not a claim — promoting is a separate act');
    }

    public function test_a_second_property_at_the_same_address_joins_the_same_file(): void
    {
        $a = $this->property(['address' => '12 Beach Road']);
        $b = $this->property(['address' => '12 Beach Road']);

        $this->assertSame($a->tracked_property_id, $b->tracked_property_id);
        $this->assertSame('high', $b->identity_confidence);
        $this->assertSame(1, $this->tpCount());
    }

    public function test_a_different_unit_gets_its_own_file(): void
    {
        $a = $this->property(['address' => '10 Heleza Boulevard', 'complex_name' => 'Pebble Beach', 'unit_number' => '303', 'property_type' => 'apartment', 'suburb' => 'Umhlanga']);
        $b = $this->property(['address' => '10 Heleza Boulevard', 'complex_name' => 'Pebble Beach', 'unit_number' => '304', 'property_type' => 'apartment', 'suburb' => 'Umhlanga']);

        $this->assertNotSame($a->tracked_property_id, $b->tracked_property_id);
        $this->assertSame(2, $this->tpCount());
    }

    public function test_a_property_with_a_portal_number_joins_the_mic_property_file_of_the_same_advert(): void
    {
        // what the extension captured: type p24, "P24-" spelling, with an address
        $mic = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id,
            ['street_number' => '40', 'street_name' => 'Hill Street', 'suburb' => 'Uvongo'],
            ['type' => 'p24', 'ref' => 'P24-116123456'],
        );

        // our own listing of the same advert: bare number, and a typed address that differs in spelling
        $p = $this->property(['address' => 'Hill St, Uvongo', 'p24_ref' => '116123456']);

        $this->assertSame($mic->id, $p->tracked_property_id);
        $this->assertSame('exact', $p->identity_confidence);
        $this->assertSame('portal_ref', $p->identity_method);
        $this->assertSame('p24:116123456', $p->p24_canonical_ref);
        $this->assertSame(1, $this->tpCount());
    }

    public function test_the_hook_is_off_when_the_switch_is_off(): void
    {
        config(['property-identity.link_on_create' => false]);
        $p = $this->property(['address' => '12 Beach Road']);

        $this->assertNull($p->tracked_property_id);
        $this->assertSame(0, $this->tpCount());
    }

    public function test_a_resolver_failure_never_blocks_the_save(): void
    {
        $this->app->bind(PropertyIdentityResolver::class, fn () => throw new \RuntimeException('boom'));
        $p = $this->property(['address' => '12 Beach Road']);

        $this->assertNotNull($p->id, 'the property is saved even though the door failed');
        $this->assertNull($p->tracked_property_id);
    }

    public function test_promoting_a_property_file_links_once_and_does_not_make_a_second_file(): void
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id,
            ['street_number' => '5', 'street_name' => 'Alpha Road', 'suburb' => 'Margate', 'erf_number' => '321'],
            ['type' => 'deeds_capture', 'ref' => 'cmainfo:ABC'],
        );

        $property = app(TrackedPropertyMatchOrCreateService::class)->promoteToStock($tp->id, $this->agent->id);

        $this->assertSame(1, $this->tpCount());
        $row = DB::table('properties')->where('id', $property->id)->first();
        $this->assertSame((int) $tp->id, (int) $row->tracked_property_id);
        $this->assertSame('promoted', $row->identity_method);
        $this->assertSame('exact', $row->identity_confidence);
        $this->assertSame((int) $property->id, (int) TrackedProperty::queryWithoutAgencyScope()->find($tp->id)->promoted_to_property_id);
    }

    // ---- comps, alerts, deals: find only ---------------------------------------------------------------------------------

    public function test_a_cma_comparable_row_attaches_to_a_property_file_that_exists_and_never_creates_one(): void
    {
        $row = MarketReportCompRow::create(['agency_id' => $this->agency->id, 'row_type' => 'comp', 'address' => '12 Beach Road', 'suburb_normalised' => 'uvongo']);
        $this->assertNull($row->fresh()->tracked_property_id);
        $this->assertSame(0, $this->tpCount(), 'a comparable never mints a property file');

        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id, ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'P24-116123456'],
        );
        $next = MarketReportCompRow::create(['agency_id' => $this->agency->id, 'row_type' => 'comp', 'address' => '12 Beach Road', 'suburb_normalised' => 'uvongo']);

        $this->assertSame($tp->id, $next->fresh()->tracked_property_id);
        $this->assertSame('high', $next->fresh()->identity_confidence);
    }

    public function test_a_presentation_sold_comp_is_attached_from_the_address_in_its_row(): void
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id, ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Palm Beach', 'erf_number' => '543'], ['type' => 'cmainfo', 'ref' => 'report:1'],
        );
        $presentationId = (int) DB::table('presentations')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'title' => 'P',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $comp = PresentationSoldComp::create([
            'presentation_id' => $presentationId, 'agency_id' => $this->agency->id, 'parser_version' => 'v1', 'suburb' => 'Palm Beach',
            'raw_row_json' => json_encode(['address' => '12 BEACH ROAD, PALM BEACH', 'erf_no' => '543', 'sale_price' => 950000]),
        ]);

        $this->assertSame($tp->id, $comp->fresh()->tracked_property_id);
        $this->assertSame(1, $this->tpCount());
    }

    public function test_an_email_alert_attaches_by_advert_number_only(): void
    {
        $alone = P24Listing::create([
            'agency_id' => $this->agency->id, 'p24_listing_number' => 'P24-117743851', 'asking_price' => 1,
            'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(),
        ]);
        $this->assertNull($alone->fresh()->tracked_property_id);

        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id, ['street_number' => '3', 'street_name' => 'Gamma Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => 'P24-117743852'],
        );
        $alert = P24Listing::create([
            'agency_id' => $this->agency->id, 'p24_listing_number' => 'P24-117743852', 'asking_price' => 1,
            'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(),
        ]);

        $this->assertSame($tp->id, $alert->fresh()->tracked_property_id);
        $this->assertSame('exact', $alert->fresh()->identity_confidence);
        $this->assertSame('portal_ref', $alert->fresh()->identity_method);
        $this->assertSame(1, $this->tpCount(), 'an alert never creates a property file');
    }

    public function test_a_deal_picks_up_the_property_file_of_its_property(): void
    {
        $p = $this->property(['address' => '12 Beach Road']);
        $dealId = (int) DB::table('deals')->insertGetId([
            'property_id' => $p->id, 'agency_id' => $this->agency->id, 'deal_date' => now()->toDateString(), 'property_value' => 1_500_000,
            'total_commission' => 90_000, 'period' => now()->format('Y-m'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = Deal::withoutGlobalScopes()->find($dealId);
        $deal->forceFill(['updated_at' => now()->addSecond()])->save();

        $row = DB::table('deals')->where('id', $dealId)->first();
        $this->assertSame((int) $p->tracked_property_id, (int) $row->tracked_property_id);
        $this->assertSame('via_property', $row->identity_method);
    }
}
