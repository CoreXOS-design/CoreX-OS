<?php

namespace Tests\Feature\Features;

use App\Events\AgencyFeatureToggled;
use App\Events\FeatureRollout\FeatureWaveReleased;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Leave\PublicHoliday;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AT-478 — gradual feature rollout engine. Spec: .ai/specs/agency-feature-rollout.md §2, §3, §8.
 *
 * Dates: Wed 4 Nov 2026 is the start day in most tests. With weekdays only and "every 2 days",
 * wave 1 = Fri 6 Nov, wave 2 = Tue 10 Nov (Sat/Sun skipped).
 */
final class FeatureRolloutServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests are about timing, lanes and pop-ups; readiness (§12.1) would hold their example features
        // back for want of a logo/address and has its own tests (FeatureRolloutReadinessHistoryTest).
        config(['feature-rollout.readiness' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): FeatureRolloutService
    {
        return app(FeatureRolloutService::class);
    }

    private function agency(string $type = 'both'): Agency
    {
        $agency = Agency::create(['name' => 'Rollout Test ' . uniqid(), 'slug' => 'rollout-' . uniqid()]);
        app(AgencyTypeFeatureSeeder::class)->seedNewAgency($agency, $type);

        return $agency->fresh();
    }

    private function on(Agency $agency, string $key): bool
    {
        app(AgencyFeatureService::class)->forget($agency->id);

        return app(AgencyFeatureService::class)->enabled($key, $agency);
    }

    private function wave(string $title, array $keys, string $track = 'general', int $position = 10): FeatureRolloutWave
    {
        return FeatureRolloutWave::create(['title' => $title, 'track' => $track, 'position' => $position, 'feature_keys' => $keys]);
    }

    private function pace(int $days = 2, bool $weekdays = true, bool $holidays = true): void
    {
        DevSetting::set('feature_rollout.interval_days', (string) $days);
        DevSetting::set('feature_rollout.weekdays_only', $weekdays ? '1' : '0');
        DevSetting::set('feature_rollout.skip_holidays', $holidays ? '1' : '0');
        DevSetting::set('feature_rollout.holiday_country', 'ZA');
        DevSetting::set('feature_rollout.release_time', '07:00');
    }

    private function wavesOf(AgencyFeatureRollout $plan)
    {
        return $this->service()->wavesOf($plan)->values();
    }

    public function test_start_holds_waved_features_and_leaves_core_and_unwaved_features_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Documents', ['docuperfect'], 'general', 10);
        $this->wave('Selling', ['presentations'], 'sales', 20);
        $agency = $this->agency();

        $plan = $this->service()->start($agency);

        $this->assertSame('active', $plan->status);
        $this->assertFalse($this->on($agency, 'docuperfect'));
        $this->assertFalse($this->on($agency, 'presentations'));
        $this->assertTrue($this->on($agency, 'properties'), 'core stays on');
        $this->assertTrue($this->on($agency, 'compliance'), 'a feature in no wave is left on');

        $waves = $this->wavesOf($plan);
        $this->assertSame(['2026-11-06', '2026-11-10'], $waves->map(fn ($w) => $w->scheduled_for->toDateString())->all(),
            'every 2 release days, weekend skipped');
    }

    public function test_weekends_count_when_weekdays_only_is_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace(2, false);
        $this->wave('One', ['docuperfect'], 'general', 10);
        $this->wave('Two', ['presentations'], 'general', 20);

        $plan = $this->service()->start($this->agency());

        $this->assertSame(['2026-11-06', '2026-11-08'], $this->wavesOf($plan)->map(fn ($w) => $w->scheduled_for->toDateString())->all());
    }

    public function test_a_public_holiday_is_skipped(): void
    {
        // Wed 16 Dec 2026 is the Day of Reconciliation. Start Mon 14 Dec, every 2 days → would be Wed 16 → Thu 17.
        Carbon::setTestNow(Carbon::parse('2026-12-14 09:00'));
        $this->pace(2);
        $this->wave('One', ['docuperfect'], 'general', 10);

        $plan = $this->service()->start($this->agency());

        $this->assertTrue(PublicHoliday::where('country_code', 'ZA')->whereDate('holiday_date', '2026-12-16')->exists(), 'SA holidays generated on demand');
        $this->assertSame('2026-12-17', $this->wavesOf($plan)->first()->scheduled_for->toDateString());
    }

    public function test_rentals_agency_gets_rentals_first_and_never_sales_waves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Documents', ['docuperfect'], 'general', 10);
        $this->wave('Selling', ['presentations'], 'sales', 20);
        $this->wave('Rentals', ['rentals'], 'rentals', 30);
        $agency = $this->agency('rentals');

        $plan = $this->service()->start($agency);
        $waves = $this->wavesOf($plan);

        $this->assertSame(['Rentals', 'Documents'], $waves->pluck('title')->all());
        $this->assertFalse($this->on($agency, 'presentations'), 'sales stays off for a rentals agency (type), not held');
        $this->assertSame(0, AgencyRolloutItem::where('feature_key', 'presentations')->count());
    }

    public function test_sales_and_rentals_side_by_side_releases_one_wave_per_lane(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Documents', ['docuperfect'], 'general', 10);
        $this->wave('Rentals', ['rentals'], 'rentals', 20);
        $this->wave('Selling', ['presentations'], 'sales', 30);
        $agency = $this->agency('both');

        $plan = $this->service()->start($agency, ['side_by_side' => true]);
        $this->assertTrue($plan->side_by_side);

        Carbon::setTestNow(Carbon::parse('2026-11-06 07:05'));
        $this->assertSame(2, $this->service()->releaseDue());
        $this->assertTrue($this->on($agency, 'docuperfect'));
        $this->assertTrue($this->on($agency, 'rentals'));
        $this->assertFalse($this->on($agency, 'presentations'), 'second sales-lane wave is not due yet');
    }

    public function test_without_side_by_side_a_both_agency_follows_the_one_default_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Documents', ['docuperfect'], 'general', 10);
        $this->wave('Rentals', ['rentals'], 'rentals', 20);

        $plan = $this->service()->start($this->agency('both'));

        $this->assertSame([1, 1], $this->wavesOf($plan)->pluck('lane')->all());
        $this->assertSame(['Documents', 'Rentals'], $this->wavesOf($plan)->pluck('title')->all());
    }

    public function test_release_waits_for_the_release_time_is_idempotent_and_skips_weekends(): void
    {
        Event::fake([FeatureWaveReleased::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Documents', ['docuperfect'], 'general', 10);
        $this->wave('Selling', ['presentations'], 'general', 20);
        $agency = $this->agency();
        $plan = $this->service()->start($agency);

        Carbon::setTestNow(Carbon::parse('2026-11-06 06:59'));
        $this->assertSame(0, $this->service()->releaseDue(), 'before the release time');

        Carbon::setTestNow(Carbon::parse('2026-11-06 07:00'));
        $this->assertSame(1, $this->service()->releaseDue());
        $this->assertSame(0, $this->service()->releaseDue(), 'same window again changes nothing');
        $this->assertTrue($this->on($agency, 'docuperfect'));
        Event::assertDispatched(FeatureWaveReleased::class, fn ($e) => $e->releasedFeatureKeys === ['docuperfect']);

        Carbon::setTestNow(Carbon::parse('2026-11-07 10:00')); // Saturday
        $this->assertSame(0, $this->service()->releaseDue());

        Carbon::setTestNow(Carbon::parse('2026-11-10 07:30'));
        $this->assertSame(1, $this->service()->releaseDue());
        $this->assertTrue($this->on($agency, 'presentations'));
        $this->assertSame('completed', $plan->fresh()->status);
    }

    public function test_a_missed_day_catches_up_one_wave_per_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace(1);
        $this->wave('One', ['docuperfect'], 'general', 10);
        $this->wave('Two', ['presentations'], 'general', 20);
        $plan = $this->service()->start($this->agency());

        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00')); // both overdue
        $this->assertSame(1, $this->service()->releaseDue());
        $this->assertSame(1, $this->wavesOf($plan)->whereNotNull('released_at')->count());
    }

    public function test_outward_facing_features_wait_for_the_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('Reach out', ['outreach', 'docuperfect'], 'general', 10);
        $agency = $this->agency();
        $this->service()->start($agency);

        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00'));
        $this->service()->releaseDue();

        $this->assertTrue($this->on($agency, 'docuperfect'));
        $this->assertFalse($this->on($agency, 'outreach'), 'outreach is never switched on by itself');
        $this->assertSame('awaiting_admin', AgencyRolloutItem::where('feature_key', 'outreach')->value('state'));
    }

    public function test_admin_switching_a_held_feature_on_counts_as_released_early_but_rollout_releases_do_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('One', ['docuperfect'], 'general', 10);
        $this->wave('Two', ['presentations'], 'general', 20);
        $agency = $this->agency();
        $this->service()->start($agency);

        event(new AgencyFeatureToggled((int) $agency->id, 'presentations', true, null));
        $this->assertSame('released_early', AgencyRolloutItem::where('feature_key', 'presentations')->value('state'));

        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00'));
        $this->service()->releaseDue();
        $this->assertSame('released', AgencyRolloutItem::where('feature_key', 'docuperfect')->value('state'));
    }

    public function test_pause_stops_releases_and_resume_redates_from_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('One', ['docuperfect'], 'general', 10);
        $plan = $this->service()->start($this->agency());

        $this->service()->pause($plan);
        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00'));
        $this->assertSame(0, $this->service()->releaseDue());

        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00')); // Wednesday
        $this->service()->resume($plan->fresh());
        $this->assertSame('2026-11-13', $this->wavesOf($plan)->first()->scheduled_for->toDateString());
    }

    public function test_release_next_now_and_turn_everything_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('One', ['docuperfect'], 'general', 10);
        $this->wave('Two', ['presentations', 'outreach'], 'general', 20);
        $agency = $this->agency();
        $plan = $this->service()->start($agency);

        $this->assertSame(1, $this->service()->releaseNext($plan, null));
        $this->assertTrue($this->on($agency, 'docuperfect'));
        $this->assertSame('2026-11-06', $this->wavesOf($plan)->whereNull('released_at')->first()->scheduled_for->toDateString(),
            'the rest is re-dated from today');

        $this->service()->finish($plan->fresh(), null);
        $this->assertTrue($this->on($agency, 'presentations'));
        $this->assertTrue($this->on($agency, 'outreach'), 'turning everything on is an explicit admin choice');
        $this->assertSame('completed', $plan->fresh()->status);
    }

    public function test_editing_the_defaults_never_changes_a_running_plan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $default = $this->wave('One', ['docuperfect'], 'general', 10);
        $plan = $this->service()->start($this->agency());

        $default->update(['title' => 'Renamed', 'feature_keys' => ['presentations']]);
        $this->pace(5);

        $wave = $this->wavesOf($plan)->first();
        $this->assertSame('One', $wave->title);
        $this->assertSame(['docuperfect'], AgencyRolloutItem::where('wave_id', $wave->id)->pluck('feature_key')->all());
        $this->assertSame(2, $plan->fresh()->interval_days);
    }

    public function test_an_agency_has_one_plan_and_its_rows_are_scoped_to_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        $this->pace();
        $this->wave('One', ['docuperfect'], 'general', 10);
        $a = $this->agency();
        $b = $this->agency();
        $planA = $this->service()->start($a);
        $this->assertSame($planA->id, $this->service()->start($a)->id, 'starting twice returns the same plan');
        $this->service()->start($b);

        $this->assertSame(2, AgencyFeatureRollout::queryWithoutAgencyScope()->count());
        $this->assertSame([(int) $a->id], AgencyRolloutWave::queryWithoutAgencyScope()->where('rollout_id', $planA->id)->pluck('agency_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertTrue($this->on($b, 'compliance'));
        $this->assertTrue($this->on($a, 'compliance'));
    }
}
