<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-level overrides for the deterministic ingestion filter (AT-43).
 * NULL means "inherit the config default" (config/communications.php) — the
 * filter never hardcodes the rules; these columns let an agency tune them.
 *   - communication_ingest_drop_noreply: tri-state (null=default, 0/1 override)
 *   - communication_ingest_blocklist_domains: null=default list, else the
 *     agency's own full list of service/bank/portal domains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('communication_ingest_drop_noreply')->nullable();
            $table->json('communication_ingest_blocklist_domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['communication_ingest_drop_noreply', 'communication_ingest_blocklist_domains']);
        });
    }
};
