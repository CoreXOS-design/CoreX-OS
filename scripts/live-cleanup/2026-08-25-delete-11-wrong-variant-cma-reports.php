<?php

/**
 * READY TO RUN WHEN THE LIVE FREEZE LIFTS. NOT RUN YET. NOT A WRITE.
 *
 * Deletes the 11 pre-fix-era cma_info_median_sales_analysis reports whose
 * price data may be mislabelled (average stored as median, or vice versa)
 * by the parser bug fixed in CmaInfoMedianSalesAnalysisParser (staging
 * commit c1d78cdea, 2026-08-25). Johan's explicit call: delete, do not
 * attempt to migrate/relabel the existing rows — "its 11 reports - and to
 * tell you the truth, the can be dumped if its mean saving corruption.
 * agents know that for each presentation they should upload new reports."
 *
 * Mirrors exactly what was already done on staging (same 11 report ids,
 * confirmed identical rows on live via read-only query 2026-08-25):
 *   1. Dump every affected row to a JSON file BEFORE touching anything.
 *   2. Soft-delete (deleted_at = now()) — never a hard delete, per
 *      CoreX non-negotiable #1. market_reports and market_data_points
 *      both carry deleted_at and both are already respected
 *      (whereNull('deleted_at')) by every consumer, confirmed via
 *      MicSnapshotHydrator's own queries.
 *
 * Reference check (done 2026-08-24, staging, applies identically to live
 * since the data is the same): the only consumer is
 * MicSnapshotHydrator::hydrateSuburbMetrics(), which reads live by
 * suburb_normalised + metric_key, not by report_id — no direct FK
 * dependency to break. Presentations that already hydrated a value from
 * these reports have it frozen into their own presentation_fields row
 * already; only a FUTURE re-hydration for these suburbs is affected, and
 * the UI's isset() guard already renders a clean skip for a missing
 * field, not an error or a zero.
 *
 * HOW TO RUN, once the freeze lifts and this has been reviewed:
 *   sudo -u www-data env HOME=/tmp php /corex/artisan tinker
 *   >>> require '/corex/scripts/live-cleanup/2026-08-25-delete-11-wrong-variant-cma-reports.php';
 *
 * Or as a one-shot script:
 *   sudo -u www-data env HOME=/tmp php /corex/artisan tinker --execute="require '/corex/scripts/live-cleanup/2026-08-25-delete-11-wrong-variant-cma-reports.php';"
 *
 * The dump path below assumes it's run from within /corex (live). Confirm
 * the target directory exists and is on the data volume, not /, per the
 * disk-hygiene rule — this dump is small (the staging equivalent was
 * 349KB) so either is fine here, but check anyway before running.
 */

$ids = [25, 30, 139, 147, 154, 162, 183, 194, 212, 227, 235];

$dumpPath = '/corex/storage/app/private/market-reports-backups/cma_median_reports_dump_live_' . now()->format('Ymd_His') . '.json';

echo "=== BEFORE — confirm state matches what was verified read-only on 2026-08-25 ===\n";
$activeReportsBefore = \DB::table('market_reports')->whereIn('id', $ids)->whereNull('deleted_at')->count();
$activePointsBefore  = \DB::table('market_data_points')->whereIn('report_id', $ids)->whereNull('deleted_at')->count();
echo "market_reports active: {$activeReportsBefore} (expect 10 — id 162 was already soft-deleted 2026-06-15, unrelated)\n";
echo "market_data_points active: {$activePointsBefore} (expect 445)\n";

if ($activeReportsBefore === 0 && $activePointsBefore === 0) {
    echo "Nothing active — already cleaned up (or state has changed since this was written). STOPPING, no dump, no delete.\n";
    return;
}

echo "\n=== DUMPING before any delete ===\n";
$dump = [
    'dumped_at' => now()->toIso8601String(),
    'reason'    => 'Same 11-report deletion already done on staging 2026-08-24, applied to live once the freeze lifted. See .ai/audits for the parser fix and the original staging deletion record.',
    'market_reports'     => \DB::table('market_reports')->whereIn('id', $ids)->get()->toArray(),
    'market_data_points' => \DB::table('market_data_points')->whereIn('report_id', $ids)->get()->toArray(),
];
file_put_contents($dumpPath, json_encode($dump, JSON_PRETTY_PRINT));
echo "Dumped " . count($dump['market_reports']) . " market_reports rows and " . count($dump['market_data_points']) . " market_data_points rows to:\n  {$dumpPath}\n";

echo "\n=== SOFT-DELETING ===\n";
$now = now();
$mdpDeleted = \DB::table('market_data_points')->whereIn('report_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now]);
$mrDeleted  = \DB::table('market_reports')->whereIn('id', $ids)->whereNull('deleted_at')->update([
    'deleted_at' => $now,
    'notes'      => \DB::raw("CONCAT(COALESCE(notes,''), '\n[" . $now->toDateString() . "] Retired: pre-fix era, median/average metric-key confusion made this era of parses untrustworthy. See CmaInfoMedianSalesAnalysisParser fix (staging c1d78cdea) + the JSON dump at " . $dumpPath . ".')"),
]);

echo "market_data_points soft-deleted: {$mdpDeleted}\n";
echo "market_reports soft-deleted: {$mrDeleted}\n";

echo "\n=== AFTER ===\n";
echo "market_reports active: " . \DB::table('market_reports')->whereIn('id', $ids)->whereNull('deleted_at')->count() . " (expect 0)\n";
echo "market_data_points active: " . \DB::table('market_data_points')->whereIn('report_id', $ids)->whereNull('deleted_at')->count() . " (expect 0)\n";
