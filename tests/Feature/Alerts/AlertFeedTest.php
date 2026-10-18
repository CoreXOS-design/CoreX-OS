<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-451 round 2 — the feed behind the live popup, called the way a browser calls it: a plain logged-in WEB SESSION
 * (actingAs, the web guard — never Sanctum, which is how the first build's token-only route passed its tests and then answered
 * every real staff browser with 401). A real-browser run on deployed QA1 is the other half of the proof.
 *
 * Input paths proven: no cursor (a new tab) -> the unread alerts of the last N minutes (agency setting, default 10) + a cursor;
 * cursor (the tab's stored point, so a page change never loses an alert) -> only newer unread alerts, with a few seconds of overlap; read alerts skipped; another user's alerts never; in-app off -> nothing; more than the page size -> has_more;
 * malformed / ancient cursor; guest -> 401; a user with a large unread history is not counted or loaded; the query is answered
 * from notifications_feed_index.
 */
final class AlertFeedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        $this->agency = Agency::create(['name' => 'Feed Agency', 'slug' => 'feed-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'F-' . $this->agency->id, 'is_active' => true]);
    }

    private function user(bool $inApp = true): User
    {
        $u = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        UserDashboardSetting::firstOrCreate(['user_id' => $u->id], UserDashboardSetting::defaults())
            ->forceFill(['notify_in_app' => $inApp, 'notify_email' => false, 'notify_push' => false])->save();

        return $u;
    }

    private function alert(User $user, string $title, ?\DateTimeInterface $at = null, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\PillarEventNotification', 'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $user->id, 'data' => json_encode(['title' => $title, 'body' => 'b', 'action_url' => url('/corex/rental-fault-reports/90'), 'event_key' => 'x']),
            'read_at' => $read ? now() : null, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);

        return $id;
    }

    private function feed(User $user, ?string $after = null)
    {
        return $this->actingAs($user)->getJson(route('api.v1.alerts.unread') . ($after !== null ? '?after=' . urlencode($after) : ''));
    }

    public function test_the_route_is_in_the_api_v1_catalogue_and_answers_a_web_session(): void
    {
        $this->assertSame('/api/v1/alerts/unread', parse_url(route('api.v1.alerts.unread'), PHP_URL_PATH));

        $this->feed($this->user())->assertOk()->assertJsonStructure(['in_app', 'items', 'has_more', 'cursor']);
    }

    public function test_a_guest_gets_401_not_a_redirect_or_a_page(): void
    {
        $this->getJson(route('api.v1.alerts.unread'))->assertUnauthorized();
    }

    public function test_a_new_tab_gets_the_unread_alerts_of_the_last_ten_minutes_and_nothing_older_or_read(): void
    {
        $user = $this->user();
        $recent = $this->alert($user, 'Fault reported - 3 minutes ago', now()->subMinutes(3));
        $this->alert($user, 'Too old', now()->subMinutes(11));
        $this->alert($user, 'Read already', now()->subMinutes(2), true);
        $this->alert($this->user(), 'Someone else', now()->subMinute());

        $json = $this->feed($user)->assertOk()->json();

        $this->assertTrue($json['catchup']);
        $this->assertTrue($json['in_app']);
        $this->assertSame([$recent], array_column($json['items'], 'id'));
        $this->assertNotEmpty($json['cursor']);
    }

    public function test_the_catch_up_window_is_the_agency_setting_and_is_clamped(): void
    {
        $user = $this->user();
        $this->alert($user, 'Twenty minutes ago', now()->subMinutes(20));

        $this->assertSame([], $this->feed($user)->json('items'), 'default 10 minutes: too old');

        \App\Models\CommandCenter\AgencyDashboardSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['alert_catchup_minutes' => 30]);
        $this->assertCount(1, $this->feed($user)->json('items'), 'the agency widened it to 30 minutes');

        \App\Models\CommandCenter\AgencyDashboardSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->update(['alert_catchup_minutes' => 0]);
        $this->assertSame(1, \App\Services\CommandCenter\AlertSwitchService::clampCatchupMinutes(0));
        $this->assertSame(240, \App\Services\CommandCenter\AlertSwitchService::clampCatchupMinutes(99999));
        $this->assertSame(10, \App\Services\CommandCenter\AlertSwitchService::clampCatchupMinutes(null));
        $this->assertSame(10, \App\Services\CommandCenter\AlertSwitchService::clampCatchupMinutes('abc'));
    }

    public function test_an_alert_that_arrives_between_two_pages_is_delivered_by_the_next_page_from_the_stored_cursor(): void
    {
        $user = $this->user();
        $cursor = $this->feed($user)->json('cursor');        // page A's last poll; the browser keeps this in the tab
        $this->travel(5)->seconds();
        $arrived = $this->alert($user, 'Fault reported while the agent was clicking');
        $this->travel(5)->seconds();

        // page B loads: it asks from the stored cursor, not from "now"
        $json = $this->feed($user, $cursor)->assertOk()->json();

        $this->assertFalse($json['catchup']);
        $this->assertSame([$arrived], array_column($json['items'], 'id'));
    }

    public function test_the_cursor_has_a_few_seconds_of_overlap_so_a_late_committing_row_is_not_lost(): void
    {
        $user = $this->user();
        $cursor = now()->toIso8601String();
        $late = $this->alert($user, 'Committed just after the last poll', now()->subSeconds(3));

        $this->assertSame([$late], array_column($this->feed($user, $cursor)->json('items'), 'id'));

        $this->alert($user, 'Ten seconds before the cursor', now()->subSeconds(10));
        $this->assertCount(1, $this->feed($user, $cursor)->json('items'), 'the overlap is seconds, not minutes');
    }

    public function test_only_alerts_since_the_cursor_come_back_and_read_ones_and_other_peoples_do_not(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->alert($user, 'Old', now()->subHour());
        $cursor = $this->feed($user)->json('cursor');

        $this->travel(2)->seconds();
        $mine = $this->alert($user, 'Owner approved - appoint contractor');
        $this->alert($user, 'Already read', null, true);
        $this->alert($other, 'Someone else');

        $json = $this->feed($user, $cursor)->assertOk()->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame($mine, $json['items'][0]['id']);
        $this->assertSame('Owner approved - appoint contractor', $json['items'][0]['title']);
        $this->assertStringContainsString('/corex/rental-fault-reports/90', $json['items'][0]['action_url']);
        $this->assertFalse($json['has_more']);
        $this->assertNotEmpty($json['cursor']);
    }

    public function test_with_in_app_off_nothing_comes_back_and_the_flag_says_so(): void
    {
        $user = $this->user(false);
        $cursor = $this->feed($user)->json('cursor');
        $this->travel(2)->seconds();
        $this->alert($user, 'Bypassed alert');

        $json = $this->feed($user, $cursor)->assertOk()->json();

        $this->assertFalse($json['in_app']);
        $this->assertSame([], $json['items']);
    }

    public function test_a_burst_over_the_page_size_returns_a_page_and_says_there_is_more(): void
    {
        $user = $this->user();
        $cursor = $this->feed($user)->json('cursor');
        $this->travel(2)->seconds();
        for ($i = 0; $i < 25; $i++) {
            $this->alert($user, "Alert $i");
        }

        $json = $this->feed($user, $cursor)->assertOk()->json();

        $this->assertCount(20, $json['items']);
        $this->assertTrue($json['has_more']);
    }

    public function test_a_malformed_or_ancient_cursor_never_breaks_and_never_reaches_far_back(): void
    {
        $user = $this->user();
        $this->alert($user, 'Two days old', now()->subDays(2));

        $this->feed($user, 'not-a-date')->assertOk()->assertJsonPath('items', []);   // treated as a new tab
        $this->feed($user, now()->subYears(5)->toIso8601String())->assertOk()->assertJsonPath('items', []);  // capped at 24 hours back
        $this->feed($user, '')->assertOk();
    }

    public function test_a_user_with_a_huge_unread_history_is_neither_counted_nor_loaded_and_the_feed_index_answers(): void
    {
        $user = $this->user();
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $user->id,
                'data' => '{"title":"backlog"}', 'read_at' => null, 'created_at' => now()->subDays(3)->subSeconds($i), 'updated_at' => now(),
            ];
        }
        DB::table('notifications')->insert($rows);
        $cursor = $this->feed($user)->json('cursor');
        $this->travel(2)->seconds();
        $this->alert($user, 'Fresh');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $json = $this->feed($user, $cursor)->assertOk()->json();
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '`notifications`'));

        $this->assertCount(1, $json['items']);
        $this->assertSame('Fresh', $json['items'][0]['title']);
        $this->assertCount(1, $queries, 'exactly one notifications query');
        $sql = $queries->first()['query'];
        $this->assertStringNotContainsStringIgnoringCase('count(', $sql, 'never a count of the unread history');
        $this->assertStringContainsString('limit 21', strtolower($sql));
        $this->assertStringContainsString('`created_at` >=', $sql);

        // The plan: MySQL must pick the (notifiable_type, notifiable_id, created_at) index and read only the newer rows.
        $q = $queries->first();
        $plan = collect(DB::select('EXPLAIN ' . $q['query'], $q['bindings']))->first();
        $this->assertSame('notifications_feed_index', $plan->key, 'the feed query is answered from notifications_feed_index');
        $this->assertLessThan(50, (int) $plan->rows, 'it reads the newer rows only, not the 600-row history');
    }

    public function test_the_popup_script_points_at_the_session_feed_and_backs_off_instead_of_dying(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $html = view('components.alert-popups')->render();

        $this->assertStringContainsString('\/api\/v1\/alerts\/unread', $html);
        $this->assertStringNotContainsString('api\/v1\/notifications', $html, 'never the token-only mobile route');
        $this->assertStringContainsString('csrf-token', $html, 'a 401 is checked against the session-armour probe before giving up');
        $this->assertStringContainsString('MAX_DELAY_MS', $html);
        $this->assertStringContainsString('failures++', $html);
        $this->assertStringContainsString('corex_alerts_cursor_' . $user->id, $html, 'the last-checked point survives a page change in this tab');
        $this->assertStringContainsString("sessionStorage.setItem(CURSOR_KEY", $html);
    }
}
