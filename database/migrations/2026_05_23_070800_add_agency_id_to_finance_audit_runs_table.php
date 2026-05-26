<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_audit_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('created_by');
        });

        DB::statement(<<<'SQL'
UPDATE finance_audit_runs t JOIN users p ON p.id=t.created_by SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `finance_audit_runs` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('finance_audit_runs')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} finance_audit_runs rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `finance_audit_runs` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('finance_audit_runs', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'finance_audit_runs_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_audit_runs', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('finance_audit_runs_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};