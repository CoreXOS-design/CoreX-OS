<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `company_expenses.agency_id`.
 *
 * Unlike rental_properties/docuperfect_documents, CompanyExpense rows carry
 * no created_by/owner/branch column of their own — CompanySummaryController
 * only ever keys them by `period` (`firstOrCreate(['period' => $period],
 * ...)`), so every agency viewing the Company Summary page for the same
 * month shared the same row. There is no column to backfill from directly.
 *
 * Each expense row's `period`, however, corresponds to the Worksheet rows
 * loaded alongside it in the very same controller method
 * (`Worksheet::where('period', $period)` runs right next to the
 * CompanyExpense::firstOrCreate() call), so the agency with the most
 * worksheets for that period (via worksheets.user_id -> users.agency_id) is
 * the best-available signal for which agency actually entered that period's
 * expense figure. Periods with no matching worksheets are left NULL and
 * reported — the follow-up NOT NULL migration refuses to advance if any
 * remain, at which point they must be assigned manually.
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('company_expenses')->whereNull('agency_id')->get(['id', 'period']);

        $affected = 0;

        foreach ($rows as $row) {
            $top = DB::table('worksheets')
                ->join('users', 'users.id', '=', 'worksheets.user_id')
                ->where('worksheets.period', $row->period)
                ->whereNotNull('users.agency_id')
                ->selectRaw('users.agency_id, count(*) as c')
                ->groupBy('users.agency_id')
                ->orderByDesc('c')
                ->first();

            if ($top && $top->agency_id) {
                DB::table('company_expenses')->where('id', $row->id)->update(['agency_id' => $top->agency_id]);
                $affected++;
            }
        }

        $stillNull = DB::table('company_expenses')->whereNull('agency_id')->count();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "    -> company_expenses backfill: set agency_id via period/worksheets/users on {$affected} row(s) (still-null: {$stillNull})" . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Reverse the backfill so the "add column" migration's down() can
        // drop the column cleanly.
        DB::table('company_expenses')->update(['agency_id' => null]);
    }
};
