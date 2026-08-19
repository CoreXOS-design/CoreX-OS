<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\ProspectingClaim;
use App\Services\Prospecting\ProspectingClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MIC funnel phase 2 — ProspectingClaimService stale-claim state machine (Johan 2026-08-13).
 * Working resets the timer + clears warn; release captures a structured reason; BM reassign moves
 * the agent + resets; BM keep resets.
 *
 * RefreshDatabase — runs on Johan's dev-check; behaviour also verified on the QA1 runtime.
 */
final class StaleClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private function claimRow(array $overrides = []): int
    {
        return (int) DB::table('prospecting_claims')->insertGetId(array_merge([
            'agency_id'       => 1,
            'prospecting_listing_id' => 1,
            'user_id'         => 10,
            'status'          => 'contacted',
            'claimed_at'      => now()->subDays(9),
            'pitched_at'      => now()->subDays(9),
            'last_updated_at' => now()->subDays(9),
            'warned_at'       => now()->subDays(2),
            'is_active'       => true,
            'created_at'      => now()->subDays(9), 'updated_at' => now(),
        ], $overrides));
    }

    private function svc(): ProspectingClaimService
    {
        return app(ProspectingClaimService::class);
    }

    public function test_working_the_claim_clears_the_warn_and_bumps_timer(): void
    {
        $id = $this->claimRow();
        $this->svc()->recordActionOnClaim(ProspectingClaim::find($id), 'contacted', 'Called the owner');
        $c = ProspectingClaim::find($id);
        $this->assertNull($c->warned_at, 'working clears the warn so it re-arms');
        $this->assertTrue($c->last_updated_at->greaterThan(now()->subMinute()), 'timer reset to now');
    }

    public function test_release_captures_structured_reason(): void
    {
        $id = $this->claimRow();
        $this->svc()->releaseClaim($id, 10, 'No address could be established', 'no_address');
        $c = ProspectingClaim::find($id);
        $this->assertFalse((bool) $c->is_active);
        $this->assertNotNull($c->released_at);
        $this->assertSame('no_address', $c->release_reason);
        $this->assertStringContainsString('RELEASED', (string) $c->notes);
    }

    public function test_reassign_moves_agent_and_resets_timer(): void
    {
        $id = $this->claimRow(['user_id' => 10]);
        [$updated, $oldUserId] = $this->svc()->reassignClaim($id, 20, 99);
        $this->assertSame(10, $oldUserId);
        $c = ProspectingClaim::find($id);
        $this->assertSame(20, (int) $c->user_id, 'owner changed to the new agent');
        $this->assertNull($c->warned_at, 'reassign resets the warn');
        $this->assertTrue($c->is_active);
        $this->assertStringContainsString('REASSIGNED', (string) $c->notes);
    }

    public function test_keep_resets_timer_without_changing_agent(): void
    {
        $id = $this->claimRow(['user_id' => 10]);
        $this->svc()->keepClaim($id, 99);
        $c = ProspectingClaim::find($id);
        $this->assertSame(10, (int) $c->user_id, 'agent unchanged');
        $this->assertNull($c->warned_at, 'keep resets the warn');
        $this->assertStringContainsString('KEPT', (string) $c->notes);
    }
}
