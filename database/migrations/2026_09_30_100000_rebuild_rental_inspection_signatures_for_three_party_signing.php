<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §15 — Johan's 2026-09-20 fuller ruling:
 * "inspections both in and out needs all party signatures. tenant, landlord
 * and agent... so all parties needs to sign, and the agent can mark either
 * tenant and / or landlord refuses to sign." Rebuilds this table's shape in
 * place (renames, never drops — QA1 already has real rows from tonight's
 * walk) rather than a fresh table, per CoreX's no-hard-delete standard.
 *
 * `signer_role`'s 'agent_on_behalf' value is retired — the agent is never a
 * stand-in party, only ever the attesting signer (§15.2). Existing
 * 'agent_on_behalf' rows are backfilled as a REFUSED tenant disposition
 * (that value was only ever used for "tenant refused to sign", per the old
 * REQUIRED_REFUSAL_PHRASE) rather than dropped — no hard deletes, and the
 * historical fact these rows represent (a tenant refused, an agent recorded
 * it) still holds true under the new shape, just expressed correctly.
 *
 * `party_contact_id`/`recorded_by_user_id` are kept NULLABLE at the schema
 * level even though the new model (RentalInspectionSignature::capture())
 * requires them for every NEW row — old rows never populated
 * signer_contact_id at all (checked: the UI never sent it), so a NOT NULL
 * constraint here would be a fabricated backfill, not a real one. The
 * requirement is enforced going forward at the single factory method, the
 * same place every other invariant in this table is enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->renameColumn('signer_role', 'party_role');
            $table->renameColumn('signer_contact_id', 'party_contact_id');
            $table->renameColumn('signed_by_user_id', 'recorded_by_user_id');
            $table->renameColumn('signature_path', 'party_signature_path');
            $table->renameColumn('refused_note', 'refusal_reason_note');
            $table->renameColumn('signed_at', 'disposition_recorded_at');
        });

        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            // Default 'signed' covers every pre-existing row except the
            // agent_on_behalf ones, corrected explicitly below — avoids a
            // two-step nullable-then-backfill-then-not-null dance for a
            // column that's only ever set explicitly by application code
            // going forward.
            $table->string('disposition', 10)->default('signed')->after('party_role');
            $table->string('refusal_reason_preset', 60)->nullable()->after('party_signature_path');
        });

        // Backfill: an old agent_on_behalf row is a REFUSED tenant
        // disposition (§ this migration's own docblock) — never dropped,
        // re-expressed under the new shape.
        DB::table('rental_inspection_signatures')
            ->where('party_role', 'agent_on_behalf')
            ->update([
                'party_role' => 'tenant',
                'disposition' => 'refused',
                'refusal_reason_preset' => 'other',
            ]);
    }

    public function down(): void
    {
        // Reverses the backfill first so the rename-down doesn't lose the
        // agent_on_behalf marker for rows this migration touched.
        DB::table('rental_inspection_signatures')
            ->where('disposition', 'refused')
            ->where('refusal_reason_preset', 'other')
            ->where('party_role', 'tenant')
            ->update(['party_role' => 'agent_on_behalf']);

        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropColumn(['disposition', 'refusal_reason_preset']);
        });

        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->renameColumn('party_role', 'signer_role');
            $table->renameColumn('party_contact_id', 'signer_contact_id');
            $table->renameColumn('recorded_by_user_id', 'signed_by_user_id');
            $table->renameColumn('party_signature_path', 'signature_path');
            $table->renameColumn('refusal_reason_note', 'refused_note');
            $table->renameColumn('disposition_recorded_at', 'signed_at');
        });
    }
};
