<?php

namespace Tests\Feature\Push;

use App\Models\Agency;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Device-token registration must yield ONE active row per physical device, so
 * the agency-wide fan-out never resolves the same token twice. Also locks the
 * soft-delete revival path that previously crashed (a re-registered, soft-deleted
 * token forced an INSERT that violated the (user_id, token) unique index).
 */
class DeviceTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(User $user, string $token, string $platform = 'android'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/device-tokens', [
            'platform' => $platform,
            'token'    => $token,
        ]);
    }

    public function test_repeated_registration_is_idempotent(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $this->register($user, 'tok-A')->assertOk();
        $this->register($user, 'tok-A')->assertOk();
        $this->register($user, 'tok-A')->assertOk();

        $this->assertSame(1, DeviceToken::where('user_id', $user->id)->where('token', 'tok-A')->count());
    }

    public function test_reregistering_a_soft_deleted_token_revives_it_without_crashing(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $this->register($user, 'tok-A')->assertOk();
        DeviceToken::where('user_id', $user->id)->where('token', 'tok-A')->delete(); // device unregisters

        $this->register($user, 'tok-A')->assertOk(); // would 500 on unique-index without the withTrashed revive

        $this->assertSame(1, DeviceToken::where('user_id', $user->id)->where('token', 'tok-A')->count());
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => 'tok-A', 'deleted_at' => null]);
    }

    public function test_same_token_under_a_new_user_supersedes_the_old_active_row(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $userA  = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        $userB  = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        // Same physical handset: user A logs out, user B logs in — same FCM token.
        $this->register($userA, 'shared-phone')->assertOk();
        $this->register($userB, 'shared-phone')->assertOk();

        $active = DeviceToken::where('token', 'shared-phone')->get(); // excludes trashed
        $this->assertCount(1, $active, 'one active row per physical device');
        $this->assertSame($userB->id, $active->first()->user_id);
    }
}
