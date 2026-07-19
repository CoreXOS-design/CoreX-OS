<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-split coverage (LEAK-2, audit 2026-07-18 §B): the buyer/property matching
 * models had no branch_id. ContactMatchController did manual branch filtering, but
 * the models were unscoped so any other query path leaked cross-branch under Split ON.
 *
 * A match belongs to the branch of the pillar it hangs off — the contact (buyer
 * requirement), the property (listing), or the prospecting listing. Inherited at
 * write time by InheritsBranchFromParent(pillar) and backfilled here from it. Runs
 * after 000002 so prospecting_listings.branch_id is already populated.
 */
return new class extends Migration
{
    /** table => [parent table, foreign key] */
    private array $tables = [
        'contact_matches'           => ['contacts', 'contact_id'],
        'property_buyer_matches'    => ['properties', 'property_id'],
        'prospecting_buyer_matches' => ['prospecting_listings', 'prospecting_listing_id'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => [$parent, $fk]) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('branch_id')->nullable()->after('agency_id')->constrained('branches')->nullOnDelete();
                });
            }
            if (Schema::hasTable($table) && Schema::hasTable($parent) && Schema::hasColumn($table, 'branch_id')) {
                DB::statement(
                    "UPDATE `{$table}` m
                     JOIN `{$parent}` p ON m.`{$fk}` = p.`id`
                     SET m.`branch_id` = p.`branch_id`
                     WHERE m.`branch_id` IS NULL AND p.`branch_id` IS NOT NULL"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('branch_id');
                });
            }
        }
    }
};
