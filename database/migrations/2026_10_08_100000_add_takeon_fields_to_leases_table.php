<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §6 (amended) — four facts captured on
 * the take-on import row's own payload but previously inert (never written
 * anywhere a human could see). Read-only, display-only facts about the
 * tenancy as it stood on the day it was taken on — never written to by any
 * other flow, never editable, never fed into any calculation.
 *
 * Deliberately NOT a scheduled-escalation feature and NOT a rental ledger
 * (leases.md §3.4/§3.3, rental-takeon-import.md §6's own reasoning for why
 * those are out of scope) — these are historical facts about ONE
 * migrated-in lease, not a general lease capability every lease gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('migrated_escalation_percent', 6, 2)->nullable()->after('migrated_from_id');
            $table->date('migrated_next_escalation_date')->nullable()->after('migrated_escalation_percent');
            $table->decimal('migrated_opening_arrears', 12, 2)->nullable()->after('migrated_next_escalation_date');
            $table->date('migrated_last_inspection_date')->nullable()->after('migrated_opening_arrears');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn([
                'migrated_escalation_percent',
                'migrated_next_escalation_date',
                'migrated_opening_arrears',
                'migrated_last_inspection_date',
            ]);
        });
    }
};
