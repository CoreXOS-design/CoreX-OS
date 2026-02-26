<?php

namespace App\Console\Commands;

use App\Mail\Signatures\LeaseExpirationMail;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\User;
use App\Notifications\LeaseExpirationAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckLeaseExpiry extends Command
{
    protected $signature = 'signatures:check-lease-expiry';

    protected $description = 'Check for expiring leases and update status (90/60/30/0 day alerts)';

    public function handle(): int
    {
        $this->info('Checking lease expiry dates...');

        $expired = 0;
        $expiringSoon = 0;
        $alerts = 0;

        // Mark leases that have expired
        $newlyExpired = LeaseRecord::where('status', LeaseRecord::STATUS_ACTIVE)
            ->whereNotNull('lease_end_date')
            ->where('lease_end_date', '<', now())
            ->get();

        foreach ($newlyExpired as $lease) {
            $lease->update(['status' => LeaseRecord::STATUS_EXPIRED]);
            $this->line("  EXPIRED: {$lease->property_address} (ended {$lease->lease_end_date->format('Y-m-d')})");
            $this->sendLeaseAlert($lease, 'expired', 0);
            $expired++;
            $alerts++;
        }

        // Also check expiring_soon leases that have now expired
        $expiredSoon = LeaseRecord::where('status', LeaseRecord::STATUS_EXPIRING_SOON)
            ->whereNotNull('lease_end_date')
            ->where('lease_end_date', '<', now())
            ->get();

        foreach ($expiredSoon as $lease) {
            $lease->update(['status' => LeaseRecord::STATUS_EXPIRED]);
            $this->line("  EXPIRED: {$lease->property_address} (ended {$lease->lease_end_date->format('Y-m-d')})");
            $this->sendLeaseAlert($lease, 'expired', 0);
            $expired++;
            $alerts++;
        }

        // Mark leases as expiring_soon (within 90 days) and send tiered alerts
        $soonExpiring = LeaseRecord::where('status', LeaseRecord::STATUS_ACTIVE)
            ->whereNotNull('lease_end_date')
            ->where('lease_end_date', '<=', now()->addDays(90))
            ->where('lease_end_date', '>=', now())
            ->get();

        foreach ($soonExpiring as $lease) {
            $daysLeft = $lease->daysUntilExpiry();
            $lease->update(['status' => LeaseRecord::STATUS_EXPIRING_SOON]);

            $level = $this->getAlertLevel($daysLeft);
            $this->line("  {$level}: {$lease->property_address} expires in {$daysLeft} days");
            $this->sendLeaseAlert($lease, $level, $daysLeft);
            $expiringSoon++;
            $alerts++;
        }

        // Re-alert existing expiring_soon leases at threshold crossings
        $existingExpiring = LeaseRecord::where('status', LeaseRecord::STATUS_EXPIRING_SOON)
            ->whereNotNull('lease_end_date')
            ->where('lease_end_date', '>=', now())
            ->get();

        foreach ($existingExpiring as $lease) {
            $daysLeft = $lease->daysUntilExpiry();
            $level = $this->getAlertLevel($daysLeft);
            if ($this->sendLeaseAlert($lease, $level, $daysLeft)) {
                $alerts++;
            }
        }

        $this->info("Done. Expired: {$expired}, Expiring soon: {$expiringSoon}, Alerts sent: {$alerts}");

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
     * Send lease alert with cache-based dedup.
     * Returns true if alert was actually sent (not a duplicate).
     */
    private function sendLeaseAlert(LeaseRecord $lease, string $level, int $daysLeft): bool
    {
        // Dedup: don't send same level alert within 7 days
        $cacheKey = "lease_alert_{$lease->id}_{$level}";
        if (Cache::has($cacheKey)) {
            return false;
        }

        try {
            $lease->loadMissing('document');
            $owner = $lease->document ? User::find($lease->document->owner_id) : null;

            if (!$owner) {
                return false;
            }

            // Send database notification
            $owner->notify(new LeaseExpirationAlert(
                lease: $lease,
                level: $level,
                daysLeft: $daysLeft,
            ));

            // Send email for urgent/expired alerts
            if (in_array($level, ['urgent', 'expired'])) {
                Mail::to($owner->email)->send(new LeaseExpirationMail(
                    agentName: $owner->name,
                    propertyAddress: $lease->property_address ?? 'Unknown property',
                    tenantName: $lease->tenant_name ?? 'Unknown tenant',
                    daysRemaining: $daysLeft,
                    leaseEndDate: $lease->lease_end_date,
                ));
            }

            // Cache to prevent duplicate alerts for 7 days
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
