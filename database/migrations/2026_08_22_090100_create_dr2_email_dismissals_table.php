<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CX-113 Phase G (Johan, 2026-08-22) — "getting an email that should not be in here so
 * how do i remove it?" Real example on his own screen: info@ppcexperts.co.za, a Google
 * Ads/web-design supplier, sitting in the DR2 unfiled queue.
 *
 * Reversible, agency-wide (same as filing — "if it is not deal correspondence it is not
 * deal correspondence for anyone"), reason-tagged, and it NEVER touches the underlying
 * Communication row or its contact link — this table only decides whether a row is
 * offered on the DR2 Unfiled Emails screen, nothing else. One row per communication,
 * updated in place across dismiss/restore cycles (no need for CommunicationLink's
 * append-only multi-row shape here — there is nothing this table needs to preserve
 * competing history of, only the current + most recent decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_dr2_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'dr2_dism_agency_fk')->cascadeOnDelete();
            $table->foreignId('communication_id')->constrained('communications', 'id', 'dr2_dism_comm_fk')->cascadeOnDelete();

            $table->string('reason', 40);
            $table->string('reason_other', 255)->nullable();
            $table->foreignId('dismissed_by_user_id')->constrained('users', 'id', 'dr2_dism_by_fk')->cascadeOnDelete();
            $table->timestamp('dismissed_at');

            // Null while active. A restore never deletes the row — the fact that this
            // email was once dismissed, by whom, why, and who put it back stays on the
            // record, matching the rest of DR2's "nothing here is ever destroyed" idiom.
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users', 'id', 'dr2_dism_restored_by_fk')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();

            $table->timestamps();

            $table->unique('communication_id', 'dr2_dism_comm_uq');
            $table->index(['agency_id', 'restored_at'], 'dr2_dism_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_dr2_dismissals');
    }
};
