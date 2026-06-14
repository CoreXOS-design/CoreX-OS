<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use App\Models\Compliance\PolicyAcknowledgement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.2 — two policies are fully isolated: signing one does not
 * satisfy another, and re-publishing one never disturbs another.
 */
final class MultiPolicyIsolationTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    public function test_completed_ack_for_one_policy_does_not_satisfy_another(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policyA = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $policyB = $this->makePolicy($agencyId, 'social', 'Social Media Policy');
        $vA = $this->makeVersion($policyA, 1, 'active');
        $vB = $this->makeVersion($policyB, 1, 'active');

        $this->completeAck($user, $vA);

        $this->assertSame('valid', $user->policyAcknowledgementStatus('comms'));
        $this->assertSame('not_started', $user->policyAcknowledgementStatus('social'));

        // No ack rows leaked onto policy B.
        $this->assertSame(0, PolicyAcknowledgement::where('policy_id', $policyB->id)->count());
        $this->assertSame(1, PolicyAcknowledgement::where('policy_id', $policyA->id)->count());
    }

    public function test_publishing_a_new_version_of_one_policy_does_not_touch_another(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policyA = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $policyB = $this->makePolicy($agencyId, 'social', 'Social Media Policy');
        $vA1 = $this->makeVersion($policyA, 1, 'active');
        $vB1 = $this->makeVersion($policyB, 1, 'active');

        $this->completeAck($user, $vB1);
        $this->assertSame('valid', $user->policyAcknowledgementStatus('social'));

        // Publish v2 of policy A only.
        $vA2 = $this->makeVersion($policyA, 2, 'draft');
        $vA2->approve($user, 'Director', null, 'A v2');

        $this->assertSame('superseded', $vA1->fresh()->status);
        // Policy B's active version is untouched and its sign-off still valid.
        $this->assertSame('active', $vB1->fresh()->status);
        $this->assertSame('valid', $user->policyAcknowledgementStatus('social'));
        $this->assertSame('not_started', $user->policyAcknowledgementStatus('comms'));
    }
}
