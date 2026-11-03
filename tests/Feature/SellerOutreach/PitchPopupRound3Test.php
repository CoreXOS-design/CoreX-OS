<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.23 drop 6: the Owner step saves the owner and nothing else, on the record's own file; the claim is said; one heading per step; dd/mm/yyyy. */
final class PitchPopupRound3Test extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;
    private int $listingId;
    private RtsProspect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.require_channel' => true, 'road-to-sold-belt.enforce_owner_gate' => true]);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent', 'admin'] as $role) {
            foreach (['access_road_to_sold', 'outreach.compose', 'command_center.calendar.view', 'market_intelligence.view', 'access_prospecting'] as $perm) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $perm, 'agency_id' => $this->agency->id], in_array($perm, ['command_center.calendar.view', 'market_intelligence.view'], true) ? ['scope' => 'all'] : []);
            }
        }
        PermissionService::clearCache();

        $this->tp = $this->tp(['street_name' => 'Cycad Court', 'street_number' => '1']);
        $this->listingId = $this->listing(['address' => '1 Ss Cycad Court', 'suburb' => 'Margate'], $this->tp);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function claim(): RtsProspect
    {
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listingId, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);

        return $this->prospect = RtsProspect::query()->where('tracked_property_id', $this->tp->id)->firstOrFail();
    }

    private function saveOwner(array $extra = [])
    {
        return $this->actingAs($this->agent)->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $this->listingId]), [
            'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345', 'email' => 'pieter@example.test', 'embed' => 1, 'rts_prospect' => $this->prospect->id,
        ] + $extra);
    }

    public function test_the_owner_step_saves_the_owner_on_the_records_own_file_and_nothing_else(): void
    {
        $this->claim();
        $before = ['tp' => TrackedProperty::query()->withoutGlobalScopes()->count(), 'props' => Property::withoutGlobalScopes()->count(), 'tasks' => CommandTask::withoutGlobalScopes()->count(),
            'sends' => DB::table('seller_outreach_sends')->count(), 'claimStatus' => ProspectingClaim::query()->value('status')];

        $r = $this->saveOwner();

        $r->assertRedirect();
        $tp = TrackedProperty::query()->withoutGlobalScopes()->find($this->tp->id);
        $this->assertNotNull($tp->owner_contact_id, 'the owner is on the record\'s own file: the row and the tiles read this');
        $this->assertSame('Pieter', Contact::withoutGlobalScopes()->find($tp->owner_contact_id)->first_name);
        $this->assertSame($before['tp'], TrackedProperty::query()->withoutGlobalScopes()->count(), 'no second tracked property');
        $this->assertSame($before['props'], Property::withoutGlobalScopes()->count(), 'no property made at the Owner step');
        $this->assertSame($before['tasks'], CommandTask::withoutGlobalScopes()->count(), 'no mandate chase tasks');
        $this->assertSame($before['sends'], DB::table('seller_outreach_sends')->count(), 'nothing "sent"');
        $this->assertSame($before['claimStatus'], ProspectingClaim::query()->value('status'), 'the claim is not turned into "contacted"');
        $this->assertNull(DB::table('prospecting_listings')->where('id', $this->listingId)->value('pitched_at'), 'not stamped pitched');
        $this->assertSame(0, DB::table('contact_property')->count(), 'no property, so no seller link was invented');
    }

    public function test_after_the_owner_the_next_screen_is_the_belts_single_answer(): void
    {
        $this->claim();
        // no deed on record: the deed picker, with the reason
        $r = $this->saveOwner();
        $to = (string) $r->headers->get('Location');
        $this->assertStringContainsString('/outreach/compose', $to);
        $this->assertStringContainsString('need=deed', $to);

        // the deed is on record (and the owner is on the file now): the pitch, on the record's own file, with the property made then and only then
        $this->tp->update(['deeds_captured_at' => now()]);
        $props = Property::withoutGlobalScopes()->count();
        $r2 = $this->saveOwner(['first_name' => 'Anna', 'last_name' => 'Verster', 'phone' => '0829990000', 'email' => 'anna@example.test', 'bypass_duplicate_check' => 1]);
        $this->assertStringContainsString('/outreach/compose', (string) $r2->headers->get('Location'));
        $this->assertStringContainsString('/contacts/', (string) $r2->headers->get('Location'), 'the composer on the owner');
        $this->assertSame($props + 1, Property::withoutGlobalScopes()->count(), 'one property, for the record, when the pitch opens');
        $this->assertSame((int) $this->tp->id, (int) RtsProspect::query()->find($this->prospect->id)->tracked_property_id);
    }

    public function test_a_property_the_belt_makes_belongs_to_the_records_file_and_spawns_no_chores(): void
    {
        config(['property-identity.link_on_create' => true]);
        $this->claim();
        $this->tp->update(['owner_contact_id' => Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345'])->id]);
        $files = TrackedProperty::query()->withoutGlobalScopes()->count();
        $tasks = CommandTask::withoutGlobalScopes()->count();

        $property = app(PitchPathService::class)->ensureProperty($this->prospect, $this->agent);

        $this->assertNotNull($property);
        $this->assertSame((int) $this->tp->id, (int) Property::withoutGlobalScopes()->find($property->id)->tracked_property_id, "the record's own file");
        $this->assertSame($files, TrackedProperty::query()->withoutGlobalScopes()->count(), 'no duplicate file');
        $this->assertSame($tasks, CommandTask::withoutGlobalScopes()->count(), 'no "Upload signed mandate / owner ID / proof of ownership"');
    }

    public function test_the_first_open_says_it_claimed_the_property_and_for_how_long(): void
    {
        $url = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $this->listingId, 'embed' => 1, 'fresh' => 1]);
        $first = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString("corexPitchResult('claimed'", $first);
        $this->assertStringContainsString('1 Ss Cycad Court', $first);
        $this->assertStringContainsString('30 working days', $first);

        $again = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString("corexPitchResult('claimed'", $again, 'only the first time: it is already theirs');
    }

    public function test_each_step_has_one_heading_inside_the_popup(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $propertyId = $this->property($this->agent->id, ['address' => '1 Ss Cycad Court', 'tracked_property_id' => $this->tp->id]);
        DB::table('prospecting_listings')->where('id', $this->listingId)->update(['matched_property_id' => $propertyId]);
        DB::table('contact_property')->insert(['contact_id' => $owner->id, 'property_id' => $propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $get = fn (string $url) => $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $h = $get(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $this->tp->id, 'embed' => 1]));
        $this->assertStringContainsString('data-qa="step-heading">Owner<', $h);
        $this->assertStringNotContainsString('Compose pitch about this property', $h);

        $h = $get(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $this->listingId, 'embed' => 1]));
        $this->assertStringContainsString('data-qa="step-heading">Pitch<', $h);
        $this->assertStringNotContainsString('pitch to sellers', $h);
        $this->assertStringNotContainsString('Property created', $h);

        $h = $get(route('seller-outreach.composer.show', ['contact' => $owner->id, 'property_id' => $propertyId, 'embed' => 1]));
        $this->assertStringContainsString('data-qa="step-heading">Contact<', $h);

        $admin = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $h = $this->actingAs($admin)->get(route('market-intelligence.work', ['_pitch_card' => $this->listingId, 'embed' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="step-heading">Property<', $h);
        $this->assertStringContainsString('[data-qa="pitch-card"] [data-qa="line-buttons"] { display: none', $h, 'the card\'s own action buttons are the popup\'s job');

        $h = $get(route('command-center.calendar', ['embed' => 1, 'pitch_book' => 1, 'prefill_contact_id' => $owner->id, 'prefill_class' => 'property_evaluation']));
        $this->assertStringContainsString("'Evaluation'", $h);
        $this->assertStringNotContainsString("'New Event'</h2>", $h);
    }

    public function test_the_phone_tab_dates_are_dd_mm_yyyy_and_marked_required(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $this->tp->update(['owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);
        $propertyId = $this->property($this->agent->id, ['address' => '1 Ss Cycad Court', 'tracked_property_id' => $this->tp->id]);
        DB::table('contact_property')->insert(['contact_id' => $owner->id, 'property_id' => $propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $h = $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $owner->id, 'property_id' => $propertyId, 'channel' => 'phone', 'embed' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="phone-call"', $h, substr(strip_tags($h), 0, 300));
        $this->assertStringNotContainsString('datetime-local', $h, 'no bare browser date box');
        $this->assertGreaterThanOrEqual(2, substr_count($h, 'data-dmy-date'), 'the call date and the bring-back date');
        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $h);
        $this->assertMatchesRegularExpression('#02\\\\?/11\\\\?/2026#', $h, 'today, shown as dd/mm/yyyy');
        $this->assertStringContainsString('value="2026-11-02"', $h, 'and posted as YYYY-MM-DD');
        $this->assertStringContainsString('title="Required"', $h);
    }

    // ---- drop 7: the deed linked from the Owner step lands on the record's own file ------------------------------------------------------------------------

    private function deedFile(): TrackedProperty
    {
        $deed = $this->tp(['street_name' => 'Faux Street', 'street_number' => '16']);
        $deed->update(['deeds_captured_at' => now()->subDays(20)]);
        DB::table('tracked_property_owners')->insert(['tracked_property_id' => $deed->id, 'agency_id' => $this->agency->id, 'name' => 'Pat Owner', 'id_number' => '7001015009087', 'is_primary' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $deed;
    }

    public function test_a_fetch_made_by_a_screen_in_the_popup_counts_as_the_popup(): void
    {
        $r = fn (array $h, string $m = 'POST') => \Illuminate\Http\Request::create('/x', $m, [], [], [], $h);
        $embedded = ['HTTP_REFERER' => 'https://qa.test/corex/prospecting/10/outreach/compose?embed=1&rts_prospect=5', 'HTTP_SEC_FETCH_DEST' => 'empty'];
        app()->instance('request', $r($embedded));
        $this->assertTrue(\App\Support\Embed::on(), 'the deed link / seller link / number ticks the popup screen makes');

        app()->instance('request', $r(['HTTP_REFERER' => 'https://qa.test/corex/prospecting/10/outreach/compose', 'HTTP_SEC_FETCH_DEST' => 'empty']));
        $this->assertFalse(\App\Support\Embed::on(), 'the full page is not the popup');

        app()->instance('request', $r($embedded, 'GET'));
        $this->assertFalse(\App\Support\Embed::on(), 'a plain GET made by script is not a screen change');
    }

    public function test_linking_a_deed_from_the_popup_makes_no_second_file_and_no_tasks_and_the_gate_sees_it(): void
    {
        config(['property-identity.link_on_create' => true]);
        $this->claim();
        $deed = $this->deedFile();
        $files = TrackedProperty::query()->withoutGlobalScopes()->count();
        $tasks = CommandTask::withoutGlobalScopes()->count();
        $gate = app(\App\Services\RoadToSold\OwnerStationService::class);
        $this->assertFalse($gate->hasDeedRecord($this->agency->id, (int) $this->tp->id), 'nothing linked yet');

        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $this->listingId]), ['tracked_property_id' => $deed->id],
            ['Sec-Fetch-Dest' => 'empty', 'Referer' => url('/corex/prospecting/' . $this->listingId . '/outreach/compose?embed=1&rts_prospect=' . $this->prospect->id)])->assertOk();

        $this->assertSame((int) $deed->id, (int) DB::table('prospecting_listings')->find($this->listingId)->linked_deed_tracked_property_id);
        $made = Property::withoutGlobalScopes()->where('external_id', 'prospecting:' . $this->listingId)->first();
        $this->assertNotNull($made, 'the sellers hang on a property made for the advert');
        $this->assertSame((int) $this->tp->id, (int) $made->tracked_property_id, "the property belongs to the RECORD'S file, not a new one");
        $this->assertSame($files, TrackedProperty::query()->withoutGlobalScopes()->count(), 'no second tracked property beside the record\'s');
        $this->assertSame($tasks, CommandTask::withoutGlobalScopes()->count(), 'no mandate chase tasks');
        $this->assertTrue($gate->hasDeedRecord($this->agency->id, (int) $this->tp->id), "the gate sees the deed linked to the record's advert");
        $this->assertSame(['the owner contact'], $gate->blockedMany($this->agent, [(int) $this->tp->id])[(int) $this->tp->id] ?? [], 'the row asks only for the owner now: the deed is not missing any more');
    }

    public function test_with_the_owner_on_the_file_the_linked_deed_opens_the_belts_owner_found(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $this->tp->update(['owner_contact_id' => $owner->id]);
        $deed = $this->deedFile();

        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $this->listingId]), ['tracked_property_id' => $deed->id], ['Referer' => url('/x?embed=1')])->assertOk();

        $this->assertSame('owner_found', RtsProspect::query()->find($this->prospect->id)->stage, 'the record moved the moment the deed was linked');
        $this->assertNull(app(\App\Services\RoadToSold\OwnerStationService::class)->refusal($this->agent, (int) $this->tp->id));
    }

    // ---- the POSSIBLE / CONFIRMED DEED MATCH card: "+ Link as seller" links that deed to the record too --------------------------------------------------------

    /** a deed file with TWO registered owners, each already a contact; the card links one of them */
    private function deedWithTwoOwners(): array
    {
        $deed = $this->deedFile();
        $pat = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Pat', 'last_name' => 'Owner', 'id_number' => '7001015009087', 'phone' => '0836383848']);
        $sam = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Sam', 'last_name' => 'Owner', 'id_number' => '6204305134084']);
        DB::table('tracked_property_owners')->where('tracked_property_id', $deed->id)->update(['contact_id' => $pat->id]);
        DB::table('tracked_property_owners')->insert(['tracked_property_id' => $deed->id, 'agency_id' => $this->agency->id, 'name' => 'Sam Owner', 'id_number' => '6204305134084', 'contact_id' => $sam->id, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return [$deed, $pat, $sam];
    }

    private function linkSellerFromCard(Contact $c, int $deedId, string $referer): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $this->listingId]),
            ['contact_id' => $c->id, 'deed_tracked_property_id' => $deedId], ['Sec-Fetch-Dest' => 'empty', 'Referer' => $referer]);
    }

    /** @return array<string,string> the two ways in: a Road to Sold row (Find owner) and MIC's Pitch now - the same popup, the same screen, the same card */
    public static function entryPoints(): array
    {
        return ['Road to Sold row' => ['rts'], 'MIC Pitch now' => ['mic']];
    }

    /** @dataProvider entryPoints */
    public function test_link_as_seller_on_a_deed_match_card_links_that_deed_to_the_record_and_opens_the_gate(string $entry): void
    {
        config(['property-identity.link_on_create' => true]);
        $this->claim();
        [$deed, $pat, $sam] = $this->deedWithTwoOwners();
        // the agent has the owner on the file already (the Owner step's other half): only the deed was missing
        $this->tp->update(['owner_contact_id' => $pat->id]);
        $gate = app(\App\Services\RoadToSold\OwnerStationService::class);
        $this->assertSame(['the deed record'], $gate->missing($this->agency->id, (int) $this->tp->id), 'before: owner on the file, deed not');
        $this->assertSame('claimed', $this->prospect->fresh()->stage);
        $files = TrackedProperty::query()->withoutGlobalScopes()->count();
        $tasks = CommandTask::withoutGlobalScopes()->count();

        $referer = url('/corex/prospecting/' . $this->listingId . '/outreach/compose?need=deed&embed=1' . ($entry === 'rts' ? '&rts_prospect=' . $this->prospect->id . '&rts_stage=claimed' : ''));
        $this->linkSellerFromCard($pat, (int) $deed->id, $referer)->assertOk();

        $this->assertSame((int) $deed->id, (int) DB::table('prospecting_listings')->find($this->listingId)->linked_deed_tracked_property_id, 'the deed is on the record\'s advert');
        $this->assertSame([], $gate->missing($this->agency->id, (int) $this->tp->id), 'after: nothing missing');
        $this->assertNull($gate->refusal($this->agent, (int) $this->tp->id), 'Create & continue passes');
        $this->assertSame('owner_found', $this->prospect->fresh()->stage, 'the belt moved the record at once');
        $this->assertSame($files, TrackedProperty::query()->withoutGlobalScopes()->count(), 'no second property file');
        $this->assertSame($tasks, CommandTask::withoutGlobalScopes()->count(), 'no chase tasks');
        $made = Property::withoutGlobalScopes()->where('external_id', 'prospecting:' . $this->listingId)->first();
        $this->assertNotNull($made);
        $this->assertSame((int) $this->tp->id, (int) $made->tracked_property_id, "the seller hangs on the RECORD'S file");
        $this->assertSame([(int) $pat->id], DB::table('contact_property')->where('property_id', $made->id)->where('role', 'seller')->whereNull('deleted_at')->pluck('contact_id')->map(fn ($v) => (int) $v)->all(),
            'only the owner the agent picked is a seller - the deed\'s other owner is not linked behind their back');
        $this->assertSame('1 Ss Cycad Court', Property::withoutGlobalScopes()->find($made->id)->address, "the property's address is not rewritten from the deed");
    }

    public function test_a_hand_typed_seller_with_no_deed_is_still_gated(): void
    {
        $this->claim();
        [$deed, $pat] = $this->deedWithTwoOwners();
        $this->tp->update(['owner_contact_id' => $pat->id]);

        $this->actingAs($this->agent)->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $this->listingId]), ['contact_id' => $pat->id],
            ['Sec-Fetch-Dest' => 'empty', 'Referer' => url('/x?embed=1')])->assertOk();

        $this->assertNull(DB::table('prospecting_listings')->find($this->listingId)->linked_deed_tracked_property_id, 'no deed on the card: no deed link');
        $this->assertSame(['the deed record'], app(\App\Services\RoadToSold\OwnerStationService::class)->missing($this->agency->id, (int) $this->tp->id));
    }

    public function test_a_deed_from_another_agency_or_with_no_owner_is_never_linked_from_the_card(): void
    {
        $this->claim();
        [$deed, $pat] = $this->deedWithTwoOwners();
        $this->linkSellerFromCard($pat, 99999999, url('/x?embed=1'))->assertNotFound();
        $bare = $this->tp(['street_name' => 'Bare Street', 'street_number' => '5']);   // a file with no registered owner

        $this->linkSellerFromCard($pat, (int) $bare->id, url('/x?embed=1'))->assertOk();

        $this->assertNull(DB::table('prospecting_listings')->find($this->listingId)->linked_deed_tracked_property_id, 'an owner-less file is not a deed');
    }

    // ---- the owner is already on the file: "Create & continue" with a blank form goes on, it does not say "capture the owner first" ---------------------------

    private function blankOwnerStep()
    {
        return $this->actingAs($this->agent)->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $this->listingId]), ['embed' => 1, 'rts_prospect' => $this->prospect->id]);
    }

    public function test_with_the_owner_and_the_deed_already_on_the_file_a_blank_create_and_continue_goes_on_to_the_pitch(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Belinda', 'last_name' => 'Ehlers', 'phone' => '0609608323']);
        $this->tp->update(['owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);   // the deed scrape captured both

        $r = $this->blankOwnerStep();

        $r->assertRedirect();
        $this->assertNull(session('error'), 'no "Capture the owner first" while the owner is on the file');
        $this->assertStringContainsString('/contacts/' . $owner->id . '/outreach/compose', (string) $r->headers->get('Location'), 'on to the composer, on the owner');
    }

    public function test_with_the_owner_on_the_file_and_no_deed_a_blank_create_and_continue_asks_for_the_deed_not_the_owner(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Belinda', 'last_name' => 'Ehlers', 'phone' => '0609608323']);
        $this->tp->update(['owner_contact_id' => $owner->id]);

        $r = $this->blankOwnerStep();

        $this->assertNull(session('error'));
        $this->assertStringContainsString('need=deed', (string) $r->headers->get('Location'));
    }

    public function test_with_nobody_on_the_file_and_a_blank_form_it_still_asks_for_the_owner(): void
    {
        $this->claim();

        $this->blankOwnerStep()->assertSessionHasErrors('contact_required');
    }

    public function test_the_same_when_the_seller_is_also_linked_on_the_adverts_property(): void
    {
        $this->claim();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Belinda', 'last_name' => 'Ehlers', 'phone' => '0609608323']);
        $propId = $this->property((int) $this->agent->id);
        DB::table('prospecting_listings')->where('id', $this->listingId)->update(['matched_property_id' => $propId]);
        DB::table('contact_property')->insert(['contact_id' => $owner->id, 'property_id' => $propId, 'role' => 'seller', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $this->tp->update(['owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);   // exactly 25 Shepstone Avenue: seller linked, owner and deed on the file

        $r = $this->blankOwnerStep();

        $this->assertNull(session('error'));
        $this->assertStringContainsString('/contacts/' . $owner->id . '/outreach/compose', (string) $r->headers->get('Location'));
    }
}
