<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\ClientUser;
use App\Models\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-508 (P1) - a tenant / owner registers their phone for push. .ai/specs/at-508-mobile-rentals.md section 4 P1.
 */
final class ClientDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $email): ClientUser
    {
        $agency = Agency::create(['name' => 'Push Agency ' . $email, 'slug' => 'pa-' . uniqid()]);

        return ClientUser::create(['email' => $email, 'current_agency_id' => $agency->id]);
    }

    public function test_registering_the_same_token_twice_keeps_one_row(): void
    {
        $cu = $this->client('a@example.test');
        Sanctum::actingAs($cu, ['client']);

        $this->postJson('/api/v1/client/device-tokens', ['platform' => 'ios', 'token' => 'tok-1'])->assertOk();
        $this->postJson('/api/v1/client/device-tokens', ['platform' => 'ios', 'token' => 'tok-1', 'app_version' => '2.0'])->assertOk();

        $this->assertSame(1, DeviceToken::where('client_user_id', $cu->id)->count());
        $this->assertNull(DeviceToken::where('client_user_id', $cu->id)->first()->user_id);
    }

    public function test_a_handset_belongs_to_one_person_after_a_relogin(): void
    {
        $first = $this->client('first@example.test');
        $second = $this->client('second@example.test');

        Sanctum::actingAs($first, ['client']);
        $this->postJson('/api/v1/client/device-tokens', ['platform' => 'android', 'token' => 'shared'])->assertOk();
        Sanctum::actingAs($second, ['client']);
        $this->postJson('/api/v1/client/device-tokens', ['platform' => 'android', 'token' => 'shared'])->assertOk();

        $this->assertSame(0, DeviceToken::where('client_user_id', $first->id)->count());
        $this->assertSame(1, DeviceToken::where('client_user_id', $second->id)->count());
    }

    public function test_unregister_removes_only_my_row_and_validation_is_enforced(): void
    {
        $mine = $this->client('mine@example.test');
        $other = $this->client('other@example.test');
        DeviceToken::create(['client_user_id' => $other->id, 'platform' => 'ios', 'token' => 'theirs']);
        DeviceToken::create(['client_user_id' => $mine->id, 'platform' => 'ios', 'token' => 'tok-mine']);

        Sanctum::actingAs($mine, ['client']);
        $this->postJson('/api/v1/client/device-tokens', ['platform' => 'fax', 'token' => 'x'])->assertStatus(422);
        $this->deleteJson('/api/v1/client/device-tokens/theirs')->assertOk();
        $this->deleteJson('/api/v1/client/device-tokens/tok-mine')->assertOk();

        $this->assertSame(1, DeviceToken::where('token', 'theirs')->count(), 'another person\'s device must be untouched');
        $this->assertSame(0, DeviceToken::where('token', 'tok-mine')->count());
    }

    public function test_push_service_finds_client_devices(): void
    {
        $cu = $this->client('push@example.test');
        DeviceToken::create(['client_user_id' => $cu->id, 'platform' => 'ios', 'token' => 'tok-push']);

        $summary = app(\App\Services\Push\PushNotificationService::class)
            ->sendToClientUserIds([$cu->id], 'test|1', ['title' => 't', 'body' => 'b']);

        $this->assertSame(1, $summary->requested);
    }
}
