<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_portal_links', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('contact_id');
        });

        DB::statement(<<<'SQL'
UPDATE buyer_portal_links t JOIN contacts p ON p.id=t.contact_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `buyer_portal_links` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('buyer_portal_links')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} buyer_portal_links rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `buyer_portal_links` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('buyer_portal_links', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'buyer_portal_links_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_portal_links', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('buyer_portal_links_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};