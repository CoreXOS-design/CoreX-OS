<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Mail\UserInviteMail;
use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Users\OneEmailService;
use App\Support\SubUserMailRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * AT-423 — One email (sub-users). Spec: .ai/specs/one-email-sub-users.md §11.
 *
 * Agencies whose agents share one inbox (agents@hfcoastal.co.za) give each agent a
 * username (andre@hfcoastal) + own password. The username lives in the unique sign-in
 * column; only mail DELIVERY is shared. These tests walk the input space around that.
 */
final class OneEmailSubUserTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'agents@hfcoastal.co.za';

    private Agency $agency;
    private User $admin;
    private User $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);

        foreach (['admin', 'agent'] as $role) {
            if (!Role::withoutGlobalScopes()->whereNull('agency_id')->where('name', $role)->exists()) {
                (new Role())->forceFill([
                    'name' => $role, 'label' => ucfirst($role), 'is_owner' => false, 'agency_id' => null,
                ])->save();
            }
        }
        Role::clearCache();

        foreach (['manage_users', 'manage_performance_settings', 'access_settings'] as $key) {
            RolePermission::insert([[
                'role' => 'admin', 'permission_key' => $key, 'scope' => null,
                'agency_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]]);
        }
        PermissionService::clearCache();

        $this->admin = User::factory()->create([
            'name' => 'Nomsa Zulu', 'email' => 'nomsa@hfcoastal.co.za',
            'agency_id' => $this->agency->id, 'role' => 'admin', 'is_admin' => 1,
        ]);

        // The shared inbox — reception signs in with it.
        $this->main = User::factory()->create([
            'name' => 'HFC Reception', 'email' => self::INBOX,
            'agency_id' => $this->agency->id, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    private function switchOn(): void
    {
        $this->agency->forceFill(['one_email_enabled' => true, 'one_email_user_id' => $this->main->id])->save();
        Agency::forgetFindMemo();
    }

    private function createSubUser(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.users.store'), array_merge([
            'name' => 'Andre', 'surname' => 'Roets',
            'sign_in_type' => 'username', 'username' => 'andre',
            'email' => '', 'cell' => '082 555 0147', 'role' => 'agent',
        ], $overrides));
    }

    private function makeSubUser(string $username = 'andre@hfcoastal', array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => 'Andre Roets', 'email' => $username,
            'agency_id' => $this->agency->id, 'role' => 'agent', 'is_active' => true,
            'password' => 'his-own-password-1',
        ], $attrs));
        $user->forceFill(['is_sub_user' => true])->save();

        return $user->fresh();
    }

    // ── The agency switch (Settings → Team Inbox) ─────────────────────────

    public function test_turning_on_requires_choosing_the_shared_inbox(): void
    {
        $this->actingAs($this->admin)
            ->put(route('corex.settings.team-inbox'), ['one_email_enabled' => '1'])
            ->assertSessionHasErrors('one_email_user_id');

        $this->assertFalse((bool) $this->agency->fresh()->one_email_enabled);
    }

    public function test_turning_on_with_a_shared_inbox_saves_both(): void
    {
        $this->actingAs($this->admin)
            ->put(route('corex.settings.team-inbox'), [
                'one_email_enabled' => '1', 'one_email_user_id' => (string) $this->main->id,
            ])->assertSessionHasNoErrors();

        $fresh = $this->agency->fresh();
        $this->assertTrue((bool) $fresh->one_email_enabled);
        $this->assertSame($this->main->id, (int) $fresh->one_email_user_id);
    }

    public function test_a_sub_user_or_another_agencys_user_cannot_be_the_shared_inbox(): void
    {
        $this->switchOn();
        $sub = $this->makeSubUser();
        $other = User::factory()->create(['email' => 'boss@otheragency.co.za', 'agency_id' => Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()])->id]);

        foreach ([$sub->id, $other->id] as $badId) {
            $this->actingAs($this->admin)
                ->put(route('corex.settings.team-inbox'), ['one_email_user_id' => (string) $badId])
                ->assertSessionHasErrors('one_email_user_id');
        }
        $this->assertSame($this->main->id, (int) $this->agency->fresh()->one_email_user_id);
    }

    public function test_the_settings_page_renders_the_team_inbox_section(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings', ['s' => 'team-inbox']))
            ->assertOk()
            ->assertSee('Team Inbox')
            ->assertSee('Let agents share one inbox, each with their own sign-in')
            ->assertSee(route('corex.settings.team-inbox'), false);
    }

    public function test_the_company_settings_page_no_longer_carries_the_switch(): void
    {
        $this->actingAs($this->admin)->get(route('admin.company-settings'))
            ->assertOk()->assertDontSee('team-inbox', false);
    }

    public function test_saving_returns_to_the_team_inbox_section(): void
    {
        // The outcome rides in the URL, not a session flash a background poller could use up.
        $this->actingAs($this->admin)
            ->put(route('corex.settings.team-inbox'), ['one_email_enabled' => '1', 'one_email_user_id' => (string) $this->main->id])
            ->assertRedirect(route('corex.settings', ['s' => 'team-inbox', 'saved' => 'on']));

        $this->actingAs($this->admin)->get(route('corex.settings', ['s' => 'team-inbox', 'saved' => 'on']))
            ->assertOk()->assertSee('Team Inbox is ON. You can now add sub-users');

        $this->actingAs($this->admin)
            ->put(route('corex.settings.team-inbox'), ['one_email_enabled' => '0'])
            ->assertRedirect(route('corex.settings', ['s' => 'team-inbox', 'saved' => 'off']));
    }

    // ── Creating a sub-user ───────────────────────────────────────────────

    public function test_switch_off_rejects_a_sub_user(): void
    {
        $this->createSubUser()->assertSessionHasErrors('username');
        $this->assertFalse(User::where('email', 'andre@hfcoastal')->exists());
    }

    public function test_creating_a_sub_user_mails_the_shared_inbox_and_hands_back_the_link(): void
    {
        Mail::fake();
        $this->switchOn();

        $this->createSubUser(['username' => '  Andre '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('invite_link')
            ->assertSessionHas('invite_username', 'andre@hfcoastal');

        $andre = User::where('email', 'andre@hfcoastal')->firstOrFail();
        $this->assertTrue($andre->isSubUser());
        $this->assertTrue($andre->isPendingInvite());
        $this->assertSame(self::INBOX, $andre->deliveryEmail());

        Mail::assertSent(UserInviteMail::class, fn (UserInviteMail $m) => $m->hasTo(self::INBOX) && !$m->hasTo('andre@hfcoastal'));
    }

    public function test_a_pending_sub_users_page_always_shows_the_link_without_any_flash(): void
    {
        Mail::fake();
        $this->switchOn();

        $this->createSubUser()->assertRedirect(route('admin.users.edit', User::where('email', 'andre@hfcoastal')->firstOrFail()));
        $andre = User::where('email', 'andre@hfcoastal')->firstOrFail();

        // A fresh visit (no session flash at all) still shows the username + a working link.
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('admin.users.edit', $andre))
            ->assertOk()->assertSee('Set-up link for Andre Roets')->assertSee('/account-setup/' . $andre->id, false);

        // Once they have set their password the panel goes away.
        $andre->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->admin)->get(route('admin.users.edit', $andre))
            ->assertOk()->assertDontSee('Set-up link for');
    }

    public function test_typing_the_full_username_is_accepted(): void
    {
        Mail::fake();
        $this->switchOn();

        $this->createSubUser(['username' => 'Andre@HFCoastal'])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'andre@hfcoastal')->exists());
    }

    public function test_bad_usernames_are_rejected_in_plain_language(): void
    {
        $this->switchOn();

        $this->createSubUser(['username' => '   '])->assertSessionHasErrors(['username' => 'Enter a username for this person.']);
        $this->createSubUser(['username' => 'an dre!'])->assertSessionHasErrors('username');
        $this->createSubUser(['username' => 'andre@gmail'])->assertSessionHasErrors('username');

        $this->assertSame(0, User::where('is_sub_user', true)->count());
    }

    public function test_a_taken_username_is_rejected(): void
    {
        Mail::fake();
        $this->switchOn();
        $this->makeSubUser('andre@hfcoastal');

        $this->createSubUser()->assertSessionHasErrors(['email' => 'That username is already taken. Try andre.r or andre2.']);
    }

    public function test_an_archived_username_hits_the_seat_lock_not_a_crash(): void
    {
        $this->switchOn();
        $old = $this->makeSubUser('andre@hfcoastal');
        $old->delete();

        $this->createSubUser()->assertSessionHasErrors('email');
        $this->assertSame(1, User::withTrashed()->where('email', 'andre@hfcoastal')->count(), 'no second account for the archived person');
    }

    public function test_a_normal_email_user_is_unchanged(): void
    {
        Mail::fake();
        $this->switchOn();

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Sipho', 'surname' => 'Ngcobo', 'email' => 'sipho.ngcobo@hfcoastal.co.za',
            'cell' => '083 555 0123', 'role' => 'agent',
        ])->assertSessionHasNoErrors();

        $sipho = User::where('email', 'sipho.ngcobo@hfcoastal.co.za')->firstOrFail();
        $this->assertFalse($sipho->isSubUser());
        Mail::assertSent(UserInviteMail::class, fn (UserInviteMail $m) => $m->hasTo('sipho.ngcobo@hfcoastal.co.za'));
    }

    // ── The set-up link ───────────────────────────────────────────────────

    public function test_the_setup_link_asks_for_the_username_before_a_password(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser('andre@hfcoastal', ['email_verified_at' => null]);

        $show = URL::temporarySignedRoute('account.setup', now()->addDays(7), ['user' => $andre->id]);
        $store = URL::temporarySignedRoute('account.setup.store', now()->addDays(7), ['user' => $andre->id]);

        $this->get($show)->assertOk()->assertSee('Enter your username')->assertDontSee('andre@hfcoastal');

        // A password straight away is not accepted — the username step comes first.
        $this->from($show)->post($store, ['password' => 'new-password-99', 'password_confirmation' => 'new-password-99'])
            ->assertSessionHasErrors('username');
        $this->assertNull($andre->fresh()->email_verified_at);

        $this->from($show)->post($store, ['username' => 'thandi@hfcoastal'])
            ->assertSessionHasErrors(['username' => "That username doesn't match this invitation."]);

        $this->from($show)->post($store, ['username' => ' Andre@HFCoastal '])->assertRedirect($show);
        $this->get($show)->assertOk()->assertSee('Set Password');

        $this->post($store, ['password' => 'new-password-99', 'password_confirmation' => 'new-password-99'])
            ->assertRedirect(route('login'));

        $andre->refresh();
        $this->assertNotNull($andre->email_verified_at);
        $this->assertTrue(Hash::check('new-password-99', $andre->password));
    }

    public function test_the_setup_link_locks_after_five_wrong_usernames(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser('andre@hfcoastal', ['email_verified_at' => null]);
        $store = URL::temporarySignedRoute('account.setup.store', now()->addDays(7), ['user' => $andre->id]);

        for ($i = 0; $i < 5; $i++) {
            $this->post($store, ['username' => 'guess' . $i . '@hfcoastal']);
        }

        $this->post($store, ['username' => 'andre@hfcoastal'])
            ->assertSessionHasErrors('username');
        $this->assertStringContainsString('Too many wrong tries', session('errors')->first('username'));
    }

    // ── Signing in ────────────────────────────────────────────────────────

    public function test_a_sub_user_signs_in_with_their_username(): void
    {
        $andre = $this->makeSubUser();

        $this->post('/login', ['email' => ' ANDRE@hfcoastal ', 'password' => 'his-own-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($andre);
    }

    public function test_two_sub_users_on_one_inbox_each_get_their_own_account(): void
    {
        $this->switchOn();
        $andre  = $this->makeSubUser('andre@hfcoastal');
        $thandi = $this->makeSubUser('thandi@hfcoastal', ['name' => 'Thandi Mkhize', 'password' => 'her-own-password-2']);

        $this->post('/login', ['email' => 'thandi@hfcoastal', 'password' => 'her-own-password-2']);
        $this->assertAuthenticatedAs($thandi);

        $this->post('/logout');
        $this->post('/login', ['email' => 'andre@hfcoastal', 'password' => 'her-own-password-2'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertSame($andre->deliveryEmail(), $thandi->deliveryEmail());
    }

    public function test_the_mobile_app_accepts_a_username(): void
    {
        $this->makeSubUser();

        $this->postJson('/api/v1/login', ['email' => 'andre@hfcoastal', 'password' => 'his-own-password-1'])
            ->assertOk()->assertJsonPath('user.email', 'andre@hfcoastal');
    }

    public function test_turning_one_email_off_never_locks_a_sub_user_out(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->actingAs($this->admin)
            ->put(route('corex.settings.team-inbox'), ['one_email_enabled' => '0'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertFalse((bool) $this->agency->fresh()->one_email_enabled);
        Agency::forgetFindMemo();

        $this->post('/login', ['email' => 'andre@hfcoastal', 'password' => 'his-own-password-1']);
        $this->assertAuthenticatedAs($andre);
        $this->assertSame(self::INBOX, $andre->fresh()->deliveryEmail(), 'mail still reaches the shared inbox');
    }

    // ── Forgotten password = admin only (Option A) ───────────────────────

    public function test_forgot_password_sends_nothing_for_a_sub_user(): void
    {
        Notification::fake();
        $this->switchOn();
        $this->makeSubUser();

        $this->post(route('password.email'), ['email' => 'andre@hfcoastal'])
            ->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_admin_temporary_password_forces_a_new_one_at_next_sign_in(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->actingAs($this->admin)->put(route('admin.users.update', $andre), [
            'name' => 'Andre', 'surname' => 'Roets', 'sign_in_type' => 'username', 'username' => 'andre',
            'cell' => '082 555 0147', 'role' => 'agent',
            'password' => 'Temp-1234', 'password_confirmation' => 'Temp-1234',
        ])->assertSessionHasNoErrors();

        $andre->refresh();
        $this->assertTrue((bool) $andre->must_change_password);
        $this->assertSame('andre@hfcoastal', $andre->email, 'unchanged username is kept');
        auth()->logout();

        // Old password is dead; the temporary one works but only reaches the change page.
        $this->post('/login', ['email' => 'andre@hfcoastal', 'password' => 'his-own-password-1'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'andre@hfcoastal', 'password' => 'Temp-1234'])->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertRedirect(route('password.change-required'));
        $this->get(route('password.change-required'))->assertOk()->assertSee('Choose a New Password');

        $this->post(route('password.change-required.store'), ['password' => 'Temp-1234', 'password_confirmation' => 'Temp-1234'])
            ->assertSessionHasErrors('password');
        $this->post(route('password.change-required.store'), ['password' => 'mine-now-777', 'password_confirmation' => 'mine-now-777'])
            ->assertRedirect(route('dashboard'));

        $andre->refresh();
        $this->assertFalse((bool) $andre->must_change_password);
        $this->assertTrue(Hash::check('mine-now-777', $andre->password));
    }

    public function test_temporary_password_must_be_typed_twice_the_same(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->actingAs($this->admin)->put(route('admin.users.update', $andre), [
            'name' => 'Andre', 'surname' => 'Roets', 'sign_in_type' => 'username', 'username' => 'andre',
            'cell' => '082 555 0147', 'role' => 'agent',
            'password' => 'Temp-1234', 'password_confirmation' => 'Temp-9999',
        ])->assertSessionHasErrors('password');

        $this->assertFalse((bool) $andre->fresh()->must_change_password);
    }

    public function test_mobile_login_is_refused_until_the_new_password_is_chosen(): void
    {
        $andre = $this->makeSubUser();
        $andre->forceFill(['must_change_password' => true])->save();

        $this->postJson('/api/v1/login', ['email' => 'andre@hfcoastal', 'password' => 'his-own-password-1'])
            ->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    // ── Mail delivery ─────────────────────────────────────────────────────

    public function test_notifications_route_to_the_shared_inbox(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->assertSame(self::INBOX, $andre->routeNotificationForMail());
        $this->assertSame('nomsa@hfcoastal.co.za', $this->admin->routeNotificationForMail(), 'normal users unchanged');
    }

    public function test_the_safety_net_re_addresses_mail_sent_to_a_username(): void
    {
        $this->switchOn();
        $this->makeSubUser('andre@hfcoastal');
        $this->makeSubUser('thandi@hfcoastal', ['name' => 'Thandi Mkhize']);

        $email = (new Email())->subject('Daily digest')->text('x')
            ->to('andre@hfcoastal')->cc('thandi@hfcoastal', 'sipho@hfcoastal.co.za');

        $this->assertTrue(SubUserMailRouter::reroute($email));
        $this->assertSame([self::INBOX], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame([self::INBOX, 'sipho@hfcoastal.co.za'], array_map(fn ($a) => $a->getAddress(), $email->getCc()));
    }

    public function test_the_safety_net_leaves_normal_mail_alone(): void
    {
        $email = (new Email())->subject('Hi')->text('x')->to('sipho@hfcoastal.co.za', 'unknown@nodomain');

        $this->assertTrue(SubUserMailRouter::reroute($email));
        $this->assertSame(['sipho@hfcoastal.co.za', 'unknown@nodomain'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
    }

    public function test_the_safety_net_cancels_mail_with_nowhere_to_go(): void
    {
        // A sub-user whose agency never chose a shared inbox (defensive — the UI prevents it).
        $this->makeSubUser('andre@hfcoastal');
        $email = (new Email())->subject('Hi')->text('x')->to('andre@hfcoastal');

        $this->assertFalse(SubUserMailRouter::reroute($email));
    }

    public function test_a_real_send_to_a_username_is_delivered_to_the_shared_inbox(): void
    {
        $this->switchOn();
        $this->makeSubUser('andre@hfcoastal');

        // Outside production the outbound guard holds every message and records where it
        // WOULD have gone — the re-addressing runs first, so the record shows the real target.
        Mail::raw('Your digest', fn ($m) => $m->to('andre@hfcoastal')->subject('Digest'));

        $held = \App\Models\OutboundMailGuardCapture::query()->latest('id')->first();
        $this->assertNotNull($held);
        $this->assertSame(self::INBOX, $held->to_addresses);
    }

    public function test_changing_the_shared_inbox_email_moves_everyones_mail(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->main->forceFill(['email' => 'team@hfcoastal.co.za'])->save();

        $this->assertSame('team@hfcoastal.co.za', $andre->fresh()->deliveryEmail());
        $this->assertSame('andre@hfcoastal', $andre->fresh()->email, 'usernames never change on their own');
    }

    public function test_an_archived_shared_inbox_still_receives_mail_and_the_admin_is_warned(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/users/' . $this->main->id . '/delete-preview')
            ->assertOk()->assertJsonPath('shared_inbox_notice', fn ($v) => str_contains((string) $v, 'shared inbox for 1 sub-user'));

        $this->main->delete();
        $this->assertSame(self::INBOX, $andre->fresh()->deliveryEmail());
    }

    // ── Outward-facing address ────────────────────────────────────────────

    public function test_the_outside_world_sees_the_shared_inbox_never_the_username(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->assertSame(self::INBOX, $andre->outward_email);
        $this->assertSame(self::INBOX, (new \App\Http\Resources\WebsiteApi\AgentResource($andre))->toArray(request())['email']);

        $andre->forceFill(['display_email' => 'andre.sales@gmail.com'])->save();
        $this->assertSame('andre.sales@gmail.com', $andre->fresh()->outward_email, 'an explicit display email still wins');
    }

    // ── Editing / converting ──────────────────────────────────────────────

    public function test_a_sub_user_can_be_given_their_own_email(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $base = ['name' => 'Andre', 'surname' => 'Roets', 'cell' => '082 555 0147', 'role' => 'agent', 'sign_in_type' => 'email'];

        $this->actingAs($this->admin)->put(route('admin.users.update', $andre), $base + ['email' => 'andre@hfcoastal'])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->admin)->put(route('admin.users.update', $andre), $base + ['email' => 'andre.roets@gmail.com'])
            ->assertSessionHasNoErrors();

        $andre->refresh();
        $this->assertFalse($andre->isSubUser());
        $this->assertSame('andre.roets@gmail.com', $andre->deliveryEmail());
        auth()->logout();

        $this->post('/login', ['email' => 'andre.roets@gmail.com', 'password' => 'his-own-password-1']);
        $this->assertAuthenticatedAs($andre);
    }

    public function test_the_shared_inbox_account_cannot_become_a_sub_user(): void
    {
        $this->switchOn();

        $this->actingAs($this->admin)->put(route('admin.users.update', $this->main), [
            'name' => 'HFC', 'surname' => 'Reception', 'cell' => '039 555 0100', 'role' => 'agent',
            'sign_in_type' => 'username', 'username' => 'reception',
        ])->assertSessionHasErrors('username');

        $this->assertFalse($this->main->fresh()->isSubUser());
    }

    public function test_a_sub_user_cannot_change_their_username_from_their_profile(): void
    {
        $andre = $this->makeSubUser();

        $this->actingAs($andre)->patch(route('profile.update'), [
            'name' => 'Andre Roets', 'email' => 'someone-else@hfcoastal', 'cell' => '082 555 0147',
        ])->assertSessionHasNoErrors();

        $andre->refresh();
        $this->assertSame('andre@hfcoastal', $andre->email);
        $this->assertNotNull($andre->email_verified_at, 'must not be flipped back to a pending invite');
    }

    public function test_the_users_list_and_edit_screens_render_for_a_sub_user(): void
    {
        $this->switchOn();
        $andre = $this->makeSubUser();

        $this->actingAs($this->admin)->get(route('admin.users'))
            ->assertOk()->assertSee('Sub-user · ' . self::INBOX)->assertSee('All sign-in types');
        $this->actingAs($this->admin)->get(route('admin.users.edit', $andre))
            ->assertOk()->assertSee('A username, sharing an inbox')->assertSee('Reset password');
        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertOk()->assertSee('How will this person sign in?')->assertSee('@hfcoastal');
    }

    public function test_the_add_user_form_has_no_choice_while_the_switch_is_off(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertOk()->assertDontSee('How will this person sign in?');
    }

    public function test_the_stem_comes_from_the_shared_inbox_domain(): void
    {
        $svc = app(OneEmailService::class);

        $this->assertSame('@hfcoastal', $svc->stemFor($this->main));
        $this->assertFalse($svc->isRealEmail('andre@hfcoastal'));
        $this->assertTrue($svc->isRealEmail(self::INBOX));
    }
}
