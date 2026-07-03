<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WS0 (AT-158 / DR2) — engine-correctness fix caught by DealPipelineEngineTest.
 *
 * The seeded pipeline templates (Standard Bond Sale, Cash Sale) carry
 * status_trigger = 'granted' on the Bond-Approved / Deposit-Paid steps, and
 * DealPipelineService::changeDealStatus() writes the trigger value straight into
 * deals_v2.status. But the column was enum('active','completed','cancelled',
 * 'on_hold') — with no 'granted' — so completing that step threw
 * "Data truncated for column 'status'" (SQLSTATE 1265) and the deal never moved
 * to granted. 'granted' is a first-class deal state (v1.1 spec §3: bond
 * approved → granted). Add it to the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `deals_v2` MODIFY `status` ".
            "ENUM('active','granted','completed','cancelled','on_hold') NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        // Collapse any 'granted' rows back to 'active' so the narrower enum applies.
        DB::table('deals_v2')->where('status', 'granted')->update(['status' => 'active']);
        DB::statement(
            "ALTER TABLE `deals_v2` MODIFY `status` ".
            "ENUM('active','completed','cancelled','on_hold') NOT NULL DEFAULT 'active'"
        );
    }
};
