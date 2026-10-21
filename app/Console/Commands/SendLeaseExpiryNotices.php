<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Lease;
use App\Services\Features\AgencyFeatureService;
use App\Services\Rentals\LeaseExpiryNoticeService;
use Illuminate\Console\Command;

/**
 * AT-489 (Johan, 21 Oct 2026) - the agency's EXPIRY NOTICE to the tenant, on Consumer Protection Act law (s14(2)(c)): daily, per agency,
 * for every running fixed-term lease whose notice has not gone out.
 *   - On/after the agency's send day (default 60 business days before the lease ends) CoreX sends it, in the agency's own "Lease expiry
 *     notice" template, to the tenants, from the lease agent's own mailbox. No template / no tenant email: it cannot send, so it alerts instead.
 *   - Once the alert day is reached (default 45 business days left) and the notice still has not gone out, the lease's agents are told ONCE.
 *   - Fewer than 40 business days left and it has not gone out: the lease is flagged overdue (lease screen, Rentals Command Centre) - live, not stored.
 * Idempotent; never changes a lease's status; an agency with Leases switched off is left alone.
 *
 *   php artisan leases:expiry-notices --dry-run            what WOULD be sent / alerted, touching nothing
 *   php artisan leases:expiry-notices --lease=123          only that lease (real run)
 *   php artisan leases:expiry-notices --agency=5           only that agency (real run)
 */
class SendLeaseExpiryNotices extends Command
{
    protected $signature = 'leases:expiry-notices {--dry-run : List what would be sent or alerted; do nothing} {--agency= : Only this agency id} {--lease= : Only this lease id}';

    protected $description = 'Send the lease expiry notice to tenants on the agency\'s send day, and alert the lease agents when it has not gone out';

    public function handle(LeaseExpiryNoticeService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = $alerted = $skipped = 0;

        foreach (Agency::all() as $agency) {
            if ($this->option('agency') && (int) $this->option('agency') !== (int) $agency->id) {
                continue;
            }
            if (! app(AgencyFeatureService::class)->enabled('rental-leases', $agency)) {
                continue;
            }

            $leases = Lease::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('status', Lease::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('is_month_to_month')->orWhere('is_month_to_month', false))
                ->whereNotNull('end_date')
                ->whereNull('expiry_notice_sent_at')
                ->whereDate('end_date', '>=', now()->toDateString())
                ->whereDate('end_date', '<=', now()->addDays(220)->toDateString()) // 80 business days is about 112 calendar days; room for holidays
                ->when($this->option('lease'), fn ($q) => $q->whereKey((int) $this->option('lease')))
                ->orderBy('end_date')
                ->get();

            foreach ($leases as $lease) {
                $st = $service->status($lease);
                if (! in_array($st['state'], [LeaseExpiryNoticeService::STATE_DUE, LeaseExpiryNoticeService::STATE_ALERT, LeaseExpiryNoticeService::STATE_OVERDUE], true)) {
                    $skipped++;

                    continue;
                }
                $line = "lease #{$lease->id} (agency {$agency->id}) ends {$lease->end_date->format('Y-m-d')}, {$st['remaining']} business days left, state {$st['state']}";

                $reason = null;
                if ($dry) {
                    $tpl = $service->templateFor((int) $agency->id);
                    $this->line($tpl ? "  WOULD SEND: {$line}" : "  WOULD ALERT (no expiry notice template): {$line}");
                    continue;
                }
                $result = $service->sendNow($lease);
                if ($result['ok']) {
                    $this->line("  SENT: {$line}");
                    $sent++;
                    continue;
                }
                $reason = $result['reason'];
                // Could not send: tell the agents - once, and only from the alert day on (the agency's own setting).
                if (in_array($st['state'], [LeaseExpiryNoticeService::STATE_ALERT, LeaseExpiryNoticeService::STATE_OVERDUE], true) && ! $lease->expiry_notice_alerted_at) {
                    $reached = $service->alertAgents($lease, $reason);
                    $alerted++;
                    $this->line("  ALERTED ({$reason}): {$line} - {$reached} agent(s)");
                } else {
                    $this->line("  NOT SENT ({$reason}), alert later: {$line}");
                }
            }
        }

        $this->info($dry ? 'Dry run. Nothing sent.' : "Done. Sent: {$sent}, alerted: {$alerted}, skipped (not due yet): {$skipped}.");

        return 0;
    }
}
