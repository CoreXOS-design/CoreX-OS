<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.1 — publishing a new version re-fires sign-off:
 * a completed ack against v1 must NOT satisfy the newly-active v2.
 */
final class VersionSupersessionTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    public function test_approving_a_new_version_reverts_signed_staff_to_not_started(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policy = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $v1 = $this->makeVersion($policy, 1, 'active');

        // User signs v1 → valid.
        $this->completeAck($user, $v1);
        $this->assertSame('valid', $user->policyAcknowledgementStatus('comms'));

        // CO publishes v2.
        $v2 = $this->makeVersion($policy, 2, 'draft');
        $v2->approve($user, 'Director', null, 'v2 publish');

        // v1 superseded, v2 active, only one active per policy.
        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame('active', $v2->fresh()->status);
        $this->assertSame((int) $v2->id, (int) $v1->fresh()->superseded_by_version_id);

        // The previously-valid user now has nothing for the active version.
        $this->assertSame('not_started', $user->policyAcknowledgementStatus('comms'));
    }
}
