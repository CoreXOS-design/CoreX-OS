<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release audit 2026-10-18, C-F2 (Johan: reminder emails only) - the day-before inspection REMINDER to a tenant / landlord is
 * opt-in, separate from the scheduled / rescheduled / cancelled notices (which keep their existing switches and defaults).
 * Nullable, read-time default (null = OFF), same pattern as every other column on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['remind_tenant_by_email', 'remind_landlord_by_email'] as $column) {
            if (! Schema::hasColumn('rental_inspection_settings', $column)) {
                Schema::table('rental_inspection_settings', function (Blueprint $table) use ($column) {
                    $table->boolean($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['remind_tenant_by_email', 'remind_landlord_by_email'] as $column) {
            if (Schema::hasColumn('rental_inspection_settings', $column)) {
                Schema::table('rental_inspection_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
