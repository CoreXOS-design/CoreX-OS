<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\Property;
use App\Models\User;
use App\Support\RentalAlertTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec notification-system-overview.md section 16 - the alert bell, the inbox, the read writes and the rentals title rule.
 * Called the way a browser calls it: a logged-in WEB session (actingAs), never Sanctum. The real-browser half (toasts that stay,
 * stack, survive a page change) is proved in headless Chromium on QA1.
 */
final class AlertBellTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        $this->agency = Agency::create(['name' => 'Bell Agency', 'slug' => 'bell-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'BL-' . $this->agency->id, 'is_active' => true]);
    }

    private function user(): User
    {
        $u = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        UserDashboardSetting::firstOrCreate(['user_id' => $u->id], UserDashboardSetting::defaults())
            ->forceFill(['notify_in_app' => true, 'notify_email' => false, 'notify_push' => false])->save();

        return $u;
    }

    private function alert(User $user, string $title, ?\DateTimeInterface $at = null, bool $read = false, string $key = 'rental_work_order.owner_decided', ?string $url = null): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\PillarEventNotification', 'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'body' => 'Fix the roof', 'action_url' => $url ?? url('/corex/rental-work-orders/9'), 'event_key' => $key]),
            'read_at' => $read ? now() : null, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);

        return $id;
    }

    private function readAt(string $id): ?string
    {
        return DB::table('notifications')->where('id', $id)->value('read_at');
    }

    // ── bell ───────────────────────────────────────────────────────────────────────────────

    public function test_the_bell_lists_only_my_unread_alerts_newest_first_with_a_count(): void
    {
        $me = $this->user();
        $old = $this->alert($me, 'Old - 8 Harbour View Road', now()->subHours(3));
        $new = $this->alert($me, 'New - 8 Harbour View Road', now()->subMinutes(1));
        $this->alert($me, 'Read already', now()->subMinutes(2), true);
        $this->alert($this->user(), 'Someone else', now());

        $json = $this->actingAs($me)->getJson(route('api.v1.alerts.bell'))->assertOk()->json();

        $this->assertSame(2, $json['unread_count']);
        $this->assertSame([$new, $old], array_column($json['items'], 'id'));
        $this->assertSame(route('corex.alerts.open', $new), $json['items'][0]['open_url']);
    }

    public function test_the_bell_count_stops_at_the_cap_and_lists_at_most_fifteen(): void
    {
        $me = $this->user();
        foreach (range(1, 120) as $i) {
            $this->alert($me, "Alert $i", now()->subSeconds($i));
        }

        $json = $this->actingAs($me)->getJson(route('api.v1.alerts.bell'))->assertOk()->json();

        $this->assertSame(100, $json['unread_count']);
        $this->assertCount(15, $json['items']);
    }

    public function test_the_feed_carries_the_unread_count_and_says_which_pending_toasts_are_still_unread(): void
    {
        $me = $this->user();
        $stillThere = $this->alert($me, 'Still unread');
        $readElsewhere = $this->alert($me, 'Read in another tab', now(), true);
        $foreign = $this->alert($this->user(), 'Not mine');

        $json = $this->actingAs($me)->getJson(route('api.v1.alerts.unread') . '?pending=' . implode(',', [$stillThere, $readElsewhere, $foreign, 'not-an-id']))
            ->assertOk()->json();

        $this->assertSame(1, $json['unread_count']);
        $this->assertSame([$stillThere], $json['still_unread']);
    }

    public function test_pending_ids_that_start_or_end_with_a_digit_are_not_mangled(): void
    {
        $me = $this->user();
        $ids = [];
        foreach (['0aaaaaaa-0000-4000-8000-000000000000', '1aaaaaaa-0000-4000-8000-000000000001', '2aaaaaaa-0000-4000-8000-000000000002'] as $uuid) {
            DB::table('notifications')->insert([
                'id' => $uuid, 'type' => 'App\\Notifications\\PillarEventNotification', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $me->id,
                'data' => json_encode(['title' => 't', 'event_key' => 'x']), 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ids[] = $uuid;
        }

        $json = $this->actingAs($me)->getJson(route('api.v1.alerts.unread') . '?pending=' . implode(',', $ids))->assertOk()->json();

        $this->assertEqualsCanonicalizing($ids, $json['still_unread']);
    }

    public function test_the_bell_routes_are_in_the_api_v1_catalogue_and_a_guest_gets_401(): void
    {
        foreach (['api.v1.alerts.bell', 'api.v1.alerts.read-all'] as $name) {
            $this->assertStringStartsWith('/api/v1/alerts/', parse_url(route($name), PHP_URL_PATH));
        }
        $this->getJson(route('api.v1.alerts.bell'))->assertUnauthorized();
        $this->postJson(route('api.v1.alerts.read-all'))->assertUnauthorized();
    }

    // ── read writes ────────────────────────────────────────────────────────────────────────

    public function test_closing_a_toast_marks_that_one_alert_read_and_returns_the_new_count(): void
    {
        $me = $this->user();
        $a = $this->alert($me, 'A');
        $b = $this->alert($me, 'B');

        $this->actingAs($me)->postJson(route('api.v1.alerts.read', $a))->assertOk()->assertJson(['ok' => true, 'unread_count' => 1]);

        $this->assertNotNull($this->readAt($a));
        $this->assertNull($this->readAt($b));
    }

    public function test_someone_elses_alert_cannot_be_read_or_opened(): void
    {
        $me = $this->user();
        $theirs = $this->alert($this->user(), 'Theirs');

        $this->actingAs($me)->postJson(route('api.v1.alerts.read', $theirs))->assertNotFound();
        $this->actingAs($me)->get(route('corex.alerts.open', $theirs))->assertNotFound();
        $this->actingAs($me)->post(route('corex.alerts.read', $theirs))->assertNotFound();
        $this->assertNull($this->readAt($theirs));
    }

    public function test_mark_all_read_touches_only_my_alerts(): void
    {
        $me = $this->user();
        $other = $this->user();
        $mine = $this->alert($me, 'Mine');
        $theirs = $this->alert($other, 'Theirs');

        $this->actingAs($me)->postJson(route('api.v1.alerts.read-all'))->assertOk()->assertJson(['unread_count' => 0]);

        $this->assertNotNull($this->readAt($mine));
        $this->assertNull($this->readAt($theirs));
    }

    public function test_opening_an_alert_marks_it_read_and_goes_to_its_own_page(): void
    {
        $me = $this->user();
        $id = $this->alert($me, 'Go', null, false, 'rental_work_order.owner_decided', url('/corex/rental-work-orders/9?tab=quotes'));

        $this->actingAs($me)->get(route('corex.alerts.open', $id))->assertRedirect('/corex/rental-work-orders/9?tab=quotes');

        $this->assertNotNull($this->readAt($id));
    }

    public function test_a_link_to_another_site_is_never_followed(): void
    {
        $me = $this->user();
        $id = $this->alert($me, 'Elsewhere', null, false, 'x', 'https://evil.example/phish');

        $this->actingAs($me)->get(route('corex.alerts.open', $id))->assertRedirect(route('corex.alerts.index'));
        $this->assertNotNull($this->readAt($id));
    }

    // ── inbox ──────────────────────────────────────────────────────────────────────────────

    public function test_the_inbox_lists_all_my_alerts_with_read_state_and_nobody_elses(): void
    {
        $me = $this->user();
        $this->alert($me, 'Unread one - 8 Harbour View Road', now()->subMinute());
        $this->alert($me, 'Read one - 8 Harbour View Road', now()->subMinutes(5), true);
        $this->alert($this->user(), 'Hidden from me');

        $html = $this->actingAs($me)->get(route('corex.alerts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Unread one - 8 Harbour View Road', $html);
        $this->assertStringContainsString('Read one - 8 Harbour View Road', $html);
        $this->assertStringNotContainsString('Hidden from me', $html);
        $this->assertSame(1, substr_count($html, 'data-read="0"'));
        $this->assertSame(1, substr_count($html, 'data-read="1"'));
        $this->assertTrue(strpos($html, 'Unread one') < strpos($html, 'Read one'), 'newest first by default');
    }

    public function test_the_inbox_filters_search_type_status_and_dates(): void
    {
        $me = $this->user();
        $this->alert($me, 'Quote approved - Shelly Beach', now()->subDays(1), false, 'rental_work_order.owner_decided');
        $this->alert($me, 'Fault reported - Uvongo', now()->subDays(10), true, 'rental_fault_report.created');

        $get = fn (array $q) => $this->actingAs($me)->get(route('corex.alerts.index', $q))->assertOk()->getContent();

        $this->assertStringNotContainsString('Uvongo', $get(['q' => 'Shelly']));
        $this->assertStringContainsString('Shelly', $get(['q' => 'Shelly']));
        $this->assertStringNotContainsString('Shelly Beach', $get(['type' => 'rental_fault_report.created']));
        $this->assertStringNotContainsString('Uvongo', $get(['status' => 'unread']));
        $this->assertStringNotContainsString('Shelly Beach', $get(['status' => 'read']));
        $this->assertStringNotContainsString('Uvongo', $get(['from' => now()->subDays(3)->toDateString()]));
        $this->assertStringNotContainsString('Shelly Beach', $get(['to' => now()->subDays(5)->toDateString()]));
        $oldest = $get(['sort' => 'oldest']);
        $this->assertTrue(strpos($oldest, 'Uvongo') < strpos($oldest, 'Shelly Beach'));
    }

    public function test_the_inbox_pages_at_twenty_five_and_has_two_empty_states(): void
    {
        $me = $this->user();
        $empty = $this->actingAs($me)->get(route('corex.alerts.index'))->assertOk()->getContent();
        $this->assertStringContainsString('No alerts yet.', $empty);

        foreach (range(1, 30) as $i) {
            $this->alert($me, "Row $i", now()->subMinutes($i));
        }
        $this->assertSame(25, substr_count($this->actingAs($me)->get(route('corex.alerts.index'))->getContent(), 'data-qa="alert-row"'));
        $this->assertSame(5, substr_count($this->actingAs($me)->get(route('corex.alerts.index', ['page' => 2]))->getContent(), 'data-qa="alert-row"'));
        $this->assertStringContainsString('No alerts match these filters.', $this->actingAs($me)->get(route('corex.alerts.index', ['q' => 'zzzz']))->getContent());
    }

    public function test_the_inbox_mark_all_read_and_mark_one_read(): void
    {
        $me = $this->user();
        $a = $this->alert($me, 'A');
        $b = $this->alert($me, 'B');

        $this->actingAs($me)->post(route('corex.alerts.read', $a))->assertRedirect();
        $this->assertNotNull($this->readAt($a));
        $this->assertNull($this->readAt($b));

        $this->actingAs($me)->post(route('corex.alerts.read-all'))->assertRedirect();
        $this->assertNotNull($this->readAt($b));
    }

    public function test_the_inbox_needs_a_login(): void
    {
        $this->get(route('corex.alerts.index'))->assertRedirect();
    }

    // ── bell on the page ───────────────────────────────────────────────────────────────────

    public function test_a_staff_page_carries_the_bell_and_the_sidebar_link(): void
    {
        $html = $this->actingAs($this->user())->get(route('corex.alerts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-alert-bell', $html);
        $this->assertStringContainsString('data-alert-bell-badge', $html);
        $this->assertStringContainsString('href="' . route('corex.alerts.index') . '"', $html);
    }

    public function test_the_bell_sits_in_the_menus_top_bar_left_of_ellie_not_in_the_user_block(): void
    {
        $html = $this->actingAs($this->user())->get(route('corex.alerts.index'))->assertOk()->getContent();

        $logo    = strpos($html, 'class="corex-sidebar-logo"');
        $bell    = strpos($html, 'data-alert-bell ');
        $ellie   = strpos($html, 'id="help-widget-slot"');
        $profile = strpos($html, 'class="corex-user-profile"');
        $this->assertNotFalse($logo);
        $this->assertNotFalse($bell);
        $this->assertTrue($logo < $bell && $bell < $ellie, 'the bell is in the top bar, just before the Ellie button');
        $this->assertSame(1, substr_count($html, 'data-alert-bell '), 'one bell only');
        $this->assertGreaterThan($bell, $profile, 'not in the user block any more');
        // Near the top the panel opens downward.
        $this->assertStringContainsString("r.top < window.innerHeight / 2", $html);
    }

    // ── one title rule for rentals ─────────────────────────────────────────────────────────

    public function test_the_title_helper_appends_once_and_normalises_the_dash(): void
    {
        $p = new Property(['title' => '8 Harbour View Road, Shelly Beach, Margate']);
        $p->id = 5;
        $address = RentalAlertTitle::addressOf($p);
        $this->assertNotSame('', $address);

        $this->assertSame("Owner approved the quote (R4,120.00) - send it to the contractor - $address", RentalAlertTitle::withProperty('Owner approved the quote (R4,120.00) - send it to the contractor', $p));
        $this->assertSame("Fault reported - $address", RentalAlertTitle::withProperty("Fault reported — $address", $p), 'an em dash is normalised, the address is not doubled');
        $this->assertSame("Fault reported - $address", RentalAlertTitle::withProperty("Fault reported - $address", $p), 'idempotent');
        $this->assertSame('Property #9', RentalAlertTitle::addressOf(null, 9));
        $this->assertSame('Anything', RentalAlertTitle::withProperty('Anything', null));
    }
}
