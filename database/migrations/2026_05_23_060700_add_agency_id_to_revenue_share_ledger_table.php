<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_share_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('commission_ledger_id');
        });

        DB::statement(<<<'SQL'
UPDATE revenue_share_ledger t JOIN commission_ledger p ON p.id=t.commission_ledger_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `revenue_share_ledger` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('revenue_share_ledger')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} revenue_share_ledger rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `revenue_share_ledger` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('revenue_share_ledger', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'revenue_share_ledger_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('revenue_share_ledger', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('revenue_share_ledger_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};