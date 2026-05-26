<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('branch_id');
        });

        DB::statement(<<<'SQL'
UPDATE deals_v2 t JOIN branches p ON p.id=t.branch_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `deals_v2` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('deals_v2')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} deals_v2 rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `deals_v2` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('deals_v2', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'deals_v2_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('deals_v2_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};