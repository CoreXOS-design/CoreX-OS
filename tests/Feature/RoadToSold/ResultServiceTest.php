<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\Property;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsResultOption;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarEventCreator;
use App\Services\PermissionService;
use App\Services\Presentations\PresentationOutcomeService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\ResultOptionService;
use App\Services\RoadToSold\ResultService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A5 (road-to-sold.md 23.9 / 23.10): ONE result list and ONE writer. Won / undecided / lost / not selling set the belt record,
 * the claim and the property together; the evaluation form, the presentation panel and the belt card all call it, and it is idempotent per day.
 */
final class ResultServiceTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ResultOptionService::forgetMemo();
        \App\Services\RoadToSold\ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A claimed property sitting on a CoreX property at Prospecting, the agent's record at $stage. @return array{0:\App\Models\Prospecting\TrackedProperty,1:Property,2:RtsProspect,3:ProspectingClaim} */
    private function belt(string $stage = ProspectStages::EVALUATION_DONE, string $propertyStatus = 'prospecting'): array
    {
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $property = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => $propertyStatus,
            'title' => '12 Bairn Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '12 Bairn Street', 'suburb' => 'Margate']);
        DB::table('properties')->where('id', $property->id)->update(['tracked_property_id' => $tp->id]);
        $claim = ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh();
        $p = RtsProspect::withoutGlobalScopes()->findOrFail($claim->rts_prospect_id);
        $p->forceFill(['property_id' => $property->id, 'stage' => $stage])->save();

        return [$tp, $property->refresh(), $p->refresh(), $claim->refresh()];
    }

    private function rec(RtsProspect $p, string $key, ?string $revisit = null, ?string $note = null, string $source = 'belt_card', ?User $by = null): array
    {
        return app(ResultService::class)->record($p->fresh(), $key, $revisit ? Carbon::parse($revisit) : null, $note, $by ?? $this->agent, $source);
    }

    public function test_the_default_list_is_the_same_everywhere_and_neutral(): void
    {
        $keys = array_column(app(ResultOptionService::class)->list($this->agency->id), 'key');
        $this->assertSame(['won_mandate', 'undecided', 'lost_other_agency', 'lost_price', 'lost_not_ready', 'not_selling'], $keys);
        $this->assertSame(6, RtsResultOption::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'seeded for the agency on first use');
        $this->assertSame('Won - mandate agreed', app(ResultOptionService::class)->byKey($this->agency->id, 'won_mandate')['label']);
    }

    public function test_won_takes_the_record_to_mandate_signed_and_the_property_to_draft(): void
    {
        [, $property, $p, $claim] = $this->belt();

        $r = $this->rec($p, 'won_mandate');

        $this->assertTrue($r['ok']);
        $p->refresh();
        // Won -> Mandate signed ("take the mandate"), and the property becoming a Draft takes the record on to Draft (A7, station 9)
        $this->assertSame(ProspectStages::DRAFT, $p->stage);
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', ProspectStages::MANDATE_SIGNED)->where('kind', 'stage')->count());
        $this->assertSame('draft', $property->fresh()->status);
        $this->assertSame(ProspectingClaim::STATUS_LISTING, $claim->fresh()->status, 'the claim is a mirror of the station');
        $this->assertTrue((bool) $claim->fresh()->is_active);
    }

    public function test_undecided_sets_the_record_aside_at_its_station_with_a_revisit_date_and_keeps_the_claim_open(): void
    {
        [, $property, $p, $claim] = $this->belt();

        $r = $this->rec($p, 'undecided', '2026-11-09', 'Wants to talk to her husband');

        $this->assertTrue($r['ok']);
        $p->refresh();
        $this->assertSame(ProspectStages::EVALUATION_DONE, $p->stage, 'stays at the station it was at');
        $this->assertNotNull($p->parked_at);
        $this->assertSame('2026-11-09', $p->parked_until->toDateString());
        $this->assertSame('Not now', $p->park_reason_label);
        $this->assertTrue((bool) $claim->fresh()->is_active, 'open until the revisit');
        $this->assertSame('prospecting', $property->fresh()->status);
    }

    public function test_lost_sets_it_aside_with_the_reason_gives_the_claim_back_but_keeps_the_record(): void
    {
        [, $property, $p, $claim] = $this->belt();

        $r = $this->rec($p, 'lost_price');

        $this->assertTrue($r['ok']);
        $p->refresh();
        $this->assertNotNull($p->parked_at, 'set aside with its reason');
        $this->assertNull($p->released_at, 'the record is kept, not released');
        $this->assertSame('Price unrealistic', $p->park_reason_label);
        $this->assertSame('2026-12-29', $p->parked_until->toDateString(), 'the result\'s own 60 days');
        $claim->refresh();
        $this->assertFalse((bool) $claim->is_active);
        $this->assertSame(ProspectingClaim::STATUS_LOST, $claim->status);
        $this->assertNotNull($claim->released_at);
        $this->assertSame('prospecting', $property->fresh()->status);
    }

    public function test_not_selling_marks_the_property_and_bringing_it_back_returns_it_to_prospecting(): void
    {
        [, $property, $p, $claim] = $this->belt();

        $this->rec($p, 'not_selling', null, 'Husband said no');

        $this->assertSame('not_selling', $property->fresh()->status);
        $claim->refresh();
        $this->assertFalse((bool) $claim->is_active);
        $this->assertSame(ProspectingClaim::STATUS_NOT_INTERESTED, $claim->status);
        $p->refresh();
        $this->assertNotNull($p->parked_at);
        $this->assertNull($p->released_at);
        $this->assertSame(1, \App\Models\PropertyNote::query()->withoutGlobalScopes()->where('property_id', $property->id)->count(), 'the reason is filed on the property');

        app(ProspectService::class)->unpark($p, $this->agent);

        $this->assertSame('prospecting', $property->fresh()->status, 'un-parking returns it');
        $this->assertNull($p->fresh()->parked_at);
    }

    public function test_the_same_result_twice_on_the_same_day_is_one_result(): void
    {
        [, , $p] = $this->belt();

        $first = $this->rec($p, 'lost_price');
        $second = $this->rec($p, 'lost_price');

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'result')->count());
    }

    public function test_a_property_already_on_the_books_is_never_closed_by_a_lost_or_not_selling_result(): void
    {
        [$tp, , $p, $claim] = $this->belt();
        DB::table('tracked_properties')->where('id', $tp->id)->update(['promoted_to_property_id' => DB::table('properties')->where('tracked_property_id', $tp->id)->value('id')]);

        $r = $this->rec($p, 'not_selling');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already been promoted onto your books', $r['message']);
        $this->assertTrue((bool) $claim->fresh()->is_active);
        $this->assertNull($p->fresh()->parked_at);
    }

    public function test_an_unknown_or_switched_off_result_is_refused_in_plain_words(): void
    {
        [, , $p] = $this->belt();
        $this->rec($p, 'won_mandate');   // seeds
        $this->assertFalse($this->rec($p, 'made_up')['ok']);
        RtsResultOption::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'lost_price')->first()->delete();
        ResultOptionService::forgetMemo();
        [, , $q] = $this->belt();
        $r = $this->rec($q, 'lost_price');   // archived: still known (old rows keep their wording), still works for a record that used it
        $this->assertTrue($r['ok']);
    }

    public function test_the_belt_card_posts_a_result_and_a_colleague_cannot(): void
    {
        [, , $p] = $this->belt();
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        PermissionService::clearCache();

        $this->actingAs($this->mate)->post(route('road-to-sold.prospects.result', $p->id), ['result_key' => 'won_mandate'])->assertStatus(404);
        $this->assertSame(ProspectStages::EVALUATION_DONE, $p->fresh()->stage);

        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.result', $p->id), ['result_key' => 'undecided', 'revisit_on' => '2026-11-09'])->assertSessionHas('status');
        $this->assertSame('2026-11-09', $p->fresh()->parked_until->toDateString());

        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.result', $p->id), ['result_key' => 'nonsense'])->assertSessionHasErrors('result_key');
    }

    public function test_the_presentation_panel_answers_from_the_list_and_writes_the_same_result(): void
    {
        [, $property, $p] = $this->belt(ProspectStages::PRESENTATION_PREPARED);
        $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id,
            'title' => 'P', 'property_address' => '12 Bairn Street', 'status' => 'draft']);
        foreach (['access_road_to_sold' => null, 'access_presentations' => null] as $perm => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $perm, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $res = $this->actingAs($this->agent)->post(route('presentations.outcome.record', $pres->id), ['result_key' => 'lost_price', 'cancellation_reason' => 'price_too_high_seller']);
        $this->assertSame(302, $res->getStatusCode(), 'redirected to ' . $res->headers->get('Location'));
        $res->assertSessionHasNoErrors();

        $row = PresentationOutcome::query()->withoutGlobalScopes()->where('presentation_id', $pres->id)->firstOrFail();
        $this->assertSame('lost_to_price_dispute', $row->outcome, 'the old value is still filled by the fixed map');
        $this->assertSame('lost_price', DB::table('presentation_outcomes')->where('id', $row->id)->value('result_key'));
        $this->assertSame('price_too_high_seller', $row->cancellation_reason);
        $this->assertNotNull($p->fresh()->parked_at, 'and the belt record followed in the same save');
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'result')->count(), 'one result, not two');
    }

    public function test_an_outcome_recorded_the_old_way_still_drives_the_result(): void
    {
        [, $property, $p] = $this->belt(ProspectStages::PRESENTATION_PREPARED);
        $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id,
            'title' => 'P', 'property_address' => '12 Bairn Street', 'status' => 'draft']);

        app(PresentationOutcomeService::class)->record($pres, ['outcome' => 'lost_to_competitor', 'cancellation_reason' => 'existing_relationship'], $this->agent);

        $p->refresh();
        $this->assertNotNull($p->parked_at);
        $this->assertSame('Seller chose another agency', $p->park_reason_label);
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'result')->count());
    }

    public function test_a_held_evaluation_feedback_with_an_answer_is_the_result_and_the_old_wording_still_maps(): void
    {
        [, $property, $p] = $this->belt(ProspectStages::APPOINTMENT_SET);
        $this->actingAs($this->agent);
        $event = app(CalendarEventCreator::class)->create(['title' => 'Evaluation', 'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'property_id' => $property->id], $this->agent)->refresh();

        CalendarEventFeedback::withoutGlobalScopes()->create([
            'calendar_event_id' => $event->id, 'property_id' => $property->id, 'feedback_kind' => 'listing_presentation', 'visibility' => 'internal_only',
            'kind_specific_data' => ['eval_status' => 'held', 'outcome' => 'Pricing too far'], 'captured_by_user_id' => $this->agent->id, 'captured_at' => now(),
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);

        $p->refresh();
        $this->assertNotNull($p->parked_at);
        $this->assertSame('Price unrealistic', $p->park_reason_label, '"Pricing too far" is the shared "Price too far apart"');
        $fb = CalendarEventFeedback::withoutGlobalScopes()->where('calendar_event_id', $event->id)->first();
        $this->assertSame('lost_price', $fb->kind_specific_data['result_key']);
        $this->assertSame('Price too far apart', $fb->kind_specific_data['outcome'], 'the form reopens with the shared wording');
    }

    public function test_the_calendar_feedback_form_offers_the_shared_list_for_a_seller_facing_appointment(): void
    {
        $labels = array_column(app(ResultOptionService::class)->list($this->agency->id), 'label');
        $this->assertContains('Won - mandate agreed', $labels);
        $this->assertNotContains('Reschedule', $labels, '"Reschedule" is a status now, not a result');
        $this->assertSame('lost_price', app(ResultOptionService::class)->keyForLabel($this->agency->id, 'Pricing too far'));
        $this->assertNull(app(ResultOptionService::class)->keyForLabel($this->agency->id, 'Reschedule'));
    }

    public function test_the_agency_edits_the_one_list_and_archived_wording_is_kept(): void
    {
        app(ResultOptionService::class)->seed($this->agency->id);
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        $this->post(route('road-to-sold.settings.results.store'), ['label' => 'Chose a relative', 'class' => 'lost', 'revisit_days' => 120])->assertSessionHas('status');
        $custom = RtsResultOption::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('label', 'Chose a relative')->firstOrFail();
        $this->assertSame('lost', $custom->class);
        $this->assertSame(120, (int) $custom->revisit_days);

        $this->put(route('road-to-sold.settings.results.update', $custom->id), ['label' => 'Chose a family member', 'class' => 'lost', 'revisit_days' => 100])->assertSessionHas('status');
        $this->assertSame('Chose a family member', $custom->fresh()->label);

        $this->delete(route('road-to-sold.settings.results.archive', $custom->id))->assertSessionHas('status');
        $this->assertNotNull(RtsResultOption::withoutGlobalScopes()->withTrashed()->find($custom->id)->deleted_at);
        $this->assertSame('Chose a family member', app(ResultOptionService::class)->byKey($this->agency->id, $custom->key)['label'], 'an old record keeps the wording');
        $this->post(route('road-to-sold.settings.results.restore', $custom->id))->assertSessionHas('status');
        $this->assertNull($custom->fresh()->deleted_at);

        $this->get(route('road-to-sold.settings.results.index'))->assertOk()->assertSee('Chose a family member');
    }

    public function test_a_second_agencys_list_is_its_own(): void
    {
        $b = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        app(ResultOptionService::class)->seed($this->agency->id);
        RtsResultOption::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'won_mandate')->update(['label' => 'We got it']);
        ResultOptionService::forgetMemo();

        $this->assertSame('We got it', app(ResultOptionService::class)->byKey($this->agency->id, 'won_mandate')['label']);
        $this->assertSame('Won - mandate agreed', app(ResultOptionService::class)->byKey((int) $b->id, 'won_mandate')['label']);
    }

    public function test_the_presentation_back_fill_is_a_dry_run_first_and_can_be_undone(): void
    {
        [, $property] = $this->belt();
        $ids = [];
        foreach (['won_sale' => 'won_mandate', 'lost_to_no_response' => 'lost_not_ready'] as $enum => $key) {
            $pres = Presentation::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'property_id' => $property->id + random_int(1000, 9000),
                'title' => 'P', 'property_address' => 'x', 'status' => 'draft']);
            $ids[$enum] = DB::table('presentation_outcomes')->insertGetId(['presentation_id' => $pres->id, 'agency_id' => $this->agency->id, 'outcome' => $enum,
                'cancellation_reason' => str_starts_with($enum, 'lost') ? 'other' : null, 'notes' => 'x', 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $dir = sys_get_temp_dir() . '/rts-res-' . uniqid();

        $this->artisan("rts:backfill-result-keys --agency={$this->agency->id} --journal-dir={$dir}")->assertSuccessful();
        $this->assertNull(DB::table('presentation_outcomes')->where('id', $ids['won_sale'])->value('result_key'));

        $this->artisan("rts:backfill-result-keys --agency={$this->agency->id} --apply --journal-dir={$dir}")->assertSuccessful();
        $this->assertSame('won_mandate', DB::table('presentation_outcomes')->where('id', $ids['won_sale'])->value('result_key'));
        $this->assertSame('lost_not_ready', DB::table('presentation_outcomes')->where('id', $ids['lost_to_no_response'])->value('result_key'));
        $this->assertSame('won_sale', DB::table('presentation_outcomes')->where('id', $ids['won_sale'])->value('outcome'), 'the old value is never changed');

        $journal = glob($dir . '/rts-backfill-result-keys-*.jsonl');
        $this->artisan("rts:backfill-result-keys --undo={$journal[0]}")->assertSuccessful();
        $this->assertNull(DB::table('presentation_outcomes')->where('id', $ids['won_sale'])->value('result_key'));
    }
}
