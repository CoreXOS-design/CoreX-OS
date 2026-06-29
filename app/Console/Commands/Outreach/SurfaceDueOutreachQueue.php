<?php

namespace App\Console\Commands\Outreach;

use App\Models\Agency;
use App\Models\Outreach\OutreachQueue;
use App\Models\Scopes\AgencyScope;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-117 §5 — surface due outreach-queue rows for the agent to work.
 *
 * Mirrors the RetryDueWebhookDeliveries claim-and-sweep (the next_retry_at
 * pattern): pick due rows, CLAIM each before processing so a concurrent sweep
 * can never double-surface, then transition. The claim here is an ATOMIC
 * conditional UPDATE (claimed_at IS NULL AND status = pending) wrapped in a
 * per-row transaction — if the transition fails the claim rolls back and the row
 * is retried next minute (no stuck half-claimed rows).
 *
 * Consent is RE-CHECKED at surface via MarketingConsentService::canMarketTo()
 * (§4b) — anyone who opted out / was suppressed between queueing and the due-time
 * is DROPPED with the reason, never shown to the agent. canMarketTo is the only
 * consent gate; no parallel checks.
 *
 * Runs cross-agency (system sweep, no auth context — the documented console
 * escape hatch from withoutGlobalScope); each row's consent is evaluated against
 * its own contact's agency, so tenancy stays correct.
 *
 * Also expires (§8) surfaced-but-never-sent rows from a PRIOR day so a stale row
 * can't be sent days late to a now-possibly-opted-out contact.
 */
class SurfaceDueOutreachQueue extends Command
{
    protected $signature = 'outreach:surface-due {--limit=500}';
    protected $description = 'Surface due outreach-queue rows (claim, re-check consent, surface/drop) + expire stale surfaced rows';

    public function handle(MarketingConsentService $consent): int
    {
        $now = now();
        $surfaced = 0;
        $dropped = 0;

        $due = OutreachQueue::withoutGlobalScope(AgencyScope::class)
            ->due($now)                 // status = pending AND due_at <= now
            ->whereNull('claimed_at')
            ->orderBy('due_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $row) {
            try {
                DB::transaction(function () use ($row, $consent, $now, &$surfaced, &$dropped) {
                    // ATOMIC CLAIM — only one sweep wins this row. The conditional
                    // UPDATE row-locks; a concurrent sweep's UPDATE blocks then sees
                    // claimed_at set and affects 0 rows.
                    $claimed = OutreachQueue::withoutGlobalScope(AgencyScope::class)
                        ->whereKey($row->id)
                        ->whereNull('claimed_at')
                        ->where('status', OutreachQueue::STATUS_PENDING)
                        ->update(['claimed_at' => $now]);

                    if ($claimed !== 1) {
                        return; // lost the race / already handled
                    }

                    $fresh = OutreachQueue::withoutGlobalScope(AgencyScope::class)->find($row->id);
                    if (!$fresh) {
                        return;
                    }

                    // Contact archived/gone → drop gracefully, never crash.
                    $contact = $fresh->contact; // BelongsTo excludes soft-deleted
                    if (!$contact) {
                        $fresh->forceFill([
                            'status'         => OutreachQueue::STATUS_DROPPED,
                            'dropped_reason' => 'contact_unavailable',
                        ])->save();
                        $dropped++;
                        return;
                    }

                    // §4b — THE consent gate, re-evaluated at surface.
                    if ($consent->canMarketTo($contact, $fresh->channel)) {
                        $fresh->forceFill([
                            'status'      => OutreachQueue::STATUS_SURFACED,
                            'surfaced_at' => $now,
                        ])->save();
                        $surfaced++;
                    } else {
                        $fresh->forceFill([
                            'status'         => OutreachQueue::STATUS_DROPPED,
                            'dropped_reason' => $consent->marketingBlockReason($contact, $fresh->channel) ?? 'not_marketable',
                        ])->save();
                        $dropped++;
                    }
                });
            } catch (\Throwable $e) {
                // The per-row transaction rolled back (claim reverted) → retried next
                // sweep. Log and continue; one bad row never aborts the whole sweep.
                Log::warning('outreach:surface-due row failed', ['queue_id' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        // §8 — expire surfaced-but-never-sent rows past their AGENCY's expiry cutoff
        // (agency-configurable: outreach_queue_expiry_hours; NULL = end of the
        // surfaced day). Per-agency because the cutoff is agency-specific.
        $expired = 0;
        $agencyIds = OutreachQueue::withoutGlobalScope(AgencyScope::class)
            ->where('status', OutreachQueue::STATUS_SURFACED)
            ->distinct()
            ->pluck('agency_id');
        foreach ($agencyIds as $aid) {
            $agency = Agency::find($aid);
            if (!$agency) {
                continue;
            }
            $expired += OutreachQueue::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $aid)
                ->where('status', OutreachQueue::STATUS_SURFACED)
                ->where('surfaced_at', '<', $agency->outreachQueueExpiryCutoff($now))
                ->update(['status' => OutreachQueue::STATUS_EXPIRED]);
        }

        $this->info("Outreach sweep: surfaced={$surfaced} dropped={$dropped} expired={$expired} (due candidates={$due->count()}).");

        return self::SUCCESS;
    }
}
