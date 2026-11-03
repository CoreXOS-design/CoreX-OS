<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Document\DocumentFiledToProperty;
use App\Events\Evaluation\EvaluationBooked;
use App\Events\Evaluation\EvaluationCompleted;
use App\Events\Presentation\PresentationPresented;
use App\Events\Property\PropertyWentLiveOnPortal;
use App\Events\Prospecting\OwnerLinkedToTrackedProperty;
use App\Events\RoadToSold\RoadToSoldOutcomeRecorded;
use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Presentation;
use App\Models\PresentationDelivery;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\User;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\SettingsService;
use App\Models\Scopes\AgencyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * road-to-sold.md section 6.2 / phase 0 (P2): the six Get Stock moments each raise ONE domain event
 * per real occurrence, and the event log records it.
 *
 *   outcome logged        RoadToSoldOutcomeRecorded
 *   evaluation booked     EvaluationBooked          (calendar and Road to Sold both create the same row)
 *   evaluation done       EvaluationCompleted       (first feedback only)
 *   owner found           OwnerLinkedToTrackedProperty
 *   presentation given    PresentationPresented     (first delivery sent only)
 *   mandate filed         DocumentFiledToProperty   (mandate type, every filing path)
 *   listing live          PropertyWentLiveOnPortal  (first stamp per portal)
 *
 * Paths proven per event are named in each test: the event firing, the lookalikes that must NOT fire
 * it, a repeat, a listener that blows up (the user's action still stands), and the event-log row.
 */
final class RoadToSoldEventsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-20 09:00:00');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────

    private function tp(?int $ownerContactId = null): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id' => $this->agency->id, 'street_number' => '14', 'street_name' => 'Marine Drive',
            'suburb' => 'Uvongo', 'town' => 'Margate', 'owner_contact_id' => $ownerContactId,
        ]);
    }

    private function contact(string $first = 'Pieter'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => $first, 'last_name' => 'van der Merwe', 'phone' => '0825512345',
        ]);
    }

    private function property(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'address' => '14 Marine Drive',
            'suburb' => 'Uvongo', 'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1500000, 'beds' => 3, 'baths' => 2, 'garages' => 1,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
    }

    private function evaluationEvent(string $category = 'property_evaluation'): CalendarEvent
    {
        return CalendarEvent::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id,
            'category' => $category, 'event_type' => $category, 'title' => 'Evaluation: 14 Marine Drive',
            'event_date' => '2026-10-22 10:00:00', 'start_at' => '2026-10-22 10:00:00', 'end_at' => '2026-10-22 11:00:00',
        ]);
    }

    private function feedback(CalendarEvent $event, ?int $contactId, bool $captured = true): CalendarEventFeedback
    {
        return CalendarEventFeedback::create([
            'calendar_event_id' => $event->id, 'contact_id' => $contactId, 'feedback_kind' => 'viewing',
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->agent->id,
            'captured_at' => $captured ? now() : null,
        ]);
    }

    private function def(string $key): Def
    {
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);

        return Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->firstOrFail();
    }

    private function logRows(string $eventClass): int
    {
        return DB::table('domain_event_log')->where('event_name', $eventClass)->count();
    }

    // ── 1. outcome logged ────────────────────────────────────────────────────────────────

    public function test_recording_an_outcome_raises_one_event_with_the_facts_and_logs_it(): void
    {
        $tp = $this->tp($this->contact()->id);

        $outcome = app(OutcomeService::class)->record($this->agent, $tp->id, $this->def('no_answer')->id, ['note' => 'Rang twice']);

        $this->assertSame(1, $this->logRows(RoadToSoldOutcomeRecorded::class), 'one domain_event_log row');
        $row = DB::table('domain_event_log')->where('event_name', RoadToSoldOutcomeRecorded::class)->first();
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame($this->agent->id, (int) $row->actor_user_id);
        $this->assertSame($outcome->id, (int) $row->subject_id);
        $payload = json_decode($row->payload_snapshot, true);
        $this->assertSame('no_answer', $payload['outcomeKey']);
        $this->assertSame($tp->id, $payload['trackedPropertyId']);
        $this->assertFalse($payload['evaluationBooked']);
    }

    public function test_an_assistants_outcome_names_both_the_agent_and_the_assistant(): void
    {
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);
        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        $tp = $this->tp();
        Event::fake([RoadToSoldOutcomeRecorded::class]);

        $this->actingAs($assistant->fresh());
        app(OutcomeService::class)->record($assistant->fresh(), $tp->id, $this->def('no_answer')->id);

        Event::assertDispatched(RoadToSoldOutcomeRecorded::class, fn ($e) => $e->userId === $this->agent->id
            && $e->recordedByUserId === $assistant->id && $e->actorUserId === $assistant->id);
    }

    public function test_an_outcome_that_is_refused_raises_nothing(): void
    {
        $tp = $this->tp();
        Event::fake([RoadToSoldOutcomeRecorded::class, EvaluationBooked::class]);

        try {
            app(OutcomeService::class)->record($this->agent, $tp->id, $this->def('spoke_evaluation_booked')->id, ['appointment_at' => '2026-10-19 15:00']);
            $this->fail('a past evaluation should be refused');
        } catch (RoadToSoldException) {
        }

        Event::assertNotDispatched(RoadToSoldOutcomeRecorded::class);
        Event::assertNotDispatched(EvaluationBooked::class);
    }

    public function test_a_failing_listener_never_loses_the_outcome(): void
    {
        $tp = $this->tp();
        Event::listen(RoadToSoldOutcomeRecorded::class, function () {
            throw new \RuntimeException('listener exploded');
        });

        $outcome = app(OutcomeService::class)->record($this->agent, $tp->id, $this->def('no_answer')->id);

        $this->assertNotNull($outcome->id);
        $this->assertDatabaseHas('rts_contact_outcomes', ['id' => $outcome->id]);
    }

    // ── 2. evaluation booked ─────────────────────────────────────────────────────────────

    public function test_booking_an_evaluation_from_road_to_sold_raises_the_outcome_event_and_the_booking_event_once_each(): void
    {
        $tp = $this->tp($this->contact()->id);
        Event::fake([RoadToSoldOutcomeRecorded::class, EvaluationBooked::class]);

        $outcome = app(OutcomeService::class)->record($this->agent, $tp->id, $this->def('spoke_evaluation_booked')->id, ['appointment_at' => '2026-10-22 10:00']);

        Event::assertDispatchedTimes(RoadToSoldOutcomeRecorded::class, 1);
        Event::assertDispatchedTimes(EvaluationBooked::class, 1);
        Event::assertDispatched(EvaluationBooked::class, fn ($e) => $e->calendarEventId === (int) $outcome->calendar_event_id
            && $e->userId === $this->agent->id && $e->agencyId === $this->agency->id);
        Event::assertDispatched(RoadToSoldOutcomeRecorded::class, fn ($e) => $e->evaluationBooked === true && $e->calendarEventId === (int) $outcome->calendar_event_id);
    }

    public function test_an_evaluation_booked_straight_on_the_calendar_raises_the_booking_event_and_a_viewing_does_not(): void
    {
        Event::fake([EvaluationBooked::class]);

        $this->evaluationEvent('viewing');
        Event::assertNotDispatched(EvaluationBooked::class);

        $e = $this->evaluationEvent('property_evaluation');
        Event::assertDispatchedTimes(EvaluationBooked::class, 1);

        // an edit of the booking (new time, new title) is not a second booking
        $e->update(['title' => 'Evaluation: 14 Marine Drive (moved)', 'event_date' => '2026-10-23 10:00:00']);
        Event::assertDispatchedTimes(EvaluationBooked::class, 1);
    }

    public function test_the_booking_event_is_written_to_the_event_log(): void
    {
        $this->evaluationEvent();

        $this->assertSame(1, $this->logRows(EvaluationBooked::class));
    }

    // ── 3. evaluation done ───────────────────────────────────────────────────────────────

    public function test_the_first_captured_feedback_completes_the_evaluation_once(): void
    {
        $event = $this->evaluationEvent();
        $owner = $this->contact();
        Event::fake([EvaluationCompleted::class]);

        $first = $this->feedback($event, $owner->id);
        Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
        Event::assertDispatched(EvaluationCompleted::class, fn ($e) => $e->calendarEventId === $event->id
            && $e->feedbackId === $first->id && $e->contactId === $owner->id && $e->actorUserId === $this->agent->id);

        // editing the first, or a second attendee's feedback, is not a second completion
        $first->update(['internal_notes' => 'Owner wants to think about it']);
        $this->feedback($event, $this->contact('Annelie')->id);
        Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
    }

    public function test_feedback_that_is_not_yet_captured_waits_and_then_completes_once_when_it_is(): void
    {
        $event = $this->evaluationEvent();
        Event::fake([EvaluationCompleted::class]);

        $draft = $this->feedback($event, null, captured: false);
        Event::assertNotDispatched(EvaluationCompleted::class);

        $draft->update(['captured_at' => now()]);
        Event::assertDispatchedTimes(EvaluationCompleted::class, 1);

        $draft->update(['internal_notes' => 'edited later']);
        Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
    }

    public function test_feedback_on_a_viewing_does_not_complete_an_evaluation(): void
    {
        Event::fake([EvaluationCompleted::class]);

        $this->feedback($this->evaluationEvent('viewing'), $this->contact()->id);

        Event::assertNotDispatched(EvaluationCompleted::class);
    }

    public function test_the_completion_event_is_written_to_the_event_log(): void
    {
        $this->feedback($this->evaluationEvent(), $this->contact()->id);

        $this->assertSame(1, $this->logRows(EvaluationCompleted::class));
    }

    // ── 4. owner found ───────────────────────────────────────────────────────────────────

    public function test_a_tracked_property_getting_its_owner_raises_the_event_once(): void
    {
        $tp = $this->tp();
        $owner = $this->contact();
        Event::fake([OwnerLinkedToTrackedProperty::class]);

        $tp->update(['owner_contact_id' => $owner->id]);

        Event::assertDispatchedTimes(OwnerLinkedToTrackedProperty::class, 1);
        Event::assertDispatched(OwnerLinkedToTrackedProperty::class, fn ($e) => $e->trackedPropertyId === $tp->id
            && $e->ownerContactId === $owner->id && $e->agencyId === $this->agency->id && $e->actorUserId === $this->agent->id);
    }

    public function test_the_other_owner_writers_are_caught_the_same_way(): void
    {
        $owner = $this->contact();
        Event::fake([OwnerLinkedToTrackedProperty::class]);

        // a tracked property created already knowing its owner (the deeds capture does this)
        $this->tp($owner->id);
        // the screen path: assign + save()
        $tp = $this->tp();
        $tp->owner_contact_id = $owner->id;
        $tp->save();

        Event::assertDispatchedTimes(OwnerLinkedToTrackedProperty::class, 2);
    }

    public function test_changing_an_owner_or_saving_something_else_is_not_owner_found(): void
    {
        $a = $this->contact('Pieter');
        $b = $this->contact('Annelie');
        $tp = $this->tp($a->id);
        Event::fake([OwnerLinkedToTrackedProperty::class]);

        $tp->update(['owner_contact_id' => $b->id]);   // owner replaced
        $tp->update(['suburb' => 'Shelly Beach']);     // unrelated change
        $tp->update(['owner_contact_id' => $b->id]);   // same value again
        $this->tp();                                   // no owner at all

        Event::assertNotDispatched(OwnerLinkedToTrackedProperty::class);
    }

    public function test_the_owner_event_is_written_to_the_event_log(): void
    {
        $this->tp()->update(['owner_contact_id' => $this->contact()->id]);

        $this->assertSame(1, $this->logRows(OwnerLinkedToTrackedProperty::class));
    }

    // ── 5. presentation given ────────────────────────────────────────────────────────────

    private function presentation(): Presentation
    {
        return Presentation::withoutEvents(fn () => Presentation::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'title' => '14 Marine Drive', 'property_address' => '14 Marine Drive', 'status' => 'draft',
        ]));
    }

    private function delivery(Presentation $p, string $channel = 'email', bool $sent = true): PresentationDelivery
    {
        Schema::disableForeignKeyConstraints();
        try {
            return PresentationDelivery::create([
                'snapshot_link_id' => 9000 + random_int(1, 8999), 'presentation_id' => $p->id, 'agency_id' => $this->agency->id,
                'sent_by_user_id' => $this->agent->id, 'channel' => $channel, 'recipient_name' => 'Pieter van der Merwe',
                'recipient_email' => 'pieter@example.co.za', 'mode' => 'full',
                'status' => $sent ? 'sent' : 'queued', 'sent_at' => $sent ? now() : null,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function test_the_first_sent_delivery_presents_the_presentation_once(): void
    {
        $p = $this->presentation();
        Event::fake([PresentationPresented::class]);

        $first = $this->delivery($p, 'email');
        $this->delivery($p, 'copy');      // a second recipient / channel
        $this->delivery($p, 'email');

        Event::assertDispatchedTimes(PresentationPresented::class, 1);
        Event::assertDispatched(PresentationPresented::class, fn ($e) => $e->presentationId === $p->id
            && $e->deliveryId === $first->id && $e->userId === $this->agent->id && $e->channel === 'email');
    }

    public function test_a_whatsapp_delivery_presents_only_when_the_agent_clicks_through(): void
    {
        $p = $this->presentation();
        Event::fake([PresentationPresented::class]);

        $queued = $this->delivery($p, 'whatsapp', sent: false);
        Event::assertNotDispatched(PresentationPresented::class);

        $queued->forceFill(['whatsapp_click_through_at' => now(), 'status' => 'sent', 'sent_at' => now()])->save();
        Event::assertDispatchedTimes(PresentationPresented::class, 1);

        $queued->forceFill(['status' => 'opened', 'opened_at' => now()])->save();   // later status changes
        Event::assertDispatchedTimes(PresentationPresented::class, 1);
    }

    public function test_a_failed_delivery_presents_nothing_and_the_presented_event_is_logged(): void
    {
        $p = $this->presentation();
        $this->delivery($p, 'email', sent: false);
        $this->assertSame(0, $this->logRows(PresentationPresented::class));

        $this->delivery($p, 'copy');
        $this->assertSame(1, $this->logRows(PresentationPresented::class));
        $this->assertSame('draft', $p->fresh()->status, 'the presentation status is not changed');
    }

    // ── 6. mandate filed ─────────────────────────────────────────────────────────────────

    private function doc(?string $typeSlug, string $source = 'upload'): Document
    {
        $typeId = $typeSlug
            ? DocumentType::firstOrCreate(['slug' => $typeSlug], ['label' => ucfirst($typeSlug), 'is_active' => true])->id
            : null;

        return Document::create([
            'agency_id' => $this->agency->id, 'original_name' => 'Sole Mandate - Merwe.pdf', 'storage_path' => 'x/' . Str::random(6) . '.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1234,
            'document_type_id' => $typeId, 'source_type' => $source, 'uploaded_by' => $this->agent->id,
        ]);
    }

    public function test_a_mandate_filed_onto_a_property_raises_the_event_whichever_way_it_is_attached(): void
    {
        $property = $this->property();
        Event::fake([DocumentFiledToProperty::class]);

        $viaDocument = $this->doc('mandate');
        $viaDocument->properties()->attach($property->id);                  // property file upload / contact upload

        $viaSync = $this->doc('mandate', 'esign');
        $viaSync->properties()->syncWithoutDetaching([$property->id]);     // e-sign filing / PDF splitter

        $viaProperty = $this->doc('mandate');
        $property->documents()->attach($viaProperty->id);                   // from the property side

        Event::assertDispatchedTimes(DocumentFiledToProperty::class, 3);
        Event::assertDispatched(DocumentFiledToProperty::class, fn ($e) => $e->documentId === $viaSync->id
            && $e->propertyId === $property->id && $e->documentTypeSlug === 'mandate'
            && $e->agencyId === $this->agency->id && $e->sourceType === 'esign');
    }

    public function test_the_same_mandate_linked_again_or_other_document_types_raise_nothing(): void
    {
        $property = $this->property();
        $mandate = $this->doc('mandate');
        $mandate->properties()->attach($property->id);
        Event::fake([DocumentFiledToProperty::class]);

        $mandate->properties()->syncWithoutDetaching([$property->id]);     // already linked: no new row
        $this->doc('fica')->properties()->attach($property->id);            // another document type
        $this->doc(null)->properties()->attach($property->id);             // a document with no type yet

        Event::assertNotDispatched(DocumentFiledToProperty::class);
    }

    public function test_linking_and_unlinking_still_work_as_before_through_the_custom_link(): void
    {
        $a = $this->property();
        $b = $this->property();
        $doc = $this->doc('mandate');

        $doc->properties()->sync([$a->id]);
        $this->assertSame([$a->id], $doc->properties()->pluck('properties.id')->all());

        $doc->properties()->sync([$b->id]);                                 // moved: a detached, b attached
        $this->assertSame([$b->id], $doc->fresh()->properties()->pluck('properties.id')->all());
        $this->assertDatabaseMissing('document_properties', ['document_id' => $doc->id, 'property_id' => $a->id]);
        $this->assertNotNull(DB::table('document_properties')->where('document_id', $doc->id)->value('created_at'), 'timestamps still written');

        $doc->properties()->detach();
        $this->assertSame(0, $doc->properties()->count());
    }

    public function test_the_mandate_event_is_written_to_the_event_log(): void
    {
        $this->doc('mandate')->properties()->attach($this->property()->id);

        $this->assertSame(1, $this->logRows(DocumentFiledToProperty::class));
    }

    // ── 7. listing live on a portal ──────────────────────────────────────────────────────

    public function test_the_first_p24_activation_raises_the_event_once_and_refreshes_do_not(): void
    {
        $property = $this->property();
        Event::fake([PropertyWentLiveOnPortal::class]);

        $property->update(['p24_ref' => '115544332', 'p24_syndication_status' => 'active', 'p24_last_submitted_at' => now(), 'p24_activated_at' => now()]);
        Event::assertDispatchedTimes(PropertyWentLiveOnPortal::class, 1);
        Event::assertDispatched(PropertyWentLiveOnPortal::class, fn ($e) => $e->portal === 'p24'
            && $e->property->id === $property->id && $e->agencyId() === $this->agency->id);

        // every later submit re-stamps p24_activated_at - not a second go-live
        $property->update(['p24_activated_at' => now()->addHour(), 'p24_last_submitted_at' => now()]);
        Event::assertDispatchedTimes(PropertyWentLiveOnPortal::class, 1);
    }

    public function test_private_property_is_its_own_event_and_both_portals_at_once_give_two(): void
    {
        $property = $this->property();
        Event::fake([PropertyWentLiveOnPortal::class]);

        $property->update(['pp_ref' => 'PP-88123', 'pp_syndication_status' => 'active', 'pp_last_submitted_at' => now(), 'pp_activated_at' => now()]);
        Event::assertDispatchedTimes(PropertyWentLiveOnPortal::class, 1);
        Event::assertDispatched(PropertyWentLiveOnPortal::class, fn ($e) => $e->portal === 'pp');

        $other = $this->property();
        $other->update(['p24_last_submitted_at' => now(), 'p24_activated_at' => now(), 'pp_last_submitted_at' => now(), 'pp_activated_at' => now()]);
        Event::assertDispatchedTimes(PropertyWentLiveOnPortal::class, 3);
    }

    public function test_stock_an_import_brings_in_is_not_a_go_live(): void
    {
        $imported = $this->property();
        Event::fake([PropertyWentLiveOnPortal::class]);

        // what P24ListingNumberReconciler does for a listing already live on P24: ref + active + activated stamp,
        // and NO last-submitted stamp, because CoreX never submitted it
        $imported->fill(['p24_listing_number' => '115599001', 'p24_ref' => '115599001', 'p24_syndication_status' => 'active', 'p24_activated_at' => now()])->save();
        // the same for a listing found live on Private Property by the activation sync
        $imported->update(['pp_ref' => 'PP-77001', 'pp_syndication_status' => 'active', 'pp_activated_at' => now()]);

        Event::assertNotDispatched(PropertyWentLiveOnPortal::class);
        $this->assertNotNull($imported->fresh()->p24_activated_at, 'the import itself is unchanged');
        $this->assertSame(0, $this->logRows(PropertyWentLiveOnPortal::class));
    }

    public function test_imported_stock_that_corex_later_refreshes_is_still_not_a_go_live(): void
    {
        $imported = $this->property();
        $imported->update(['p24_ref' => '115599002', 'p24_syndication_status' => 'active', 'p24_activated_at' => now()]);
        Event::fake([PropertyWentLiveOnPortal::class]);

        // the agent's first CoreX submit re-stamps the existing activated_at and records the submit
        $imported->update(['p24_last_submitted_at' => now(), 'p24_activated_at' => now()->addMinute()]);

        Event::assertNotDispatched(PropertyWentLiveOnPortal::class);
    }

    public function test_a_corex_listing_confirmed_live_by_the_activation_sync_still_counts(): void
    {
        $property = $this->property();
        $property->update(['p24_syndication_status' => 'submitted', 'p24_last_submitted_at' => now()]);   // submit, no ref back yet
        Event::fake([PropertyWentLiveOnPortal::class]);

        // Property24SyndicationService::syncActivationStatus - P24 now reports the listing active
        $property->update(['p24_syndication_status' => 'active', 'p24_activated_at' => now()]);

        Event::assertDispatchedTimes(PropertyWentLiveOnPortal::class, 1);
    }

    public function test_saving_a_property_that_never_went_live_raises_nothing(): void
    {
        $property = $this->property();
        Event::fake([PropertyWentLiveOnPortal::class]);

        $property->update(['price' => 1650000, 'p24_syndication_status' => 'submitted', 'p24_last_submitted_at' => now()]);

        Event::assertNotDispatched(PropertyWentLiveOnPortal::class);
    }

    public function test_the_live_event_is_written_to_the_event_log(): void
    {
        $this->property()->update(['pp_last_submitted_at' => now(), 'pp_activated_at' => now()]);

        $this->assertSame(1, $this->logRows(PropertyWentLiveOnPortal::class));
    }

    // ── the safety rule, for the observer-raised events ─────────────────────────────────

    public function test_a_failing_listener_never_breaks_the_save_that_raised_it(): void
    {
        foreach ([EvaluationBooked::class, OwnerLinkedToTrackedProperty::class, PropertyWentLiveOnPortal::class, DocumentFiledToProperty::class] as $class) {
            Event::listen($class, function () {
                throw new \RuntimeException('listener exploded');
            });
        }
        $property = $this->property();

        $this->assertNotNull($this->evaluationEvent()->id);
        $this->tp()->update(['owner_contact_id' => $this->contact()->id]);
        $property->update(['p24_last_submitted_at' => now(), 'p24_activated_at' => now()]);
        $this->doc('mandate')->properties()->attach($property->id);

        $this->assertNotNull($property->fresh()->p24_activated_at);
        $this->assertSame(1, DB::table('document_properties')->where('property_id', $property->id)->count());
    }
}
