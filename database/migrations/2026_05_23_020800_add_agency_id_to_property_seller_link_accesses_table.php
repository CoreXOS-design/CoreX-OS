<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_seller_link_accesses', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('link_id');
        });

        DB::statement(<<<'SQL'
UPDATE property_seller_link_accesses t JOIN property_seller_links p ON p.id=t.link_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `property_seller_link_accesses` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('property_seller_link_accesses')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} property_seller_link_accesses rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `property_seller_link_accesses` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('property_seller_link_accesses', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'property_seller_link_accesses_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('property_seller_link_accesses', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('property_seller_link_accesses_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};