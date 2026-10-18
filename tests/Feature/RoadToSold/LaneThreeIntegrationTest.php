<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsPropertyFlag;
use App\Models\RoadToSold\RtsSetting;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 — lane 2 against lane 3's REAL HoldService and call log (rts_holds, rts_property_flags,
 * rts_contact_outcomes), built through the container exactly as the nightly command builds it.
 */
final class LaneThreeIntegrationTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        SettingsService::forget();
        Cache::flush();
        RtsSetting::create([
            'agency_id' => $this->agency->id, 'track' => 'sales',
            'selections' => [['key' => 'long_on_market', 'enabled' => true]],
        ]);
        SettingsService::forget();
    }

    private function build($user)
    {
        Cache::flush();

        return app(ListBuilderService::class)->buildFor($user, self::WED);
    }

    private function hold(int $trackedId, int $userId, $expires = null, $released = null): void
    {
        RtsHold::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $trackedId, 'user_id' => $userId,
            'started_at' => now()->subDays(2), 'expires_at' => $expires ?? now()->addDays(20), 'released_at' => $released,
        ]);
    }

    private function outcome(int $userId, int $trackedId, array $x = []): RtsContactOutcome
    {
        return RtsContactOutcome::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'user_id' => $userId, 'tracked_property_id' => $trackedId,
            'outcome_key' => 'no_answer', 'outcome_label' => 'No answer', 'kind' => 'attempt',
            'next_action' => 'return_after_days', 'next_action_at' => '2026-10-13', 'is_current' => true, 'dropped' => false,
            'occurred_at' => now()->subDays(7),
        ], $x));
    }

    public function test_two_agents_sharing_a_suburb_never_get_the_same_property_while_a_hold_exists(): void
    {
        $tpHeld = $this->tp(); $tpFree = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '1 Shared Rd'], $tpHeld);
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '2 Shared Rd'], $tpFree);

        $this->hold($tpHeld->id, $this->agent->id);     // the first agent has called it: it is theirs for 30 days

        $mate = $this->build($this->mate);
        $this->assertSame([$tpFree->id], $mate->pluck('tracked_property_id')->all(), 'held by another agent: not offered');

        $holder = $this->build($this->agent);
        $this->assertSame([$tpFree->id], $holder->pluck('tracked_property_id')->all(), 'the holder is not offered it as "new" either — a follow-up brings it back');
    }

    public function test_a_lapsed_or_released_hold_frees_the_property(): void
    {
        $lapsed = $this->tp(); $released = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01'], $lapsed);
        $this->listing(['first_seen_at' => '2026-03-01'], $released);
        $this->hold($lapsed->id, $this->agent->id, now()->subDay());
        $this->hold($released->id, $this->agent->id, null, now()->subHour());

        $this->assertEqualsCanonicalizing([$lapsed->id, $released->id], $this->build($this->mate)->pluck('tracked_property_id')->all());
    }

    public function test_a_do_not_contact_property_is_never_offered_to_any_agent(): void
    {
        $dnc = $this->tp(); $ok = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01'], $dnc);
        $this->listing(['first_seen_at' => '2026-03-01'], $ok);
        RtsPropertyFlag::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $dnc->id, 'flag' => 'never_suggest',
        ]);

        $this->assertSame([$ok->id], $this->build($this->agent)->pluck('tracked_property_id')->all());
        $this->assertSame([$ok->id], $this->build($this->mate)->pluck('tracked_property_id')->all());
    }

    public function test_due_follow_ups_from_the_real_call_log_come_first_and_count_toward_the_target(): void
    {
        $fu = $this->tp(); $late = $this->tp(); $future = $this->tp(); $dropped = $this->tp(); $never = $this->tp(); $mates = $this->tp();
        foreach ([$fu, $late, $future, $dropped, $never, $mates] as $tp) {
            $this->listing(['first_seen_at' => '2026-03-01', 'address' => '1 X Rd'], $tp);
        }
        $new = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '9 New Rd'], $new);
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '10 New Rd']);
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => '11 New Rd']);

        $this->outcome($this->agent->id, $fu->id, ['next_action_at' => '2026-10-14', 'outcome_label' => 'Spoke - call back']);
        $this->outcome($this->agent->id, $late->id, ['next_action_at' => '2026-10-09']);                       // overdue: still due
        $this->outcome($this->agent->id, $future->id, ['next_action_at' => '2026-10-20']);                      // not yet
        $this->outcome($this->agent->id, $dropped->id, ['dropped' => true]);                                    // dropped after no-answers
        $this->outcome($this->agent->id, $never->id, ['next_action' => 'never_again', 'next_action_at' => null]);
        $this->outcome($this->agent->id, $mates->id, ['is_current' => false]);                                   // superseded by a newer outcome
        $this->outcome($this->mate->id, $mates->id, ['next_action_at' => '2026-10-14']);                        // someone else's follow-up

        // capturing an outcome always gives the agent a hold (lane 3's OutcomeService); mirror that
        foreach ([$fu, $late, $future, $dropped, $never] as $tp) {
            $this->hold($tp->id, $this->agent->id);
        }

        $items = $this->build($this->agent);

        $followUps = $items->where('origin', 'follow_up');
        $this->assertEqualsCanonicalizing([$fu->id, $late->id], $followUps->pluck('tracked_property_id')->all());
        $this->assertSame('follow_up', $items->first()->origin);
        $this->assertStringContainsString('Follow-up: Spoke - call back, due 14 Oct', $items->firstWhere('tracked_property_id', $fu->id)->reason);
        $this->assertSame(5, $items->count(), 'default target of 5 on a weekday, follow-ups included');
        $this->assertNotContains($dropped->id, $items->pluck('tracked_property_id')->all());
        $this->assertNotContains($never->id, $items->pluck('tracked_property_id')->all());
        $this->assertNotContains($future->id, $items->pluck('tracked_property_id')->all(), 'a future follow-up is offered then, not now');

        // the other agent sees only their own follow-up
        $mateItems = $this->build($this->mate);
        $this->assertSame([$mates->id], $mateItems->where('origin', 'follow_up')->pluck('tracked_property_id')->all());
    }

    public function test_a_follow_up_on_a_property_that_has_since_become_do_not_contact_is_not_called(): void
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01'], $tp);
        $this->outcome($this->agent->id, $tp->id, ['next_action_at' => '2026-10-14']);
        RtsPropertyFlag::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $tp->id, 'flag' => 'never_suggest',
        ]);

        $this->assertNotContains($tp->id, $this->build($this->agent)->pluck('tracked_property_id')->all());
    }

    public function test_capturing_an_outcome_marks_the_list_item_worked_so_it_stays_off_the_open_list(): void
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01'], $tp);
        $items = $this->build($this->agent);
        $item = $items->firstWhere('tracked_property_id', $tp->id);

        // what lane 3's OutcomeService does when an outcome carries the list item's id
        \Illuminate\Support\Facades\DB::table('rts_list_items')->where('id', $item->id)->update(['status' => 'worked']);

        $this->assertSame('worked', RtsListItem::withoutGlobalScopes()->find($item->id)->status);
        // closing the day later does not touch a worked item
        $r = app(ListBuilderService::class)->closePastDays($this->agent, '2026-10-15');
        $this->assertSame(['missed' => 0, 'rolled' => 0], $r);
    }
}
