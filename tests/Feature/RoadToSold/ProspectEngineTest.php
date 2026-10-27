<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\Property;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsProspectEvent;
use App\Models\User;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - the prospect engine (.ai/specs/road-to-sold.md section 22): the allocation (one agent per property), the stages,
 * the outcome rules (next action, due date, tries, parking), the follow-ups, the nightly build in rounds, and who may see what.
 */
final class ProspectEngineTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->prospects = app(ProspectService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function def(string $key): Def
    {
        return Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->firstOrFail();
    }

    private function record(User $agent, string $key, $tp, array $input = []): RtsContactOutcome
    {
        $this->actingAs($agent);

        return app(OutcomeService::class)->record($agent, $tp->id, $this->def($key)->id, $input);
    }

    private function prospectOf($tp): RtsProspect
    {
        return RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->firstOrFail();
    }

    // ── The allocation ───────────────────────────────────────────────────

    public function test_a_property_belongs_to_one_agent_until_it_is_released(): void
    {
        $tp = $this->tp();

        $mine = $this->prospects->place($this->agent, $tp->id);
        $this->assertNotNull($mine);
        $this->assertSame(S::FOUND, $mine->stage);
        $this->assertSame($mine->id, $this->prospects->place($this->agent, $tp->id)->id, 'the same agent asking again changes nothing');
        $this->assertNull($this->prospects->place($this->mate, $tp->id), 'another agent is refused');
        $this->assertSame([$tp->id], $this->prospects->ownedByOthers($this->mate, [$tp->id]));
        $this->assertSame([], $this->prospects->ownedByOthers($this->agent, [$tp->id]));

        $this->prospects->release($mine, $this->agent);
        $taken = $this->prospects->place($this->mate, $tp->id);
        $this->assertSame($this->mate->id, (int) $taken->user_id, 'a released property is free again');
        $this->assertSame(S::FOUND, $taken->stage, 'and starts over at Found');
        $this->assertSame(1, RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->count(), 'one row per property, re-used');
    }

    public function test_the_history_is_append_only(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);
        $event = RtsProspectEvent::withoutGlobalScopes()->where('prospect_id', $p->id)->firstOrFail();
        $this->assertSame('placed', $event->kind);

        $this->expectException(\LogicException::class);
        $event->update(['note' => 'changed']);
    }

    // ── Stages ───────────────────────────────────────────────────────────

    public function test_stages_only_move_forward_and_the_same_event_moves_nothing_twice(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);
        $src = ['event_class' => 'X', 'subject_type' => 'thing', 'subject_id' => 7];

        $this->assertTrue($this->prospects->advance($p, S::EVALUATION_DONE, $this->agent, $src));
        $this->assertFalse($this->prospects->advance($p->refresh(), S::EVALUATION_DONE, $this->agent, $src), 'the same event twice');
        $this->assertFalse($this->prospects->advance($p->refresh(), S::CONTACTED, $this->agent, ['subject_type' => 'thing', 'subject_id' => 8]), 'never backward');
        $this->assertSame(S::EVALUATION_DONE, $p->refresh()->stage);
        $this->assertSame('Give the presentation', $p->next_action_label);
        $this->assertSame(1, RtsProspectEvent::withoutGlobalScopes()->where('prospect_id', $p->id)->where('kind', 'stage')->count());

        $this->assertTrue($this->prospects->advance($p, S::ADVERTISING, $this->agent, ['subject_type' => 'thing', 'subject_id' => 9], true), 'a reset is explicit');
        $this->assertFalse($this->prospects->advance($p->refresh(), 'not_a_stage'));
    }

    public function test_registered_closes_the_prospect_and_frees_the_property(): void
    {
        $tp = $this->tp();
        $p = $this->prospects->place($this->agent, $tp->id);
        $this->prospects->advance($p, S::REGISTERED, $this->agent, ['subject_type' => 'deal', 'subject_id' => 1]);

        $this->assertNotNull($p->refresh()->closed_at);
        $this->assertSame('registered', $p->closed_reason);
        $this->assertNull($this->prospects->ownerOf($this->agency->id, $tp->id), 'a finished story frees the property');
    }

    // ── Parking ──────────────────────────────────────────────────────────

    public function test_parked_stock_has_a_reason_and_comes_back_on_its_date_as_a_follow_up(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);
        $this->prospects->advance($p, S::EVALUATION_DONE, $this->agent, ['subject_type' => 't', 'subject_id' => 1]);
        $this->prospects->park($p->refresh(), 'chose_other_agency', null, 'went with Sam', $this->agent);

        $p->refresh();
        $this->assertSame('Seller chose another agency', $p->park_reason_label);
        $this->assertSame('2027-01-12', $p->parked_until->toDateString(), 'the reason\'s own interval (90 days)');
        $this->assertSame(S::EVALUATION_DONE, $p->parked_from_stage);
        $this->assertCount(0, $this->prospects->dueFollowUps($this->agent, Carbon::parse(self::WED)));
        $this->assertCount(1, $this->prospects->dueFollowUps($this->agent, Carbon::parse('2027-01-12')));

        $this->prospects->unpark($p, $this->agent);
        $p->refresh();
        $this->assertNull($p->parked_at);
        $this->assertSame(S::EVALUATION_DONE, $p->stage, 'it returns at the stage it left');
        $this->assertSame(self::WED, $p->next_action_at->toDateString());
        $this->assertCount(1, $this->prospects->dueFollowUps($this->agent, Carbon::parse(self::WED)));
    }

    public function test_real_progress_brings_a_parked_prospect_back(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);
        $this->prospects->park($p, 'not_selling_now', null, null, $this->agent);
        $this->prospects->advance($p->refresh(), S::PRESENTATION_GIVEN, $this->agent, ['subject_type' => 'p', 'subject_id' => 1]);

        $this->assertNull($p->refresh()->parked_at);
    }

    // ── Outcomes: next action, date, stage ───────────────────────────────

    public function test_no_answer_calls_again_in_seven_days_and_three_in_a_row_drop_it(): void
    {
        $tp = $this->tp();
        $o = $this->record($this->agent, 'no_answer', $tp);

        $p = $this->prospectOf($tp);
        $this->assertSame('Call again', $p->next_action_label);
        $this->assertSame('2026-10-21', $p->next_action_at->toDateString());
        $this->assertSame(S::FOUND, $p->stage, 'an attempt is not contact');

        $this->record($this->agent, 'no_answer', $tp);
        $this->record($this->agent, 'no_answer', $tp);
        $p->refresh();
        $this->assertNull($p->next_action_label, 'three in a row: off the list and on the manager\'s report');
        $this->assertSame('dropped', $p->next_action_key);
        $this->assertSame(0, $this->prospects->dueFollowUps($this->agent, Carbon::parse('2026-12-01'))->count());
    }

    public function test_not_now_maybe_later_follows_up_in_six_months_by_default_or_three_or_twelve(): void
    {
        $a = $this->tp(); $b = $this->tp(); $c = $this->tp();

        $this->record($this->agent, 'spoke_not_interested', $a);
        $this->assertSame('2027-04-14', $this->prospectOf($a)->next_action_at->toDateString(), 'default 6 months');
        $this->assertSame(S::CONTACTED, $this->prospectOf($a)->stage);

        $this->record($this->agent, 'spoke_not_interested', $b, ['follow_up_months' => 3]);
        $this->assertSame('2027-01-14', $this->prospectOf($b)->next_action_at->toDateString());
        $this->record($this->agent, 'spoke_not_interested', $c, ['follow_up_months' => 12]);
        $this->assertSame('2027-10-14', $this->prospectOf($c)->next_action_at->toDateString());

        $this->expectException(RoadToSoldException::class);
        $this->record($this->agent, 'spoke_not_interested', $this->tp(), ['follow_up_months' => 5]);
    }

    public function test_call_back_needs_a_date_and_sole_mandate_follows_up_at_the_expiry_date(): void
    {
        $tp = $this->tp();
        try {
            $this->record($this->agent, 'spoke_call_back', $tp);
            $this->fail('a date is required');
        } catch (RoadToSoldException) {
            $this->assertNull(RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->first(), 'nothing written');
        }
        $this->record($this->agent, 'spoke_call_back', $tp, ['next_action_at' => '2026-10-20']);
        $this->assertSame('2026-10-20', $this->prospectOf($tp)->next_action_at->toDateString());

        $sole = $this->tp();
        $this->record($this->agent, 'mandated_other_agency_sole', $sole, ['next_action_at' => '2027-03-01']);
        $this->assertSame('2027-03-01', $this->prospectOf($sole)->next_action_at->toDateString());
    }

    public function test_listed_with_another_agency_checks_the_mandate_expiry_and_without_a_date_waits_ninety_days(): void
    {
        $tp = $this->tp();
        $this->record($this->agent, 'listed_other_agency_open', $tp);

        $p = $this->prospectOf($tp);
        $this->assertSame('Check the mandate expiry', $p->next_action_label);
        $this->assertSame('2027-01-12', $p->next_action_at->toDateString());
    }

    public function test_wrong_number_finds_the_owner_again_and_two_tries_park_it(): void
    {
        $tp = $this->tp();
        $this->record($this->agent, 'wrong_number', $tp);

        $p = $this->prospectOf($tp);
        $this->assertSame('Find the owner', $p->next_action_label);
        $this->assertSame('2026-10-17', $p->next_action_at->toDateString());
        $this->assertSame(1, $p->tries);
        $this->assertNull($p->parked_at);

        $this->record($this->agent, 'wrong_number', $tp);
        $p->refresh();
        $this->assertNotNull($p->parked_at, 'two tries and it is parked');
        $this->assertSame('No owner found', $p->park_reason_label);
    }

    public function test_after_the_evaluation_undecided_follows_up_in_a_week_and_the_two_losses_park_it(): void
    {
        $a = $this->tp(); $b = $this->tp(); $c = $this->tp();

        $this->record($this->agent, 'eval_undecided', $a);
        $this->assertSame('2026-10-21', $this->prospectOf($a)->next_action_at->toDateString());
        $this->assertSame(S::EVALUATION_DONE, $this->prospectOf($a)->stage);

        $this->record($this->agent, 'eval_chose_other', $b);
        $pb = $this->prospectOf($b);
        $this->assertSame('Seller chose another agency', $pb->park_reason_label);
        $this->assertSame('2027-01-12', $pb->parked_until->toDateString());
        $this->assertContains($pb->id, $this->prospects->dueFollowUps($this->agent, Carbon::parse('2027-01-12'))->pluck('id')->all(), 'it returns on its date');

        $this->record($this->agent, 'eval_selling_privately', $c);
        $this->assertSame('Not selling now', $this->prospectOf($c)->park_reason_label);
        $this->assertSame('2026-11-13', $this->prospectOf($c)->parked_until->toDateString());
    }

    public function test_do_not_contact_closes_the_prospect_for_everyone(): void
    {
        $tp = $this->tp();
        $this->record($this->agent, 'do_not_contact', $tp);

        $this->assertSame('do_not_contact', $this->prospectOf($tp)->closed_reason);
    }

    public function test_another_agent_cannot_record_a_call_on_a_property_that_is_not_theirs(): void
    {
        $tp = $this->tp();
        $this->prospects->place($this->agent, $tp->id);

        try {
            $this->record($this->mate, 'no_answer', $tp);
            $this->fail('the property is the first agent\'s');
        } catch (RoadToSoldException $e) {
            $this->assertStringContainsString('is working this property', $e->getMessage());
        }
        $this->assertSame(0, RtsContactOutcome::withoutGlobalScopes()->count());
    }

    public function test_an_evaluation_booked_from_a_call_attaches_a_property_and_the_owner_to_the_calendar_entry(): void
    {
        $owner = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Botha', 'phone' => '0825550101',
        ]);
        $tp = $this->tp(['street_number' => '12', 'street_name' => 'Marine Drive', 'owner_contact_id' => $owner->id, 'suburb' => 'Uvongo']);

        $o = $this->record($this->agent, 'spoke_evaluation_booked', $tp, ['appointment_at' => '2026-10-20 10:00']);

        $this->assertNotNull($o->property_id, 'the property is attached');
        $property = Property::withoutGlobalScopes()->find($o->property_id);
        $this->assertSame($this->agent->id, (int) $property->agent_id);
        $this->assertNotSame('active', $property->status, 'a prospecting property, never live stock');
        $this->assertSame($property->id, (int) $tp->fresh()->promoted_to_property_id);
        $this->assertTrue(DB::table('contact_property')->where('contact_id', $owner->id)->where('property_id', $property->id)->exists(), 'the owner is linked to it');

        $event = \App\Models\CommandCenter\CalendarEvent::withoutGlobalScopes()->find($o->calendar_event_id);
        $this->assertSame($property->id, (int) $event->property_id);
        $this->assertSame($owner->id, (int) $event->contact_id);

        $p = $this->prospectOf($tp);
        $this->assertSame(S::APPOINTMENT_SET, $p->stage);
        $this->assertSame('Do the evaluation', $p->next_action_label);
        $this->assertSame('2026-10-20', $p->next_action_at->toDateString());
        $this->assertSame($property->id, (int) $p->property_id);
    }

    // ── The outcome rules are the agency's ───────────────────────────────

    public function test_an_older_form_that_does_not_send_the_new_fields_never_wipes_them(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $row = $this->def('wrong_number');
        $this->assertSame(2, $row->max_tries);

        $svc->save($this->agency->id, null, ['label' => 'Wrong number (renamed)', 'kind' => 'attempt', 'next_action' => 'find_owner_after_days', 'delay_days' => '3'], $row);

        $row->refresh();
        $this->assertSame('Wrong number (renamed)', $row->label);
        $this->assertSame(2, $row->max_tries);
        $this->assertSame('park', $row->after_max_tries);
        $this->assertSame('no_owner_found', $row->park_reason_key);
    }

    public function test_the_agency_can_edit_months_tries_and_the_stage_an_outcome_moves_to(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $row = $svc->save($this->agency->id, null, [
            'label' => 'Maybe next year', 'kind' => 'conversation', 'next_action' => 'return_after_months',
            'choice_months' => '2, 4, 8', 'default_months' => '4', 'stage_after' => 'contacted',
            'max_tries' => '3', 'after_max_tries' => 'manager_report',
        ]);
        $this->assertSame([2, 4, 8], $row->choice_months);
        $this->assertSame(4, $row->default_months);
        $this->assertSame('manager_report', $row->after_max_tries);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->save($this->agency->id, null, ['label' => 'Bad', 'kind' => 'conversation', 'next_action' => 'return_after_months', 'choice_months' => 'soon']);
    }

    public function test_evaluation_outcomes_are_offered_only_once_an_appointment_is_set(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $early = $svc->availableFor($this->agency->id, 'sales', S::FOUND)->pluck('key')->all();
        $late = $svc->availableFor($this->agency->id, 'sales', S::APPOINTMENT_SET)->pluck('key')->all();

        $this->assertNotContains('eval_chose_other', $early);
        $this->assertContains('eval_chose_other', $late);
        $this->assertContains('no_answer', $early);
    }

    public function test_the_upgrade_changes_only_untouched_old_defaults_and_adds_the_new_outcomes(): void
    {
        // an agency still on the old eight: edit one of them, leave another as seeded
        Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereIn('key', ['eval_undecided', 'eval_chose_other', 'eval_selling_privately', 'no_owner_found'])->forceDelete();
        $this->def('spoke_not_interested')->forceFill(['label' => 'Spoke - not interested', 'next_action' => 'return_on_picked_date', 'choice_months' => null, 'default_months' => null, 'stage_after' => null])->save();
        $this->def('spoke_call_back')->forceFill(['label' => 'We agreed a call back', 'next_action' => 'return_on_picked_date'])->save();

        $svc = app(OutcomeDefinitionService::class);
        $report = $svc->upgradeDefaults($this->agency->id);
        $this->assertContains('spoke_not_interested', $report['updated']);
        $this->assertContains('spoke_call_back', $report['kept'], 'the agency changed its wording: left alone');
        $this->assertSame(['no_owner_found', 'eval_undecided', 'eval_chose_other', 'eval_selling_privately'], array_values(array_intersect(['no_owner_found', 'eval_undecided', 'eval_chose_other', 'eval_selling_privately'], $report['added'])));
        $this->assertSame('return_on_picked_date', $this->def('spoke_not_interested')->next_action, 'a dry run writes nothing');

        $svc->upgradeDefaults($this->agency->id, 'sales', true);
        $this->assertSame('return_after_months', $this->def('spoke_not_interested')->fresh()->next_action);
        $this->assertSame('We agreed a call back', $this->def('spoke_call_back')->fresh()->label);
        $this->assertSame(1, Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'eval_chose_other')->count());
    }

    // ── Allocation in the list builder ───────────────────────────────────

    private function pool(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->listing(['first_seen_at' => '2026-03-01 08:00:00', 'address' => "{$i} Pool Rd"]);
        }
    }

    public function test_the_nightly_build_deals_the_stock_out_in_rounds_so_everyone_gets_a_share(): void
    {
        $this->pool(6);
        $this->setting(['selections' => [['key' => 'long_on_market', 'enabled' => true]]]);

        $this->artisan('rts:build-lists', ['--agency' => $this->agency->id, '--date' => self::WED, '--no-close' => true])->assertExitCode(0);

        $a = RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->pluck('tracked_property_id')->all();
        $b = RtsListItem::withoutGlobalScopes()->where('user_id', $this->mate->id)->pluck('tracked_property_id')->all();
        $this->assertCount(3, $a);
        $this->assertCount(3, $b, 'six properties, two agents: three each, not five and one');
        $this->assertSame([], array_values(array_intersect($a, $b)), 'never the same property twice');
        $this->assertSame(6, RtsProspect::withoutGlobalScopes()->count());
    }

    public function test_a_property_someone_is_pitching_through_its_tracked_property_is_not_offered_to_others(): void
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01 08:00:00'], $tp);
        $this->setting(['selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        app(\App\Services\Prospecting\ProspectingClaimService::class)->createTempLockForTrackedProperty($tp->id, $this->mate->id, $this->agency->id);

        $this->assertCount(0, app(ListBuilderService::class)->buildFor($this->agent, self::WED), 'locked by a colleague');
        $this->assertCount(1, app(ListBuilderService::class)->buildFor($this->mate, self::WED), 'but it is theirs to be offered');
    }

    public function test_a_found_property_the_agent_never_reached_is_offered_to_them_again_but_one_they_called_is_not_new(): void
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01 08:00:00'], $tp);
        $this->setting(['selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        $this->prospects->place($this->agent, $tp->id);

        $this->assertCount(1, app(ListBuilderService::class)->buildFor($this->agent, self::WED), 'found, never worked: still theirs to be offered');
        $this->assertCount(0, app(ListBuilderService::class)->buildFor($this->mate, self::WED));

        $this->record($this->agent, 'no_answer', $tp);
        Cache::flush();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $next = app(ListBuilderService::class)->buildFor($this->agent, '2026-10-15');
        $this->assertCount(0, $next, 'called, so it comes back as a follow-up in a week, not as new intake');
    }

    public function test_parked_stock_returns_on_its_date_as_a_follow_up_in_todays_intake(): void
    {
        $tp = $this->tp();
        $p = $this->prospects->place($this->agent, $tp->id);
        $this->prospects->park($p, 'cannot_be_reached', Carbon::parse('2026-10-14')->addDay(), null, $this->agent);   // 15 Oct

        $none = app(ListBuilderService::class)->buildFor($this->agent, self::WED);
        $this->assertCount(0, $none, 'not yet');

        Cache::flush();
        $due = app(ListBuilderService::class)->buildFor($this->agent, '2026-10-15');
        $this->assertCount(1, $due);
        $this->assertSame('follow_up', $due->first()->origin);
        $this->assertStringContainsString('Back from parked (Cannot be reached)', (string) $due->first()->reason);
    }

    // ── Who may see what ─────────────────────────────────────────────────

    public function test_prospects_are_read_through_the_viewers_breadth_and_never_across_agencies(): void
    {
        $other = $this->makeAgent(null, \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Second']));
        $mine = $this->prospects->place($this->agent, $this->tp()->id);
        $theirs = $this->prospects->place($other, $this->tp()->id);

        $foreign = \App\Models\Agency::create(['name' => 'Elsewhere ' . uniqid(), 'slug' => 'els-' . uniqid()]);
        $foreignBranch = \App\Models\Branch::create(['agency_id' => $foreign->id, 'name' => 'Main']);
        $stranger = $this->makeAgent($foreign, $foreignBranch);
        $this->prospects->place($stranger, $this->tp([], $foreign)->id);

        $this->actingAs($this->agent);
        $ids = RtsProspect::query()->get()->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, RtsProspect::query()->visibleTo($this->agent)->pluck('id')->all(), 'an agent sees only their own');
        $this->assertSame(2, RtsProspect::query()->count(), 'the second agency\'s prospect is never in view');
    }

    private function setting(array $attrs): void
    {
        \App\Models\RoadToSold\RtsSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], $attrs);
        SettingsService::forget();
    }
}
