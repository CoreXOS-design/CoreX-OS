<?php

namespace App\Console\Commands;

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
 * `Lease` has no intermediate "expiring_soon" status (only
 * draft/active/expired/cancelled) — this command never invents one; a
 * lease stays 'active' right up until its end_date passes, at which
 * point it flips straight to 'expired'. The agency's own configurable
 * notice window (LeaseSetting::expiryNoticeWindowDaysFor(), already
 * live on the Lease Settings screen and the onboarding wizard — this
 * command is the only thing that was never calling it) decides how far
 * out the tiered alerts below start firing, PER LEASE'S OWN agency_id —
 * not a single global window, so each agency's own setting governs its
 * own leases. Console commands run with no authenticated user, so
 * Lease's own AgencyScope is a no-op here already (confirmed:
 * AgencyScope::applyInner() returns immediately when Auth::user() is
 * null) — withoutGlobalScopes() below is explicit about that rather
 * than relying on the implicit console behaviour, matching
 * LeaseSetting's own existing convention.
 */
class CheckLeaseExpiry extends Command
{
    protected $signature = 'signatures:check-lease-expiry';

    protected $description = 'Check real rentals leases for expiry and send tiered 60/30/0-day alerts to the agent (per-agency notice window)';

    public function handle(): int
    {
        $this->info('Checking real rentals lease expiry dates...');

        $expired = 0;
        $alerts = 0;

        // Flip anything whose end_date has already passed — active leases only,
        // a draft/cancelled lease was never counting down to anything.
        $newlyExpired = Lease::withoutGlobalScopes()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->where('end_date', '<', now()->startOfDay())
            ->get();

        foreach ($newlyExpired as $lease) {
            $lease->update(['status' => Lease::STATUS_EXPIRED]);
            $address = $lease->property?->title ?? 'Unknown property';
            $this->line("  EXPIRED: {$address} (ended {$lease->end_date->format('Y-m-d')})");
            if ($this->sendLeaseAlert($lease, 'expired', 0)) {
                $alerts++;
            }
            $expired++;
        }

        // Still active, end_date in the future — alert anything inside ITS
        // OWN agency's notice window. Grouped per-lease (not per-agency bulk
        // query) because the window boundary differs lease-by-lease.
        $stillActive = Lease::withoutGlobalScopes()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', now()->startOfDay())
            ->get();

        foreach ($stillActive as $lease) {
            $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id);
            $daysLeft = (int) now()->startOfDay()->diffInDays($lease->end_date, false);

            if ($daysLeft > $windowDays) {
                continue; // outside this lease's OWN agency's configured notice window
            }

            $level = $this->getAlertLevel($daysLeft);
            if ($this->sendLeaseAlert($lease, $level, $daysLeft)) {
                $address = $lease->property?->title ?? 'Unknown property';
                $this->line("  {$level}: {$address} expires in {$daysLeft} days");
                $alerts++;
            }
        }

        $this->info("Done. Expired: {$expired}, Alerts sent: {$alerts}");

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
