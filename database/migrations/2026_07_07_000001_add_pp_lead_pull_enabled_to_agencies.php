<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-199 — Private Property buyer-enquiry LEAD PULL toggle.
 *
 * Adds a per-agency kill-switch that gates the scheduled PP lead pull
 * (ListingLeadDetailsFeed → portal_leads). DEFAULT OFF: the code ships
 * dormant and only pulls once an admin flips this on. Mirrors the existing
 * `pp_enabled` credential switch — same shape, same surface. Additive and
 * reversible; no data touched.
 *
 * NOTE: no portal-enum change is needed — 'pp' is already a valid
 * portal_leads.portal value (PortalLead::PORTAL_PP), seeded when the PP
 * webhook path was built.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'pp_lead_pull_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('pp_lead_pull_enabled')
                  ->default(false)
                  ->after('pp_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'pp_lead_pull_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('pp_lead_pull_enabled');
        });
    }
};
