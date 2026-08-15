<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DR2 company (entity) party — per-deal email routing choice (D-min, spec:
 * dr2-company-selection).
 *
 * When the seller/buyer party is a COMPANY (contact_kind='entity'), the deal
 * emails go to the company's natural-person representative(s), NOT the company
 * (an entity has no email). WHO gets the mail is decided by the shared,
 * proxy-aware foundation cc1 owns (capacity + signs_as_proxy on
 * contact_representatives; Contact::emailRepresentatives()). This column stores
 * ONLY the agent's per-deal CHOICE of routing mode; the actual recipients are
 * RE-RESOLVED LIVE at send from cc1's API (single source of truth — Johan).
 *
 *   null / 'inherit' → honour the entity's configured proxy rule
 *                      (proxy signs/e-mails for all, else all reps).
 *   'all'            → e-mail EVERY representative (override any proxy).
 *   'proxy'          → e-mail ONLY the designated proxy.
 *
 * Meaningful only on an entity party row; a natural-person party ignores it.
 * DR2 adds NO capacity/proxy columns of its own — those live on cc1's
 * contact_representatives foundation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contacts', function (Blueprint $table) {
            $table->string('representative_email_mode', 20)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('deal_contacts', function (Blueprint $table) {
            $table->dropColumn('representative_email_mode');
        });
    }
};
