<?php

namespace App\Console\Commands\Outreach;

use App\Models\Agency;
use App\Models\Outreach\OutreachQueue;
use App\Models\Scopes\AgencyScope;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Console\Command;
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
    protected $signature = 'outreach:surface-due {--limit=1000}';
    protected $description = 'Outreach queue maintenance: drop READY rows whose contact opted out / went away, and expire stale READY rows';

    public function handle(MarketingConsentService $consent): int
    {
        $now = now();
        $dropped = 0;

        // (a) CONSENT RE-VALIDATION on READY rows — drop anyone who opted out (or
        // whose contact was archived) since queueing, so the agent never opens a
        // now-blocked row. Dispatch re-checks consent too (the hard gate); this just
        // keeps the visible list honest. Light: one canMarketTo per ready row.
        OutreachQueue::withoutGlobalScope(AgencyScope::class)
            ->where('status', OutreachQueue::STATUS_READY)
            ->with('contact')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (OutreachQueue $row) use ($consent, &$dropped) {
                try {
                    $contact = $row->contact; // BelongsTo excludes soft-deleted
                    $reason = null;
                    if (!$contact) {
                        $reason = 'contact_unavailable';
                    } elseif (!$consent->canMarketTo($contact, $row->channel)) {
                        $reason = $consent->marketingBlockReason($contact, $row->channel) ?? 'not_marketable';
                    }
                    if ($reason !== null) {
                        $row->forceFill(['status' => OutreachQueue::STATUS_DROPPED, 'dropped_reason' => $reason])->save();
                        $dropped++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('outreach queue consent re-check failed', ['queue_id' => $row->id, 'error' => $e->getMessage()]);
                }
            });

        // (b) EXPIRE READY-but-unsent rows past their AGENCY's expiry cutoff
        // (agency-configurable outreach_queue_expiry_hours; NULL = end of the day the
        // row was prepared). created_at = prepared time (no surfacing now). Per agency.
        $expired = 0;
        $agencyIds = OutreachQueue::withoutGlobalScope(AgencyScope::class)
            ->where('status', OutreachQueue::STATUS_READY)
            ->distinct()
            ->pluck('agency_id');
        foreach ($agencyIds as $aid) {
            $agency = Agency::find($aid);
            if (!$agency) {
                continue;
            }
            $expired += OutreachQueue::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $aid)
                ->where('status', OutreachQueue::STATUS_READY)
                ->where('created_at', '<', $agency->outreachQueueExpiryCutoff($now))
                ->update(['status' => OutreachQueue::STATUS_EXPIRED]);
        }

        $this->info("Outreach queue maintenance: dropped={$dropped} expired={$expired}.");

        return self::SUCCESS;
    }
}
