<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\SyncAgentToP24Job;
use App\Models\Contact;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * AT-422 — Admin → Users → <user> edit page:
 *   1. the "Communication Capture" (mailbox) box lives ONLY under the Actions tab, above the
 *      Save bar (it used to sit at the bottom of every tab);
 *   2. Actions has a per-user switch for the daily digest email, and the digest job honours it;
 *   3. the Property24 switch reads "Show on Property24", ON when the agent is on P24.
 */
final class UserActionsAndP24ToggleTest extends TestCase
{
    use RefreshDatabase;

    // ── Daily digest switch ─────────────────────────────────────────────

    public function test_a_new_user_receives_the_daily_digest_by_default(): void
    {
        [, $admin] = $this->agencyWithAdmin();
        $this->assertTrue((bool) $admin->fresh()->daily_digest_enabled);
    }

    public function test_the_switch_turns_the_daily_digest_off_and_back_on_for_that_user_only(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $target = $this->agent($agencyId);
        $bystander = $this->agent($agencyId);

        $this->actingAs($admin)->postJson(route('admin.users.toggle-daily-digest', $target))
            ->assertOk()->assertJson(['success' => true, 'daily_digest_enabled' => false]);
        $this->assertFalse((bool) $target->fresh()->daily_digest_enabled);
        $this->assertTrue((bool) $bystander->fresh()->daily_digest_enabled, 'nobody else is affected');

        $this->actingAs($admin)->postJson(route('admin.users.toggle-daily-digest', $target))
            ->assertOk()->assertJson(['daily_digest_enabled' => true]);
        $this->assertTrue((bool) $target->fresh()->daily_digest_enabled);
    }

    public function test_the_switch_needs_the_manage_users_permission(): void
    {
        [$agencyId] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);
        $target = $this->agent($agencyId);
        // Seed SOME grants so the unseeded "allow all" test fallback doesn't apply.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $agencyId]);

        $this->actingAs($agent)->postJson(route('admin.users.toggle-daily-digest', $target))->assertForbidden();
        $this->assertTrue((bool) $target->fresh()->daily_digest_enabled, 'a refused request changes nothing');
    }

    public function test_the_daily_digest_job_skips_a_switched_off_user_and_still_emails_everyone_else(): void
    {
        [$agencyId] = $this->agencyWithAdmin();
        $on  = $this->agent($agencyId, ['name' => 'Digest On Agent',  'email' => 'digest.on@example.test']);
        $off = $this->agent($agencyId, ['name' => 'Digest Off Agent', 'email' => 'digest.off@example.test']);
        $off->update(['daily_digest_enabled' => false]);

        // The job only counts a birthday when the notification catalogue knows `contact.birthday`
        // (enabled by default). A fresh test database doesn't seed it, so add the real row.
        if (! DB::table('notification_event_types')->where('key', 'contact.birthday')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'contact.birthday', 'pillar' => 'contact', 'group_label' => 'Activity',
                'label' => 'Contact birthday today', 'default_enabled' => 1, 'threshold_unit' => 'none',
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 1, 'is_adapter' => 0,
                'sort_order' => 7, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Both own a contact whose birthday is today, so both are due a digest.
        foreach ([$on, $off] as $owner) {
            Contact::unguarded(fn () => Contact::create([
                'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $owner->id,
                'first_name' => 'Birthday', 'last_name' => 'For ' . $owner->id, 'phone' => '0820000' . random_int(100, 999),
                'birthday' => now()->subYears(40)->toDateString(), 'birthday_reminder' => true,
            ]));
        }

        Artisan::call('corex:calendar:send-digests', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('digest.on@example.test', $out, 'a normal user still gets the digest');
        $this->assertStringNotContainsString('digest.off@example.test', $out, 'a switched-off user is skipped entirely');
    }

    // ── Property24 switch (server side) ─────────────────────────────────

    public function test_p24_switch_flips_the_flag_and_reports_p24_success(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);
        Queue::fake();
        $this->mock(Property24SyndicationService::class, fn (MockInterface $m) => $m->shouldReceive('updateAgentOnP24')->andReturn(true));

        // On P24 (default) -> hidden.
        $this->actingAs($admin)->postJson(route('admin.users.toggle-p24', $agent))
            ->assertOk()
            ->assertJson(['success' => true, 'exclude_from_p24' => true, 'p24_ok' => true, 'message' => 'Hidden from Property24.']);
        $this->assertTrue((bool) $agent->fresh()->exclude_from_p24);
        Queue::assertNothingPushed();   // hiding needs no photo re-sync

        // Hidden -> back on P24, and the full re-sync (photo) is queued.
        $this->actingAs($admin)->postJson(route('admin.users.toggle-p24', $agent))
            ->assertOk()
            ->assertJson(['exclude_from_p24' => false, 'p24_ok' => true, 'message' => 'Now visible on Property24.']);
        $this->assertFalse((bool) $agent->fresh()->exclude_from_p24);
        Queue::assertPushed(SyncAgentToP24Job::class, fn ($job) => true);
    }

    public function test_p24_switch_never_500s_when_property24_itself_fails(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);
        Queue::fake();

        // P24 answers with an error string…
        $this->mock(Property24SyndicationService::class, fn (MockInterface $m) => $m->shouldReceive('updateAgentOnP24')->once()->andReturn('P24 said 503'));
        $this->actingAs($admin)->postJson(route('admin.users.toggle-p24', $agent))
            ->assertOk()
            ->assertJson(['success' => true, 'exclude_from_p24' => true, 'p24_ok' => false])
            ->assertJsonPath('message', 'Saved, but Property24 update failed: P24 said 503');
        $this->assertTrue((bool) $agent->fresh()->exclude_from_p24, 'the choice is saved even though P24 failed');

        // …or the call blows up entirely.
        $this->mock(Property24SyndicationService::class, fn (MockInterface $m) => $m->shouldReceive('updateAgentOnP24')->once()->andThrow(new \RuntimeException('connection refused')));
        $this->actingAs($admin)->postJson(route('admin.users.toggle-p24', $agent))
            ->assertOk()
            ->assertJson(['success' => true, 'p24_ok' => false]);
    }

    public function test_p24_switch_needs_the_manage_users_permission(): void
    {
        [$agencyId] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);
        $other = $this->agent($agencyId);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $agencyId]);

        $this->actingAs($agent)->postJson(route('admin.users.toggle-p24', $other))->assertForbidden();
        $this->assertFalse((bool) $other->fresh()->exclude_from_p24);
    }

    // ── The edit page ───────────────────────────────────────────────────

    public function test_communication_capture_sits_under_actions_above_the_save_bar_and_only_once(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);

        $html = $this->actingAs($admin)->get(route('admin.users.edit', $agent))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Communication Capture'), 'rendered exactly once');
        // Blade comments never reach the page, so use real page text as the markers: the first
        // Actions-tab panel, the box heading, and the Save button.
        $actionsAt = strpos($html, "x-show=\"activeTab === 'actions'\"");
        $captureAt = strpos($html, 'Communication Capture');
        $saveBarAt = strpos($html, 'Save Changes');
        $this->assertNotFalse($actionsAt, 'the Actions tab panel is on the page');
        $this->assertNotFalse($saveBarAt, 'the Save button is on the page');
        $this->assertGreaterThan($actionsAt, $captureAt, 'after the Actions tab begins');
        $this->assertLessThan($saveBarAt, $captureAt, 'above the Save bar');
        // Shown only on the Actions tab (the page hides it on every other tab).
        $this->assertMatchesRegularExpression("/x-show=\"activeTab === 'actions'\"[^>]*>\s*(?:\{\{--.*?--\}\}\s*)?<h3[^>]*>Communication Capture/s", $html);
    }

    public function test_actions_tab_offers_the_daily_digest_switch_and_it_reflects_the_saved_state(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId);

        $on = $this->actingAs($admin)->get(route('admin.users.edit', $agent))->assertOk()->getContent();
        $this->assertStringContainsString('Daily Digest Email', $on);
        $this->assertStringContainsString(route('admin.users.toggle-daily-digest', $agent), $on);
        $this->assertStringContainsString('digestOn: true', $on);

        $agent->update(['daily_digest_enabled' => false]);
        $off = $this->actingAs($admin)->get(route('admin.users.edit', $agent))->assertOk()->getContent();
        $this->assertStringContainsString('digestOn: false', $off);
    }

    public function test_p24_switch_reads_show_on_property24_and_is_on_for_an_agent_on_p24(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $onP24  = $this->agent($agencyId);
        $hidden = $this->agent($agencyId);
        $hidden->update(['exclude_from_p24' => true]);

        $a = $this->actingAs($admin)->get(route('admin.users.edit', $onP24))->assertOk()->getContent();
        $this->assertStringContainsString('Show on Property24', $a);
        $this->assertStringNotContainsString('Exclude from Property24', $a);
        $this->assertStringContainsString('onP24: true', $a, 'an agent on P24 shows the switch ON');

        $b = $this->actingAs($admin)->get(route('admin.users.edit', $hidden))->assertOk()->getContent();
        $this->assertStringContainsString('onP24: false', $b, 'a hidden agent shows the switch OFF');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin'])];
    }

    private function agent(int $agencyId, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent'], $attrs));
    }
}
