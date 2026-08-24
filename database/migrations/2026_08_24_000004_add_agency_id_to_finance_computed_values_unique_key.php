<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * finance_computed_values previously had a unique key of
 * (definition_id, entity_type, entity_id, period) with NO agency_id
 * component, even though the agency_id column itself was added earlier
 * (2026_05_23_071000_add_agency_id_to_finance_computed_values_table).
 *
 * Because company_period rows are keyed on the hardcoded entity_id=1
 * platform-wide (see FinanceReadModel::getCompanyPeriodMap), two agencies
 * rolling up the SAME period collided on this unique key: whichever
 * agency's deal-save triggered the first RollupService::computeRollups()
 * for that period "won" the row, and every other agency's upsert attempt
 * for the same (definition_id, entity_type=company_period, entity_id=1,
 * period) failed with a duplicate-key error. This migration widens the
 * unique key to include agency_id so each agency gets its own row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the new (wider) unique index FIRST, keeping definition_id as its
        // leading column, so InnoDB always has a supporting index for the
        // finance_computed_values_definition_id_foreign FK — dropping the old
        // unique index before a replacement exists fails with error 1553
        // ("needed in a foreign key constraint").
        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->unique(
                ['definition_id', 'agency_id', 'entity_type', 'entity_id', 'period'],
                'fcv_agency_def_entity_period_unique'
            );
        });

        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->dropUnique('fcv_def_entity_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->unique(
                ['definition_id', 'entity_type', 'entity_id', 'period'],
                'fcv_def_entity_period_unique'
            );
        });

        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->dropUnique('fcv_agency_def_entity_period_unique');
        });
    }
};
