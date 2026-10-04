<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Notifications\LeaseExpiryAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AT-439 — repointed from the legacy DocuPerfect `LeaseRecord` table (2
 * test-artifact rows, no real tenancy data) to the real rentals `Lease`
 * model (the spine every other rentals feature — fault reports, work
 * orders, inventory, inspections — already hangs off). Confirmed via a
 * read-only check before this change: zero overlap between the 2
 * `lease_records` rows and any `leases` row (`migrated_from_table`/
 * `source_document_id` cross-reference), so repointing here does not
 * double-alert on anything that exists today.
 *
 * AT-439 hotfix (2026-10-04) — Johan's approved design, corrected after an
 * unscoped verification run of an earlier version of this command
 * auto-flipped real leases to 'expired': a lease whose end_date has
 * passed is NEVER changed automatically. It stays 'active' and is
 * flagged (via the same alert) for the agent to record the real outcome
 * (renewed / month-to-month / notice / ended) — the status change only
 * ever happens when the agent acts, through LeaseRenewalService/
 * LeaseActivationService (AT-444), never from this command. This command
 * raises the reminder/alert ONLY; it reads, it never writes `status`.
 *
 * Also hardened the same day: the query is no longer a single
 * `withoutGlobalScopes()` call spanning every agency implicitly. It now
 * iterates agencies EXPLICITLY, one at a time, with `agency_id` named in
 * the query for that iteration — console commands run with no
 * authenticated user, so `Lease`'s own `AgencyScope` is a no-op here
 * regardless (confirmed: `AgencyScope::applyInner()` returns immediately
 * when `Auth::user()` is null), but relying on that implicit absence to
 * silently cover every agency in one bulk query is exactly the shape of
 * mistake that let a verification run touch real data across the whole
 * table at once. The explicit per-agency loop makes the scope visible at
 * the call site, and resolves each agency's OWN
 * `LeaseSetting::expiryNoticeWindowDaysFor()` within that same iteration.
 */
class CheckLeaseExpiry extends Command
{
    protected $signature = 'signatures:check-lease-expiry';

    protected $description = 'Flag overdue rental leases and send tiered 60/30/0-day alerts to the agent, per agency (never changes lease status)';

    public function handle(): int
    {
        $this->info('Checking real rentals lease expiry dates...');

        $overdue = 0;
        $alerts = 0;

        foreach (Agency::all() as $agency) {
            $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agency->id);

            // Overdue — end_date already passed. Flagged via the alert ONLY;
            // status is never written here (Johan's ruling, 2026-10-04 —
            // see class docblock). The agent records the real outcome.
            $overdueLeases = Lease::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('status', Lease::STATUS_ACTIVE)
                ->whereNotNull('end_date')
                ->where('end_date', '<', now()->startOfDay())
                ->get();

            foreach ($overdueLeases as $lease) {
                $daysLeft = (int) now()->startOfDay()->diffInDays($lease->end_date, false);
                $address = $lease->property?->title ?? 'Unknown property';

                if ($this->sendLeaseAlert($lease, 'expired', $daysLeft)) {
                    $this->line("  OVERDUE: {$address} (ended {$lease->end_date->format('Y-m-d')}) — agent flagged, status unchanged");
                    $alerts++;
                }
                $overdue++;
            }

            // Still active, end_date in the future — alert anything inside
            // THIS agency's own configured notice window.
            $stillActive = Lease::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('status', Lease::STATUS_ACTIVE)
                ->whereNotNull('end_date')
                ->where('end_date', '>=', now()->startOfDay())
                ->where('end_date', '<=', now()->startOfDay()->addDays($windowDays))
                ->get();

            foreach ($stillActive as $lease) {
                $daysLeft = (int) now()->startOfDay()->diffInDays($lease->end_date, false);
                $level = $this->getAlertLevel($daysLeft);

                if ($this->sendLeaseAlert($lease, $level, $daysLeft)) {
                    $address = $lease->property?->title ?? 'Unknown property';
                    $this->line("  {$level}: {$address} expires in {$daysLeft} days");
                    $alerts++;
                }
            }
        }

        $this->info("Done. Overdue (flagged, status unchanged): {$overdue}, Alerts sent: {$alerts}");

        return 0;
    }

    private function getAlertLevel(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0  => 'expired',
            $daysLeft <= 30 => 'urgent',
            $daysLeft <= 60 => 'warning',
            default         => 'notice',
        };
    }

    /**
     * Cache-based dedup, same shape as the legacy command's own
     * sendLeaseAlert() — a distinct cache-key prefix ('lease_v2_alert_',
     * vs the legacy 'lease_alert_') so this never collides with any key
     * the still-separately-running legacy LeaseRecord command has
     * already written for a numerically-identical-but-unrelated id.
     * Returns true only if an alert was actually sent (not a duplicate,
     * not skipped for lack of a recipient).
     */
    private function sendLeaseAlert(Lease $lease, string $level, int $daysLeft): bool
    {
        $cacheKey = "lease_v2_alert_{$lease->id}_{$level}";
        if (Cache::has($cacheKey)) {
            return false;
        }

        try {
            // Recipient stays the agent, same as the legacy command — never
            // the tenant or landlord. The real Lease model's own equivalent
            // of "the document's owner" is whichever agent created it.
            $agent = $lease->createdByUser;

            if (!$agent) {
                return false;
            }

            $agent->notify(new LeaseExpiryAlert(
                lease: $lease,
                level: $level,
                daysLeft: $daysLeft,
            ));

            // No email — in-app notification only, same as the legacy
            // command (LeaseExpirationMail stays dead code; not revived here).

            Cache::put($cacheKey, true, now()->addDays(7));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send lease expiry alert', [
                'lease_id' => $lease->id,
                'level' => $level,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
