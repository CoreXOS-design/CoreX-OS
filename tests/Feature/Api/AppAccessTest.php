<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App Access — mobile "Delete my account" (Apple 5.1.1(v)).
 *
 * Spec: .ai/specs/mobile-app-access.md
 */
final class AppAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(array $attributes = []): User
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);

        return User::factory()->create(array_merge([
            'agency_id' => $agency->id,
            'role'      => 'agent',
            'is_active' => true,
            'password'  => 'a-real-password-123',
        ], $attributes));
    }

    public function test_login_succeeds_normally_when_app_access_is_on(): void
    {
        $user = $this->makeAgent();

        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'a-real-password-123',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_is_refused_with_account_deleted_after_app_access_is_revoked(): void
    {
        $user = $this->makeAgent();
        $user->revokeAppAccess();

        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'a-real-password-123',
        ])->assertStatus(403)->assertJson(['code' => 'account_deleted']);
    }

    public function test_wrong_password_still_wins_over_a_revoked_account(): void
    {
        $user = $this->makeAgent();
        $user->revokeAppAccess();

        // The password check must run BEFORE the app-access check, so a wrong
        // guess can never be used to probe whether an account was deleted.
        $this->postJson('/api/v1/login', [
            'email'    => $user->email,
            'password' => 'totally-wrong',
        ])->assertStatus(401)->assertJson(['code' => 'invalid_password']);
    }

    public function test_delete_account_requires_the_correct_password(): void
    {
        $user  = $this->makeAgent();
        $token = $user->createToken('corex-mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'wrong'])
            ->assertStatus(422)->assertJson(['code' => 'invalid_password']);

        $this->assertTrue($user->fresh()->hasAppAccess());
    }

    public function test_delete_account_with_correct_password_turns_off_app_access(): void
    {
        $user  = $this->makeAgent();
        $token = $user->createToken('corex-mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'a-real-password-123'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasAppAccess());
        $this->assertNotNull($user->fresh()->app_access_revoked_at);
    }

    public function test_delete_account_leaves_other_named_tokens_untouched(): void
    {
        $user        = $this->makeAgent();
        $mobileToken = $user->createToken('corex-mobile')->plainTextToken;
        $user->createToken('chrome-extension-unrelated');

        $this->withHeader('Authorization', "Bearer {$mobileToken}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'a-real-password-123'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->where('name', 'corex-mobile')->count());
        $this->assertSame(1, $user->tokens()->where('name', 'chrome-extension-unrelated')->count());
    }

    public function test_delete_account_clears_push_device_tokens(): void
    {
        $user  = $this->makeAgent();
        $token = $user->createToken('corex-mobile')->plainTextToken;
        DeviceToken::create(['user_id' => $user->id, 'platform' => 'ios', 'token' => 'push-tok-1']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'a-real-password-123'])
            ->assertOk();

        $this->assertSame(0, DeviceToken::where('user_id', $user->id)->count());
    }

    public function test_delete_account_is_idempotent(): void
    {
        $user  = $this->makeAgent();
        $token = $user->createToken('corex-mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'a-real-password-123'])
            ->assertOk();

        // Second attempt with a fresh token (the first was deleted by the first call).
        $secondToken = $user->createToken('corex-mobile')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$secondToken}")
            ->deleteJson('/api/v1/me/app-access', ['password' => 'a-real-password-123'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasAppAccess());
    }

    public function test_an_already_issued_token_stops_working_on_the_very_next_request_after_revoke(): void
    {
        $user  = $this->makeAgent();
        $token = $user->createToken('corex-mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/theme')
            ->assertOk();

        // Revoke via a direct write so the still-held $token above isn't deleted
        // by revokeAppAccess() itself — this proves the standing middleware check,
        // not just "the token got deleted".
        $user->forceFill(['app_access_revoked_at' => now()])->save();

        // Sanctum's guard caches the resolved user for the lifetime of a single
        // test method's requests — a real second HTTP request in production
        // always re-resolves the user fresh, but a second in-test call here
        // would otherwise see the stale (pre-revoke) in-memory user. Force it.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/theme')
            ->assertStatus(403);
    }

    public function test_a_revoked_agent_can_still_use_a_normal_web_session(): void
    {
        $user = $this->makeAgent();
        $user->revokeAppAccess();

        // The sanctum-only gate must never touch the web guard, and the Tools
        // tab must show the disabled state with a way to turn it back on.
        $this->actingAs($user)->get(route('agent.portal'))
            ->assertOk()
            ->assertSee('App Access')
            ->assertSee('Turn App Access back on');
    }

    public function test_my_portal_shows_app_access_enabled_by_default(): void
    {
        $user = $this->makeAgent();

        $this->actingAs($user)->get(route('agent.portal'))
            ->assertOk()
            ->assertSee('App Access')
            ->assertDontSee('Turn App Access back on');
    }

    public function test_agent_can_restore_app_access_themselves_from_my_portal(): void
    {
        $user = $this->makeAgent();
        $user->revokeAppAccess();

        $this->actingAs($user)
            ->post(route('agent.portal.app-access.restore'))
            ->assertRedirect();

        $this->assertTrue($user->fresh()->hasAppAccess());
    }
}
