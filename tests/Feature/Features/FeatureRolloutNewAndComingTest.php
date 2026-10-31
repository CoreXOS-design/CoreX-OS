<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FeatureRollout\RolloutDayBeforeNotification;
use App\Notifications\FeatureRollout\RolloutEarlyRequestNotification;
use App\Services\AI\Ellie\EllieToolkit;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureRolloutUpdates;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-478 Part 5c — "New and coming" (§12.5 coming soon + ask early, §12.9 what's-new history), the
 * day-before notice to admins (§12.4) and Ellie's whats_new_for_me (§12.6). Real permission system: the
 * agency has its own admin + agent roles with seeded grants.
 */
final class FeatureRolloutNewAndComingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature-rollout.readiness' => []]);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect'], 'intro_text' => 'Paperwork lives here now.']);
        FeatureRolloutWave::create(['title' => 'Presenting', 'track' => 'general', 'position' => 20, 'feature_keys' => ['presentations']]);

        $this->agency = Agency::create(['name' => 'Coming ' . uniqid(), 'slug' => 'coming-' . uniqid()]);
        $now = now();
        DB::table('roles')->insert([
            ['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'admin', 'label' => 'Principal', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Pat Principal']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'Sam Agent']);
        $this->assertTrue($this->admin->hasPermission('manage_feature_rollout'));
        $this->assertFalse($this->agent->hasPermission('manage_feature_rollout'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function start(): AgencyFeatureRollout
    {
        return app(FeatureRolloutService::class)->start($this->agency);
    }

    private function item(string $key): AgencyRolloutItem
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()->where('agency_id', $this->agency->id)->where('feature_key', $key)->firstOrFail();
    }

    private function release(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        app(FeatureRolloutService::class)->releaseDue();
        app(AgencyFeatureService::class)->forget($this->agency->id);
    }

    public function test_the_page_and_its_menu_link_exist_only_for_an_agency_on_gradual_rollout(): void
    {
        $this->actingAs($this->agent)->get(route('corex.new-and-coming.index'))->assertNotFound();
        $this->assertStringNotContainsString(route('corex.new-and-coming.index'), $this->actingAs($this->agent)->get(route('agent.portal'))->getContent());

        $this->start();
        $this->actingAs($this->agent)->get(route('corex.new-and-coming.index'))->assertOk()
            ->assertSee('Coming next')->assertSee('Documents')->assertSee('Fri 6 Nov')->assertSee('Ask for this early');
        $this->assertStringContainsString(route('corex.new-and-coming.index'), $this->actingAs($this->agent)->get(route('agent.portal'))->getContent());
    }

    public function test_asking_early_tells_the_admins_once_and_shows_on_their_panel_and_history(): void
    {
        Notification::fake();
        $this->start();
        $item = $this->item('docuperfect');

        $this->actingAs($this->agent)->post(route('corex.new-and-coming.early', $item->id))->assertSessionHas('success');
        Notification::assertSentTo($this->admin, RolloutEarlyRequestNotification::class);
        Notification::assertNotSentTo($this->agent, RolloutEarlyRequestNotification::class);
        $this->actingAs($this->agent)->post(route('corex.new-and-coming.early', $item->id))->assertSessionHas('success');
        Notification::assertSentToTimes($this->admin, RolloutEarlyRequestNotification::class, 1);
        $this->actingAs($this->agent)->get(route('corex.new-and-coming.index'))->assertSee('You asked');

        $panel = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Asked for early by Sam Agent', $panel);
        $this->assertStringContainsString('Asked for ' . e(config('corex-features.docuperfect.label')) . ' early', $panel);
    }

    public function test_asking_early_is_refused_for_anything_not_waiting_or_not_this_agencys(): void
    {
        $this->start();
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        app(FeatureRolloutService::class)->start($other);
        $foreign = AgencyRolloutItem::queryWithoutAgencyScope()->where('agency_id', $other->id)->firstOrFail();

        $this->actingAs($this->agent)->post(route('corex.new-and-coming.early', $foreign->id))->assertSessionHas('error');
        $this->release('2026-11-06 08:00');
        $this->actingAs($this->agent)->post(route('corex.new-and-coming.early', $this->item('docuperfect')->id))->assertSessionHas('error');
        $this->assertSame(0, DB::table('rollout_feature_users')->whereNotNull('early_requested_at')->count());
    }

    public function test_switched_on_history_lists_only_what_the_person_can_open_and_keeps_old_groups(): void
    {
        $this->start();
        $this->release('2026-11-06 08:00');
        // Take Documents away from agents: it must not be listed for them.
        app(\App\Services\FeatureRollout\FeatureRolloutAccess::class)->setRoleAccess($this->agency->id, 'agent', 'docuperfect', false);

        $this->actingAs($this->admin)->get(route('corex.new-and-coming.index'))->assertOk()
            ->assertSee('Already switched on')->assertSee('Paperwork lives here now.');
        $agentHtml = $this->actingAs($this->agent)->get(route('corex.new-and-coming.index', ['show' => 'on']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Paperwork lives here now.', $agentHtml);

        Carbon::setTestNow(Carbon::parse('2027-02-01 09:00')); // months later, the history is still there
        $this->actingAs($this->admin)->get(route('corex.new-and-coming.index', ['show' => 'on']))->assertOk()->assertSee('Paperwork lives here now.');
    }

    public function test_the_day_before_notice_goes_to_the_rollout_admins_once(): void
    {
        Notification::fake();
        $this->start(); // Documents → Fri 6 Nov, so the notice is due Thu 5 Nov from 07:00
        $updates = app(FeatureRolloutUpdates::class);

        $this->assertSame(0, $updates->notifyDayBefore(Carbon::parse('2026-11-04 10:00')), 'too early');
        $this->assertSame(0, $updates->notifyDayBefore(Carbon::parse('2026-11-05 06:30')), 'before the release time');
        $this->assertSame(1, $updates->notifyDayBefore(Carbon::parse('2026-11-05 07:15')));
        Notification::assertSentTo($this->admin, RolloutDayBeforeNotification::class);
        Notification::assertNotSentTo($this->agent, RolloutDayBeforeNotification::class);
        $this->assertSame(0, $updates->notifyDayBefore(Carbon::parse('2026-11-05 07:30')), 'once');
    }

    public function test_ellie_answers_whats_new_for_me_from_the_users_own_agency(): void
    {
        $this->start();
        $this->release('2026-11-06 08:00');

        $out = json_decode(app(EllieToolkit::class)->execute('whats_new_for_me', [], $this->admin), true);
        $this->assertSame('Documents', $out['switched_on_recently'][0]['group']);
        $this->assertSame(config('corex-features.docuperfect.label'), $out['switched_on_recently'][0]['features'][0]['name']);
        $this->assertSame('Presenting', $out['coming_next'][0]['group']);
        $this->assertSame('/corex/new-and-coming', $out['page']);

        $outsider = User::factory()->create(['agency_id' => Agency::create(['name' => 'Plain ' . uniqid(), 'slug' => 'plain-' . uniqid()])->id, 'role' => 'agent', 'is_active' => true]);
        $none = json_decode(app(EllieToolkit::class)->execute('whats_new_for_me', [], $outsider), true);
        $this->assertSame('not on gradual introduction', $none['result']);
    }
}
