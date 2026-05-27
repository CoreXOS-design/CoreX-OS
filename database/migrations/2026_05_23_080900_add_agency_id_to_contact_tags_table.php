<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('is_active');
        });

        DB::statement(<<<'SQL'
UPDATE contact_tags SET agency_id=1 WHERE agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `contact_tags` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('contact_tags')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} contact_tags rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `contact_tags` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('contact_tags', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'contact_tags_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_tags', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('contact_tags_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};