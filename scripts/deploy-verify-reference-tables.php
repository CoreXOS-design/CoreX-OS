<?php
/**
 * DEPLOY-1 — post-deploy reference table verifier.
 *
 * Bootstraps Laravel, counts rows in each table that a reference seeder
 * is supposed to populate, and exits non-zero if ANY of them is empty.
 * deploy.sh invokes this at step 10b; a non-zero exit triggers the
 * failure trap which rolls back from the pre-deploy mysqldump taken at
 * step 2.
 *
 * The list is the load-bearing contract: keep it in sync with the
 * REF_SEEDERS array in deploy.sh. Adding a new reference seeder?
 * Add its table here too.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every reference table the deploy is required to leave seeded.
// One entry per seeder (PayrollSeeder is represented by payroll_tax_tables
// — if PayrollSeeder runs cleanly it populates all four Payroll* tables,
// so one canary is enough).
//
// Two entry formats:
//   - string                         → table must have >= 1 row (default).
//   - 'table' => int (minRowCount)   → table must have >= minRowCount rows.
//                                      Use the per-seeder count to catch a
//                                      PARTIAL seed (DEPLOY-FIX 2026-06-04
//                                      — staging dropped to 1 row in
//                                      market_report_types because only the
//                                      one-time migration's row survived
//                                      the DB copy; the simple `>= 1`
//                                      check passed and the deploy let it
//                                      ship. Threshold check catches it.)
//
// Per-table thresholds reflect the seeder's canonical row count at write
// time. Bump these when the seeder changes its target.
$tables = [
    'calendar_event_class_settings',
    'buyer_match_tiers',
    'agency_feedback_options',
    'public_holidays',
    'leave_types',
    'deal_pipeline_templates',
    // DEPLOY-FIX 2026-06-04 — threshold check: 13 rows per
    // MarketReportTypesSeeder. Catches the partial-seed state that
    // broke CMA imports today.
    'market_report_types' => 13,
    'deposit_trust_interest',
    'suggested_action_thresholds',
    'payroll_tax_tables',
    // M6.2-FIX — HFC activity-calendar mappings. Hard-fail on empty so a
    // future DB reload that lost these rows aborts the deploy instead of
    // silently disabling the Module 6 auto-points engine.
    'activity_definition_calendar_classes',
];

$failures = [];
fwrite(STDOUT, "Reference-table row counts:\n");

foreach ($tables as $tableKey => $tableSpec) {
    // Normalise both entry formats into (table, minCount):
    //   numeric key, string value     → ($value, 1)
    //   string key, int value         → ($key, $value)
    if (is_int($tableKey)) {
        $table = $tableSpec;
        $minCount = 1;
    } else {
        $table = $tableKey;
        $minCount = (int) $tableSpec;
    }

    if (!Schema::hasTable($table)) {
        $failures[] = "$table (TABLE MISSING)";
        fwrite(STDOUT, sprintf("  %-40s  MISSING\n", $table));
        continue;
    }
    try {
        $count = DB::table($table)->count();
    } catch (\Throwable $e) {
        $failures[] = "$table (query failed: " . $e->getMessage() . ")";
        fwrite(STDOUT, sprintf("  %-40s  ERROR: %s\n", $table, $e->getMessage()));
        continue;
    }
    $minLabel = $minCount > 1 ? sprintf(' (min %d)', $minCount) : '';
    fwrite(STDOUT, sprintf("  %-40s  %d%s\n", $table, $count, $minLabel));
    if ($count < $minCount) {
        $failures[] = "$table ({$count} rows, expected >= {$minCount})";
    }
}

if (!empty($failures)) {
    fwrite(STDERR, "\nVERIFY_FAIL: " . implode('; ', $failures) . "\n");
    fwrite(STDERR, "One or more reference tables are empty or partially seeded.\n");
    fwrite(STDERR, "Per DEPLOY-1 decision 5, this aborts the deploy. Rolling back\n");
    fwrite(STDERR, "from the pre-deploy backup.\n");
    exit(1);
}

fwrite(STDOUT, "\nVERIFY_OK: all " . count($tables) . " reference tables populated.\n");
exit(0);
