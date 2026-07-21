<?php

namespace App\Console\Commands\Compliance;

use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * AT-236 — company-document expiry NOTIFIER (the active half; the ComplianceCalendarSource
 * remains the passive calendar feed).
 *
 * Daily scan of every agency's compliance provisions that carry an expiry date. Fires
 * TWO gateway events to the agency's admins / Compliance Officers:
 *   • compliance.document_expiring — once, when the doc enters its lead window
 *   • compliance.document_expired  — once, on/after the expiry date
 *
 * The LEAD is the doc-type's own `renewal_days` (agency-configurable in Compliance →
 * Agency Documents), defaulting to 30 days when a card has expiry but no lead set.
 *
 * Dedup is the dispatcher's job: a STABLE `threshold_hit_at` (the lead date, resp. the
 * expiry date) means each alert fires once per crossing per recipient, and re-fires only
 * when a replacement document moves the date. No per-row "notified" bookkeeping needed.
 */
class NotifyDocumentExpiries extends Command
{
    protected $signature = 'compliance:notify-document-expiries {--dry-run : Report what would fire without sending}';

    protected $description = 'Notify agency admins/CO of company documents entering their renewal window or past expiry (AT-236).';

    private const DEFAULT_LEAD_DAYS = 30;

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $today = Carbon::today();
        $dry = (bool) $this->option('dry-run');

        $provisions = AgencyComplianceProvision::withoutGlobalScopes()
            ->with('documentType')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotNull('effective_until')
            ->get();

        $recipientCache = [];
        $fired = 0;

        foreach ($provisions as $p) {
            $config = $p->documentType;
            if (! $config) {
                continue; // orphaned provision — nothing to describe it
            }

            $expiry = Carbon::parse($p->effective_until)->startOfDay();
            $lead   = $config->renewal_days ?: self::DEFAULT_LEAD_DAYS;
            $leadDate = $expiry->copy()->subDays($lead);

            $isExpired  = $today->greaterThanOrEqualTo($expiry);
            $isExpiring = ! $isExpired && $today->greaterThanOrEqualTo($leadDate);
            if (! $isExpired && ! $isExpiring) {
                continue; // still comfortably in date
            }

            $agencyId = (int) $p->agency_id;
            $recipients = $recipientCache[$agencyId] ??= $this->recipientsForAgency($agencyId);
            if ($recipients->isEmpty()) {
                continue;
            }

            $name = $config->name;
            if ($isExpired) {
                $eventKey  = 'compliance.document_expired';
                $threshold = $expiry;
                $title     = "{$name} — EXPIRED";
                $body      = "{$name} expired on {$expiry->format('Y-m-d')}. Upload a replacement in Company Documents.";
                $severity  = 'overdue';
            } else {
                $daysOut   = $today->diffInDays($expiry);
                $when      = $daysOut <= 0 ? 'today' : "in {$daysOut} day" . ($daysOut === 1 ? '' : 's');
                $eventKey  = 'compliance.document_expiring';
                $threshold = $leadDate;
                $title     = "{$name} — expires {$when}";
                $body      = "{$name} expires on {$expiry->format('Y-m-d')}. Renew before it lapses.";
                $severity  = 'warning';
            }

            foreach ($recipients as $user) {
                if ($dry) {
                    $this->line("[dry] {$eventKey} → {$user->email} :: {$title}");
                    $fired++;
                    continue;
                }
                $sent = $dispatcher->fire($user, $eventKey, $p, [
                    'title'            => $title,
                    'body'             => $body,
                    'subject_label'    => $name,
                    'action_url'       => '/corex/compliance/agency-settings',
                    'severity'         => $severity,
                    'threshold_hit_at' => $threshold,
                ]);
                if ($sent) {
                    $fired++;
                }
            }
        }

        $this->info(($dry ? '[dry-run] ' : '') . "compliance:notify-document-expiries — {$fired} notification(s) " . ($dry ? 'would fire' : 'dispatched') . '.');

        return self::SUCCESS;
    }

    /** Agency admins + Compliance Officers (manage_agency_compliance) for the agency. */
    private function recipientsForAgency(int $agencyId)
    {
        return User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('manage_agency_compliance'))
            ->values();
    }
}
