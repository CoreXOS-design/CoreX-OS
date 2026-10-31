<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Notifications\FeatureRollout\RolloutTourNudgeNotification;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureRolloutUsage;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Support\Tours\TourRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AT-478 Part 5b — is what the rollout switched on being used? (spec §12.6 tour follow-up, §12.7 the CoreX
 * team's overview, §12.8 suggest a pause on low use). A fresh test DB has no grants, so everyone in the
 * agency "has access" — the counts below are over all of its active people.
 */
final class FeatureRolloutUsageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    /** @var list<User> */
    private array $agents = [];
    private string $feature; // a waveable feature with an openable guided tour

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature-rollout.readiness' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');

        $waveable = app(FeatureRolloutService::class)->waveableFeatures();
        $outward = (array) config('feature-rollout.admin_switch_on', []);
        foreach (app(FeatureTourCoverage::class)->toursByFeature() as $key => $tours) {
            if (isset($waveable[$key]) && ! in_array($key, $outward, true)
                && collect($tours)->contains(fn ($t) => ($tour = TourRegistry::find($t['key'])) && TourRegistry::launchTarget($tour))) {
                $this->feature = $key;
                break;
            }
        }
        $this->assertTrue(isset($this->feature), 'fixture: a waveable feature with a guided tour');

        FeatureRolloutWave::create(['title' => 'First', 'track' => 'general', 'position' => 10, 'feature_keys' => [$this->feature]]);
        FeatureRolloutWave::create(['title' => 'Second', 'track' => 'general', 'position' => 20, 'feature_keys' => ['presentations' === $this->feature ? 'docuperfect' : 'presentations']]);

        $this->agency = Agency::create(['name' => 'Usage ' . uniqid(), 'slug' => 'usage-' . uniqid()]);
        // The agency's own roles, so the admin's access prompt has a grid (no grants: everyone has access).
        foreach ([['admin', 'Principal', 2], ['agent', 'Agent', 4]] as [$n, $l, $o]) {
            \Illuminate\Support\Facades\DB::table('roles')->insert(['name' => $n, 'label' => $l, 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => $o, 'agency_id' => $this->agency->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        Role::clearCache();
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Ada Admin']);
        foreach (['Ben', 'Cara', 'Dev'] as $n) {
            $this->agents[] = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => "$n Agent"]);
        }
        app(FeatureRolloutService::class)->start($this->agency);
        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00'));
        app(FeatureRolloutService::class)->releaseDue(); // "First" switches on Fri 6 Nov
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function usage(): FeatureRolloutUsage
    {
        return app(FeatureRolloutUsage::class);
    }

    private function plan(): AgencyFeatureRollout
    {
        return app(FeatureRolloutService::class)->forAgency((int) $this->agency->id);
    }

    private function openedBy(): array
    {
        return $this->usage()->stats($this->agency, [$this->feature])[$this->feature]['opened'];
    }

    public function test_opening_a_switched_on_feature_is_recorded_once_through_the_real_middleware(): void
    {
        $globs = (array) config("corex-features.{$this->feature}.route_names", []);
        $prefixes = (array) config("corex-features.{$this->feature}.route_prefixes", []);
        // A probe page that belongs to the feature (by its route-name glob, or its URL prefix).
        $name = $globs !== [] ? str_replace('*', 'probe', $globs[0]) : 'rollout-usage-probe';
        // Deep enough that no existing "{id}" route in the feature's area can match it first.
        $uri = ($prefixes !== [] ? trim($prefixes[0], '/') . '/' : '') . 'zz-probe/zz-probe/zz-probe/zz-probe/zz-probe';
        Route::middleware(['web', 'auth'])->get($uri, fn () => 'ok')->name($name);
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs($this->agents[0])->get('/' . $uri)->assertOk();
        $this->actingAs($this->agents[0])->get('/' . $uri)->assertOk();
        $this->assertSame([$this->agents[0]->id], $this->openedBy());
        $this->assertDatabaseCount('rollout_feature_users', 1);

        // An agency with no rollout records nothing.
        $other = Agency::create(['name' => 'Plain ' . uniqid(), 'slug' => 'plain-' . uniqid()]);
        $plain = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($plain)->get('/' . $uri)->assertOk();
        $this->assertDatabaseCount('rollout_feature_users', 1);
    }

    public function test_the_panel_shows_who_opened_it_and_who_took_its_tour(): void
    {
        \Illuminate\Support\Facades\DB::table('rollout_feature_users')->insert([
            'agency_id' => $this->agency->id, 'user_id' => $this->agents[0]->id, 'feature_key' => $this->feature,
            'first_opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tourKey = app(FeatureTourCoverage::class)->toursByFeature()[$this->feature][0]['key'];
        UserTourProgress::create(['user_id' => $this->agents[0]->id, 'tour_key' => $tourKey, 'completed_at' => now()]);

        $html = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Opened by 1 of 4', $html);
        $this->assertStringContainsString('Tour taken by 1 of 4', $html);
        $this->assertStringContainsString('Cara Agent', $html, 'who has not yet');
    }

    public function test_low_use_suggests_a_pause_but_never_pauses(): void
    {
        $this->assertNull($this->usage()->lowUse($this->plan()), 'too early to judge on release day');

        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00')); // Monday: the day before "Second" (Tue 10 Nov)
        $low = $this->usage()->lowUse($this->plan());
        $this->assertSame(['group' => 'First', 'opened' => 0, 'people' => 4], $low);
        $this->assertStringContainsString('data-low-use', $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->getContent());
        $popup = $this->actingAs($this->admin)->get(route('agent.portal'))->getContent();
        $this->assertStringContainsString('rollout-review-heading', $popup);
        $this->assertStringContainsString('data-low-use', $popup);
        $this->assertSame(AgencyFeatureRollout::STATUS_ACTIVE, $this->plan()->status, 'a suggestion only');

        foreach ([$this->admin, $this->agents[0]] as $u) {
            $this->usage()->mark((int) $this->agency->id, (int) $u->id, $this->feature, 'first_opened_at');
        }
        $this->assertNull($this->usage()->lowUse($this->plan()), 'half the team has opened it');
    }

    public function test_tour_reminders_go_once_and_not_to_people_who_took_the_tour(): void
    {
        Notification::fake();
        $tourKey = app(FeatureTourCoverage::class)->toursByFeature()[$this->feature][0]['key'];
        UserTourProgress::create(['user_id' => $this->agents[0]->id, 'tour_key' => $tourKey, 'completed_at' => now()]);

        $this->assertSame(0, $this->usage()->nudgeTours(Carbon::parse('2026-11-07 09:00')), 'not yet');
        $this->assertSame(3, $this->usage()->nudgeTours(Carbon::parse('2026-11-10 09:00')));
        Notification::assertSentTo($this->agents[1], RolloutTourNudgeNotification::class);
        Notification::assertNotSentTo($this->agents[0], RolloutTourNudgeNotification::class);
        $this->assertSame(0, $this->usage()->nudgeTours(Carbon::parse('2026-11-11 09:00')), 'once only');
        $this->assertSame(0, $this->usage()->nudgeTours(Carbon::parse('2026-12-20 09:00')), 'never for something long gone');
    }

    public function test_the_corex_team_overview_flags_agencies_that_need_attention(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'agency_id' => null], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->forceFill(['is_owner' => true])->save();
        Role::clearCache();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);

        $healthy = Agency::create(['name' => 'Healthy ' . uniqid(), 'slug' => 'healthy-' . uniqid()]);
        app(FeatureRolloutService::class)->start($healthy);

        app(FeatureRolloutService::class)->pause($this->plan());
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00'));

        $html = $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Paused for 10 days', $html);
        $this->assertStringContainsString(route('admin.agency-setup-progress.show', $this->agency->id), $html);
        $this->assertStringNotContainsString(route('admin.agency-setup-progress.show', $healthy->id), $html, 'needs-attention view');

        $all = $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['o_show' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.agency-setup-progress.show', $healthy->id), $all);
    }
}
