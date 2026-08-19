<?php
/**
 * Restore MIC prices corrupted by the P24 price misparse (2026-08-10).
 *
 * DRY-RUN by default (read-only): prints exactly what WOULD change.
 * Pass --apply to write, inside a single transaction.
 *
 *   Read-only (safe, default):   php scripts/prospecting/restore-misparsed-prices.php
 *   Apply (ONLY on Johan's go):  php scripts/prospecting/restore-misparsed-prices.php --apply
 *
 * Target set: price_history rows changed today whose jump vs the prior value is an
 * order-of-magnitude misparse (factor >= FACTOR) AND whose corrupt value is STILL
 * the live price (we never touch a listing that has since legitimately moved on).
 * For each it: (a) restores prospecting_listings.price to the prior good value,
 * (b) restores tracked_properties.last_known_asking_price IF it still holds the
 * corrupt value, (c) deletes the bogus price_history row, (d) leaves an audit row
 * in prospecting_price_anomalies (status=confirmed_bad).
 *
 * Run from the target checkout (e.g. /corex for live) so it uses that env's DB.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const FACTOR = 4;
$apply = in_array('--apply', $argv, true);
$today = \Carbon\Carbon::now()->startOfDay();

$rows = DB::table('prospecting_price_history as h')
    ->join('prospecting_listings as l', 'l.id', '=', 'h.prospecting_listing_id')
    ->where('h.changed_at', '>=', $today)
    ->whereNotNull('h.old_price')->whereNotNull('h.new_price')
    ->where('h.old_price', '>', 0)->where('h.new_price', '>', 0)
    ->select('h.id as hist_id', 'h.prospecting_listing_id as lid', 'h.old_price', 'h.new_price',
             'l.price as current_price', 'l.tracked_property_id as tpid',
             'l.agency_id', 'l.portal_source', 'l.portal_ref')
    ->orderBy('h.prospecting_listing_id')->get();

$targets = [];
foreach ($rows as $r) {
    $o = (int) $r->old_price; $n = (int) $r->new_price;
    $implausible = ($n >= $o * FACTOR || $n * FACTOR <= $o);
    if (!$implausible) continue;
    if ((int) $r->current_price !== $n) continue;   // only if the corrupt value is STILL live
    $targets[] = $r;
}

echo ($apply ? "APPLY" : "DRY-RUN (read-only)") . " — MIC price restore\n";
echo "Connection DB: " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Targets (factor >= " . FACTOR . ", still-corrupt live): " . count($targets) . "\n";
echo str_repeat('-', 96) . "\n";
printf("%-8s %-14s %-13s %-13s %-8s %-13s %-9s\n",
    'listing', 'portal_ref', 'restore_to', 'from(corrupt)', 'tp_id', 'tp_now', 'tp_fix?');

$hasAnomalyTable = Schema::hasTable('prospecting_price_anomalies');

$run = function () use ($targets, $today, $hasAnomalyTable) {
    $fixedListings = 0; $fixedTps = 0;
    foreach ($targets as $r) {
        $o = (int) $r->old_price; $n = (int) $r->new_price;

        $tpNow = $r->tpid ? (int) DB::table('tracked_properties')->where('id', $r->tpid)
            ->value('last_known_asking_price') : null;
        $tpFix = ($r->tpid && $tpNow === $n);
        printf("%-8s %-14s %-13s %-13s %-8s %-13s %-9s\n",
            $r->lid, $r->portal_ref, number_format($o), number_format($n),
            $r->tpid ?? '-', $tpNow !== null ? number_format($tpNow) : '-', $tpFix ? 'YES' : 'no');

        // Prior good change timestamp (the change BEFORE the corrupt one), for price_changed_at.
        $priorTs = DB::table('prospecting_price_history')
            ->where('prospecting_listing_id', $r->lid)
            ->where('id', '<>', $r->hist_id)
            ->orderByDesc('changed_at')->value('changed_at');

        DB::table('prospecting_listings')->where('id', $r->lid)->update([
            'price'            => $o,
            'price_changed_at' => $priorTs,
        ]);
        $fixedListings++;

        if ($tpFix) {
            DB::table('tracked_properties')->where('id', $r->tpid)
                ->where('last_known_asking_price', $n)
                ->update(['last_known_asking_price' => $o]);
            $fixedTps++;
        }

        DB::table('prospecting_price_history')->where('id', $r->hist_id)->delete();

        if ($hasAnomalyTable) {
            DB::table('prospecting_price_anomalies')->insert([
                'prospecting_listing_id' => $r->lid,
                'agency_id'      => $r->agency_id,
                'portal_source'  => $r->portal_source,
                'portal_ref'     => $r->portal_ref,
                'stored_price'   => $o,
                'rejected_price' => $n,
                'jump_factor'    => $n >= $o ? round($n / max(1, $o), 2) : -1 * round($o / max(1, $n), 2),
                'search_url'     => null,
                'status'         => 'confirmed_bad',
                'reviewed_at'    => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
    return [$fixedListings, $fixedTps];
};

if (!$apply) {
    // Dry-run: show the table only, write nothing.
    foreach ($targets as $r) {
        $tpNow = $r->tpid ? (int) DB::table('tracked_properties')->where('id', $r->tpid)
            ->value('last_known_asking_price') : null;
        $tpFix = ($r->tpid && $tpNow === (int) $r->new_price);
        printf("%-8s %-14s %-13s %-13s %-8s %-13s %-9s\n",
            $r->lid, $r->portal_ref, number_format((int) $r->old_price),
            number_format((int) $r->new_price), $r->tpid ?? '-',
            $tpNow !== null ? number_format($tpNow) : '-', $tpFix ? 'YES' : 'no');
    }
    echo str_repeat('-', 96) . "\n";
    echo "DRY-RUN — nothing written. Re-run with --apply (on Johan's go) to restore.\n";
    exit(0);
}

[$fl, $ft] = DB::transaction($run);
echo str_repeat('-', 96) . "\n";
echo "APPLIED — listings restored: $fl ; tracked_properties restored: $ft ; bogus history rows deleted: $fl\n";
