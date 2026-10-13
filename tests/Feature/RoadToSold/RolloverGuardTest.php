<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsPropertyFlag;
use App\Models\RoadToSold\RtsSetting;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 - roll-over passes the SAME never-suggest gate as every other path, with the real hold, do-not-contact
 * and parked rules (lane 3's HoldService / call log), and the list day passed in: a missed property that another
 * agent now holds, that went do-not-contact, that the agent parked past tomorrow, or that became another agent's
 * CoreX stock is closed as missed - never carried onto the next day's list.
 */
final class RolloverGuardTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';
    private const THU = '2026-10-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        RtsSetting::create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'missed_items_mode' => RtsSetting::MISSED_ROLL,
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

    private function listed(): \App\Models\Prospecting\TrackedProperty
    {
        $tp = $this->tp();
        $this->listing(['first_seen_at' => '2026-03-01', 'address' => random_int(1, 99) . ' Roll Rd'], $tp);

        return $tp;
    }

    private function item(int $trackedId): RtsListItem
    {
        return RtsListItem::query()->forDay($this->agent, self::WED)->where('tracked_property_id', $trackedId)->firstOrFail();
    }

    public function test_only_what_the_next_day_may_have_rolls_over_the_rest_is_closed_as_missed(): void
    {
        $fine = $this->listed(); $heldByMate = $this->listed(); $dnc = $this->listed(); $parked = $this->listed(); $mateStock = $this->listed();
        $b = app(ListBuilderService::class);
        $this->assertCount(5, $b->buildFor($this->agent, self::WED), 'all five are on the list when it is built');

        // then, before the day is closed:
        RtsHold::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $heldByMate->id, 'user_id' => $this->mate->id,
            'started_at' => now(), 'expires_at' => now()->addDays(20),
        ]);
        RtsPropertyFlag::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $dnc->id, 'flag' => 'never_suggest',
        ]);
        RtsContactOutcome::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'user_id' => $this->agent->id, 'tracked_property_id' => $parked->id,
            'outcome_key' => 'spoke_call_back', 'outcome_label' => 'Spoke - call back', 'kind' => 'conversation',
            'next_action' => Def::ACTION_PICKED_DATE, 'next_action_at' => '2026-12-01', 'is_current' => true, 'dropped' => false, 'occurred_at' => now(),
        ]);
        $prop = $this->staleOther();
        \Illuminate\Support\Facades\DB::table('prospecting_listings')->where('tracked_property_id', $mateStock->id)->update(['matched_property_id' => $prop]);

        $r = $b->closePastDays($this->agent, self::THU);

        $this->assertSame(['missed' => 4, 'rolled' => 1], $r);
        $this->assertSame('rolled', $this->item($fine->id)->status);
        foreach ([$heldByMate, $dnc, $parked, $mateStock] as $blocked) {
            $this->assertSame('missed', $this->item($blocked->id)->status, "tp {$blocked->id}");
        }
        $next = RtsListItem::query()->forDay($this->agent, self::THU)->get();
        $this->assertSame([$fine->id], $next->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['rollover'], $next->pluck('origin')->all());
        // and the build for that day does not bring the blocked ones back through the selections either
        Cache::flush();
        $this->assertNotContains($dnc->id, $b->buildFor($this->agent, self::THU)->pluck('tracked_property_id')->all());
    }

    public function test_a_missed_property_whose_return_date_is_the_next_day_is_not_treated_as_parked(): void
    {
        $due = $this->listed();
        $b = app(ListBuilderService::class);
        $b->buildFor($this->agent, self::WED);
        RtsContactOutcome::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'user_id' => $this->agent->id, 'tracked_property_id' => $due->id,
            'outcome_key' => 'spoke_call_back', 'outcome_label' => 'Spoke - call back', 'kind' => 'conversation',
            'next_action' => Def::ACTION_PICKED_DATE, 'next_action_at' => self::THU, 'is_current' => true, 'dropped' => false, 'occurred_at' => now(),
        ]);

        $r = $b->closePastDays($this->agent, self::THU);

        $this->assertSame(['missed' => 0, 'rolled' => 1], $r, 'the list day is passed to the guard: due that day is not parked');
        $thu = RtsListItem::query()->forDay($this->agent, self::THU)->where('tracked_property_id', $due->id)->get();
        $this->assertCount(1, $thu, 'on the next list exactly once (as a rollover or the follow-up)');
    }

    private function staleOther(): int
    {
        return $this->property($this->mate->id, ['status' => 'withdrawn', 'updated_at' => now()->subDays(40), 'created_at' => now()->subDays(80)]);
    }
}
