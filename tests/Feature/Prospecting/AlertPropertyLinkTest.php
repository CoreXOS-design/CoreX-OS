<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24Listing;
use App\Models\PpAlertListing;
use App\Models\PpAlertPriceChange;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\PrivateProperty\PpEmailParserService;
use App\Services\PrivateProperty\PpImapImportService;
use App\Services\Prospecting\AlertPropertyLinker;
use App\Services\Prospecting\PropertyAlertFacts;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * property-identity.md section 15, C1: e-mail alerts (Property24 and Private Property) are tied to their property file THROUGH THE DOOR by advert
 * number, per agency; the property screen shows what the alerts say; the backfill is dry-run first and writes derived links only.
 */
final class AlertPropertyLinkTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->adminA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->adminB = User::factory()->create(['agency_id' => $this->b->id, 'branch_id' => $branchB->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function tpWithAdvert(Agency $agency, string $type, string $ref, array $facts = []): TrackedProperty
    {
        return app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agency->id, array_merge(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo'], $facts), ['type' => $type, 'ref' => $ref],
        );
    }

    private function p24Alert(Agency $agency, string $number, float $price = 1_500_000): P24Listing
    {
        return P24Listing::create([
            'agency_id' => $agency->id, 'p24_listing_number' => $number, 'asking_price' => $price, 'original_price' => $price, 'suburb' => 'Uvongo',
            'first_seen_date' => now()->subDays(9)->toDateString(), 'last_seen_date' => now()->toDateString(), 'times_seen' => 3,
        ]);
    }

    public function test_the_door_ties_an_alert_to_the_property_with_that_advert_number_and_saves_the_confidence(): void
    {
        $tp = $this->tpWithAdvert($this->agency, 'p24', 'P24-117743852');
        $alert = $this->p24Alert($this->agency, 'P24-117743852');

        $r = app(AlertPropertyLinker::class)->linkModel($alert);

        $this->assertSame(AlertPropertyLinker::RESULT_LINKED, $r['result']);
        $this->assertSame($tp->id, $alert->fresh()->tracked_property_id);
        $this->assertSame('exact', $alert->fresh()->identity_confidence);
        $this->assertSame('portal_ref', $alert->fresh()->identity_method);
        $this->assertSame(AlertPropertyLinker::RESULT_ALREADY, app(AlertPropertyLinker::class)->linkModel($alert->fresh())['result'], 'a second pass changes nothing');
    }

    public function test_an_alert_with_no_property_file_stays_unlinked_and_no_file_is_created(): void
    {
        $alert = $this->p24Alert($this->agency, 'P24-117000001');
        $before = TrackedProperty::queryWithoutAgencyScope()->count();

        $r = app(AlertPropertyLinker::class)->linkModel($alert);

        $this->assertSame(AlertPropertyLinker::RESULT_NO_MATCH, $r['result']);
        $this->assertNull($alert->fresh()->tracked_property_id);
        $this->assertSame($before, TrackedProperty::queryWithoutAgencyScope()->count(), 'an alert never mints a property file');
    }

    public function test_an_unreadable_advert_number_is_reported_not_guessed(): void
    {
        $alert = $this->p24Alert($this->agency, 'CC5-LIVE-TEST-1');
        $this->assertSame(AlertPropertyLinker::RESULT_UNREADABLE, app(AlertPropertyLinker::class)->linkModel($alert)['result']);
    }

    public function test_another_agencys_property_with_the_same_advert_number_is_never_linked_or_shown(): void
    {
        $tpB = $this->tpWithAdvert($this->b, 'p24', 'P24-117743852');
        $alertA = $this->p24Alert($this->agency, 'P24-117743852', 1_111_111);

        $this->assertSame(AlertPropertyLinker::RESULT_NO_MATCH, app(AlertPropertyLinker::class)->linkModel($alertA)['result'], "agency A's alert never ties to agency B's file");
        $this->assertNull($alertA->fresh()->tracked_property_id);

        $shownToB = app(PropertyAlertFacts::class)->for($this->b->id, $tpB);
        $this->assertSame([], $shownToB, "agency B's property file shows none of agency A's alerts for the same advert");
    }

    public function test_the_property_screen_shows_the_alert_facts_to_its_own_agency_only(): void
    {
        $tpA = $this->tpWithAdvert($this->agency, 'p24', 'P24-117743852');
        $tpB = $this->tpWithAdvert($this->b, 'p24', 'P24-117743852');
        $a = $this->p24Alert($this->agency, 'P24-117743852', 1_450_000);
        $a->update(['is_mandated' => true]);
        $a->priceChanges()->create(['old_price' => 1_500_000, 'new_price' => 1_450_000, 'change_date' => now()->subDay()->toDateString()]);
        $a->update(['original_price' => 1_500_000, 'asking_price' => 1_450_000]);

        $res = $this->actingAs($this->adminA)->get(route('market-intelligence.opportunities.show', $tpA->id))->assertOk();
        $res->assertSee('What the portal e-mail alerts say')->assertSee('R 1,450,000')->assertSee('-3.3%')->assertSee('R 1,500,000 to R 1,450,000')->assertSee('Mandated');

        $b = $this->actingAs($this->adminB)->get(route('market-intelligence.opportunities.show', $tpB->id))->assertOk();
        $b->assertDontSee('What the portal e-mail alerts say')->assertDontSee('R 1,450,000');
    }

    public function test_an_alert_that_arrived_before_the_property_file_shows_once_the_advert_is_on_it(): void
    {
        $this->p24Alert($this->agency, 'P24-117743899', 990_000);   // never linked: nothing existed then
        $tp = $this->tpWithAdvert($this->agency, 'p24', 'P24-117743899');

        $rows = app(PropertyAlertFacts::class)->for($this->agency->id, $tp);
        $this->assertCount(1, $rows);
        $this->assertSame(990000.0, $rows[0]['price']);
    }

    public function test_the_backfill_dry_run_writes_nothing_and_apply_writes_derived_links_only(): void
    {
        $tp = $this->tpWithAdvert($this->agency, 'p24', 'P24-117743852');
        $tpB = $this->tpWithAdvert($this->b, 'p24', 'P24-117743852');
        $linkable = $this->p24Alert($this->agency, 'P24-117743852');
        $orphan = $this->p24Alert($this->agency, 'P24-117000002');
        $files = TrackedProperty::queryWithoutAgencyScope()->count();

        $this->assertSame(0, Artisan::call('alerts:link-properties', ['--dry-run' => true, '--portal' => 'p24']));
        $out = Artisan::output();
        $this->assertStringContainsString('would link:', $out);
        $this->assertNull($linkable->fresh()->tracked_property_id, 'dry run wrote nothing');

        $this->assertSame(0, Artisan::call('alerts:link-properties', ['--apply' => true, '--portal' => 'p24']));
        $this->assertSame($tp->id, $linkable->fresh()->tracked_property_id);
        $this->assertNotSame($tpB->id, $linkable->fresh()->tracked_property_id);
        $this->assertNull($orphan->fresh()->tracked_property_id);
        $this->assertSame($files, TrackedProperty::queryWithoutAgencyScope()->count(), 'no property file created or merged');

        Artisan::call('alerts:link-properties', ['--apply' => true, '--portal' => 'p24']);
        $this->assertStringContainsString('already linked:      1', Artisan::output(), 'a second run is idempotent');
    }

    public function test_the_backfill_refuses_outside_qa(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(1, Artisan::call('alerts:link-properties', ['--apply' => true]));
        $this->assertStringContainsString('only runs on QA', Artisan::output());
    }

    // ---- Private Property -------------------------------------------------------------------------------------------

    public function test_the_private_property_reader_finds_every_advert_in_the_sample_mail(): void
    {
        $rows = (new PpEmailParserService())->parse((string) file_get_contents(base_path('tests/Fixtures/pp-alert-sample.html')), '3 new properties in Uvongo');

        $this->assertCount(2, $rows);
        $this->assertSame('T1014486', $rows[0]['pp_listing_number']);
        $this->assertSame(2495000.0, $rows[0]['asking_price']);
        $this->assertSame(3, $rows[0]['bedrooms']);
        $this->assertSame('House', $rows[0]['property_type']);
        $this->assertSame(3, $rows[0]['bathrooms']);
        $this->assertSame(2, $rows[0]['garages']);
        $this->assertTrue($rows[0]['is_mandated']);
        $this->assertSame('Uvongo', $rows[0]['suburb']);
        $this->assertStringContainsString('privateproperty.co.za', (string) $rows[0]['pp_url']);
        $this->assertSame('RR4719094', $rows[1]['pp_listing_number']);
        $this->assertSame(1150000.0, $rows[1]['asking_price']);
        $this->assertFalse($rows[1]['is_mandated']);
        $this->assertSame('Margate', $rows[1]['suburb']);
        $this->assertSame([], (new PpEmailParserService())->parse('<p>no adverts here</p>', 'Hello'));
    }

    public function test_a_private_property_alert_is_stored_per_agency_tracks_price_changes_and_ties_to_its_property(): void
    {
        $tp = $this->tpWithAdvert($this->agency, 'pp', 'PP-T1014486');
        $svc = new PpImapImportService(new PpEmailParserService());
        $row = ['pp_listing_number' => 'T1014486', 'asking_price' => 2_495_000.0, 'property_type' => 'House', 'suburb' => 'Uvongo', 'bedrooms' => 3, 'bathrooms' => 3, 'garages' => 2, 'is_mandated' => false, 'pp_url' => null];

        $this->assertSame([1, 0], $svc->storeRows($this->agency->id, [$row]));
        $alert = PpAlertListing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('pp:T1014486', $alert->canonical_ref);
        $this->assertSame($tp->id, $alert->tracked_property_id);
        $this->assertSame('exact', $alert->identity_confidence);

        $this->assertSame([0, 1], $svc->storeRows($this->agency->id, [array_merge($row, ['asking_price' => 2_395_000.0])]));
        $this->assertSame(1, PpAlertPriceChange::where('listing_id', $alert->id)->count());
        $this->assertSame(2, (int) $alert->fresh()->times_seen);

        // agency B stores its own copy of the same advert; nothing of A's changes
        $this->assertSame([1, 0], $svc->storeRows($this->b->id, [$row]));
        $this->assertNull(PpAlertListing::withoutGlobalScopes()->where('agency_id', $this->b->id)->value('tracked_property_id'), 'B has no property file for it');
        $this->assertSame(2, PpAlertListing::withoutGlobalScopes()->count());

        $facts = app(PropertyAlertFacts::class)->for($this->agency->id, $tp);
        $this->assertSame('Private Property', $facts[0]['portal']);
        $this->assertSame(2395000.0, $facts[0]['price']);
        $this->assertCount(1, $facts[0]['changes']);
    }

    public function test_the_private_property_import_is_off_until_switched_on(): void
    {
        config(['services.pp_imap.enabled' => false]);
        $this->assertSame('disabled', (new PpImapImportService(new PpEmailParserService()))->import()['status']);
    }
}
