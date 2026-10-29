<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3, R8 and R10 (road-to-sold.md 22.10, 22.11) - the pitch path from a row and the evaluation from a call.
 *
 * Proven: the row asks one question and gets one answer (owner known -> the composer, made on the property; nobody -> Find owner on the screen
 * that fits the row); the property is made from the MIC address through the listing route or the promote-to-stock path, never a placeholder;
 * an existing role on the property is never changed; the pop-up context survives every redirect; the composer never lands on "No property or
 * address to pitch about" when opened from a row; "No owner found" is an outcome; only the signed-in person's own prospect is reachable
 * (another agent's = 403, another agency's = 404); and an evaluation booked from a call lands in the calendar with the property AND the owners.
 */
final class PitchPathTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private PitchPathService $path;
    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults((int) $this->agency->id);
        $this->path = app(PitchPathService::class);
        $this->prospects = app(ProspectService::class);

        // a populated grants table (empty permits everything in tests): agents hold the page, the breadth and the composer
        foreach (['access_road_to_sold', 'road_to_sold.view', 'outreach.compose'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => $key === 'road_to_sold.view' ? 'own' : null]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $first = 'Pieter'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => $first, 'last_name' => 'Botha', 'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    private function row(array $tp = [], array $listing = []): RtsProspect
    {
        $tpModel = $this->tp($tp + ['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $attrs = [];
        if ($listing !== []) {
            $attrs['prospecting_listing_id'] = $this->listing($listing + ['suburb' => 'Uvongo'], $tpModel);
        }

        return $this->prospects->place($this->agent, $tpModel->id, 'sales', $attrs);
    }

    // ── the decision ─────────────────────────────────────────────────────

    public function test_with_nobody_known_the_row_goes_to_find_owner_on_the_tracked_property_screen_with_the_pop_up_context(): void
    {
        $p = $this->row();

        $next = $this->path->next($p, $this->agent);

        $this->assertSame('find_owner', $next['step']);
        $this->assertStringContainsString('/tracked-properties/' . $p->tracked_property_id . '/outreach/compose', $next['url']);
        $this->assertStringContainsString('embed=1', $next['url']);
        $this->assertStringContainsString('rts_prospect=' . $p->id, $next['url']);
        $this->assertNull(Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->first(), 'asking never creates a property for a row nobody owns yet');
    }

    public function test_a_row_that_came_from_an_advert_goes_to_find_owner_on_the_advert_capture(): void
    {
        $p = $this->row([], ['address' => '12 Marine Drive']);

        $next = $this->path->next($p, $this->agent);

        $this->assertSame('find_owner', $next['step']);
        $this->assertStringContainsString('/prospecting/' . $p->prospecting_listing_id . '/outreach/compose', $next['url']);
    }

    public function test_with_the_owner_known_the_row_pitches_directly_and_the_property_is_made_from_the_address(): void
    {
        $owner = $this->owner();
        $p = $this->row(['owner_contact_id' => $owner->id]);

        $next = $this->path->next($p, $this->agent);

        $this->assertSame('pitch', $next['step']);
        $property = Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertNotSame('active', $property->status, 'a prospecting property, never live stock');
        $this->assertSame($property->id, (int) $next['property_id']);
        $this->assertSame($property->id, (int) $p->refresh()->property_id);
        $this->assertSame($property->id, (int) DB::table('tracked_properties')->where('id', $p->tracked_property_id)->value('promoted_to_property_id'));
        $this->assertSame('owner', DB::table('contact_property')->where('contact_id', $owner->id)->where('property_id', $property->id)->value('role'), 'linked once, as the owner the tracked property names');
        $this->assertStringContainsString('/contacts/' . $owner->id . '/outreach/compose', $next['url']);
        $this->assertStringContainsString('property_id=' . $property->id, $next['url']);
        $this->assertStringContainsString('rts_prospect=' . $p->id, $next['url']);

        // asking again finds the same property, makes no second one
        $this->path->next($p->refresh(), $this->agent);
        $this->assertSame(1, Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    public function test_the_listing_route_makes_the_property_for_a_row_that_came_from_an_advert(): void
    {
        $owner = $this->owner();
        $p = $this->row(['owner_contact_id' => $owner->id], ['address' => '12 Marine Drive']);

        $this->path->next($p, $this->agent);

        $property = Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('prospecting:' . $p->prospecting_listing_id, $property->external_id, 'the listing route keys the property to its advert');
        $this->assertSame($property->id, (int) DB::table('prospecting_listings')->where('id', $p->prospecting_listing_id)->value('matched_property_id'));
        $this->assertSame($property->id, (int) $p->refresh()->property_id);
    }

    public function test_an_advert_with_no_address_borrows_the_tracked_propertys_never_a_placeholder(): void
    {
        $owner = $this->owner();
        $p = $this->row(['owner_contact_id' => $owner->id], ['address' => null]);

        $this->path->next($p, $this->agent);

        $property = Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertStringNotContainsString('Prospecting listing', (string) $property->title);
        $this->assertNotSame('', trim((string) $property->address));
    }

    public function test_an_existing_role_on_the_property_is_never_changed(): void
    {
        $owner = $this->owner();
        $propertyId = $this->property($this->agent->id);
        DB::table('contact_property')->insert(['contact_id' => $owner->id, 'property_id' => $propertyId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $tp = $this->tp(['owner_contact_id' => $owner->id, 'promoted_to_property_id' => $propertyId, 'street_name' => 'Marine Drive']);
        $p = $this->prospects->place($this->agent, $tp->id, 'sales', ['property_id' => $propertyId]);

        $next = $this->path->next($p, $this->agent);

        $this->assertSame('pitch', $next['step']);
        $this->assertSame('owner', DB::table('contact_property')->where('contact_id', $owner->id)->where('property_id', $propertyId)->value('role'));
    }

    public function test_the_pop_up_context_is_read_only_for_the_signed_in_persons_own_prospect(): void
    {
        $p = $this->row();
        $request = \Illuminate\Http\Request::create('/x', 'GET', ['embed' => '1', 'rts_prospect' => (string) $p->id, 'rts_stage' => 'found', 'evil' => 'x']);

        $this->assertSame(['embed' => '1', 'rts_prospect' => (string) $p->id, 'rts_stage' => 'found'], $this->path->carry($request));
        $this->assertSame($p->id, $this->path->prospectFor($request, $this->agent)->id);
        $this->assertNull($this->path->prospectFor($request, $this->mate), 'a colleague\'s prospect id is ignored, not an error');
        $this->assertNull($this->path->prospectFor($request, $this->agent, $p->tracked_property_id + 99), 'and so is one for another property');
    }

    // ── the endpoints ────────────────────────────────────────────────────

    public function test_the_pitch_path_answers_json_for_the_pop_up_host_and_redirects_a_plain_page(): void
    {
        $p = $this->row();

        $this->actingAs($this->agent)->getJson(route('road-to-sold.prospects.pitch-path', ['prospect' => $p->id]))
            ->assertOk()->assertJsonPath('step', 'find_owner');

        $this->actingAs($this->agent)->get(route('road-to-sold.prospects.pitch-path', ['prospect' => $p->id]))
            ->assertRedirectContains('/outreach/compose');
    }

    public function test_another_agent_in_the_agency_is_refused_with_403(): void
    {
        $p = $this->row();

        $this->actingAs($this->mate)->getJson(route('road-to-sold.prospects.pitch-path', ['prospect' => $p->id]))->assertForbidden();
    }

    public function test_another_agent_cannot_record_no_owner_found_on_it(): void
    {
        $p = $this->row();

        $this->actingAs($this->mate)->postJson(route('road-to-sold.prospects.no-owner', ['prospect' => $p->id]))->assertForbidden();
        $this->assertSame(0, RtsContactOutcome::withoutGlobalScopes()->count());
    }

    public function test_a_prospect_in_another_agency_is_a_404(): void
    {
        $p = $this->row();
        $agency2 = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $branch2 = \App\Models\Branch::create(['agency_id' => $agency2->id, 'name' => 'Main']);
        $stranger = $this->makeAgent($agency2, $branch2);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $agency2->id]);
        PermissionService::clearCache();

        $this->actingAs($stranger)->getJson(route('road-to-sold.prospects.pitch-path', ['prospect' => $p->id]))->assertNotFound();
    }

    public function test_no_owner_found_is_recorded_as_an_outcome_on_the_prospect(): void
    {
        $p = $this->row();

        $res = $this->actingAs($this->agent)->post(route('road-to-sold.prospects.no-owner', ['prospect' => $p->id]), ['embed' => '1', 'rts_prospect' => $p->id]);

        $res->assertOk()->assertSee('Saved: No owner found.');
        $outcome = RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $p->tracked_property_id)->firstOrFail();
        $this->assertSame('no_owner_found', $outcome->outcome_key);
        $this->assertSame($this->agent->id, (int) $outcome->user_id);
        $this->assertSame($outcome->id, (int) $p->refresh()->last_outcome_id);
        $this->assertNotNull($p->next_action_at, 'it comes back for another try');
    }

    // ── the screens in the chain ─────────────────────────────────────────

    public function test_saving_a_contact_from_a_rows_tracked_property_screen_makes_the_property_and_keeps_the_pop_up_context(): void
    {
        $p = $this->row();

        $res = $this->actingAs($this->agent)->post(
            route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $p->tracked_property_id]),
            ['first_name' => 'Sanet', 'phone' => '0825550111', 'embed' => '1', 'rts_prospect' => (string) $p->id, 'rts_stage' => 'found'],
        );

        $property = Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Sanet')->firstOrFail();
        $res->assertRedirectContains('/contacts/' . $contact->id . '/outreach/compose');
        $res->assertRedirectContains('property_id=' . $property->id);
        $res->assertRedirectContains('rts_prospect=' . $p->id);
        $res->assertRedirectContains('embed=1');
        $this->assertSame($contact->id, (int) DB::table('tracked_properties')->where('id', $p->tracked_property_id)->value('owner_contact_id'));
        $this->assertSame($property->id, (int) $p->refresh()->property_id);
    }

    public function test_the_same_save_outside_the_pop_up_is_unchanged_no_property_no_context(): void
    {
        $tp = $this->tp(['street_name' => 'Marine Drive']);

        $res = $this->actingAs($this->agent)->post(
            route('seller-outreach.entry.store-from-tracked-property', ['trackedProperty' => $tp->id]),
            ['first_name' => 'Sanet', 'phone' => '0825550111'],
        );

        $res->assertRedirectContains('/outreach/compose');
        $this->assertStringNotContainsString('property_id', (string) $res->headers->get('Location'));
        $this->assertSame(0, Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'no promotion outside Road to Sold');
    }

    public function test_the_composer_opened_from_a_row_never_shows_no_property_or_address_to_pitch_about(): void
    {
        $p = $this->row();
        $contact = $this->owner('Lerato');

        $res = $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $contact->id, 'rts_prospect' => $p->id, 'embed' => 1]));

        $res->assertOk()->assertDontSee('No property or address to pitch about');
        $this->assertTrue(DB::table('contact_property')->where('contact_id', $contact->id)->exists(), 'the property was made and linked');
        $this->assertNotNull($p->refresh()->property_id);

        // outside Road to Sold the empty state is unchanged
        $other = $this->owner('Zodwa');
        $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $other->id]))
            ->assertOk()->assertSee('No property or address to pitch about');
    }

    // ── R10: the evaluation from a call ─────────────────────────────────

    public function test_an_evaluation_booked_from_a_call_puts_the_property_and_every_owner_on_the_calendar_entry(): void
    {
        $owner = $this->owner('Pieter');
        $coOwner = $this->owner('Annelie');
        $tp = $this->tp(['street_number' => '12', 'street_name' => 'Marine Drive', 'owner_contact_id' => $owner->id, 'suburb' => 'Uvongo']);
        DB::table('tracked_property_owners')->insert([
            ['agency_id' => DB::table('tracked_properties')->where('id', $tp->id)->value('agency_id'), 'tracked_property_id' => $tp->id, 'contact_id' => $owner->id, 'name' => 'Pieter Botha', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['agency_id' => DB::table('tracked_properties')->where('id', $tp->id)->value('agency_id'), 'tracked_property_id' => $tp->id, 'contact_id' => $coOwner->id, 'name' => 'Annelie Botha', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $def = \App\Models\RoadToSold\RtsOutcomeDefinition::query()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();
        $this->actingAs($this->agent);

        $outcome = app(OutcomeService::class)->record($this->agent, $tp->id, $def->id, ['appointment_at' => '2026-10-20 10:00']);

        $event = CalendarEvent::withoutGlobalScopes()->findOrFail($outcome->calendar_event_id);
        $this->assertSame('property_evaluation', $event->category);
        $this->assertSame($this->agent->id, (int) $event->user_id, 'the agent owns the appointment');
        $this->assertSame((int) $outcome->property_id, (int) $event->property_id);
        $links = DB::table('calendar_event_links')->where('calendar_event_id', $event->id)->get();
        $this->assertTrue($links->where('linkable_type', Property::class)->where('linkable_id', $outcome->property_id)->isNotEmpty(), 'the property is on the entry');
        $parties = $links->where('linkable_type', Contact::class)->pluck('linkable_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame([$owner->id, $coOwner->id], $parties, 'both owners are parties to the appointment');
        $this->assertSame('seller_contact', $links->firstWhere('linkable_id', $owner->id)->role);
        foreach ([$owner, $coOwner] as $c) {
            $this->assertTrue(DB::table('contact_property')->where('contact_id', $c->id)->where('property_id', $outcome->property_id)->exists(), 'each owner is linked to the property');
        }
        $this->assertSame(S::APPOINTMENT_SET, RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->value('stage'));
    }

    public function test_an_evaluation_with_no_known_owner_still_carries_the_property(): void
    {
        $tp = $this->tp(['street_number' => '3', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $def = \App\Models\RoadToSold\RtsOutcomeDefinition::query()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();
        $this->actingAs($this->agent);

        $outcome = app(OutcomeService::class)->record($this->agent, $tp->id, $def->id, ['appointment_at' => '2026-10-21 09:00']);

        $this->assertNotNull($outcome->property_id);
        $this->assertSame(0, DB::table('calendar_event_links')->where('calendar_event_id', $outcome->calendar_event_id)->where('linkable_type', Contact::class)->count());
        $this->assertSame(1, DB::table('calendar_event_links')->where('calendar_event_id', $outcome->calendar_event_id)->where('linkable_type', Property::class)->count());
    }
}
