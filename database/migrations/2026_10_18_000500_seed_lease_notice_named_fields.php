<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §18.8 — the template editor's merge-field picker (the named-field catalogue) gets the lease's
 * notice and early-cancellation terms, so any agency can place them in its own lease agreement. Same shape as
 * 2026_09_04_170000 (Rental Amount[words]): computed source, existence-checked, never destructive on down().
 */
return new class extends Migration
{
    private const FIELDS = [
        ['Lease Notice Period', 'notice_period', 'text'],
        ['Lease Notice Period Unit', 'notice_period_unit', 'text'],
        ['Lease Earliest Notice Date', 'earliest_notice_date', 'date'],
        ['Lease Early Cancellation Allowed', 'early_cancellation_allowed', 'text'],
        ['Lease Early Cancellation Notice', 'early_cancellation_notice', 'text'],
        ['Lease Early Cancellation Notice Unit', 'early_cancellation_notice_unit', 'text'],
        ['Lease Early Cancellation Penalty', 'early_cancellation_penalty', 'text'],
    ];

    public function up(): void
    {
        $order = 940;
        foreach (self::FIELDS as [$name, $column, $type]) {
            $order++;
            $exists = DB::table('docuperfect_named_fields')
                ->where('source_type', 'computed')
                ->where('source_column', $column)
                ->whereNull('source_contact_type')
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('docuperfect_named_fields')->insert([
                'name' => $name,
                'field_type' => $type,
                'source_type' => 'computed',
                'source_column' => $column,
                'source_contact_type' => null,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op: reference catalogue rows; removing them would break any template field already mapped to one.
    }
};
