<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website lead capture (Agency Public API §9 → built).
 *
 * The public agency website POSTs property enquiries to
 * POST /api/v1/website/leads. Those land in the SAME portal_leads pipeline as
 * Property24 / Private Property leads, so "website" becomes a third portal
 * value. This migration only widens the enum.
 *
 * The "Website" contact source is per-agency (contact_sources is tenant-scoped),
 * so it is created lazily per agency by WebsiteLeadService — not seeded globally.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE portal_leads MODIFY portal ENUM('p24','pp','website') NOT NULL");
    }

    public function down(): void
    {
        // Revert the enum. Any 'website' rows would violate the narrowed enum,
        // so re-tag them to 'pp' first (defensive — none exist on a clean down).
        DB::table('portal_leads')->where('portal', 'website')->update(['portal' => 'pp']);
        DB::statement("ALTER TABLE portal_leads MODIFY portal ENUM('p24','pp') NOT NULL");

        // Intentionally keep the "Website" contact_source — it may already be
        // referenced by Contact rows (non-negotiable #1: no destructive rollback).
    }
};
