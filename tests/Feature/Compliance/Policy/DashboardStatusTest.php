<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.6 — the register computes per-staff status and tallies
 * correctly across a mixed cohort for the selected policy.
 */
final class DashboardStatusTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    public function test_register_tallies_a_mixed_staff_cohort(): void
    {
        $agencyId = $this->seedAgency();

        // Acting officer is also counted — make it the single "valid" staffer.
        $officer = $this->makeUser($agencyId);
        $this->actingAs($officer);

        $inProgressUser = $this->makeUser($agencyId, 'agent');
        $expiredUser    = $this->makeUser($agencyId, 'agent');
        $notStartedUser = $this->makeUser($agencyId, 'agent');

        $policy = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $version = $this->makeVersion($policy, 1, 'active');

        // valid
        $this->completeAck($officer, $version);
        // in_progress (confirmed but not signed)
        $this->confirmedAck($inProgressUser, $version);
        // expired (completed, validity in the past)
        $expiredAck = $this->completeAck($expiredUser, $version);
        $expiredAck->update(['valid_until' => now()->subDay()]);
        // notStartedUser: no ack

        $response = $this->get(route('compliance.policy.dashboard.index', ['policy' => 'comms']));
        $response->assertOk();

        $this->assertSame(4, $response->viewData('totalStaff'));
        $this->assertSame(1, $response->viewData('validCount'));
        $this->assertSame(1, $response->viewData('inProgressCount'));
        $this->assertSame(1, $response->viewData('expiredCount'));
        $this->assertSame(1, $response->viewData('neverStartedCount'));

        // Per-user statuses are independently correct.
        $byUser = collect($response->viewData('staffData'))
            ->mapWithKeys(fn ($row) => [$row['user']->id => $row['status']]);

        $this->assertSame('valid', $byUser[$officer->id]);
        $this->assertSame('in_progress', $byUser[$inProgressUser->id]);
        $this->assertSame('expired', $byUser[$expiredUser->id]);
        $this->assertSame('not_started', $byUser[$notStartedUser->id]);
    }
}
