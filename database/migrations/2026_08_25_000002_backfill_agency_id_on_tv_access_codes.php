<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `tv_access_codes.agency_id`.
 *
 * Two sources, tried in order:
 *   1. `branch_id` -> `branches.agency_id` (per-branch codes — the majority,
 *      and the most trustworthy source: a code's branch IS its agency).
 *   2. `created_by` -> `users.agency_id` (company-wide codes, branch_id is
 *      NULL by design for these — falls back to whoever created the code).
 *
 * Rows still left NULL (company code created by a deleted/agency-less
 * user) are reported — the follow-up NOT NULL migration refuses to
 * advance if any remain; those rows need a manual agency_id assignment
 * (they are orphaned company codes and should probably just be revoked).
 */
return new class extends Migration {
    public function up(): void
    {
        $viaBranch = DB::update(
            'UPDATE tv_access_codes tc '
            . 'INNER JOIN branches b ON b.id = tc.branch_id '
            . 'SET tc.agency_id = b.agency_id '
            . 'WHERE tc.agency_id IS NULL AND b.agency_id IS NOT NULL'
        );

        $viaCreator = DB::update(
            'UPDATE tv_access_codes tc '
            . 'INNER JOIN users u ON u.id = tc.created_by '
            . 'SET tc.agency_id = u.agency_id '
            . 'WHERE tc.agency_id IS NULL AND u.agency_id IS NOT NULL'
        );

        $stillNull = DB::table('tv_access_codes')->whereNull('agency_id')->count();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "    -> tv_access_codes backfill: {$viaBranch} row(s) via branch_id, {$viaCreator} row(s) via created_by (still-null: {$stillNull})" . PHP_EOL);
        }
    }

    public function down(): void
    {
        DB::table('tv_access_codes')->update(['agency_id' => null]);
    }
};
