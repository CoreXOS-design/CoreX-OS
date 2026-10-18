<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\Rentals\LeaseAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §17.6 — fills the owner's agent and the tenant's agent on leases that were created before leases
 * carried agents, by the SAME default rules a new lease uses (LeaseAgentService::defaultsForExistingLease).
 *
 * Soft: it only ever fills a side that is EMPTY — a side someone has chosen is never touched — writes through the query
 * builder (no model events, `updated_at` unchanged) and never deletes anything. Logged: one `lease_agents_backfilled` row in
 * each lease's history, naming the person and the rule used for each side it filled. Reversible: `--revert` empties exactly
 * the sides this command filled, and only while they still hold the value it wrote (a side changed since stays as chosen).
 * Reported: counts per rule, per side.
 *
 * A deploy never runs it — it is run by hand, `--dry-run` first, and `--agency=` limits it to one agency.
 */
class BackfillLeaseAgents extends Command
{
    protected $signature = 'leases:backfill-agents
        {--dry-run : Report what would be filled, write nothing}
        {--agency= : Only this agency id}
        {--lease= : Only this lease id}
        {--revert : Undo a previous back-fill (empties only what it filled and nobody has changed since)}';

    protected $description = "Fill every lease's owner's agent / tenant's agent by the default rules (dry-run first; reversible; logged)";

    public function handle(LeaseAgentService $agents): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Lease::withoutGlobalScopes()->withTrashed();
        if ($this->option('agency') !== null) {
            $query->where('agency_id', (int) $this->option('agency'));
        }
        if ($this->option('lease') !== null) {
            $query->where('id', (int) $this->option('lease'));
        }

        return $this->option('revert') ? $this->revert($query, $dryRun) : $this->fill($query, $agents, $dryRun);
    }

    private function fill($query, LeaseAgentService $agents, bool $dryRun): int
    {
        $report = ['scanned' => 0, 'filled_leases' => 0, 'already_set' => 0, 'nothing_to_fill' => 0, 'no_agent_found' => 0];
        $rules = ['owner' => [], 'tenant' => []];

        $query->chunkById(200, function ($leases) use ($agents, $dryRun, &$report, &$rules) {
            foreach ($leases as $lease) {
                $report['scanned']++;
                if ($lease->owner_agent_user_id !== null && $lease->tenant_agent_user_id !== null) {
                    $report['already_set']++;

                    continue;
                }

                $defaults = $agents->defaultsForExistingLease($lease);
                $set = [];
                $meta = [];
                foreach (LeaseAgentService::SIDES as $side) {
                    $column = LeaseAgentService::column($side);
                    if ($lease->{$column} !== null) {
                        continue;
                    }
                    $picked = $defaults[$side];
                    if ($picked['id'] === null) {
                        $rules[$side][LeaseAgentService::RULE_NONE] = ($rules[$side][LeaseAgentService::RULE_NONE] ?? 0) + 1;

                        continue;
                    }
                    $set[$column] = $picked['id'];
                    $meta[$side] = ['user_id' => $picked['id'], 'rule' => $picked['rule']];
                    $rules[$side][$picked['rule']] = ($rules[$side][$picked['rule']] ?? 0) + 1;
                }

                if ($set === []) {
                    $report['no_agent_found']++;

                    continue;
                }
                $report['filled_leases']++;
                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($lease, $set, $meta) {
                    DB::table('leases')->where('id', $lease->id)->update($set);
                    LeaseEvent::create([
                        'lease_id' => $lease->id,
                        'event_type' => LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED,
                        'description' => 'Agents set by the back-fill: ' . collect($meta)
                            ->map(fn (array $m, string $side) => LeaseAgentService::sideLabel($side) . ' user #' . $m['user_id'] . ' (' . $m['rule'] . ')')
                            ->implode('; '),
                        'actor_user_id' => null,
                        'metadata' => $meta,
                        'occurred_at' => now(),
                        'created_at' => now(),
                    ]);
                });
            }
        });

        $this->line(($dryRun ? 'DRY RUN — nothing written. ' : '') . 'Lease agents back-fill');
        $this->table(['', 'leases'], collect($report)->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        foreach ($rules as $side => $counts) {
            $this->line(LeaseAgentService::sideLabel($side) . ' — by rule:');
            $this->table(['rule', 'sides'], collect($counts)->map(fn ($n, $rule) => [$rule, $n])->values()->all());
        }

        return self::SUCCESS;
    }

    private function revert($query, bool $dryRun): int
    {
        $reverted = 0;
        $kept = 0;

        $query->chunkById(200, function ($leases) use ($dryRun, &$reverted, &$kept) {
            foreach ($leases as $lease) {
                $latest = LeaseEvent::where('lease_id', $lease->id)
                    ->where('event_type', LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED)
                    ->orderByDesc('id')
                    ->first();
                if (! $latest || ! empty($latest->metadata['reverted'])) {
                    continue;
                }

                $clear = [];
                foreach (LeaseAgentService::SIDES as $side) {
                    $wrote = $latest->metadata[$side]['user_id'] ?? null;
                    $column = LeaseAgentService::column($side);
                    // Only what the back-fill wrote and nobody has changed since.
                    $changedSince = LeaseEvent::where('lease_id', $lease->id)
                        ->where('event_type', LeaseEvent::TYPE_LEASE_AGENT_CHANGED)
                        ->where('id', '>', $latest->id)
                        ->get()
                        ->contains(fn (LeaseEvent $e) => ($e->metadata['side'] ?? null) === $side);
                    if ($wrote !== null && (int) $lease->{$column} === (int) $wrote && ! $changedSince) {
                        $clear[$column] = null;
                    } elseif ($wrote !== null) {
                        $kept++;
                    }
                }
                if ($clear === []) {
                    continue;
                }
                $reverted++;
                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($lease, $clear, $latest) {
                    DB::table('leases')->where('id', $lease->id)->update($clear);
                    LeaseEvent::create([
                        'lease_id' => $lease->id,
                        'event_type' => LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED,
                        'description' => 'Back-filled agents removed (reverted)',
                        'actor_user_id' => null,
                        'metadata' => ['reverted' => true, 'reverts_event_id' => $latest->id, 'cleared' => array_keys($clear)],
                        'occurred_at' => now(),
                        'created_at' => now(),
                    ]);
                });
            }
        });

        $this->line(($dryRun ? 'DRY RUN — nothing written. ' : '') . "Reverted {$reverted} lease(s); {$kept} side(s) left as they were because they changed after the back-fill.");

        return self::SUCCESS;
    }
}
