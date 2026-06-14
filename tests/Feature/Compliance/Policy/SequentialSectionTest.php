<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.3 — the wizard is strictly sequential: a step cannot be
 * skipped, and the signature page is unreachable until every required
 * section is confirmed.
 */
final class SequentialSectionTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    public function test_cannot_skip_ahead_or_reach_sign_until_all_sections_confirmed(): void
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policy = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $this->makeVersion($policy, 1, 'active', requiredSections: 2);

        // Start → lands on step 1.
        $this->post(route('policy.ack.start', 'comms'))
            ->assertRedirect(route('policy.ack.step', ['comms', 1]));

        // Cannot jump to step 2 before confirming step 1 → bounced to step 1.
        $this->get(route('policy.ack.step', ['comms', 2]))
            ->assertRedirect(route('policy.ack.step', ['comms', 1]));

        // Cannot reach the signature page yet → bounced back to the wizard.
        $this->get(route('policy.ack.sign', 'comms'))
            ->assertRedirect(route('policy.ack.step', ['comms', 1]));

        // Confirm section 1 → next step is 2, not done yet.
        $r1 = $this->post(route('policy.ack.confirm', ['comms', 1]));
        $r1->assertOk()->assertJson(['success' => true, 'all_done' => false]);
        $this->assertSame(route('policy.ack.step', ['comms', 2]), $r1->json('next_url'));

        // Confirm section 2 → all done, next is the signature page.
        $r2 = $this->post(route('policy.ack.confirm', ['comms', 2]));
        $r2->assertOk()->assertJson(['success' => true, 'all_done' => true]);
        $this->assertSame(route('policy.ack.sign', 'comms'), $r2->json('next_url'));

        // Now the signature page is reachable.
        $this->get(route('policy.ack.sign', 'comms'))->assertOk();
    }
}
