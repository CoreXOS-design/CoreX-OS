<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\PresentationGenerated;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\RoadToSold\BeltLinks;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\ProspectStepService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A4 (road-to-sold.md 23.8 / 23.10): the appointment and the presentation carry the property; the evaluation says what happened.
 */
final class BeltLinksTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A CoreX property sitting on a tracked property file, the agent's open belt record on it, and the owner contact. @return array{0:\App\Models\Prospecting\TrackedProperty,1:Property,2:RtsProspect,3:Contact} */
    private function file(string $stage = ProspectStages::CONTACTED): array
    {
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999), 'owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);
        $property = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => '12 Bairn Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '12 Bairn Street', 'suburb' => 'Margate', 'tracked_property_id' => $tp->id]);
        DB::table('properties')->where('id', $property->id)->update(['tracked_property_id' => $tp->id]);   // not mass-assignable
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['property_id' => $property->id, 'stage' => $stage]);

        return [$tp, $property, $p, $owner];
    }

    private function book(Property $property, ?User $by = null, array $extra = []): CalendarEvent
    {
        $by ??= $this->agent;
        $this->actingAs($by);

        return app(CalendarEventCreator::class)->create($extra + [
            'title' => 'Evaluation', 'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'property_id' => $property->id,
        ], $by)->refresh();
    }

    private function feedback(CalendarEvent $event, Property $property, array $kind): CalendarEventFeedback
    {
        return CalendarEventFeedback::withoutGlobalScopes()->create([
            'calendar_event_id' => $event->id, 'property_id' => $property->id, 'feedback_kind' => 'listing_presentation', 'visibility' => 'internal_only',
            'kind_specific_data' => $kind, 'captured_by_user_id' => $this->agent->id, 'captured_at' => now(), 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);
    }

    private function stage(RtsProspect $p): string
    {
        return (string) $p->fresh()->stage;
    }

    public function test_an_evaluation_on_the_plain_calendar_is_matched_to_the_agents_open_record_for_the_property(): void
    {
        [$tp, $property, $p] = $this->file();

        $event = $this->book($property);

        $this->assertSame((int) $tp->id, (int) $event->tracked_property_id);
        $this->assertSame((int) $p->id, (int) $event->rts_prospect_id);
        $this->assertSame(ProspectStages::APPOINTMENT_SET, $this->stage($p), 'the booking moved the record to Evaluation booked');
    }

    public function test_an_evaluation_for_a_property_with_no_belt_record_is_still_booked_with_only_the_property_file(): void
    {
        [$tp, $property, $p] = $this->file();
        app(ProspectService::class)->release($p, null, 'test');

        $event = $this->book($property);

        $this->assertSame((int) $tp->id, (int) $event->tracked_property_id);
        $this->assertNull($event->rts_prospect_id, 'no open record, nothing to name');
        $this->assertSame('property_evaluation', $event->category);
    }

    public function test_a_colleague_s_evaluation_never_takes_another_agents_record(): void
    {
        [, $property, $p] = $this->file();

        $event = $this->book($property, $this->mate);

        $this->assertNull($event->rts_prospect_id, "the booking agent has no record of their own for it; the owner agent's record is not theirs to name");
        $this->assertSame(ProspectStages::CONTACTED, $this->stage($p));
    }

    public function test_the_outcome_that_books_an_evaluation_stamps_the_property_and_the_record_on_the_appointment(): void
    {
        [$tp, $property, $p] = $this->file(ProspectStages::OWNER_FOUND);
        $this->actingAs($this->agent);
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();

        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $def->id, ['channel' => 'phone', 'appointment_at' => '2026-11-04 09:30', 'property_id' => $property->id]);

        $event = CalendarEvent::withoutGlobalScopes()->findOrFail($o->calendar_event_id);
        $this->assertSame((int) $tp->id, (int) $event->tracked_property_id);
        $this->assertSame((int) $p->id, (int) $event->rts_prospect_id);
        $this->assertSame(ProspectStages::APPOINTMENT_SET, $this->stage($p));
    }

    public function test_evaluation_is_an_actionable_class(): void
    {
        $this->assertContains('property_evaluation', \App\Models\CommandCenter\CalendarEventClassSetting::ACTIONABLE_CATEGORIES);
    }

    public function test_held_feedback_completes_the_evaluation_and_the_appointment(): void
    {
        [, $property, $p] = $this->file();
        $event = $this->book($property);

        $this->feedback($event, $property, ['eval_status' => 'held', 'outcome' => 'Considering']);

        $this->assertSame(ProspectStages::EVALUATION_DONE, $this->stage($p));
        $this->assertSame('completed', $event->fresh()->status, 'done is one rule: the held feedback completes the appointment');
    }

    public function test_feedback_with_no_status_is_held_as_it_always_was(): void
    {
        [, $property, $p] = $this->file();
        $event = $this->book($property);

        $this->feedback($event, $property, ['outcome' => 'Considering']);

        $this->assertSame(ProspectStages::EVALUATION_DONE, $this->stage($p));
    }

    public function test_a_no_show_is_not_a_held_evaluation_and_sets_the_record_aside_for_a_short_revisit(): void
    {
        [, $property, $p] = $this->file();
        $event = $this->book($property);

        $this->feedback($event, $property, ['eval_status' => 'no_show']);

        $p->refresh();
        $this->assertSame(ProspectStages::APPOINTMENT_SET, $p->stage, 'never reached Evaluation held');
        $this->assertNotNull($p->parked_at);
        $this->assertSame('2026-11-13', $p->parked_until->toDateString(), 'back in 14 days');
        $this->assertStringContainsString('no-show', (string) $p->park_note);
        $this->assertNotSame('completed', $event->fresh()->status);
    }

    public function test_a_rescheduled_feedback_counts_neither_as_held_nor_as_lost(): void
    {
        [, $property, $p] = $this->file();
        $event = $this->book($property);

        $this->feedback($event, $property, ['eval_status' => 'rescheduled']);

        $p->refresh();
        $this->assertSame(ProspectStages::APPOINTMENT_SET, $p->stage);
        $this->assertNull($p->parked_at);
    }

    public function test_a_held_feedback_after_a_rescheduled_one_still_counts(): void
    {
        [, $property, $p] = $this->file();
        $event = $this->book($property);
        $first = $this->feedback($event, $property, ['eval_status' => 'rescheduled']);

        $first->update(['kind_specific_data' => ['eval_status' => 'held', 'outcome' => 'Considering']]);

        $this->assertSame(ProspectStages::EVALUATION_DONE, $this->stage($p), 'the edit that makes it held is the one that counts');
    }

    public function test_a_generated_presentation_carries_the_property_the_owner_the_record_and_the_appointment_and_moves_the_record(): void
    {
        [$tp, $property, $p, $owner] = $this->file(ProspectStages::APPOINTMENT_SET);
        $event = $this->book($property);
        $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id,
            'title' => 'P', 'property_address' => '12 Bairn Street', 'status' => 'draft']);

        app(BeltLinks::class)->linkPresentation($pres, $this->agent->id);
        event(new PresentationGenerated($pres->refresh(), new PresentationVersion()));

        $pres->refresh();
        $this->assertSame((int) $tp->id, (int) $pres->tracked_property_id);
        $this->assertSame((int) $owner->id, (int) $pres->seller_contact_id);
        $this->assertSame((int) $p->id, (int) $pres->rts_prospect_id);
        $this->assertSame((int) $event->id, (int) $pres->calendar_event_id);
        $this->assertSame(ProspectStages::PRESENTATION_PREPARED, $this->stage($p));
    }

    public function test_presentation_prepared_does_not_pull_a_record_back_and_a_second_version_changes_nothing(): void
    {
        [, $property, $p] = $this->file(ProspectStages::EVALUATION_DONE);
        $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id,
            'title' => 'P', 'property_address' => '12 Bairn Street', 'status' => 'draft']);

        event(new PresentationGenerated($pres, new PresentationVersion()));
        event(new PresentationGenerated($pres, new PresentationVersion()));

        $this->assertSame(ProspectStages::EVALUATION_DONE, $this->stage($p), 'forward only');
    }

    public function test_the_presented_event_no_longer_moves_the_stage(): void
    {
        [, $property, $p] = $this->file(ProspectStages::APPOINTMENT_SET);
        $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id,
            'title' => 'P', 'property_address' => '12 Bairn Street', 'status' => 'draft']);

        event(new \App\Events\Presentation\PresentationPresented(
            presentationId: (int) $pres->id, deliveryId: 1, agencyId: $this->agency->id, userId: $this->agent->id, channel: 'email', propertyId: $property->id, trackedPropertyId: null,
        ));

        $this->assertSame(ProspectStages::APPOINTMENT_SET, $this->stage($p));
    }

    public function test_the_presentation_step_opens_the_generator_on_that_property_not_the_index(): void
    {
        [, $property, $p] = $this->file(ProspectStages::APPOINTMENT_SET);

        $url = app(ProspectStepService::class)->urlFor($p, 'presentation');

        $this->assertStringContainsString('/presentation-path', (string) $url);
        $this->assertStringNotContainsString('presentations/create', (string) $url);
        $this->assertStringNotContainsString('/presentations', (string) $url);

        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        \App\Services\PermissionService::clearCache();
        $res = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.presentation-path', $p->id));

        $res->assertRedirect();
        $to = $res->headers->get('Location');
        $this->assertStringContainsString('/properties/' . $property->id, (string) $to);
        $this->assertStringContainsString('generate_presentation=1', (string) $to);
    }

    public function test_another_agency_s_property_is_never_linked(): void
    {
        $b = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        [, $property] = $this->file();

        $this->assertNull(app(BeltLinks::class)->trackedOf((int) $property->id, (int) $b->id));
    }

    public function test_a_booking_never_attaches_the_appointment_to_a_property_that_was_archived_since_the_file_was_promoted(): void
    {
        [$tp, $property, $p] = $this->file(ProspectStages::OWNER_FOUND);
        $dead = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'prospecting',
            'title' => 'Old promote', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => 'Old promote', 'suburb' => 'Margate']);
        $dead->delete();
        DB::table('tracked_properties')->where('id', $tp->id)->update(['promoted_to_property_id' => $dead->id]);
        $this->actingAs($this->agent);
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();

        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $def->id, ['channel' => 'phone', 'appointment_at' => '2026-11-04 09:30']);

        $event = CalendarEvent::withoutGlobalScopes()->findOrFail($o->calendar_event_id);
        $this->assertNotSame((int) $dead->id, (int) $event->property_id, 'the calendar cannot open an appointment on an archived property');
    }
}
