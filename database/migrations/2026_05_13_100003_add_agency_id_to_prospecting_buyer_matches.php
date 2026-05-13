<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add nullable column first so we can backfill before enforcing NOT NULL.
        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('contact_id');
        });

        // 2. Backfill from the contact's agency_id.
        DB::statement(<<<'SQL'
            UPDATE prospecting_buyer_matches pbm
            INNER JOIN contacts c ON c.id = pbm.contact_id
            SET pbm.agency_id = c.agency_id
        SQL);

        // 3. Verify zero NULLs before enforcing NOT NULL.
        $nullCount = DB::table('prospecting_buyer_matches')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} prospecting_buyer_matches rows still have NULL agency_id after backfill. "
                . 'Investigate orphans before continuing.'
            );
        }

        // 4. Enforce NOT NULL via raw SQL (avoids doctrine/dbal dependency for change()).
        DB::statement('ALTER TABLE prospecting_buyer_matches MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        // 5. Add FK + indexes.
        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->index(['agency_id', 'contact_id'], 'pbm_agency_contact_idx');
            $table->index(['agency_id', 'prospecting_listing_id'], 'pbm_agency_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            // MySQL requires the FK to be dropped before the index it depends on.
            $table->dropForeign(['agency_id']);
            $table->dropIndex('pbm_agency_listing_idx');
            $table->dropIndex('pbm_agency_contact_idx');
            $table->dropColumn('agency_id');
        });
    }
};
