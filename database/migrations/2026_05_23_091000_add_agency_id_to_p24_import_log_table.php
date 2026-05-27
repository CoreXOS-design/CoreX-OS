<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p24_import_log', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('id');
        });

        DB::statement(<<<'SQL'
UPDATE p24_import_log SET agency_id=1 WHERE agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `p24_import_log` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('p24_import_log')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} p24_import_log rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `p24_import_log` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('p24_import_log', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'p24_import_log_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('p24_import_log', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('p24_import_log_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};