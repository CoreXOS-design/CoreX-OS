<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair the drift between `p24_syndication_status` (a local cache) and what
 * Property24's portal actually carries, using P24's own `is-on-portal` as the
 * single source of truth.
 *
 * Why this exists: PropertyObserver used to write 'deactivated' for ANY terminal
 * P24 status, including 'Sold' and 'Rented' — which P24 keeps ON the portal. That
 * value is what every delist guard reads as "already removed", so those listings
 * became un-delistable: the toggle skipped them, the desync job skipped them, and
 * CoreX reported them off the portal while they were publicly live. The code path
 * is fixed (Property24ListingMapper::removesFromPortal); this command repairs the
 * rows the old behaviour stranded.
 *
 * Two phases, both idempotent:
 *   1. Reconcile — correct p24_syndication_status to match portal reality.
 *   2. --withdraw — for listings that are ON the portal but SHOULD NOT be
 *      (an off-market status that removes, or syndication switched off), push
 *      the Withdrawn that never went out.
 *
 * Audit: .ai/audits/p24-sold-not-delisted-2026-07-10.md (property #2142)
 */
class ReconcileP24PortalPresence extends Command
{
    protected $signature = 'p24:reconcile-portal-presence
        {--property= : Restrict to a single property ID}
        {--all : Check every property with a p24_ref, not just the suspect set}
        {--withdraw : Also push Withdrawn for listings that are live but should not be}
        {--dry-run : Report what would change and exit without writing or pushing}
        {--sleep=1 : Seconds to pause between P24 calls (self-throttle)}';

    protected $description = 'Reconcile p24_syndication_status against P24 is-on-portal, and optionally withdraw stranded live listings';

    public function handle(Property24SyndicationService $svc): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $withdraw = (bool) $this->option('withdraw');
        $sleep    = max(0, (int) $this->option('sleep'));

        $query = Property::withoutGlobalScope(AgencyScope::class)
            ->whereNull('deleted_at')
            ->whereNotNull('p24_ref')
            ->where('p24_ref', '!=', '');

        if ($id = $this->option('property')) {
            $query->where('id', (int) $id);
        } elseif (! $this->option('all')) {
            // The suspect set: rows claiming to be off the portal. Those are the
            // ones the old Sold-push / toggle-off bugs stranded. Covers 'error' and
            // 'rejected' as well as 'deactivated' — all three read as "not
            // advertised" while hiding a listing that may be publicly live, and
            // sweeping only 'deactivated' left 189 such rows unchecked.
            $query->whereIn('p24_syndication_status', Property::P24_CLAIMS_OFF_PORTAL_STATUSES);
        }

        $targets = $query->orderBy('id')->get();
        $this->info("Checking {$targets->count()} listing(s) against P24 is-on-portal" . ($dryRun ? ' [DRY RUN]' : ''));

        $stats = ['checked' => 0, 'unknown' => 0, 'status_fixed' => 0, 'withdrawn' => 0, 'withdraw_failed' => 0, 'ok' => 0];

        foreach ($targets as $property) {
            $stats['checked']++;

            $onPortal = $svc->isOnPortal($property);
            if ($onPortal === null) {
                $stats['unknown']++;
                $this->warn("  #{$property->id} ref={$property->p24_ref} — P24 gave no answer; left untouched");
                $this->pause($sleep);
                continue;
            }

            $shouldBeOff = $this->shouldBeOffPortal($property);
            $truthful    = $this->truthfulStatus($property, $onPortal);

            // Phase 2 first when applicable: withdrawing sets the status itself,
            // so there is never a window where the row claims to be advertised.
            if ($onPortal && $shouldBeOff && $withdraw) {
                if ($dryRun) {
                    $this->line("  #{$property->id} LIVE but should be off ({$property->status}) — would push Withdrawn");
                    $stats['withdrawn']++;
                    $this->pause($sleep);
                    continue;
                }

                $result = $svc->deactivateListing($property);
                if ($result['success'] ?? false) {
                    $stats['withdrawn']++;
                    $this->info("  #{$property->id} withdrawn from P24");
                } else {
                    $stats['withdraw_failed']++;
                    $this->error("  #{$property->id} withdraw FAILED: " . ($result['message'] ?? 'unknown'));
                }
                $this->pause($sleep);
                continue;
            }

            if ($truthful === (string) $property->p24_syndication_status) {
                $stats['ok']++;
                $this->pause($sleep);
                continue;
            }

            $note = $onPortal ? 'LIVE on portal' : 'not on portal';
            $this->line("  #{$property->id} {$note} — status '{$property->p24_syndication_status}' → '{$truthful}'"
                . ($onPortal && $shouldBeOff ? '  (still needs --withdraw)' : ''));

            // A listing that is publicly advertised but should not be is a real-world
            // problem (an off-market or de-syndicated property still on P24), and the
            // scheduled sweep runs without --withdraw — so it must ALARM rather than
            // only print to a console nobody reads. Fix the caller; do not silence.
            if ($onPortal && $shouldBeOff) {
                Log::channel('property24')->warning(
                    "P24 STRANDED ADVERT: property #{$property->id} (ref {$property->p24_ref}) is LIVE on the portal "
                    . "but should not be (market status '{$property->status}', syndication "
                    . ($property->p24_syndication_enabled ? 'on' : 'OFF') . "). "
                    . "Run: php artisan p24:reconcile-portal-presence --property={$property->id} --withdraw",
                    ['property_id' => $property->id, 'p24_ref' => $property->p24_ref, 'status' => $property->status]
                );
            }

            if (! $dryRun) {
                $property->updateQuietly(['p24_syndication_status' => $truthful]);
            }
            $stats['status_fixed']++;
            $this->pause($sleep);
        }

        $this->newLine();
        $this->info(sprintf(
            'checked=%d  already-correct=%d  status-fixed=%d  withdrawn=%d  withdraw-failed=%d  no-answer=%d',
            $stats['checked'], $stats['ok'], $stats['status_fixed'],
            $stats['withdrawn'], $stats['withdraw_failed'], $stats['unknown']
        ));

        return $stats['withdraw_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Should P24 be carrying this listing at all? No, when the property's market
     * status maps to a P24 status that removes it (Withdrawn/Expired/Cancelled),
     * or when the agent has switched P24 syndication off. Sold/Rented deliberately
     * stay on the portal, so they are NOT "should be off".
     */
    private function shouldBeOffPortal(Property $property): bool
    {
        if (! $property->p24_syndication_enabled) {
            return true;
        }

        $p24Status = Property24ListingMapper::getP24Status(
            $property->status,
            $property->p24_ref,
            $property->status_label
        );

        return Property24ListingMapper::removesFromPortal($p24Status);
    }

    /**
     * The value p24_syndication_status should hold given portal reality. Off the
     * portal is unambiguous. On the portal, a terminal-but-listed market status
     * (sold/rented) keeps its own state; anything else is simply advertised.
     */
    private function truthfulStatus(Property $property, bool $onPortal): string
    {
        if (! $onPortal) {
            return Property::PORTAL_OFF_STATUS;
        }

        $p24Status = strtolower(Property24ListingMapper::getP24Status(
            $property->status,
            $property->p24_ref,
            $property->status_label
        ));

        return in_array($p24Status, Property::P24_ON_PORTAL_TERMINAL_STATUSES, true)
            ? $p24Status
            : 'active';
    }

    private function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
