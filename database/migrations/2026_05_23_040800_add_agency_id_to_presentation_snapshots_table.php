<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('presentation_id');
        });

        DB::statement(<<<'SQL'
UPDATE presentation_snapshots t JOIN presentations p ON p.id=t.presentation_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `presentation_snapshots` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('presentation_snapshots')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} presentation_snapshots rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `presentation_snapshots` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('presentation_snapshots', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'presentation_snapshots_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_snapshots', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('presentation_snapshots_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};