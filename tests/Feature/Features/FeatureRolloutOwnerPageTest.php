<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutPlan;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-478 Part 4 — Admin → Agency Setup Progress → one agency: the CoreX team's view of an agency's
 * gradual introduction and their changes to it (spec agency-feature-rollout.md §5.5, acceptance #12).
 */
final class FeatureRolloutOwnerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Agency $agency;
    private string $spare; // a waveable feature that is on and in no default group

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutWave::create(['title' => 'Presenting', 'track' => 'general', 'position' => 20, 'feature_keys' => ['presentations']]);

        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->forceFill(['is_owner' => true])->save();
        Role::clearCache();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);

        $this->agency = $this->newAgency('Coastal');
        $outward = (array) config('feature-rollout.admin_switch_on', []);
        $this->spare = collect(app(FeatureRolloutService::class)->waveableFeatures())->keys()
            ->first(fn ($k) => ! in_array($k, ['docuperfect', 'presentations', ...$outward], true)
                && app(AgencyFeatureService::class)->enabled($k, $this->agency));
        $this->assertNotNull($this->spare, 'fixture: some waveable feature is on by default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function newAgency(string $name): Agency
    {
        $agency = Agency::create(['name' => $name . ' ' . uniqid(), 'slug' => strtolower($name) . '-' . uniqid()]);
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step = 1;
        $s->completed_steps = ['welcome', 'identity'];
        $s->expires_at = now()->addDays(30);
        $s->save();

        return $agency;
    }

    private function on(string $key, ?Agency $agency = null): bool
    {
        $agency ??= $this->agency;
        app(AgencyFeatureService::class)->forget($agency->id);

        return app(AgencyFeatureService::class)->enabled($key, $agency);
    }

    private function plan(?Agency $agency = null): ?AgencyFeatureRollout
    {
        return app(FeatureRolloutService::class)->forAgency((int) ($agency ?? $this->agency)->id);
    }

    private function wave(string $title, ?Agency $agency = null): AgencyRolloutWave
    {
        return app(FeatureRolloutService::class)->wavesOf($this->plan($agency))->firstWhere('title', $title);
    }

    private function item(string $key): AgencyRolloutItem
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $this->plan()->id)->where('feature_key', $key)->firstOrFail();
    }

    private function url(string $name, array $params = []): string
    {
        return route('admin.agency-setup-progress.' . $name, ['agency' => $this->agency->id] + $params);
    }

    private function started(): AgencyFeatureRollout
    {
        return app(FeatureRolloutService::class)->start($this->agency);
    }

    // ── The list ───────────────────────────────────────────────────────────────

    public function test_the_list_shows_each_agencys_introduction_links_to_it_and_filters_by_it(): void
    {
        $other = $this->newAgency('Inland');
        $this->started();

        $html = $this->actingAs($this->owner)->get(route('admin.agency-setup-progress'))->assertOk()->getContent();
        $this->assertStringContainsString('0 of 2 groups on · next Fri 6 Nov', $html);
        $this->assertStringContainsString('Not on gradual introduction', $html);
        $this->assertStringContainsString($this->url('show'), $html);

        // Each row links to its agency's page (names alone also appear in the owner's agency switcher).
        $mine = $this->url('show');
        $theirs = route('admin.agency-setup-progress.show', ['agency' => $other->id]);
        $this->actingAs($this->owner)->get(route('admin.agency-setup-progress', ['rollout' => 'on']))
            ->assertOk()->assertSee($mine, false)->assertDontSee($theirs, false);
        $this->actingAs($this->owner)->get(route('admin.agency-setup-progress', ['rollout' => 'none', 'sort' => 'rollout']))
            ->assertOk()->assertSee($theirs, false)->assertDontSee($mine, false);
    }

    // ── Starting ───────────────────────────────────────────────────────────────

    public function test_the_owner_can_start_an_introduction_only_with_the_confirmation(): void
    {
        $this->actingAs($this->owner)->get($this->url('show'))->assertOk()
            ->assertSee('Start gradual introduction')->assertSee('I understand these 2 features switch off');

        $this->actingAs($this->owner)->post($this->url('rollout.start'), ['interval_days' => 3, 'weekdays_only' => '1'])
            ->assertSessionHasErrors('confirm');
        $this->assertNull($this->plan());

        $this->actingAs($this->owner)->post($this->url('rollout.start'), ['interval_days' => 3, 'weekdays_only' => '1', 'confirm' => '1', 'plan_id' => FeatureRolloutPlan::defaultId()])
            ->assertRedirect($this->url('show'));
        $this->assertSame(3, $this->plan()->interval_days);
        $this->assertFalse($this->on('docuperfect'));
        $this->actingAs($this->owner)->get($this->url('show'))->assertOk()->assertSee('Documents')->assertSee('Presenting');
    }

    // ── Changing the plan ──────────────────────────────────────────────────────

    public function test_adding_moving_and_archiving_change_only_this_agencys_plan(): void
    {
        $this->started();
        $other = $this->newAgency('Inland');
        $otherPlan = app(FeatureRolloutService::class)->start($other);
        $defaultsBefore = FeatureRolloutWave::orderBy('id')->get(['title', 'feature_keys'])->toArray();

        // Add a feature that is on → held in Documents, switched off now.
        $this->actingAs($this->owner)->post($this->url('rollout.waves.features.store', ['wave' => $this->wave('Documents')->id]), ['feature_key' => $this->spare])
            ->assertSessionHas('success');
        $this->assertSame($this->wave('Documents')->id, $this->item($this->spare)->wave_id);
        $this->assertFalse($this->on($this->spare));
        $this->assertNotNull($this->wave('Documents')->customised_at);

        // Move Presentations into Documents, then archive the empty Presenting group.
        $this->actingAs($this->owner)->post($this->url('rollout.items.action', ['item' => $this->item('presentations')->id, 'action' => 'move']), ['wave_id' => $this->wave('Documents')->id])
            ->assertSessionHas('success');
        $this->assertSame($this->wave('Documents')->id, $this->item('presentations')->wave_id);
        $presenting = $this->wave('Presenting');
        $this->actingAs($this->owner)->delete($this->url('rollout.waves.archive', ['wave' => $presenting->id]))->assertSessionHas('success');
        $this->assertSoftDeleted('agency_rollout_waves', ['id' => $presenting->id]);
        $this->actingAs($this->owner)->get($this->url('show', ['status' => 'archived']))->assertOk()->assertSee('Presenting')->assertSee('Restore');

        // Archiving a group that still has features is refused, in plain words.
        $this->actingAs($this->owner)->delete($this->url('rollout.waves.archive', ['wave' => $this->wave('Documents')->id]))
            ->assertSessionHas('error');
        $this->assertNotNull($this->wave('Documents'));

        // Restore brings it back at the end, dated.
        $this->actingAs($this->owner)->post($this->url('rollout.waves.restore', ['wave' => $presenting->id]))->assertSessionHas('success');
        $this->assertNotNull($this->wave('Presenting')->scheduled_for);

        // Differences against the default.
        $this->actingAs($this->owner)->get($this->url('show'))->assertOk()
            ->assertSee('Changed for this agency')->assertSee('Only in this agency')->assertSee(config("corex-features.{$this->spare}.label"));

        // Nothing leaked: the other agency and the Dev Settings defaults are untouched.
        $this->assertSame(['docuperfect'], app(FeatureRolloutService::class)->itemsOf($this->wave('Documents', $other))->pluck('feature_key')->all());
        $this->assertTrue($this->on($this->spare, $other));
        $this->assertNull($this->wave('Documents', $other)->customised_at);
        $this->assertSame($defaultsBefore, FeatureRolloutWave::orderBy('id')->get(['title', 'feature_keys'])->toArray());
        $this->assertSame($otherPlan->id, $this->plan($other)->id);
    }

    public function test_a_feature_switched_off_for_the_agency_or_already_in_the_plan_cannot_be_added(): void
    {
        $this->started();
        $docs = $this->wave('Documents');

        $this->actingAs($this->owner)->post($this->url('rollout.waves.features.store', ['wave' => $docs->id]), ['feature_key' => 'presentations'])
            ->assertSessionHas('error');
        $this->assertSame($this->wave('Presenting')->id, $this->item('presentations')->wave_id);

        app(\App\Services\Features\AgencyFeatureWriter::class)->write($this->agency, [$this->spare => false]);
        $this->actingAs($this->owner)->post($this->url('rollout.waves.features.store', ['wave' => $docs->id]), ['feature_key' => $this->spare])
            ->assertSessionHas('error');
        $this->assertFalse(AgencyRolloutItem::queryWithoutAgencyScope()->where('feature_key', $this->spare)->exists());
    }

    public function test_switch_on_now_skip_edit_and_reorder(): void
    {
        $this->started();
        $this->actingAs($this->owner)->post($this->url('rollout.items.action', ['item' => $this->item('docuperfect')->id, 'action' => 'on']))->assertSessionHas('success');
        $this->assertTrue($this->on('docuperfect'));
        $this->assertSame(AgencyRolloutItem::STATE_RELEASED_EARLY, $this->item('docuperfect')->state);

        $this->actingAs($this->owner)->post($this->url('rollout.items.action', ['item' => $this->item('presentations')->id, 'action' => 'skip']))->assertSessionHas('success');
        $this->assertSame(AgencyRolloutItem::STATE_DROPPED, $this->item('presentations')->state);
        $this->assertFalse($this->on('presentations'));

        $this->actingAs($this->owner)->put($this->url('rollout.waves.update', ['wave' => $this->wave('Presenting')->id]), ['title' => 'Showing homes', 'intro_text' => 'Pitch decks are here.'])
            ->assertSessionHas('success');
        $this->assertSame('Pitch decks are here.', $this->wave('Showing homes')->intro_text);

        $this->assertTrue($this->wave('Documents')->scheduled_for->lt($this->wave('Showing homes')->scheduled_for));
        $this->actingAs($this->owner)->post($this->url('rollout.waves.move', ['wave' => $this->wave('Showing homes')->id]), ['direction' => 'up'])->assertSessionHas('success');
        $this->assertTrue($this->wave('Showing homes')->scheduled_for->lt($this->wave('Documents')->scheduled_for), 'now switches on first');
    }

    public function test_reset_rebuilds_the_groups_still_to_come_from_the_default_and_switches_nothing_on(): void
    {
        $this->started();
        $docs = $this->wave('Documents');
        app(FeatureRolloutService::class)->addFeature($docs, $this->spare, null);
        app(FeatureRolloutService::class)->moveFeature($this->item('presentations'), $docs);
        app(FeatureRolloutService::class)->addWave($this->plan(), 'Owner extra', null);

        $this->actingAs($this->owner)->post($this->url('rollout.control', ['action' => 'reset']))->assertSessionHas('success');

        $titles = app(FeatureRolloutService::class)->wavesOf($this->plan())->pluck('title')->all();
        $this->assertSame(['Documents', 'Presenting', 'Other features'], $titles);
        $this->assertSame('Presenting', AgencyRolloutWave::queryWithoutAgencyScope()->find($this->item('presentations')->wave_id)->title);
        $this->assertSame('Other features', AgencyRolloutWave::queryWithoutAgencyScope()->find($this->item($this->spare)->wave_id)->title);
        $this->assertNull($this->wave('Documents')->customised_at);
        $this->assertFalse($this->on('docuperfect'));
        $this->assertFalse($this->on($this->spare), 'a reset never switches anything on');
        $this->assertSoftDeleted('agency_rollout_waves', ['id' => $docs->id]);
    }

    public function test_released_groups_are_history_and_cannot_be_changed(): void
    {
        $this->started();
        Carbon::setTestNow(Carbon::parse('2026-11-06 08:00'));
        app(FeatureRolloutService::class)->releaseDue();
        $docs = $this->wave('Documents');
        $this->assertNotNull($docs->released_at);

        $this->actingAs($this->owner)->put($this->url('rollout.waves.update', ['wave' => $docs->id]), ['title' => 'Renamed'])->assertSessionHas('error');
        $this->actingAs($this->owner)->post($this->url('rollout.waves.features.store', ['wave' => $docs->id]), ['feature_key' => $this->spare])->assertSessionHas('error');
        $this->actingAs($this->owner)->post($this->url('rollout.items.action', ['item' => $this->item('presentations')->id, 'action' => 'move']), ['wave_id' => $docs->id])->assertSessionHas('error');
        $this->assertSame('Documents', $docs->fresh()->title);
        $this->assertTrue($this->on($this->spare));
    }

    public function test_pace_saves_release_time_weekdays_and_holidays(): void
    {
        $this->started();
        $this->actingAs($this->owner)->post($this->url('rollout.pace'), [
            'interval_days' => 1, 'release_time' => '09:30', 'weekdays_only' => '0', 'skip_holidays' => '0',
        ])->assertSessionHas('success');
        $plan = $this->plan();
        $this->assertSame(1, $plan->interval_days);
        $this->assertSame('09:30', substr((string) $plan->release_time, 0, 5));
        $this->assertFalse($plan->weekdays_only);
        $this->assertFalse($plan->skip_holidays);
        $this->assertSame('2026-11-05', $this->wave('Documents')->scheduled_for->toDateString());
    }

    // ── Access ─────────────────────────────────────────────────────────────────

    public function test_non_owners_are_refused_and_another_agencys_ids_are_not_reachable(): void
    {
        $this->started();
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $this->agency->id, 'is_active' => true]);
        foreach ([
            fn () => $this->actingAs($admin)->get($this->url('show')),
            fn () => $this->actingAs($admin)->post($this->url('rollout.control', ['action' => 'pause'])),
            fn () => $this->actingAs($admin)->post($this->url('rollout.items.action', ['item' => $this->item('docuperfect')->id, 'action' => 'on'])),
        ] as $call) {
            $this->assertContains($call()->status(), [403, 404]);
        }
        $this->assertSame(AgencyFeatureRollout::STATUS_ACTIVE, $this->plan()->status);
        $this->assertFalse($this->on('docuperfect'));

        // A group id from ANOTHER agency's plan, sent to this agency's address, is not found.
        $other = $this->newAgency('Inland');
        app(FeatureRolloutService::class)->start($other);
        $this->actingAs($this->owner)->put($this->url('rollout.waves.update', ['wave' => $this->wave('Documents', $other)->id]), ['title' => 'Hijack'])->assertNotFound();
        $this->assertSame('Documents', $this->wave('Documents', $other)->title);
    }
}
