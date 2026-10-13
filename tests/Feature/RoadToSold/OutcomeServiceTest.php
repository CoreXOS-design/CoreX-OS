<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsPropertyFlag;
use App\Models\User;
use App\Services\RoadToSold\HoldService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-454 (lane 3) - outcome capture, holds, drop-off, do-not-contact, outcome list.
 * Spec: .ai/specs/road-to-sold.md section 10.
 *
 * Input paths proven: every seeded outcome; attempt vs conversation counted apart; no-answer run
 * (1, 2, drop on 3, reset by a conversation); picked date required / past rejected; valuation
 * booked from the seeded outcome and as the extra on a conversation (refused on an attempt, needs a
 * time); hold blocks another agent, lapses, restarts; do-not-contact with and without an owner
 * contact (consent record + never-suggest marker, bypasses another agent's hold); wrong number
 * tag; no-owner property (history on the property only); note optional / over-long; another
 * agency's property refused; outcome list add / rename / archive / recreate (restores) / blank /
 * bad days / reorder; second agency gets its own seed.
 */
final class OutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 09:00:00');

        [$this->agencyId, $this->agent, $this->other] = $this->makeAgency();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:int,1:User,2:User} */
    private function makeAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'is_active' => true]);
        $b = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'is_active' => true]);

        return [$agencyId, $a, $b];
    }

    private function tp(?int $ownerContactId = null, ?int $agencyId = null): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id'        => $agencyId ?? $this->agencyId,
            'street_number'    => '14',
            'street_name'      => 'Marine Drive',
            'suburb'           => 'Uvongo',
            'town'             => 'Margate',
            'owner_contact_id' => $ownerContactId,
        ]);
    }

    private function owner(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Pieter', 'last_name' => 'van der Merwe', 'phone' => '0825512345',
        ]);
    }

    private function def(string $key, ?int $agencyId = null): Def
    {
        app(OutcomeDefinitionService::class)->ensureDefaults($agencyId ?? $this->agencyId);

        return Def::withoutGlobalScopes()->where('agency_id', $agencyId ?? $this->agencyId)->where('key', $key)->firstOrFail();
    }

    private function svc(): OutcomeService
    {
        return app(OutcomeService::class);
    }

    private function record(string $key, TrackedProperty $tp, array $in = [], ?User $by = null): RtsContactOutcome
    {
        $this->actingAs($by ?? $this->agent);

        return $this->svc()->record($by ?? $this->agent, $tp->id, $this->def($key)->id, $in);
    }

    // ── The outcome list ────────────────────────────────────────────────────────────────────

    public function test_eight_outcomes_are_seeded_once_per_agency_and_a_second_agency_gets_its_own(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $svc->ensureDefaults($this->agencyId);
        $svc->ensureDefaults($this->agencyId);

        $this->assertSame(8, Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
        $noAnswer = $this->def('no_answer');
        $this->assertSame('attempt', $noAnswer->kind);
        $this->assertSame(Def::ACTION_AFTER_DAYS, $noAnswer->next_action);
        $this->assertSame(7, $noAnswer->delay_days);
        $this->assertTrue($noAnswer->counts_as_no_answer);
        $this->assertSame('conversation', $this->def('do_not_contact')->kind);
        $this->assertTrue($this->def('do_not_contact')->records_do_not_contact);
        $this->assertSame('Find the right contact', $this->def('wrong_number')->list_tag);
        $this->assertSame(Def::ACTION_EXPIRY_ELSE, $this->def('mandated_other_agency_sole')->next_action);

        [$agencyB] = $this->makeAgency();
        OutcomeDefinitionService::forgetMemo();
        $svc->ensureDefaults($agencyB);
        $this->assertSame(8, Def::withoutGlobalScopes()->where('agency_id', $agencyB)->count());
        $this->assertSame(16, Def::withoutGlobalScopes()->count());

        $svc->ensureDefaults(0);
        $this->assertSame(16, Def::withoutGlobalScopes()->count(), 'agency id 0 never writes');
    }

    public function test_add_rename_switch_off_archive_and_recreate_restores_the_archived_row(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $this->actingAs($this->agent);

        $new = $svc->save($this->agencyId, $this->agent->id, [
            'label' => '  Phone is off  ', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '3',
            'counts_as_no_answer' => true,
        ]);
        $this->assertSame('Phone is off', $new->label);
        $this->assertSame('phone_is_off', $new->key);
        $this->assertSame(3, $new->delay_days);

        $svc->save($this->agencyId, $this->agent->id, [
            'label' => 'Phone switched off', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => 5,
        ], $new);
        $this->assertSame('Phone switched off', $new->fresh()->label);
        $this->assertSame('phone_is_off', $new->fresh()->key, 'key is stable across a rename');

        $svc->setActive($new, false);
        $this->assertCount(8, $svc->list($this->agencyId), 'a switched-off outcome is not offered');
        $this->assertCount(9, $svc->list($this->agencyId, 'sales', false));

        $svc->archive($new);
        $this->assertNull(Def::find($new->id));
        $again = $svc->save($this->agencyId, $this->agent->id, [
            'label' => 'Phone is off', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => 2,
        ]);
        $this->assertSame($new->id, $again->id, 'create after archive restores the trashed row (unique ignores soft deletes)');
        $this->assertNull($again->deleted_at);
        $this->assertSame(2, $again->delay_days);
    }

    public function test_outcome_list_input_space_is_refused_in_plain_words(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $bad = [
            ['label' => '   ', 'kind' => 'attempt', 'next_action' => 'never_again'],
            ['label' => 'X', 'kind' => 'maybe', 'next_action' => 'never_again'],
            ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'teleport'],
            ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => ''],
            ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '0'],
            ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => 'abc'],
            ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'move_to_list', 'list_tag' => ' '],
        ];
        foreach ($bad as $i => $in) {
            try {
                $svc->save($this->agencyId, $this->agent->id, $in);
                $this->fail("case {$i} should be refused");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(8, Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
    }

    public function test_reorder_changes_the_order_shown_to_agents(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $ids = $svc->list($this->agencyId)->pluck('id')->all();
        $svc->reorder($this->agencyId, array_reverse($ids));

        $this->assertSame(array_reverse($ids), $svc->list($this->agencyId)->pluck('id')->all());
    }

    // ── Capturing an outcome ────────────────────────────────────────────────────────────────

    public function test_no_answer_is_an_attempt_returns_in_seven_days_holds_the_property_and_leaves_last_contacted_alone(): void
    {
        $owner = $this->owner();
        $tp = $this->tp($owner->id);

        $o = $this->record('no_answer', $tp, ['note' => 'Rang twice']);

        $this->assertSame('attempt', $o->kind);
        $this->assertSame(1, $o->no_answer_streak);
        $this->assertFalse($o->dropped);
        $this->assertSame('return_after_days', $o->next_action);
        $this->assertSame('2026-10-21', $o->next_action_at->toDateString());
        $this->assertTrue($o->is_current);
        $this->assertSame($owner->id, $o->contact_id);
        $this->assertNull($owner->fresh()->last_contacted_at, 'an attempt does not move last contacted');
        $this->assertDatabaseHas('contact_notes', ['contact_id' => $owner->id, 'user_id' => $this->agent->id, 'body' => 'Road to Sold - No answer: Rang twice']);

        $hold = RtsHold::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->first();
        $this->assertSame($this->agent->id, (int) $hold->user_id);
        $this->assertSame('2026-11-13', $hold->expires_at->toDateString(), '30 days from the call (agency setting)');
    }

    public function test_a_conversation_moves_last_contacted_and_writes_a_contacted_note(): void
    {
        $owner = $this->owner();
        $tp = $this->tp($owner->id);

        $o = $this->record('spoke_call_back', $tp, ['next_action_at' => '2026-10-30']);

        $this->assertSame('conversation', $o->kind);
        $this->assertSame('2026-10-30', $o->next_action_at->toDateString());
        $this->assertNotNull($owner->fresh()->last_contacted_at);
        $this->assertDatabaseHas('contact_notes', ['contact_id' => $owner->id, 'type' => 'Contacted']);
    }

    public function test_three_no_answers_in_a_row_drop_the_property_and_a_conversation_resets_the_run(): void
    {
        $tp = $this->tp();
        $a = $this->record('no_answer', $tp);
        $b = $this->record('no_answer', $tp);
        $this->assertSame([1, 2], [$a->no_answer_streak, $b->no_answer_streak]);
        $this->assertFalse($b->dropped);

        $c = $this->record('no_answer', $tp);
        $this->assertSame(3, $c->no_answer_streak);
        $this->assertTrue($c->dropped);
        $this->assertSame('dropped', $c->next_action);
        $this->assertNull($c->next_action_at);
        $this->assertCount(0, $this->svc()->followUpsDue($this->agent, '2027-01-01'), 'a dropped property never comes back as a follow-up');

        $tp2 = $this->tp();
        $this->record('no_answer', $tp2);
        $this->record('no_answer', $tp2);
        $this->record('spoke_call_back', $tp2, ['next_action_at' => '2026-11-02']);
        $again = $this->record('no_answer', $tp2);
        $this->assertSame(1, $again->no_answer_streak, 'a conversation resets the run');
        $this->assertFalse($again->dropped);
    }

    public function test_the_drop_limit_is_the_agency_setting_not_a_constant(): void
    {
        DB::table('rts_settings')->insert([
            'agency_id' => $this->agencyId, 'track' => 'sales', 'no_answer_drop_after' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SettingsService::forget();
        $tp = $this->tp();
        $this->record('no_answer', $tp);
        $this->assertTrue($this->record('no_answer', $tp)->dropped);
    }

    public function test_a_picked_date_is_required_and_cannot_be_in_the_past(): void
    {
        $tp = $this->tp();
        foreach ([[], ['next_action_at' => '2026-10-01'], ['next_action_at' => 'not a date']] as $in) {
            try {
                $this->record('spoke_not_interested', $tp, $in);
                $this->fail('should be refused');
            } catch (RoadToSoldException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, RtsContactOutcome::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsHold::withoutGlobalScopes()->count());

        $ok = $this->record('spoke_not_interested', $tp, ['next_action_at' => '2026-10-14']);
        $this->assertSame('2026-10-14', $ok->next_action_at->toDateString(), 'today is allowed');
    }

    public function test_sole_mandate_elsewhere_asks_for_a_date_because_no_expiry_is_known(): void
    {
        $tp = $this->tp();
        $this->expectException(RoadToSoldException::class);
        $this->record('mandated_other_agency_sole', $tp);
    }

    public function test_valuation_booked_creates_the_calendar_appointment_and_leaves_the_list(): void
    {
        $owner = $this->owner();
        $tp = $this->tp($owner->id);

        $o = $this->record('spoke_valuation_booked', $tp, ['appointment_at' => '2026-10-20 10:00']);

        $this->assertTrue($o->valuation_booked);
        $this->assertSame('calendar_appointment', $o->next_action);
        $this->assertNull($o->next_action_at);
        $event = CalendarEvent::withoutGlobalScopes()->find($o->calendar_event_id);
        $this->assertSame('property_evaluation', $event->category);
        $this->assertSame($this->agent->id, (int) $event->user_id);
        $this->assertSame($owner->id, (int) $event->contact_id);
        $this->assertStringContainsString('Marine Drive', $event->title);
        $this->assertCount(0, $this->svc()->followUpsDue($this->agent, '2027-01-01'));
    }

    public function test_valuation_booked_is_offered_on_any_conversation_but_never_on_an_attempt_and_needs_a_time(): void
    {
        $tp = $this->tp();
        $o = $this->record('spoke_call_back', $tp, ['valuation_booked' => '1', 'appointment_at' => '2026-10-21 14:30']);
        $this->assertTrue($o->valuation_booked);
        $this->assertNotNull($o->calendar_event_id);
        $this->assertSame('calendar_appointment', $o->next_action, 'the appointment is the follow-up');

        $tp2 = $this->tp();
        $att = $this->record('no_answer', $tp2, ['valuation_booked' => '1', 'appointment_at' => '2026-10-21 14:30']);
        $this->assertFalse($att->valuation_booked, 'an attempt cannot book a valuation');
        $this->assertNull($att->calendar_event_id);

        $tp3 = $this->tp();
        $this->expectException(RoadToSoldException::class);
        $this->record('spoke_call_back', $tp3, ['valuation_booked' => '1', 'next_action_at' => '2026-10-30']);
    }

    public function test_wrong_number_is_an_attempt_that_moves_the_property_to_the_tagged_list(): void
    {
        $owner = $this->owner();
        $tp = $this->tp($owner->id);
        $o = $this->record('wrong_number', $tp);

        $this->assertSame('attempt', $o->kind);
        $this->assertSame('move_to_list', $o->next_action);
        $this->assertSame('Find the right contact', $o->list_tag);
        $this->assertNull($o->next_action_at);
        $this->assertNull($owner->fresh()->last_contacted_at);
    }

    public function test_property_with_no_owner_keeps_its_history_on_the_property_only(): void
    {
        $tp = $this->tp();
        $o = $this->record('spoke_call_back', $tp, ['next_action_at' => '2026-10-30']);

        $this->assertNull($o->contact_id);
        $this->assertSame(0, ContactNote::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->svc()->historyForProperty($this->agencyId, $tp->id));
    }

    public function test_note_is_optional_but_not_unbounded(): void
    {
        $tp = $this->tp();
        $this->assertNull($this->record('no_answer', $tp)->note);

        $this->expectException(RoadToSoldException::class);
        $this->record('no_answer', $tp, ['note' => str_repeat('x', 5001)]);
    }

    // ── Holds ───────────────────────────────────────────────────────────────────────────────

    public function test_a_hold_blocks_other_agents_until_it_lapses_and_each_outcome_restarts_it(): void
    {
        $tp = $this->tp();
        $holds = app(HoldService::class);

        $this->record('no_answer', $tp);
        $this->assertTrue($holds->isHeldByOther($tp->id, $this->other));
        $this->assertFalse($holds->isHeldByOther($tp->id, $this->agent));
        $this->assertSame([$tp->id], $holds->excludedIdsFor($this->other, [$tp->id, 999999]));
        $this->assertSame([], $holds->excludedIdsFor($this->agent, [$tp->id]));
        $this->assertSame([$tp->id], $holds->ownHeldIds([$tp->id], $this->agent));

        try {
            $this->record('no_answer', $tp, [], $this->other);
            $this->fail('another agent must be refused while the hold is on');
        } catch (RoadToSoldException $e) {
            $this->assertStringContainsString('is working this property until', $e->getMessage());
        }

        Carbon::setTestNow('2026-10-30 09:00:00');
        $this->record('no_answer', $tp);   // restarts the hold from the new call
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->assertTrue($holds->isHeldByOther($tp->id, $this->other), '30 days from the second call, not the first');

        Carbon::setTestNow('2026-12-05 09:00:00');
        $this->assertFalse($holds->isHeldByOther($tp->id, $this->other), 'lapsed: free again');
        $mine = $this->record('no_answer', $tp, [], $this->other);
        $this->assertSame($this->other->id, (int) $mine->user_id);
        $this->assertSame(1, RtsHold::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->count(), 'one hold row per property is re-used');
        $this->assertSame(1, $mine->no_answer_streak, 'another agent starts their own run');
    }

    public function test_hold_queries_are_agency_bounded_with_nobody_signed_in(): void
    {
        $tp = $this->tp();
        $this->record('no_answer', $tp);
        auth()->logout();

        [$agencyB, $bAgent] = $this->makeAgency();
        $this->assertSame([], app(HoldService::class)->excludedIdsFor($bAgent, [$tp->id]), 'agency B never sees agency A holds');
    }

    // ── Do not contact ──────────────────────────────────────────────────────────────────────

    public function test_do_not_contact_records_a_call_opt_out_through_consent_and_blocks_every_agent(): void
    {
        $owner = $this->owner();
        $tp = $this->tp($owner->id);
        $this->actingAs($this->agent);
        $owner->setConsent('channel_call', 'given', 'verbal', $this->agent->id);
        $this->assertFalse((bool) $owner->fresh()->opt_out_call, 'starts as callable once consent is on record');

        $o = $this->record('do_not_contact', $tp, ['note' => 'Asked us to stop']);

        $this->assertSame('never_again', $o->next_action);
        $this->assertTrue((bool) $owner->fresh()->opt_out_call, 'derived by recomputeChannelConsent from a declined channel_call record');
        $this->assertDatabaseHas('contact_consent_records', [
            'contact_id' => $owner->id, 'consent_type' => 'channel_call', 'decision' => 'declined', 'revoked_at' => null,
        ]);
        $this->assertNotNull($owner->fresh()->last_contacted_at, 'it was a conversation');
        $this->assertSame(1, RtsPropertyFlag::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->where('flag', 'never_suggest')->count());

        $holds = app(HoldService::class);
        $this->assertSame([$tp->id], $holds->excludedIdsFor($this->agent, [$tp->id]), 'excluded even for the agent who asked');
        $this->assertSame([$tp->id], $holds->excludedIdsFor($this->other, [$tp->id]));
        $this->assertSame(0, RtsHold::withoutGlobalScopes()->count(), 'the marker, not a hold, carries it');
    }

    public function test_do_not_contact_on_a_property_with_no_owner_contact_still_excludes_it_for_everyone(): void
    {
        $tp = $this->tp();
        $this->record('do_not_contact', $tp);

        $this->assertSame(0, DB::table('contact_consent_records')->count());
        $this->assertSame([$tp->id], app(HoldService::class)->excludedIdsFor($this->other, [$tp->id]));
    }

    public function test_do_not_contact_is_recordable_even_while_another_agent_holds_the_property(): void
    {
        $tp = $this->tp();
        $this->record('no_answer', $tp);   // agent holds it

        $o = $this->record('do_not_contact', $tp, [], $this->other);

        $this->assertSame('never_suggest', RtsPropertyFlag::withoutGlobalScopes()->first()->flag);
        $this->assertSame($this->other->id, (int) $o->user_id);
    }

    public function test_do_not_contact_twice_is_idempotent_and_survives_a_restore_of_the_marker(): void
    {
        $tp = $this->tp();
        $this->record('do_not_contact', $tp);
        RtsPropertyFlag::withoutGlobalScopes()->first()->delete();

        $this->record('do_not_contact', $tp);
        $this->assertSame(1, RtsPropertyFlag::withoutGlobalScopes()->withTrashed()->count());
        $this->assertNull(RtsPropertyFlag::withoutGlobalScopes()->withTrashed()->first()->deleted_at);
    }

    // ── Follow-ups, current outcome, scope, tenancy ─────────────────────────────────────────

    public function test_follow_ups_due_are_the_current_outcome_only_and_carry_forward_when_overdue(): void
    {
        $tp = $this->tp();
        $this->record('no_answer', $tp);                                   // due 21 Oct
        $this->assertCount(0, $this->svc()->followUpsDue($this->agent, '2026-10-20'));
        $this->assertCount(1, $this->svc()->followUpsDue($this->agent, '2026-10-21'));
        $this->assertCount(1, $this->svc()->followUpsDue($this->agent, '2026-10-26'), 'overdue carries forward');
        $this->assertCount(0, $this->svc()->followUpsDue($this->other, '2026-10-26'), 'it is the agent\'s follow-up');

        $this->record('spoke_call_back', $tp, ['next_action_at' => '2026-11-10']);   // supersedes it
        $due = $this->svc()->followUpsDue($this->agent, '2026-10-26');
        $this->assertCount(0, $due);
        $this->assertCount(1, $this->svc()->followUpsDue($this->agent, '2026-11-10'));
        $this->assertSame(1, RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->where('is_current', true)->count());

        $current = $this->svc()->currentFor($this->agencyId, [$tp->id]);
        $this->assertSame('Spoke - call back', $current[$tp->id]->outcome_label);
    }

    public function test_history_keeps_the_wording_after_the_agency_renames_or_archives_the_outcome(): void
    {
        $tp = $this->tp();
        $this->record('no_answer', $tp);
        $def = $this->def('no_answer');
        $def->update(['label' => 'Not reachable']);
        $def->delete();

        $row = RtsContactOutcome::withoutGlobalScopes()->first();
        $this->assertSame('No answer', $row->outcome_label);
        $this->assertSame('No answer', $this->svc()->historyForProperty($this->agencyId, $tp->id)->first()->outcome_label);
    }

    public function test_visibility_follows_own_branch_and_agency_breadth(): void
    {
        $tp = $this->tp();
        $this->record('no_answer', $tp);
        $this->actingAs($this->other);

        $this->assertSame(0, RtsContactOutcome::query()->visibleTo($this->other)->count(), 'own: another agent\'s calls are not mine');
        $this->actingAs($this->agent);
        $this->assertSame(1, RtsContactOutcome::query()->visibleTo($this->agent)->count());
    }

    public function test_another_agencys_property_or_outcome_is_refused(): void
    {
        [$agencyB, $bUser] = $this->makeAgency();
        $foreign = $this->tp(null, $agencyB);

        $this->actingAs($this->agent);
        try {
            $this->svc()->record($this->agent, $foreign->id, $this->def('no_answer')->id);
            $this->fail('cross-agency property must be refused');
        } catch (RoadToSoldException $e) {
            $this->assertStringContainsString('could not be found', $e->getMessage());
        }

        $this->actingAs($bUser);
        $foreignDef = $this->def('no_answer', $agencyB);
        $this->actingAs($this->agent);
        $mine = $this->tp();
        $this->expectException(RoadToSoldException::class);
        $this->svc()->record($this->agent, $mine->id, $foreignDef->id);
    }

    public function test_a_switched_off_outcome_cannot_be_captured(): void
    {
        $tp = $this->tp();
        $def = $this->def('no_answer');
        $def->update(['is_active' => false]);
        $this->actingAs($this->agent);

        $this->expectException(RoadToSoldException::class);
        $this->svc()->record($this->agent, $tp->id, $def->id);
    }
}
