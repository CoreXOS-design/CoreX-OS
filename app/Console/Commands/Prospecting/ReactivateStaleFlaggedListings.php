<?php

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * ONE-OFF data fix (Johan, 2026-10-07) for the stale-listing window change 30 → 90 days.
 *
 * Until now `prospecting:flag-stale-listings` presumed a portal listing off-market after
 * 30 days without a re-sighting. The window is now each agency's own setting (default 90).
 * Listings the old 30-day rule switched off, whose last sighting is still inside the new
 * window, are switched back on by this command.
 *
 * WHICH ROWS (conservative — see "fingerprint"): only rows the NIGHTLY JOB switched off
 * by absence, never a listing a capture or a suburb reconcile reported as withdrawn/sold.
 * The job leaves an unmistakable fingerprint that those other writers do not:
 *   - is_active = 0, portal_status = 'withdrawn', off_market_at set;
 *   - portal_status_changed_at is >= 30 days AFTER the last sighting (a capture/reconcile
 *     stamps the status at the moment it saw the card, i.e. ~0 days after last_seen_at);
 *   - portal_status_changed_at falls inside the job's own schedule slot (03:50–03:59,
 *     routes/console.php `dailyAt('03:50')`) — a manual `--days` run elsewhere is left alone;
 *   - the last sighting (or first sighting if never re-sighted) is within the agency's window.
 * An explicit sold/under_offer status is never touched (the job never overwrote one).
 *
 * WHAT IT CHANGES: is_active = 1, portal_status = 'active', portal_status_changed_at = NULL,
 * off_market_at = NULL — i.e. the listing looks as it did before the job flagged it. The
 * cached buyer-match rows the job purged are rebuilt by the nightly `prospecting:recompute-matches`.
 *
 * IDEMPOTENT: a second run finds nothing (the rows are active). REVERSIBLE: every changed
 * row's prior values are written to a snapshot JSON; `--reverse=<file>` puts them back
 * (skipping any row that was changed again since).
 */
class ReactivateStaleFlaggedListings extends Command
{
    protected $signature = 'prospecting:reactivate-stale-flagged
                            {--dry-run : Report the counts per agency without writing anything}
                            {--days= : Override the window for ALL agencies (default: each agency listing_off_market_days, 90)}
                            {--reverse= : Path of a snapshot written by a previous run — restore those rows to their flagged state}';

    protected $description = 'One-off: switch listings back on that the old 30-day stale rule switched off but are inside the (now 90-day) window; reversible via snapshot';

    /** The nightly job's schedule slot (routes/console.php) — the fingerprint's time-of-day part. */
    private const JOB_SLOT_FROM = '03:50:00';
    private const JOB_SLOT_TO   = '03:59:59';

    /** The window the old job used — the fingerprint's "flagged this long after last sighting" part. */
    private const OLD_WINDOW_DAYS = 30;

    public function handle(): int
    {
        if ($path = $this->option('reverse')) {
            return $this->reverse((string) $path);
        }

        $override = $this->option('days') !== null && $this->option('days') !== '' ? max(1, (int) $this->option('days')) : null;
        $dryRun   = (bool) $this->option('dry-run');

        $agencyIds = ProspectingListing::withoutGlobalScopes()
            ->where('is_active', false)->whereNull('deleted_at')
            ->distinct()->pluck('agency_id');

        $records = [];
        $perAgency = [];
        foreach ($agencyIds as $agencyId) {
            $agencyId = (int) $agencyId;
            // Read-only lookup (a dry run must not create threshold rows).
            $window = $override
                ?? max(1, (int) (SuggestedActionThresholds::withoutGlobalScopes()->where('agency_id', $agencyId)->value('listing_off_market_days') ?? 90));
            $cutoff = now()->subDays($window);

            $rows = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('is_active', 0)
                ->where('portal_status', ProspectingListing::PORTAL_STATUS_WITHDRAWN)
                ->whereNotNull('off_market_at')
                ->whereNotNull('portal_status_changed_at')
                ->whereRaw('COALESCE(last_seen_at, first_seen_at) IS NOT NULL')
                ->whereRaw('COALESCE(last_seen_at, first_seen_at) >= ?', [$cutoff])
                ->whereRaw('portal_status_changed_at >= COALESCE(last_seen_at, first_seen_at) + INTERVAL ' . (int) self::OLD_WINDOW_DAYS . ' DAY')
                ->whereRaw('TIME(portal_status_changed_at) BETWEEN ? AND ?', [self::JOB_SLOT_FROM, self::JOB_SLOT_TO])
                ->get(['id', 'agency_id', 'suburb', 'portal_status', 'portal_status_changed_at', 'off_market_at', 'last_seen_at', 'first_seen_at']);

            if ($rows->isEmpty()) {
                continue;
            }
            $perAgency[$agencyId] = ['window' => $window, 'count' => $rows->count(), 'suburbs' => $rows->pluck('suburb')->countBy()->sortDesc()->take(5)->all()];
            foreach ($rows as $r) {
                $records[] = (array) $r;
            }
        }

        $total = count($records);
        $this->info(($dryRun ? '[dry run] ' : '') . "Listings the old stale rule switched off that are inside the window: {$total}");
        foreach ($perAgency as $agencyId => $info) {
            $top = collect($info['suburbs'])->map(fn ($n, $s) => "{$s} {$n}")->implode(', ');
            $this->line("  agency {$agencyId}: {$info['count']} (window {$info['window']} days) — top suburbs: {$top}");
        }

        if ($total === 0 || $dryRun) {
            if ($dryRun) {
                $this->warn('Dry run — nothing written.');
            }
            return self::SUCCESS;
        }

        $dir = storage_path('app/private/data-backfills');
        File::ensureDirectoryExists($dir);
        $file = $dir . '/stale-listings-reactivated-' . now()->format('Ymd-His') . '.json';
        File::put($file, json_encode([
            'command'    => 'prospecting:reactivate-stale-flagged',
            'run_at'     => now()->toIso8601String(),
            'per_agency' => $perAgency,
            'records'    => $records,
        ], JSON_PRETTY_PRINT));

        $changed = 0;
        foreach (array_chunk(array_column($records, 'id'), 1000) as $ids) {
            $changed += DB::table('prospecting_listings')
                ->whereIn('id', $ids)
                ->where('is_active', 0) // no-op if a capture already re-activated it meanwhile
                ->update([
                    'is_active'                => 1,
                    'portal_status'            => ProspectingListing::PORTAL_STATUS_ACTIVE,
                    'portal_status_changed_at' => null,
                    'off_market_at'            => null,
                ]);
        }

        $this->info("Switched {$changed} listing(s) back on. Snapshot (to reverse): {$file}");
        $this->line('Their purged buyer-match scores are rebuilt by the nightly prospecting:recompute-matches (04:00), or run it now.');
        Log::info('Reactivated listings the old 30-day stale rule had switched off', ['count' => $changed, 'snapshot' => $file]);

        return self::SUCCESS;
    }

    private function reverse(string $path): int
    {
        if (! File::exists($path)) {
            $this->error("Snapshot not found: {$path}");
            return self::FAILURE;
        }
        $snapshot = json_decode(File::get($path), true);
        $records  = $snapshot['records'] ?? null;
        if (! is_array($records)) {
            $this->error('Not a snapshot written by this command.');
            return self::FAILURE;
        }

        $restored = 0;
        $skipped  = 0;
        foreach ($records as $r) {
            // Only put back what is still exactly as this command left it.
            $n = DB::table('prospecting_listings')
                ->where('id', $r['id'])
                ->where('is_active', 1)
                ->whereNull('off_market_at')
                ->where('portal_status', ProspectingListing::PORTAL_STATUS_ACTIVE)
                ->update([
                    'is_active'                => 0,
                    'portal_status'            => $r['portal_status'],
                    'portal_status_changed_at' => $r['portal_status_changed_at'],
                    'off_market_at'            => $r['off_market_at'],
                ]);
            $n ? $restored++ : $skipped++;
        }

        $this->info("Reversed {$restored} listing(s) to their flagged state; {$skipped} skipped (changed since, e.g. re-captured).");
        Log::info('Reversed stale-listing reactivation', ['restored' => $restored, 'skipped' => $skipped, 'snapshot' => $path]);

        return self::SUCCESS;
    }
}
