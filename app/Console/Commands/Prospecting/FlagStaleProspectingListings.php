<?php

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingListing;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BUG 2 (MIC) — the ONLY signal CoreX has that a prospecting listing is still
 * live is re-sighting it (Chrome capture bumps last_seen_at + is_active=true
 * on every sighting; ImportP24Alerts writes a different table entirely).
 * Nothing anywhere ever flips is_active back to false, so a listing that was
 * sold/withdrawn/delisted keeps its old cached buyer-match score and sits at
 * the top of MIC forever.
 *
 * Without a live P24 status API to poll, re-sighting absence is the most
 * reliable signal buildable today: a listing not re-confirmed by ANY capture
 * within the window is presumed off-market. Self-healing — the next capture
 * that re-surfaces it resets is_active=true (ProspectingApiController::import).
 *
 * Window: PER AGENCY, default 90 days (suggested_action_thresholds.listing_off_market_days,
 * Settings → Prospecting Setup → Stale-claim rules, and the Setup Wizard's Market
 * Intelligence step). Johan 2026-10-07: "a mandate normally runs 90 days" — the
 * original hard-coded 30 days switched a whole suburb's competition off between
 * agent searches (Uvongo, 26 Sep: 1,321 listings), emptying the presentation's
 * Active Competition section. Re-capture cadence is agent-driven (Chrome
 * extension, not a scheduled crawl), so the window must outlast irregular
 * cadence. --days overrides the window for every agency (manual/targeted run).
 */
class FlagStaleProspectingListings extends Command
{
    protected $signature = 'prospecting:flag-stale-listings
                            {--days= : Override the window (days since last_seen_at, or first_seen_at if never re-sighted) for ALL agencies. Default: each agency own listing_off_market_days setting (90)}
                            {--listing=* : Restrict to specific prospecting_listings ids (targeted run)}
                            {--dry-run : Report what would be flagged without writing}';

    protected $description = 'Flag prospecting listings not re-confirmed within the window as inactive, and purge their stale buyer-match cache rows';

    public function handle(ProspectingConfigurationService $config): int
    {
        $override   = $this->option('days') !== null && $this->option('days') !== '' ? max(1, (int) $this->option('days')) : null;
        $listingIds = array_map('intval', $this->option('listing'));

        // One window per agency: its own setting (default 90) unless --days overrides.
        $agencyIds = ProspectingListing::withoutGlobalScopes()
            ->where('is_active', true)->whereNull('deleted_at')
            ->when(!empty($listingIds), fn ($q) => $q->whereIn('id', $listingIds))
            ->distinct()->pluck('agency_id');

        $windows = [];
        foreach ($agencyIds as $agencyId) {
            $windows[(int) $agencyId] = $override
                ?? max(1, (int) $config->getSuggestedActionThresholds((int) $agencyId)->listing_off_market_days);
        }
        $days = $override ?? (count($windows) ? min($windows) : 90); // for the log line only

        $query = ProspectingListing::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($outer) use ($windows) {
                foreach ($windows as $agencyId => $agencyDays) {
                    $cutoff = now()->subDays($agencyDays);
                    $outer->orWhere(function ($q) use ($agencyId, $cutoff) {
                        $q->where('agency_id', $agencyId)->where(function ($q) use ($cutoff) {
                            // Signal A — absence: not re-confirmed within the agency's window (presumed off-market).
                            $q->where('last_seen_at', '<', $cutoff)
                                ->orWhere(function ($q2) use ($cutoff) {
                                    $q2->whereNull('last_seen_at')->where('first_seen_at', '<', $cutoff);
                                })
                                // Signal B — explicit portal status: the P24 card reported it
                                // sold/under-offer/withdrawn but the row is still is_active=true
                                // (captured before this shipped, or a purge that didn't land).
                                ->orWhereIn('portal_status', ProspectingListing::OFF_MARKET_STATUSES);
                        });
                    });
                }
                if (empty($windows)) {
                    $outer->whereRaw('1 = 0');
                }
            });

        if (!empty($listingIds)) {
            $query->whereIn('id', $listingIds);
        }

        $stale = $query->get(['id', 'address', 'suburb', 'last_seen_at', 'portal_status', 'agency_id']);

        if ($stale->isEmpty()) {
            $this->info('No stale listings found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} listing(s) not re-confirmed within their agency's window (" . ($override ? "{$override} days, --days override" : 'per-agency setting, default 90') . '):');
        foreach ($stale as $l) {
            $this->line("  #{$l->id} {$l->address}, {$l->suburb} — last seen: " . ($l->last_seen_at ?? 'never'));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');
            return self::SUCCESS;
        }

        $ids = $stale->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            $now = now();

            ProspectingListing::withoutGlobalScopes()->whereIn('id', $ids)->update(['is_active' => false]);

            // Stamp the exit date once, so days-on-market = off_market_at − first_seen_at
            // stays meaningful and a re-run never overwrites the true first exit.
            ProspectingListing::withoutGlobalScopes()->whereIn('id', $ids)
                ->whereNull('off_market_at')
                ->update(['off_market_at' => $now]);

            // Absence with no explicit portal status yet = delisted/off-market, cause
            // unknown → 'withdrawn'. Never overwrite a real sold/under_offer already set
            // (Signal B), so we only touch NULL/'active' rows — no mislabelling as sold.
            ProspectingListing::withoutGlobalScopes()->whereIn('id', $ids)
                ->where(function ($q) {
                    $q->whereNull('portal_status')
                        ->orWhere('portal_status', ProspectingListing::PORTAL_STATUS_ACTIVE);
                })
                ->update([
                    'portal_status'            => ProspectingListing::PORTAL_STATUS_WITHDRAWN,
                    'portal_status_changed_at' => $now,
                ]);

            // The cached score is now for an off-market listing — purge it
            // immediately rather than waiting for the next per-buyer recompute.
            DB::table('prospecting_buyer_matches')->whereIn('prospecting_listing_id', $ids)->delete();
        });

        $this->info("Flagged {$stale->count()} listing(s) inactive (absence or off-market portal status), stamped off_market_at, and purged their cached buyer matches.");

        Log::info('Flagged stale prospecting listings inactive', [
            'count' => $stale->count(),
            'days'  => $days,
            'ids'   => $ids,
        ]);

        return self::SUCCESS;
    }
}
