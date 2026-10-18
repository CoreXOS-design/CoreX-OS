<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "current name -> code AND description (so nothing is lost)" (Johan).
 * `description` just copies `name` verbatim — nothing to derive. `code`
 * needs a short, agency-unique value derived from the same name: uppercase,
 * non-alphanumeric collapsed to a single dash, truncated to 20 chars, with
 * a numeric suffix appended on collision within the SAME agency's
 * non-archived rows (archived rows are excluded from the collision check
 * the same way the application-layer uniqueness rule will be — archived
 * code reuse is allowed by design).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('rental_catalogue_items')
            ->select('id', 'agency_id', 'name')
            ->orderBy('agency_id')
            ->orderBy('id')
            ->get();

        $usedPerAgency = [];

        foreach ($rows as $row) {
            $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($row->name)));
            $base = trim($base, '-');
            $base = $base === '' ? 'ITEM' : $base;
            $base = substr($base, 0, 20);

            $usedPerAgency[$row->agency_id] ??= [];
            $code = $base;
            $suffix = 2;
            while (in_array($code, $usedPerAgency[$row->agency_id], true)) {
                $code = substr($base, 0, 20 - strlen((string) $suffix) - 1) . '-' . $suffix;
                $suffix++;
            }
            $usedPerAgency[$row->agency_id][] = $code;

            DB::table('rental_catalogue_items')->where('id', $row->id)->update([
                'code' => $code,
                'description' => $row->name,
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to revert — the columns themselves are dropped by the
        // migration that added them, on its own down().
    }
};
