<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC ↔ Deeds ↔ Contact loop — Part B (Johan 2026-08-14).
 *
 * The "No contact details available" dead-end marker. When an agent captures a seller from a deed
 * but there is genuinely nothing contactable (no TVA record / opted out / no record found), the
 * contact + property are still created, and THIS row records the dead end on the one canonical
 * contact so any future agent immediately sees it's been chased and there's nothing to reach.
 *
 * One active flag per contact (unique contact_id — updateOrCreate); the audit trail
 * (contact_audit_log) carries the compounding history. Kept off the contacts table (cc3's pillar)
 * so it composes without touching that schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_dead_end_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('contact_id')->unique();          // one active flag per contact
            $table->unsignedBigInteger('property_id')->nullable();       // the property being pitched, when known
            $table->string('reason', 32)->default('not_in_tva');         // opted_out | not_in_tva | no_record_found
            $table->string('source', 40)->nullable();                    // where it was set (e.g. seller_outreach)
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['agency_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_dead_end_flags');
    }
};
