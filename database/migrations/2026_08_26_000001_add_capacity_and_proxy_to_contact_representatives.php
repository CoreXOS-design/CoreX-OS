<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entity-rep SHARED FOUNDATION (Johan, 2026-08-15) — capacity + proxy on the
 * entity <-> natural-person link, consumed by BOTH esign (recipient builder)
 * and DR2 (company attorney/supplier signers). Spec: .ai/specs/contact-entity-type.md §6.
 *
 * - `capacity` = the representative's capacity FOR THIS entity link
 *   (Director / Executor / Trustee / Member / Other). Per-link by design: a
 *   person can be Director of company X AND Executor of estate Y.
 * - `signs_as_proxy` = this representative holds proxy to sign on behalf of
 *   ALL representatives of this entity. Resolution rule (Contact::signingRepresentatives):
 *   if any rep of an entity has signs_as_proxy=true → only that rep signs;
 *   otherwise ALL reps sign (e.g. 4 directors each sign unless one is proxy).
 *
 * Additive, no backfill: existing rows get capacity=NULL + signs_as_proxy=false,
 * which is exactly the pre-feature behaviour (all reps, no capacity label).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_representatives', function (Blueprint $table) {
            $table->string('capacity', 40)->nullable()->after('is_primary');
            $table->boolean('signs_as_proxy')->default(false)->after('capacity');

            // Proxy lookup per entity (Contact::signingRepresentatives / hasProxyRepresentative).
            $table->index(['entity_contact_id', 'signs_as_proxy'], 'contact_reps_entity_proxy_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_representatives', function (Blueprint $table) {
            $table->dropIndex('contact_reps_entity_proxy_idx');
            $table->dropColumn(['capacity', 'signs_as_proxy']);
        });
    }
};
