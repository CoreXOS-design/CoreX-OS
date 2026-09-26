<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Events\Agent\AgentDeactivated;
use App\Jobs\SyncAgentToP24Job;
use App\Mail\UserInviteMail;
use App\Models\Billing\AgentSeatRelease;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-422 — Users list "Ledger" + bulk actions, and the "Roster" edit page's new structure.
 * Spec: .ai/specs/users-pages-restyle.md
 */
final class UsersLedgerBulkTest extends TestCase
{
    use RefreshDatabase;

    // ── The Ledger list ─────────────────────────────────────────────────

    public function test_ledger_rows_carry_ffc_state_listings_and_last_seen(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $expired = $this->agent($agencyId, ['name' => 'Ledger Expired']);
        $soon    = $this->agent($agencyId, ['name' => 'Ledger Soon']);
        $valid   = $this->agent($agencyId, ['name' => 'Ledger Valid']);
        $none    = $this->agent($agencyId, ['name' => 'Ledger NoFfc']);

        DB::table('users')->where('id', $expired->id)->update(['ffc_expiry_date' => now()->subDays(17)->toDateString()]);
        DB::table('users')->where('id', $soon->id)->update(['ffc_expiry_date' => now()->addDays(22)->toDateString()]);
        DB::table('users')->where('id', $valid->id)->update(['ffc_expiry_date' => now()->addDays(200)->toDateString()]);

        // Valid has 2 on-market listings and 1 withdrawn one (must NOT be counted); a login 3 hours ago.
        foreach (['active', 'for_sale', 'withdrawn'] as $i => $status) {
            Property::create([
                'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $valid->id,
                'title' => "Ledger listing {$i}", 'status' => $status, 'listing_type' => 'sale',
                'property_type' => 'house', 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
            ]);
        }
        DB::table('login_histories')->insert(['user_id' => $valid->id, 'event' => 'login', 'created_at' => now()->subHours(3)]);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        $row = fn (User $u): string => $this->rowAttrs($html, $u->id);
        $this->assertStringContainsString('data-ffc="bad"', $row($expired));
        $this->assertStringContainsString('data-ffc="warn"', $row($soon));
        $this->assertStringContainsString('data-ffc="ok"', $row($valid));
        $this->assertStringContainsString('data-ffc="none"', $row($none));
        $this->assertStringContainsString('data-listings="2"', $row($valid), 'only on-market listings count');
        $this->assertStringContainsString('data-listings="0"', $row($none));
        $this->assertStringNotContainsString('data-last="0"', $row($valid), 'has a real last-login time');
        $this->assertStringContainsString('data-last="0"', $row($none), 'never logged in');
        foreach (['FFC expiry', 'Listings', 'Last seen', 'Property24'] as $col) {
            $this->assertStringContainsString($col, $html);
        }
    }

    public function test_the_quick_edit_panel_survives_inside_each_row(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId, ['name' => 'Panel Person']);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        // The unchanged quick-edit form, its Save button and the Delete control all still render.
        $this->assertStringContainsString('id="roleForm-' . $agent->id . '"', $html);
        $this->assertStringContainsString(route('admin.users.role.update', $agent), $html);
        $this->assertStringContainsString('form="roleForm-' . $agent->id . '"', $html);
        $this->assertStringContainsString('data-agent-delete', $html);
        $this->assertStringContainsString(route('admin.users.edit', $agent), $html, 'Edit link');
    }

    public function test_status_is_derived_the_same_way_as_before(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $active   = $this->agent($agencyId, ['name' => 'St Active']);
        $pending  = $this->agent($agencyId, ['name' => 'St Pending', 'email_verified_at' => null]);
        $inactive = $this->agent($agencyId, ['name' => 'St Inactive', 'is_active' => 0]);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringContainsString('data-status="active"', $this->rowAttrs($html, $active->id));
        $this->assertStringContainsString('data-status="pending"', $this->rowAttrs($html, $pending->id));
        $this->assertStringContainsString('data-status="inactive"', $this->rowAttrs($html, $inactive->id));
    }

    // ── Bulk: Resend invitation ─────────────────────────────────────────

    public function test_bulk_resend_invitation_mails_only_those_who_still_need_it_and_says_who_was_skipped(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        Mail::fake();
        // A real pending invite is created with is_active = false until first login
        // (store()) — this fixture must match that, not agent()'s active-by-default
        // state, or it can't catch the bug where bulk resend skipped every genuine
        // pending invite as "inactive".
        $pending             = $this->agent($agencyId, ['name' => 'Needs Invite', 'is_active' => 0, 'email_verified_at' => null]);
        $done                = $this->agent($agencyId, ['name' => 'Already Setup']);
        $deactivatedButSetUp = $this->agent($agencyId, ['name' => 'Gone Quiet', 'is_active' => 0]);

        $res = $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'action' => 'resend_invite', 'user_ids' => [$pending->id, $done->id, $deactivatedButSetUp->id],
        ]);

        $res->assertRedirect(route('admin.users'));
        $res->assertSessionHas('status', 'Invitation resent to 1 person.');
        Mail::assertSent(UserInviteMail::class, 1);
        Mail::assertSent(UserInviteMail::class, fn ($m) => $m->hasTo($pending->email));
        $skipped = implode(' | ', session('bulk_skipped'));
        $this->assertStringContainsString('Already Setup — has already set up their account', $skipped);
        $this->assertStringContainsString('Gone Quiet — has already set up their account', $skipped);
    }

    // ── Bulk: Deactivate ────────────────────────────────────────────────

    public function test_bulk_deactivate_runs_the_same_side_effects_as_the_single_action(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        Queue::fake();
        Event::fake([AgentDeactivated::class]);
        $a = $this->agent($agencyId, ['name' => 'Bulk A']);
        $b = $this->agent($agencyId, ['name' => 'Bulk B']);

        $res = $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => [$a->id, $b->id]]);

        $res->assertRedirect(route('admin.users'));
        $this->assertStringContainsString('Deactivated 2 people.', (string) session('status'));
        foreach ([$a, $b] as $u) {
            $this->assertFalse((bool) $u->fresh()->is_active);
            $this->assertNotNull(AgentSeatRelease::where('user_id', $u->id)->first(), 'the billable seat is released, as the single action does');
        }
        Event::assertDispatched(AgentDeactivated::class, 2);
        Queue::assertPushed(SyncAgentToP24Job::class, 2);
    }

    public function test_bulk_deactivate_skips_yourself_and_people_already_inactive(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        Queue::fake();
        $inactive = $this->agent($agencyId, ['name' => 'Already Off', 'is_active' => 0]);
        $target   = $this->agent($agencyId, ['name' => 'Real Target']);

        $this->actingAs($admin)->post(route('admin.users.bulk'), [
            'action' => 'deactivate', 'user_ids' => [$admin->id, $inactive->id, $target->id],
        ])->assertRedirect(route('admin.users'));

        $this->assertTrue((bool) $admin->fresh()->is_active, 'you can never deactivate yourself');
        $this->assertFalse((bool) $target->fresh()->is_active);
        $skipped = implode(' | ', session('bulk_skipped'));
        $this->assertStringContainsString('you cannot deactivate yourself', $skipped);
        $this->assertStringContainsString('Already Off — is already inactive', $skipped);
    }

    public function test_bulk_never_touches_another_agencys_users(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        [$otherAgencyId] = $this->agencyWithAdmin();
        Queue::fake();
        Mail::fake();
        $mine   = $this->agent($agencyId, ['name' => 'Mine']);
        $theirs = $this->agent($otherAgencyId, ['name' => 'Theirs', 'email_verified_at' => null]);

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => [$mine->id, $theirs->id]]);
        $this->assertFalse((bool) $mine->fresh()->is_active);
        $this->assertTrue((bool) $theirs->fresh()->is_active, "another agency's user is never deactivated");
        $this->assertStringContainsString('1 selected person could not be found.', implode(' | ', session('bulk_skipped')));

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'resend_invite', 'user_ids' => [$theirs->id]]);
        Mail::assertNothingSent();
    }

    public function test_bulk_reports_when_nobody_was_changed(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $off = $this->agent($agencyId, ['is_active' => 0]);

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => [$off->id]])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHasErrors('bulk');
    }

    public function test_bulk_rejects_bad_input_and_needs_the_manage_users_permission(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent  = $this->agent($agencyId);
        $target = $this->agent($agencyId);

        // Bad input: no ids, unknown action, ids that are not numbers.
        $this->actingAs($admin)->postJson(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => []])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.users.bulk'), ['action' => 'delete_everyone', 'user_ids' => [$target->id]])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => ['x']])->assertStatus(422);
        $this->assertTrue((bool) $target->fresh()->is_active);

        // Seed SOME grants so the unseeded "allow all" test fallback doesn't apply.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $agencyId]);
        \App\Services\PermissionService::clearCache();   // the admin requests above memoised "no rules seeded yet"
        $this->actingAs($agent)->postJson(route('admin.users.bulk'), ['action' => 'deactivate', 'user_ids' => [$target->id]])->assertForbidden();
        $this->assertTrue((bool) $target->fresh()->is_active, 'a refused request changes nothing');
    }

    public function test_the_single_deactivate_still_works_through_the_shared_method(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        Queue::fake();
        Event::fake([AgentDeactivated::class]);
        $agent = $this->agent($agencyId, ['name' => 'Single Off']);

        $this->actingAs($admin)->post(route('admin.users.toggle', $agent))->assertRedirect();

        $this->assertFalse((bool) $agent->fresh()->is_active);
        $this->assertNotNull(AgentSeatRelease::where('user_id', $agent->id)->first());
        $this->assertStringContainsString('Single Off deactivated.', (string) session('status'));
        Event::assertDispatched(AgentDeactivated::class, 1);
    }

    // ── The Roster edit page ────────────────────────────────────────────

    public function test_edit_page_has_the_profile_panel_with_all_three_switches_and_the_digest_in_both_places(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agent = $this->agent($agencyId, ['name' => 'Roster Person']);

        $html = $this->actingAs($admin)->get(route('admin.users.edit', $agent))->assertOk()->getContent();

        $this->assertStringContainsString('class="ue-cols"', $html);
        $this->assertStringContainsString('aria-label="Profile summary"', $html);
        $this->assertStringContainsString('Show on Property24', $html);
        $this->assertStringContainsString(route('admin.users.toggle-p24', $agent), $html);
        $this->assertStringContainsString('Daily digest email', $html, 'panel switch');
        $this->assertStringContainsString('Daily Digest Email', $html, 'Actions-tab card');
        $this->assertSame(2, substr_count($html, route('admin.users.toggle-daily-digest', $agent)), 'panel + Actions card');
        $this->assertSame(2, substr_count($html, '@user-digest.window'), 'both listen, so they stay in step');
        // The old dark banner and the Role-tab copies of the P24 / website switches are gone.
        $this->assertStringNotContainsString('corex-page-banner', $html);
        $this->assertSame(1, substr_count($html, route('admin.users.toggle-p24', $agent)), 'one Property24 switch only');
        // Segmented tabs, and Save in the header plus the sticky bar.
        $this->assertSame(5, substr_count($html, 'class="ue-tab"'));
        $this->assertStringContainsString('Save changes', $html);
        $this->assertStringContainsString('Save Changes', $html);
    }

    public function test_panel_offers_resend_invitation_only_while_the_invite_is_pending(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $pending = $this->agent($agencyId, ['email_verified_at' => null]);
        $active  = $this->agent($agencyId);

        $p = $this->actingAs($admin)->get(route('admin.users.edit', $pending))->assertOk()->getContent();
        $a = $this->actingAs($admin)->get(route('admin.users.edit', $active))->assertOk()->getContent();

        $this->assertStringContainsString('>Resend invitation</button>', $p);
        $this->assertStringNotContainsString('>Resend invitation</button>', $a);
        $this->assertStringContainsString('Deactivate user', $a);
    }

    public function test_create_page_has_no_profile_panel_and_keeps_its_form_checkboxes(): void
    {
        [, $admin] = $this->agencyWithAdmin();

        $html = $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->getContent();

        $this->assertStringContainsString('class="ue-cols solo"', $html);
        $this->assertStringNotContainsString('aria-label="Profile summary"', $html);
        $this->assertStringContainsString('name="exclude_from_p24"', $html, 'create mode still uses the form checkbox');
        $this->assertStringContainsString('Create user', $html);
        $this->assertStringContainsString('Create User', $html);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** The opening <tbody …> tag (with its data-* attributes) for one user's Ledger row. */
    private function rowAttrs(string $html, int $userId): string
    {
        $this->assertSame(1, preg_match('/<tbody[^>]*data-id="' . $userId . '"[^>]*>/s', $html, $m), "row for user {$userId}");

        return $m[0];
    }

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
        return User::factory()->create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'is_active' => 1,
            'email_verified_at' => now(),
        ], $attrs));
    }
}
