<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use App\Models\Compliance\PolicyAcknowledgement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.4 — complete() stamps a one-year validity window and the
 * status/scope helpers reflect valid → expired correctly.
 */
final class AcknowledgementValidityTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    public function test_complete_stamps_one_year_validity_and_is_valid_now(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policy = $this->makePolicy($agencyId, 'comms');
        $version = $this->makeVersion($policy, 1, 'active');

        $ack = $this->completeAck($user, $version);

        $this->assertSame('completed', $ack->status);
        $this->assertNotNull($ack->completed_at);
        $this->assertTrue(
            $ack->valid_until->isSameDay($ack->completed_at->copy()->addYear()),
            'valid_until must be exactly one year after completion'
        );
        $this->assertTrue($ack->isValid());
        $this->assertSame('valid', $user->policyAcknowledgementStatus('comms'));

        // scopeValid finds it now.
        $this->assertSame(1, PolicyAcknowledgement::where('policy_id', $policy->id)->valid()->count());
    }

    public function test_expired_validity_flips_is_valid_and_status(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policy = $this->makePolicy($agencyId, 'comms');
        $version = $this->makeVersion($policy, 1, 'active');
        $ack = $this->completeAck($user, $version);

        // Force the validity window into the past.
        $ack->update(['valid_until' => now()->subDay()]);
        $ack = $ack->fresh();

        $this->assertFalse($ack->isValid());
        $this->assertTrue($ack->isComplete());
        $this->assertSame('expired', $user->policyAcknowledgementStatus('comms'));
        $this->assertSame(0, PolicyAcknowledgement::where('policy_id', $policy->id)->valid()->count());
    }
}
