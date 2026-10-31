<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24Listing;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\PropertyAlertFacts;
use App\Services\Prospecting\PropertySourceFacts;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * property-identity.md section 15, screens S1-S3: "How we know this property" line, the source tag / alert marker / Source filter on the MIC
 * list, and the portal alerts page linking to the property file. Per agency throughout.
 */
final class PropertySourceFactsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function tp(Agency $agency, string $ref, string $street = 'Marine Drive'): TrackedProperty
    {
        return app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agency->id, ['street_number' => '14', 'street_name' => $street, 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => $ref],
        );
    }

    private function alert(Agency $agency, string $number, ?TrackedProperty $tp = null): P24Listing
    {
        $row = P24Listing::create([
            'agency_id' => $agency->id, 'p24_listing_number' => $number, 'asking_price' => 1_400_000, 'original_price' => 1_500_000, 'suburb' => 'Uvongo',
            'first_seen_date' => '2026-06-03', 'last_seen_date' => '2026-06-26', 'times_seen' => 2,
        ]);
        if ($tp) {
            $row->forceFill(['tracked_property_id' => $tp->id])->save();
        }

        return $row;
    }

    private function listing(Agency $agency, string $ref, ?TrackedProperty $tp): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://p24.test/' . $ref, 'address' => '14 Marine Drive',
            'suburb' => 'Uvongo', 'price' => 1_400_000, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp?->id,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ]);
    }

    public function test_property_line_names_the_doors_portal_refs_and_dates_from_this_agencys_own_rows_only(): void
    {
        $tp = $this->tp($this->agency, 'P24-117000001');
        $alert = $this->alert($this->agency, 'P24-117000001', $tp);
        $this->alert($this->b, 'P24-117000001'); // another agency's alert for the same advert must never count
        $this->listing($this->agency, 'P24-117000001', $tp);
        $tp->load('externalRefs');
        $linked = DB::table('prospecting_listings')->where('tracked_property_id', $tp->id)->get();

        $alerts = app(PropertyAlertFacts::class)->for($this->agency->id, $tp);
        $f = app(PropertySourceFacts::class)->forProperty($alerts, $linked, $tp->externalRefs);

        $this->assertSame(['Email alert', 'Extension'], $f['sources']);
        $this->assertSame(['P24'], $f['portals']);
        $this->assertSame(['P24-117000001'], $f['refs']);
        $this->assertSame('3 Jun 2026', $f['first_seen'] === null ? null : date('j M Y', strtotime('2026-06-03')));
        $this->assertSame(1, count($alerts), 'only this agency\'s alert');
        $this->assertSame($alert->id, P24Listing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('id'));
    }

    public function test_list_tag_and_alert_marker_and_source_filter_agree_and_are_agency_scoped(): void
    {
        $withAlert = $this->listing($this->agency, 'P24-117000002', null);
        $this->alert($this->agency, 'P24-117000002');
        $plain = $this->listing($this->agency, 'P24-117000003', null);
        $otherAgencyOnly = $this->listing($this->agency, 'P24-117000004', null);
        $this->alert($this->b, 'P24-117000004'); // alert belongs to agency B: our advert is NOT an email-alert advert for us

        $svc = app(PropertySourceFacts::class);
        $map = $svc->forListings($this->agency->id, [$withAlert, $plain, $otherAgencyOnly]);
        $this->assertSame('Email alert', $map[$withAlert->id]['tag']);
        $this->assertTrue($map[$withAlert->id]['alert']);
        $this->assertSame('Extension', $map[$plain->id]['tag']);
        $this->assertSame('Extension', $map[$otherAgencyOnly->id]['tag']);

        $ids = fn (string $source) => ProspectingListing::query()->tap(fn ($q) => $svc->applyFilter($q, $source, $this->agency->id))->pluck('id')->sort()->values()->all();
        $this->assertSame([$withAlert->id], $ids(PropertySourceFacts::EMAIL));
        $this->assertSame([$plain->id, $otherAgencyOnly->id], $ids(PropertySourceFacts::EXTENSION));
        $this->assertSame([], $ids(PropertySourceFacts::DEEDS));
    }

    public function test_property_page_shows_the_source_line_and_portal_alerts_page_links_to_the_property(): void
    {
        $tp = $this->tp($this->agency, 'P24-117000005');
        $this->alert($this->agency, 'P24-117000005', $tp);

        $this->actingAs($this->admin)->get(route('market-intelligence.opportunities.show', ['tp' => $tp->id]))
            ->assertOk()->assertSee('How we know this property', false)->assertSee('Email alert', false)->assertSee('P24-117000005', false);

        $this->actingAs($this->admin)->get(route('market-intelligence.portal-alerts'))
            ->assertOk()->assertSee('data-qa="alert-property-link"', false)
            ->assertSee(route('market-intelligence.opportunities.show', ['tp' => $tp->id]), false);
    }
}
