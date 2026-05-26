<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('pipeline_template_id');
        });

        DB::statement(<<<'SQL'
UPDATE deal_pipeline_steps t JOIN deal_pipeline_templates p ON p.id=t.pipeline_template_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `deal_pipeline_steps` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('deal_pipeline_steps')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} deal_pipeline_steps rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `deal_pipeline_steps` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'deal_pipeline_steps_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('deal_pipeline_steps_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};