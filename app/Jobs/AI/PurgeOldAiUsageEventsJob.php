<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AI\AiUsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retention sweep for the append-only AI cost ledger — hard-deletes
 * `ai_usage_events` rows older than 13 months (a full rolling year + the
 * current month, for year-over-year reporting).
 *
 * DOCUMENTED EXCEPTION to CLAUDE.md non-negotiable #1 ("No hard deletes.
 * Ever."), mirroring PurgeOldSoftDeletedCacheJob. Rationale: ai_usage_events
 * is a derived cost ledger, not user/business data. There is no user-facing
 * restore path; 13-month-old per-call cost rows have no operational value once
 * the budgeting window they belong to has closed. The table carries no
 * SoftDeletes by design (append-only), so retention is a straight delete.
 *
 * Scheduled weekly via routes/console.php.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §3.2.8 (retention).
 */
class PurgeOldAiUsageEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETENTION_MONTHS = 13;

    public function handle(): void
    {
        $cutoff = now()->subMonthsNoOverflow(self::RETENTION_MONTHS);

        $count = AiUsageEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->delete();

        Log::info('PurgeOldAiUsageEventsJob complete', [
            'hard_deleted'     => $count,
            'cutoff'           => $cutoff->toIso8601String(),
            'retention_months' => self::RETENTION_MONTHS,
        ]);
    }
}
