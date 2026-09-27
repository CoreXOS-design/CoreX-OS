<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-423 — One email (sub-users). Spec: .ai/specs/one-email-sub-users.md §7.
 *
 * A sub-user's sign-in "email" is a username (andre@hfcoastal). The portals must be
 * sent the address that actually reaches them — the shared inbox — never the username,
 * and a normal agent's payload must be byte-for-byte what it was (same address), so the
 * P24 profile fingerprint does not change and no extra refresh call is triggered.
 */
final class OneEmailAgentAddressTest extends TestCase
{
    use RefreshDatabase;

    private function profilePayload(User $user): array
    {
        $method = new \ReflectionMethod(Property24SyndicationService::class, 'agentProfilePayload');
        $method->setAccessible(true);

        return $method->invoke(app(Property24SyndicationService::class), $user, 12345, 678);
    }

    public function test_a_sub_user_is_sent_to_property24_with_the_shared_inbox(): void
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $main = User::factory()->create(['email' => 'agents@hfcoastal.co.za', 'agency_id' => $agency->id]);
        $agency->forceFill(['one_email_enabled' => true, 'one_email_user_id' => $main->id])->save();
        Agency::forgetFindMemo();

        $andre = User::factory()->create([
            'name' => 'Andre Roets', 'email' => 'andre@hfcoastal', 'agency_id' => $agency->id,
            'cell' => '082 555 0147',
        ]);
        $andre->forceFill(['is_sub_user' => true])->save();

        $this->assertSame('agents@hfcoastal.co.za', $this->profilePayload($andre->fresh())['emailAddress']);
    }

    public function test_a_normal_agent_payload_is_unchanged(): void
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $sipho = User::factory()->create([
            'name' => 'Sipho Ngcobo', 'email' => 'sipho.ngcobo@hfcoastal.co.za', 'agency_id' => $agency->id,
            'cell' => '083 555 0123', 'display_email' => 'sipho.sales@gmail.com',
        ]);

        // Still the sign-in email (display_email is not used on this feed — unchanged behaviour).
        $this->assertSame('sipho.ngcobo@hfcoastal.co.za', $this->profilePayload($sipho)['emailAddress']);
    }
}
