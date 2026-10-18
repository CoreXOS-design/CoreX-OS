<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §3 — AT-445. A landlord now
 * approves/declines/"I'll handle it myself" DIRECTLY through the portal —
 * the click itself is the evidence, no agent transcription. Existing
 * `evidence_type` values (whatsapp|email|verbal_note) all describe an
 * agent recording something told to them; this adds the fourth value,
 * 'portal', plus the contact-attributed counterpart to the existing
 * agent-attributed `recorded_by_user_id`. Exactly one of the two actor
 * columns is set per row (enforced at the application layer in
 * RentalFaultReport::recordApproval()/RentalWorkOrder::recordApproval(),
 * which now accept `User|Contact`), matching this table's own established
 * "exactly one of" pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->foreignId('recorded_by_contact_id')->nullable()
                ->after('recorded_by_user_id')
                ->constrained('contacts', indexName: 'rental_approvals_contact_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_contact_id');
        });
    }
};
