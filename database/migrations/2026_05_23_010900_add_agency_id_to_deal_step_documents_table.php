<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('deal_step_instance_id');
        });

        DB::statement(<<<'SQL'
UPDATE deal_step_documents t JOIN deal_step_instances p ON p.id=t.deal_step_instance_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `deal_step_documents` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('deal_step_documents')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} deal_step_documents rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `deal_step_documents` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('deal_step_documents', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'deal_step_documents_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_documents', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('deal_step_documents_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};