<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.23 drop 4: the Book evaluation step - the calendar's own form inside the pitch popup, prefilled; the call logged against the appointment. */
final class PitchBookStepTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;
    private int $propertyId;
    private Contact $owner;

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
            foreach (['access_road_to_sold', 'outreach.compose', 'command_center.calendar.view'] as $perm) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $perm, 'agency_id' => $this->agency->id], $perm === 'command_center.calendar.view' ? ['scope' => 'own'] : []);
            }
        }
        PermissionService::clearCache();

        $this->tp = $this->tp(['street_name' => 'Pitch Road', 'street_number' => '12']);
        $this->owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345', 'email' => 'pieter@example.test']);
        $this->propertyId = $this->property($this->agent->id, ['address' => '12 Pitch Road', 'suburb' => 'Margate', 'tracked_property_id' => $this->tp->id]);
        DB::table('contact_property')->insert(['contact_id' => $this->owner->id, 'property_id' => $this->propertyId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->tp->update(['owner_contact_id' => $this->owner->id, 'deeds_captured_at' => now(), 'promoted_to_property_id' => $this->propertyId]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prospect()
    {
        $listing = $this->listing([], $this->tp);
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);

        return \App\Models\RoadToSold\RtsProspect::query()->where('tracked_property_id', $this->tp->id)->first();
    }

    private function bookQuery(array $extra = []): array
    {
        return ['embed' => 1, 'pitch_book' => 1, 'prefill_contact_id' => $this->owner->id, 'prefill_class' => 'property_evaluation', 'prefill_title' => 'Evaluation: 12 Pitch Road'] + $extra;
    }

    public function test_the_calendar_form_opens_in_the_popup_with_the_evaluation_prefill_and_nothing_else(): void
    {
        $h = $this->actingAs($this->agent)->get(route('command-center.calendar', $this->bookQuery()))->assertOk()->getContent();

        $this->assertStringContainsString('name="pitch_book" value="1"', $h, 'saving will go on to the Evaluation booked page');
        $this->assertStringContainsString('aside[data-tour="cal-event-form"] { position: fixed', $h, 'the form fills the popup');
        $this->assertStringContainsString("params.get('prefill_title')", $h);

        $plain = $this->actingAs($this->agent)->get(route('command-center.calendar'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="pitch_book"', $plain, 'the calendar on its own page is unchanged');
        $this->assertStringNotContainsString('position: fixed !important; inset: 0 0 3.6rem', $plain);
    }

    public function test_the_phone_tab_defers_an_evaluation_outcome_to_the_calendar_form(): void
    {
        $h = $this->actingAs($this->agent)->get(route('seller-outreach.composer.show', ['contact' => $this->owner->id, 'property_id' => $this->propertyId, 'channel' => 'phone', 'embed' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="phone-book-hint"', $h);
        $this->assertStringContainsString('Next: book the appointment', $h);
        $this->assertStringContainsString('pitch_book=1', $h, 'the book address is on the page');
        $this->assertStringContainsString('prefill_class=property_evaluation', $h);
        $this->assertStringContainsString('corexPitchBook(', $h, 'the popup learns the step 5 address');
    }

    public function test_the_belt_rows_button_opens_the_evaluation_form_with_the_property_the_owner_and_the_title(): void
    {
        $p = $this->prospect();
        $r = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.evaluation-path', ['prospect' => $p->id, 'embed' => 1]));

        $r->assertRedirect();
        $url = (string) $r->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/command-center/calendar', $url);
        $this->assertSame('property_evaluation', $q['prefill_class']);
        $this->assertSame('1', $q['pitch_book']);
        $this->assertSame((string) $p->id, $q['rts_prospect']);
        $this->assertSame('Evaluation: 12 Pitch Road', $q['prefill_title']);
        $this->assertSame($this->propertyId, json_decode($q['prefill_properties'], true)[0]['id']);
        $att = json_decode($q['prefill_attendees'], true)[0];
        $this->assertSame([(int) $this->owner->id, 'seller_contact'], [$att['id'], $att['role']], 'the owner is the seller');

        $other = $this->makeAgent();
        $this->actingAs($other)->get(route('road-to-sold.prospects.evaluation-path', $p->id))->assertForbidden();
    }

    public function test_with_no_owner_the_row_goes_to_find_the_owner_not_to_a_calendar_without_a_seller(): void
    {
        $p = $this->prospect();
        $this->tp->update(['owner_contact_id' => null]);
        $r = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.evaluation-path', $p->id));

        $r->assertRedirect();
        $this->assertStringNotContainsString('command-center/calendar', (string) $r->headers->get('Location'));
    }

    public function test_saving_in_the_popup_goes_to_the_booked_page_and_the_call_is_logged_against_that_one_appointment(): void
    {
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();
        $when = now()->addDays(10)->setTime(10, 0);

        $save = $this->actingAs($this->agent)->post(route('command-center.calendar.store'), [
            'title' => 'Evaluation: 12 Pitch Road', 'category' => 'property_evaluation', 'event_date' => $when->format('Y-m-d H:i:s'), 'end_date' => $when->copy()->addHour()->format('Y-m-d H:i:s'),
            'property_ids' => [$this->propertyId], 'attendees' => [['id' => $this->owner->id, 'type' => 'contact', 'role' => 'seller_contact']],
            'pitch_book' => 1, 'embed' => 1, 'pitch_outcome' => $def->id, 'pitch_channel' => 'phone', 'pitch_when' => now()->subMinutes(5)->format('Y-m-d\TH:i'), 'pitch_note' => 'Wants it valued before December',
        ]);
        $save->assertRedirect();
        $to = (string) $save->headers->get('Location');
        $this->assertStringContainsString('/outreach/evaluation-booked/', $to);
        $this->assertStringContainsString('pitch_outcome=' . $def->id, $to, $to);

        $event = CalendarEvent::withoutGlobalScopes()->where('category', 'property_evaluation')->firstOrFail();
        $page = $this->actingAs($this->agent)->get($to)->assertOk()->getContent();
        $this->assertStringContainsString('Appointment booked', $page);
        $this->assertStringContainsString($when->format('j M H:i'), $page);
        $this->assertStringContainsString('12 Pitch Road', $page);
        $this->assertStringNotContainsString('booked-problem', $page, trim(strip_tags(substr($page, (int) strpos($page, 'booked-problem'), 400))));
        $this->assertStringContainsString('Spoke - evaluation booked', $page, 'the call is logged');

        $this->assertSame(1, CalendarEvent::withoutGlobalScopes()->where('category', 'property_evaluation')->count(), 'ONE appointment: the outcome used the one just made');
        $row = RtsContactOutcome::query()->firstOrFail();
        $this->assertSame((int) $event->id, (int) $row->calendar_event_id);
        $this->assertSame('phone', $row->channel);
        $this->assertTrue((bool) $row->valuation_booked);
        $this->assertSame('Wants it valued before December', $row->note);
        $this->assertSame($when->format('Y-m-d'), $row->next_action_at?->format('Y-m-d'), 'it comes back on the day of the appointment');
    }

    public function test_an_evaluation_booked_by_the_belt_button_alone_logs_no_call(): void
    {
        $when = now()->addDays(3)->setTime(9, 30);
        $save = $this->actingAs($this->agent)->post(route('command-center.calendar.store'), [
            'title' => 'Evaluation: 12 Pitch Road', 'category' => 'property_evaluation', 'event_date' => $when->format('Y-m-d H:i:s'), 'end_date' => $when->copy()->addHour()->format('Y-m-d H:i:s'),
            'property_ids' => [$this->propertyId], 'pitch_book' => 1, 'embed' => 1,
        ]);
        $page = $this->actingAs($this->agent)->get((string) $save->headers->get('Location'))->assertOk()->getContent();

        $this->assertStringContainsString('Appointment booked', $page);
        $this->assertSame(0, RtsContactOutcome::query()->count());
    }

    public function test_another_agents_appointment_cannot_be_used_to_log_a_call(): void
    {
        $other = $this->makeAgent();
        $event = $this->actingAs($other)->post(route('command-center.calendar.store'), [
            'title' => 'Evaluation: other', 'category' => 'property_evaluation', 'event_date' => now()->addDay()->format('Y-m-d H:i:s'), 'end_date' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
        ]);
        $this->assertTrue($event->isRedirect());
        $ev = CalendarEvent::withoutGlobalScopes()->where('user_id', $other->id)->firstOrFail();
        $def = Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();

        $this->actingAs($this->agent)->get(route('seller-outreach.entry.evaluation-booked', ['calendarEvent' => $ev->id, 'pitch_outcome' => $def->id]))->assertForbidden();
    }
}
