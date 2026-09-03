<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — every one of the 132 buyer-pipeline contacts
 * (is_buyer=true, buyer_state set) had agent_id=NULL, so every card on
 * /command-center/buyers/pipeline reads "Unassigned" — a pipeline where no
 * buyer has an agent working them fails the "living system" test hard, at
 * a glance, board-wide (found while checking the flagship buyers' cards
 * specifically, but confirmed systemic before fixing broadly).
 *
 * Round-robins real agents/branch_managers across every currently-
 * unassigned buyer. Idempotent: only ever touches agent_id IS NULL rows —
 * never reassigns a buyer that already has a real agent (including one set
 * by an agent's own future demo action).
 */
final class DemoBuyerAgentAssignmentSeeder
{
    public function run(int $agencyId): array
    {
        $agents = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])
            ->orderBy('id')
            ->get(['id', 'branch_id']);

        if ($agents->isEmpty()) {
            return ['updated' => 0, 'note' => "Skipped — agency {$agencyId} has no agents."];
        }

        $buyers = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->where('is_buyer', true)
            ->whereNotNull('buyer_state')
            ->whereNull('agent_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id']);

        $updated = 0;
        foreach ($buyers as $i => $buyer) {
            $agent = $agents[$i % $agents->count()];
            DB::table('contacts')->where('id', $buyer->id)->update([
                'agent_id' => $agent->id,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return ['updated' => $updated];
    }
}
