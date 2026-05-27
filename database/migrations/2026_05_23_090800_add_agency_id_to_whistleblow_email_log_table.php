<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whistleblow_email_log', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('complaint_id');
        });

        DB::statement(<<<'SQL'
UPDATE whistleblow_email_log t JOIN whistleblow_complaints p ON p.id=t.complaint_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `whistleblow_email_log` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('whistleblow_email_log')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} whistleblow_email_log rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `whistleblow_email_log` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('whistleblow_email_log', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'whistleblow_email_log_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whistleblow_email_log', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('whistleblow_email_log_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};