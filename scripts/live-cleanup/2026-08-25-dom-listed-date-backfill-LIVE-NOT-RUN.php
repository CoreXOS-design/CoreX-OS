<?php

/**
 * PREPARED FOR LIVE. NOT RUN. Requires Johan's specific explicit order for
 * this exact action (team rule 5) — this script existing is not that order.
 *
 * Mirrors the QA1 backfill (QA1 commit — see corex-qa1 branch history,
 * 2026-08-25) exactly: for active, agency_id=1 properties whose stored
 * listed_date is contradicted by their own real portal history by more
 * than 7 days, replace listed_date with the earliest of
 * property_portal_metrics.metric_date / portal_leads.received_at already
 * on file. Where no such signal exists, or the contradiction is <=7 days,
 * the record is left alone.
 *
 * Root cause (confirmed 2026-08-25, both staging/QA1 and — per this
 * script's own reason for existing — presumed live too): P24-onboarded
 * stock's listed_date defaults to the CoreX load date, because the P24
 * feed carries no original-listing-date column and the import job
 * (ConfirmP24PropertyRowJob) never sets one. Confirmed on QA1: 85/117
 * active properties (72.6%) were contradicted by more than 7 days before
 * this backfill ran; the worst cases were off by 168 days. 0/17
 * agent-captured properties were ever contradicted.
 *
 * HOW TO RUN, once Johan has given the explicit go for this exact action
 * on live:
 *   1. Read this whole file first.
 *   2. sudo -u www-data env HOME=/tmp php /corex/artisan tinker
 *      >>> require '/corex/scripts/live-cleanup/2026-08-25-dom-listed-date-backfill-LIVE-NOT-RUN.php';
 *   This is a TWO-STEP script: it first computes the affected set and
 *   writes a snapshot, printing the intended count, and STOPS there
 *   (returns) without writing anything. Re-run the SAME require a second
 *   time to actually apply the write — it will detect the snapshot file
 *   already exists and proceed to the update step. This gives a mandatory
 *   pause between "here is what I am about to change" and the write, on
 *   live, by construction — not by discipline alone.
 */

$agencyId = 1;
$snapshotDir = '/corex/storage/app/private/data-backfills';
$snapshotPath = $snapshotDir . '/dom-listed-date-backfill-live-2026-08-25.json';

if (!file_exists($snapshotPath)) {
    echo "=== STEP 1 of 2: computing affected set, no writes yet ===\n";

    $props = \DB::table('properties')->whereNull('deleted_at')->where('agency_id', $agencyId)
        ->where('status', 'active')->whereNotNull('listed_date')
        ->select('id', 'listed_date')->get();

    $toChange = [];
    foreach ($props as $p) {
        $earliestMetric = \DB::table('property_portal_metrics')->where('property_id', $p->id)->min('metric_date');
        $earliestLead = \DB::table('portal_leads')->where('listing_id', $p->id)->whereNull('deleted_at')->min('received_at');
        $candidates = [];
        if ($earliestMetric) $candidates[] = $earliestMetric;
        if ($earliestLead) $candidates[] = \Carbon\Carbon::parse($earliestLead)->toDateString();
        if (!$candidates) continue;
        $earliest = min($candidates);

        $gap = \Carbon\Carbon::parse($p->listed_date)->diffInDays(\Carbon\Carbon::parse($earliest), false);
        if ($gap < -7) {
            $toChange[] = ['id' => $p->id, 'old_listed_date' => $p->listed_date, 'new_listed_date' => $earliest, 'distortion_days' => abs($gap)];
        }
    }

    echo "Properties whose listed_date WOULD change: " . count($toChange) . "\n";

    @mkdir($snapshotDir, 0775, true);
    file_put_contents($snapshotPath, json_encode([
        'agency_id'   => $agencyId,
        'scope'       => 'active properties, agency_id=1, listed_date IS NOT NULL, real portal activity predates stored listed_date by more than 7 days',
        'snapshot_at' => now()->toIso8601String(),
        'records'     => $toChange,
    ], JSON_PRETTY_PRINT));
    echo "Snapshot written to: {$snapshotPath}\n";
    echo "STOPPING HERE. Review the snapshot. Re-run this same require to apply the write.\n";
    return;
}

echo "=== STEP 2 of 2: snapshot already exists, applying the write ===\n";
$snapshot = json_decode(file_get_contents($snapshotPath), true);
$records = $snapshot['records'];
echo "About to update " . count($records) . " properties (from snapshot file, taken " . $snapshot['snapshot_at'] . ").\n";

$updated = 0;
foreach ($records as $r) {
    $count = \DB::table('properties')
        ->where('id', $r['id'])
        ->where('agency_id', $agencyId)
        ->where('status', 'active')
        ->where('listed_date', $r['old_listed_date']) // no-op if it already changed since the snapshot
        ->update(['listed_date' => $r['new_listed_date']]);
    $updated += $count;
}

echo "Rows actually updated: {$updated}\n";
echo "Re-run the distortion check afterwards and confirm it lands near 0, matching the QA1 result.\n";
