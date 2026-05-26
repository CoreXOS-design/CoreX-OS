<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_evaluation_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('commercial_evaluation_id');
        });

        DB::statement(<<<'SQL'
UPDATE commercial_evaluation_assets t JOIN commercial_evaluations p ON p.id=t.commercial_evaluation_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `commercial_evaluation_assets` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('commercial_evaluation_assets')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} commercial_evaluation_assets rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `commercial_evaluation_assets` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('commercial_evaluation_assets', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'commercial_evaluation_assets_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_evaluation_assets', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('commercial_evaluation_assets_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};