<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Agency suspension — .ai/specs/agency-suspension.md */
class AgencySuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function roles(): void
    {
        $o = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $o->is_owner = true;
        $o->save();
        $a = Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent', 'sort_order' => 2]);
        $a->is_owner = false;
        $a->save();
        Role::clearCache();
    }

    private function agentFor(Agency $agency): User
    {
        return User::factory()->create([
            'role' => 'agent', 'agency_id' => $agency->id, 'is_active' => true,
            'password' => bcrypt('secret-pass-1'),
        ]);
    }

    public function test_suspended_agency_user_cannot_sign_in_and_sees_support_message(): void
    {
        $this->roles();
        $agency = Agency::create(['name' => 'Paused Co', 'slug' => 'paused-co']);
        $user = $this->agentFor($agency);
        $agency->suspend();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('info@corexweb.co.za', session('errors')->first('email'));
        $this->assertStringContainsString('suspended', session('errors')->first('email'));
    }

    public function test_other_agency_is_unaffected_and_lifting_restores_access(): void
    {
        $this->roles();
        $paused = Agency::create(['name' => 'Paused Co', 'slug' => 'paused-co']);
        $other = Agency::create(['name' => 'Other Co', 'slug' => 'other-co']);
        $pausedUser = $this->agentFor($paused);
        $otherUser = $this->agentFor($other);
        $paused->suspend();

        $this->post('/login', ['email' => $otherUser->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($otherUser);
        auth()->logout();

        $paused->unsuspend();
        $this->post('/login', ['email' => $pausedUser->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($pausedUser);
    }

    public function test_already_signed_in_user_is_bounced_to_login_on_next_request(): void
    {
        $this->roles();
        $agency = Agency::create(['name' => 'Paused Co', 'slug' => 'paused-co']);
        $user = $this->agentFor($agency);
        $this->actingAs($user);
        $agency->suspend();

        $this->get('/corex')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_system_owner_is_never_blocked(): void
    {
        $this->roles();
        Agency::create(['name' => 'Paused Co', 'slug' => 'paused-co'])->suspend();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => true, 'password' => bcrypt('secret-pass-1')]);

        $this->post('/login', ['email' => $owner->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_toggle_is_owner_only_and_reversible(): void
    {
        $this->roles();
        $agency = Agency::create(['name' => 'Paused Co', 'slug' => 'paused-co']);

        $agent = $this->agentFor($agency);
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => true]);

        $this->actingAs($agent)
            ->post(route('agencies.toggle-suspend', $agency))
            ->assertForbidden();
        $this->assertFalse($agency->fresh()->isSuspended());

        $this->actingAs($owner)->post(route('agencies.toggle-suspend', $agency))->assertRedirect(route('agencies.index'));
        $this->assertTrue($agency->fresh()->isSuspended());

        $this->actingAs($owner)->post(route('agencies.toggle-suspend', $agency));
        $this->assertFalse($agency->fresh()->isSuspended());
    }
}
