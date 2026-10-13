<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\AssistantAssignment;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454, 13 Oct 2026 (conductor):
 *  - gap 1: a property the agent parked with their own outcome (a future date, the wrong-number list, a booked valuation, dropped after
 *    no-answers) is not offered back as a NEW property when the 30-day hold lapses; a return date that has arrived is a follow-up instead;
 *  - gap 8: an assistant works the list of the agent they act for - one user id for the page's build, its read, the nightly build, add-to-day
 *    and roll-over; the outcome is the agent's, and who actually made the call is kept.
 */
final class ParkedAndAssistantTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        RtsSetting::create([
            'agency_id' => $this->agency->id, 'track' => 'sales',
            'selections' => [['key' => 'long_on_market', 'enabled' => true]],
        ]);
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(User $user, string $day = self::WED)
    {
        Cache::flush();

        return app(ListBuilderService::class)->buildFor($user, $day);
    }

    private function listed(): \App\Models\Prospecting\TrackedProperty
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => random_int(1, 99) . ' Park Rd'], $tp);

        return $tp;
    }

    private function outcome(int $userId, int $trackedId, array $x = []): RtsContactOutcome
    {
        return RtsContactOutcome::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'user_id' => $userId, 'tracked_property_id' => $trackedId,
            'outcome_key' => 'spoke_call_back', 'outcome_label' => 'Spoke - call back', 'kind' => 'conversation',
            'next_action' => Def::ACTION_PICKED_DATE, 'next_action_at' => '2026-12-14', 'is_current' => true, 'dropped' => false,
            'occurred_at' => now()->subDay(),
        ], $x));
    }

    private function offered($list): array
    {
        return $list->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    // ── gap 1: parked properties ─────────────────────────────────────────

    public function test_a_property_with_a_return_date_in_the_future_is_not_offered_back_as_new_to_the_agent_who_parked_it(): void
    {
        $parked = $this->listed();
        $free = $this->listed();
        $this->outcome($this->agent->id, $parked->id);   // call back on 14 Dec; no hold row = the hold has lapsed

        $this->assertSame([$free->id], $this->offered($this->build($this->agent)));
    }

    public function test_the_other_agent_is_not_held_back_by_it_once_the_hold_lapsed(): void
    {
        $parked = $this->listed();
        $this->outcome($this->agent->id, $parked->id);

        $this->assertSame([$parked->id], $this->offered($this->build($this->mate)), 'spec: a hold lapses and the property is free again');
    }

    public function test_wrong_number_valuation_booked_and_dropped_are_not_offered_back_either(): void
    {
        $wrong = $this->listed();
        $valuation = $this->listed();
        $dropped = $this->listed();
        $free = $this->listed();
        $this->outcome($this->agent->id, $wrong->id, ['next_action' => Def::ACTION_MOVE_TO_LIST, 'next_action_at' => null, 'list_tag' => 'Find the right contact', 'kind' => 'attempt']);
        $this->outcome($this->agent->id, $valuation->id, ['next_action' => Def::ACTION_APPOINTMENT, 'next_action_at' => null, 'valuation_booked' => true]);
        $this->outcome($this->agent->id, $dropped->id, ['next_action' => 'dropped', 'next_action_at' => null, 'dropped' => true, 'kind' => 'attempt', 'no_answer_streak' => 3]);

        $this->assertSame([$free->id], $this->offered($this->build($this->agent)));
    }

    public function test_a_return_date_that_has_arrived_comes_back_as_a_follow_up_not_as_a_new_property(): void
    {
        $due = $this->listed();
        $this->outcome($this->agent->id, $due->id, ['next_action_at' => '2026-10-14']);   // due today

        $list = $this->build($this->agent);
        $this->assertSame([$due->id], $this->offered($list));
        $this->assertSame(RtsListItem::ORIGIN_FOLLOW_UP, $list->first()->origin);
    }

    public function test_a_follow_up_due_on_the_day_being_built_is_not_mistaken_for_a_parked_property(): void
    {
        $due = $this->listed();
        $this->outcome($this->agent->id, $due->id, ['next_action_at' => '2026-10-15']);   // due TOMORROW

        $today = $this->build($this->agent);   // today: parked, not offered
        $this->assertNotContains($due->id, $this->offered($today));

        $tomorrow = $this->build($this->agent, '2026-10-15');   // built for tomorrow: it is due then
        $this->assertContains($due->id, $this->offered($tomorrow));
        $this->assertSame(RtsListItem::ORIGIN_FOLLOW_UP, $tomorrow->firstWhere('tracked_property_id', $due->id)->origin);
    }

    public function test_only_the_latest_outcome_counts(): void
    {
        $tp = $this->listed();
        $this->outcome($this->agent->id, $tp->id, ['is_current' => false]);   // an old parked outcome, superseded

        $this->assertSame([$tp->id], $this->offered($this->build($this->agent)));
    }

    // ── gap 8: assistants ────────────────────────────────────────────────

    private function assistantFor(User $agent): User
    {
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);
        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        return $assistant->fresh();
    }

    public function test_an_assistant_builds_and_reads_the_list_of_the_agent_they_act_for(): void
    {
        $assistant = $this->assistantFor($this->agent);
        for ($i = 0; $i < 3; $i++) { $this->listed(); }

        $built = $this->build($assistant);
        $this->assertCount(3, $built);
        $this->assertSame([(int) $this->agent->id], $built->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->all(), 'the rows belong to the agent');
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('user_id', $assistant->id)->count(), 'nothing under the assistant\'s own id');

        // the same rows read three ways: the assistant, the agent, the page's id-based read
        $ids = $built->pluck('id')->sort()->values()->all();
        $this->assertSame($ids, app(ListBuilderService::class)->listFor($assistant, self::WED)->pluck('id')->sort()->values()->all());
        $this->assertSame($ids, RtsListItem::query()->forDay($assistant, self::WED)->pluck('id')->sort()->values()->all());
        $this->assertSame($ids, RtsListItem::query()->forDay((int) $this->agent->id, self::WED)->pluck('id')->sort()->values()->all());

        // and the agent building afterwards adds nothing: ONE list
        $again = $this->build($this->agent);
        $this->assertSame($ids, $again->pluck('id')->sort()->values()->all());
    }

    public function test_the_nightly_build_for_the_agent_is_the_list_the_assistant_opens(): void
    {
        $assistant = $this->assistantFor($this->agent);
        for ($i = 0; $i < 3; $i++) { $this->listed(); }

        $this->artisan('rts:build-lists', ['--date' => self::WED, '--agency' => $this->agency->id])->assertExitCode(0);

        $this->assertSame(
            RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->pluck('id')->sort()->values()->all(),
            RtsListItem::query()->forDay($assistant, self::WED)->pluck('id')->sort()->values()->all()
        );
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('user_id', $assistant->id)->count());
    }

    public function test_add_to_day_and_close_past_days_by_the_assistant_act_on_the_agents_list(): void
    {
        $assistant = $this->assistantFor($this->agent);
        for ($i = 0; $i < 8; $i++) { $this->listed(); }
        $this->build($assistant);

        $added = app(ListBuilderService::class)->addToDay($assistant, 'long_on_market', 2, self::WED);
        $this->assertCount(2, $added);
        $this->assertSame([(int) $this->agent->id], $added->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(RtsListItem::ORIGIN_ADDED, $added->first()->origin);

        $res = app(ListBuilderService::class)->closePastDays($assistant, '2026-10-15');
        $this->assertSame(7, $res['missed'], 'the agent\'s open items of the day before are closed (5 target + 2 added)');
    }

    public function test_an_outcome_recorded_by_the_assistant_is_the_agents_and_keeps_who_made_the_call(): void
    {
        $assistant = $this->assistantFor($this->agent);
        $owner = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Pieter', 'last_name' => 'van der Merwe', 'phone' => '0825512345',
        ]);
        $tp = $this->tp(['owner_contact_id' => $owner->id, 'street_name' => 'Marine Drive', 'street_number' => '14']);
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '14 Marine Drive'], $tp);
        $list = $this->build($assistant);
        $item = $list->firstWhere('tracked_property_id', $tp->id);
        $this->assertNotNull($item);

        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $def = Def::query()->where('agency_id', $this->agency->id)->where('key', 'no_answer')->firstOrFail();

        $outcome = app(OutcomeService::class)->record($assistant, $tp->id, $def->id, ['list_item_id' => $item->id]);

        $this->assertSame((int) $this->agent->id, (int) $outcome->user_id, 'the outcome is the agent\'s (target, report)');
        $this->assertSame((int) $assistant->id, (int) $outcome->recorded_by_user_id, 'who actually made the call');
        $this->assertSame((int) $this->agent->id, (int) RtsHold::query()->where('tracked_property_id', $tp->id)->value('user_id'), 'the agent holds it');
        $this->assertSame('worked', $item->fresh()->status, 'the agent\'s list row is worked');
        $this->assertStringContainsString('call made by ' . $assistant->name, (string) ContactNote::query()->where('contact_id', $owner->id)->value('body'));

        // an agent recording it themselves leaves recorded_by empty
        $tp2 = $this->tp(['street_name' => 'Beach Rd', 'street_number' => '2']);
        $self = app(OutcomeService::class)->record($this->agent, $tp2->id, $def->id);
        $this->assertNull($self->recorded_by_user_id);
    }

    public function test_an_agent_and_other_agents_are_unaffected(): void
    {
        $tp = $this->listed();
        $this->assertSame([$tp->id], $this->offered($this->build($this->agent)));
        $this->assertSame([$tp->id], $this->offered($this->build($this->mate)), 'no hold, no parking: an ordinary second agent still gets it');
        $this->assertSame($this->agent, app(ListBuilderService::class)->listOwner($this->agent));
    }
}
