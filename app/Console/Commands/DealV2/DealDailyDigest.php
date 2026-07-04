<?php

namespace App\Console\Commands\DealV2;

use App\Mail\DealV2\DealDailyDigestMail;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\DealV2\DealV2;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * AT-158 DR2 WS6 — the per-user morning digest (07:00).
 *
 * One email per responsible agent summarising their pipeline: steps due today,
 * overdue steps, steps that turned amber/red, and deals registered yesterday.
 * Deal-centric single pass → fan out to each deal's responsible agents. Only
 * users with content AND an email preference receive a mail. System-scope.
 */
class DealDailyDigest extends Command
{
    protected $signature = 'deals:daily-digest {--user= : Limit to a single user id (debug)} {--dry-run}';

    protected $description = 'Send each agent a morning digest of their deal pipeline (due today, overdue, amber/red, registered yesterday).';

    public function handle(): int
    {
        $today     = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Per-user accumulator: [userId => ['overdue'=>[], 'due_today'=>[], 'amber_red'=>[], 'registered_yesterday'=>[]]]
        $buckets = [];
        $add = function (int $uid, string $section, array $row) use (&$buckets) {
            $buckets[$uid] ??= ['overdue' => [], 'due_today' => [], 'amber_red' => [], 'registered_yesterday' => []];
            $buckets[$uid][$section][] = $row;
        };

        DealV2::withoutGlobalScopes()
            ->whereIn('status', ['active', 'granted'])
            ->with(['stepInstances', 'listingAgent', 'sellingAgent', 'agents'])
            ->chunkById(200, function ($deals) use ($add, $today, $yesterday) {
                foreach ($deals as $deal) {
                    $agentIds = $this->responsibleAgentIds($deal);
                    if (empty($agentIds)) {
                        continue;
                    }
                    $ref = $deal->reference ?: ('Deal #' . $deal->id);

                    foreach ($deal->stepInstances as $step) {
                        if (! in_array($step->status, ['active', 'overdue'], true)) {
                            continue;
                        }
                        $due = $step->due_date?->toDateString();
                        $row = ['deal' => $ref, 'deal_id' => $deal->id, 'step' => $step->name, 'due' => $due, 'rag' => $step->current_rag];

                        if ($step->status === 'overdue') {
                            foreach ($agentIds as $uid) { $add($uid, 'overdue', $row); }
                        } elseif ($due === $today) {
                            foreach ($agentIds as $uid) { $add($uid, 'due_today', $row); }
                        } elseif (in_array($step->current_rag, ['amber', 'red'], true)) {
                            foreach ($agentIds as $uid) { $add($uid, 'amber_red', $row); }
                        }
                    }

                    if ($deal->actual_registration?->toDateString() === $yesterday) {
                        foreach ($agentIds as $uid) {
                            $add($uid, 'registered_yesterday', ['deal' => $ref, 'deal_id' => $deal->id]);
                        }
                    }
                }
            });

        $onlyUser = $this->option('user') ? (int) $this->option('user') : null;
        $sent = 0;

        foreach ($buckets as $uid => $sections) {
            if ($onlyUser && $uid !== $onlyUser) {
                continue;
            }
            if (! array_filter($sections)) {
                continue; // nothing to say
            }
            $user = User::withoutGlobalScopes()->find($uid);
            if (! $user || ! $user->is_active || ! $user->email) {
                continue;
            }
            if (! (UserDashboardSetting::getEffective($user)->notify_email ?? true)) {
                continue; // respects the user's email preference
            }

            if ($this->option('dry-run')) {
                $this->line("would send digest → {$user->email} (" . collect($sections)->map(fn ($s) => count($s))->sum() . ' items)');
                $sent++;
                continue;
            }

            Mail::to($user->email)->send(new DealDailyDigestMail($user, $sections));
            $sent++;
        }

        $this->info("deals:daily-digest — {$sent} digest(s) " . ($this->option('dry-run') ? 'would be sent.' : 'sent.'));

        return self::SUCCESS;
    }

    private function responsibleAgentIds(DealV2 $deal): array
    {
        return collect([$deal->listing_agent_id, $deal->selling_agent_id])
            ->merge($deal->agents->pluck('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
