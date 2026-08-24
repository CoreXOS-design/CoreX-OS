<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-engagement request from an expired buyer Client Page link (Johan, 2026-08-24).
 *
 * Same shape as 2026_07_06_000001_add_website_to_portal_leads_portal_enum.php:
 * a new public, non-portal inbound channel becomes a fourth `portal` value
 * rather than a new table, so it lands in the SAME pipeline agents already
 * watch (Real Estate → Portal Leads, mobile push, the agency-scoped toast).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE portal_leads MODIFY portal ENUM('p24','pp','website','shared_link') NOT NULL");
    }

    public function down(): void
    {
        // Revert the enum. Any 'shared_link' rows would violate the narrowed
        // enum, so re-tag them to 'website' first (defensive — none exist on
        // a clean down; non-negotiable #1: no destructive rollback).
        DB::table('portal_leads')->where('portal', 'shared_link')->update(['portal' => 'website']);
        DB::statement("ALTER TABLE portal_leads MODIFY portal ENUM('p24','pp','website') NOT NULL");
    }
};
