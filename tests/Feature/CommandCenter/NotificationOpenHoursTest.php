<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-weekday open-hours schedule: pure window logic (incl. midnight wrap),
 * timezone evaluation, persistence round-trip, and the dispatch-time gate that
 * suppresses ALL channels (in-app, email, push) outside the window.
 */
final class NotificationOpenHoursTest extends TestCase
{
    use RefreshDatabase;

    // ── Pure window logic (windowsAllowAt) ───────────────────────────────

    public function test_same_day_window_is_inclusive_of_start_exclusive_of_end(): void
    {
        $svc = new NotificationPreferenceService();
        $at  = Carbon::parse('2026-06-17 10:00', 'Africa/Johannesburg'); // a Wednesday

        $this->assertTrue($svc->windowsAllowAt($this->windowsFor([$at->isoWeekday() => ['08:00', '17:00']]), $at));
        // before start
        $this->assertFalse($svc->windowsAllowAt($this->windowsFor([$at->isoWeekday() => ['11:00', '17:00']]), $at));
        // exactly at end is excluded
        $end = $at->copy()->setTime(17, 0);
        $this->assertFalse($svc->windowsAllowAt($this->windowsFor([$end->isoWeekday() => ['08:00', '17:00']]), $end));
    }

    public function test_disabled_day_is_fully_quiet(): void
    {
        $svc = new NotificationPreferenceService();
        $at  = Carbon::parse('2026-06-17 10:00', 'Africa/Johannesburg');

        $windows = $this->windowsFor([$at->isoWeekday() => ['08:00', '17:00']]);
        $windows[(string) $at->isoWeekday()]['enabled'] = false;

        $this->assertFalse($svc->windowsAllowAt($windows, $at));
    }

    public function test_full_day_window_when_start_equals_end(): void
    {
        $svc = new NotificationPreferenceService();
        $at  = Carbon::parse('2026-06-17 03:33', 'Africa/Johannesburg');

        $this->assertTrue($svc->windowsAllowAt($this->windowsFor([$at->isoWeekday() => ['00:00', '00:00']]), $at));
    }

    public function test_midnight_wrap_evening_tail_belongs_to_opening_day(): void
    {
        $svc = new NotificationPreferenceService();
        $at  = Carbon::parse('2026-06-17 23:00', 'Africa/Johannesburg');

        // 22:00 → 06:00 window opens on this weekday; 23:00 is inside the evening tail.
        $this->assertTrue($svc->windowsAllowAt($this->windowsFor([$at->isoWeekday() => ['22:00', '06:00']]), $at));
    }

    public function test_midnight_wrap_morning_tail_belongs_to_previous_day(): void
    {
        $svc = new NotificationPreferenceService();
        $at  = Carbon::parse('2026-06-17 03:00', 'Africa/Johannesburg');

        $iso     = (int) $at->isoWeekday();
        $prevIso = $iso === 1 ? 7 : $iso - 1;

        // Today's window is OFF; the previous day owns a 22:00→06:00 wrap.
        $windows = $this->windowsFor([$prevIso => ['22:00', '06:00']]);
        $this->assertTrue($svc->windowsAllowAt($windows, $at), '03:00 should fall in the previous day\'s wrap tail');

        // After the morning tail ends (06:00), nothing is open.
        $after = $at->copy()->setTime(7, 0);
        $this->assertFalse($svc->windowsAllowAt($windows, $after));

        // If the previous day is not a wrap window, the morning tail does not exist.
        $sameDay = $this->windowsFor([$prevIso => ['08:00', '17:00']]);
        $this->assertFalse($svc->windowsAllowAt($sameDay, $at));
    }

    // ── Timezone ─────────────────────────────────────────────────────────

    public function test_timezone_fallback_is_app_timezone(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        $this->assertSame(config('app.timezone'), $svc->resolveTimezone($user));
    }

    public function test_now_is_evaluated_in_the_resolved_timezone(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        // Window 08:00–17:00 local (Africa/Johannesburg, UTC+2) on a Wednesday.
        $wed = Carbon::parse('2026-06-17 00:00', 'Africa/Johannesburg');
        $this->setOpenHours($user, true, [$wed->isoWeekday() => ['08:00', '17:00']]);

        // 07:00 UTC == 09:00 SAST → inside the window.
        $this->assertTrue($svc->withinOpenHours($user, Carbon::parse('2026-06-17 07:00', 'UTC')));
        // 16:00 UTC == 18:00 SAST → outside the window.
        $this->assertFalse($svc->withinOpenHours($user, Carbon::parse('2026-06-17 16:00', 'UTC')));
    }

    public function test_disabled_open_hours_always_allows(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        $this->setOpenHours($user, false, []);
        $this->assertTrue($svc->withinOpenHours($user, Carbon::parse('2026-06-17 03:00', 'Africa/Johannesburg')));
    }

    // ── Persistence round-trip ───────────────────────────────────────────

    public function test_day_windows_persist_and_default_missing_days_to_disabled(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        $svc->applyUpdates($user, [
            'open_hours' => [
                'enabled'     => true,
                'day_windows' => [
                    '1' => ['enabled' => true, 'start' => '09:00', 'end' => '15:00'],
                    // days 2..7 omitted → must default to disabled
                ],
            ],
        ]);

        $snap = $svc->snapshot($user);
        $windows = $snap['open_hours']['day_windows'];

        $this->assertCount(7, $windows);
        $this->assertTrue($windows['1']['enabled']);
        $this->assertSame('09:00', $windows['1']['start']);
        $this->assertSame('15:00', $windows['1']['end']);
        foreach (['2', '3', '4', '5', '6', '7'] as $k) {
            $this->assertFalse($windows[$k]['enabled'], "day {$k} should default to disabled");
        }

        // Legacy approximation is still present for older clients.
        $this->assertSame([1], $snap['open_hours']['days']);
        $this->assertSame('09:00', $snap['open_hours']['start']);
        $this->assertSame('15:00', $snap['open_hours']['end']);
    }

    public function test_legacy_single_window_payload_is_expanded_to_day_windows(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        // Older client sends the single-window shape with a days[] list.
        $svc->applyUpdates($user, [
            'open_hours' => [
                'enabled' => true,
                'start'   => '08:00',
                'end'     => '18:00',
                'days'    => [1, 2, 3],
            ],
        ]);

        $windows = $svc->snapshot($user)['open_hours']['day_windows'];
        foreach ([1, 2, 3] as $iso) {
            $this->assertTrue($windows[(string) $iso]['enabled']);
            $this->assertSame('08:00', $windows[(string) $iso]['start']);
            $this->assertSame('18:00', $windows[(string) $iso]['end']);
        }
        foreach ([4, 5, 6, 7] as $iso) {
            $this->assertFalse($windows[(string) $iso]['enabled']);
        }
    }

    public function test_legacy_columns_synthesize_day_windows_when_json_absent(): void
    {
        [, $user] = $this->seedAgencyUser();
        $svc = new NotificationPreferenceService();

        // Simulate a row written before the day_windows column existed.
        $settings = UserDashboardSetting::firstOrCreate(['user_id' => $user->id], UserDashboardSetting::defaults());
        $settings->forceFill([
            'open_hours_enabled'     => true,
            'open_hours_start'       => '06:30',
            'open_hours_end'         => '20:30',
            'open_hours_day_windows' => null,
        ])->save();

        $windows = $svc->snapshot($user)['open_hours']['day_windows'];
        $this->assertCount(7, $windows);
        foreach (range(1, 7) as $iso) {
            $this->assertTrue($windows[(string) $iso]['enabled'], "synthesised day {$iso} should be enabled");
            $this->assertSame('06:30', $windows[(string) $iso]['start']);
            $this->assertSame('20:30', $windows[(string) $iso]['end']);
        }
    }

    // ── Dispatch gate (all channels) ─────────────────────────────────────

    public function test_dispatch_is_suppressed_on_all_channels_outside_open_hours(): void
    {
        [, $user] = $this->seedAgencyUser();
        $type = $this->makeEventType();
        $this->enablePref($user, $type);

        // Enabled today, but only 08:00–09:00 — "now" at 12:00 is outside.
        $now = Carbon::parse('2026-06-17 12:00', 'Africa/Johannesburg');
        Carbon::setTestNow($now);
        $this->setOpenHours($user, true, [$now->isoWeekday() => ['08:00', '09:00']]);

        $dispatcher = app(NotificationDispatcher::class);
        $fired = $dispatcher->fire($user, $type->key, $user, [
            'title' => 'x', 'body' => 'y', 'threshold_hit_at' => $now,
        ]);

        $this->assertFalse($fired);
        $this->assertSame(0, NotificationDispatchLog::where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->count());

        Carbon::setTestNow();
    }

    public function test_dispatch_proceeds_inside_open_hours(): void
    {
        [, $user] = $this->seedAgencyUser();
        $type = $this->makeEventType();
        $this->enablePref($user, $type);

        $now = Carbon::parse('2026-06-17 12:00', 'Africa/Johannesburg');
        Carbon::setTestNow($now);
        $this->setOpenHours($user, true, [$now->isoWeekday() => ['08:00', '17:00']]);

        $dispatcher = app(NotificationDispatcher::class);
        $fired = $dispatcher->fire($user, $type->key, $user, [
            'title' => 'x', 'body' => 'y', 'threshold_hit_at' => $now,
        ]);

        $this->assertTrue($fired);
        // in_app + push were enabled → two dispatch-log rows.
        $this->assertSame(2, NotificationDispatchLog::where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());

        Carbon::setTestNow();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Build a 7-key day_windows map; $overrides keyed by ISO weekday => [start, end] (enabled). */
    private function windowsFor(array $overrides): array
    {
        $out = [];
        foreach (range(1, 7) as $iso) {
            $out[(string) $iso] = ['enabled' => false, 'start' => '00:00', 'end' => '00:00'];
        }
        foreach ($overrides as $iso => [$start, $end]) {
            $out[(string) $iso] = ['enabled' => true, 'start' => $start, 'end' => $end];
        }
        return $out;
    }

    private function setOpenHours(User $user, bool $enabled, array $overrides): void
    {
        $settings = UserDashboardSetting::firstOrCreate(['user_id' => $user->id], UserDashboardSetting::defaults());
        $settings->forceFill([
            'open_hours_enabled'     => $enabled,
            'open_hours_day_windows' => $this->windowsFor($overrides),
        ])->save();
    }

    private function makeEventType(): NotificationEventType
    {
        return NotificationEventType::create([
            'key'             => 'test.open_hours_' . Str::random(6),
            'pillar'          => 'property',
            'group_label'     => 'Test',
            'label'           => 'Test event',
            'description'     => 'Test',
            'default_enabled' => true,
            'threshold_unit'  => 'hours',
            'default_threshold' => 24,
            'threshold_min'   => 1,
            'threshold_max'   => 168,
            'supports_in_app' => true,
            'supports_email'  => true,
            'supports_push'   => true,
            'is_adapter'      => false,
            'adapter_column'  => null,
            'sort_order'      => 1,
        ]);
    }

    private function enablePref(User $user, NotificationEventType $type): void
    {
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'notification_event_type_id' => $type->id],
            ['enabled' => true, 'threshold' => 24, 'channel_in_app' => true, 'channel_email' => false, 'channel_push' => true]
        );
    }

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return [$agencyId, $user];
    }
}
