<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_screening_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('employee_screening_id');
        });

        DB::statement(<<<'SQL'
UPDATE employee_screening_checks t JOIN employee_screenings p ON p.id=t.employee_screening_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `employee_screening_checks` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('employee_screening_checks')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} employee_screening_checks rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `employee_screening_checks` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('employee_screening_checks', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'employee_screening_checks_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_screening_checks', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('employee_screening_checks_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};