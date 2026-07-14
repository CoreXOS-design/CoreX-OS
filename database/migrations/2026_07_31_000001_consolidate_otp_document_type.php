<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-254 decision A — ONE OTP slug. The catalogue carried two: `otp` (ES-1 / e-sign,
 * the slug the distribution matrix keys 20 rules to) and `offer_to_purchase` (the
 * splitter's pre-ES-1 slug). A splitter-filed OTP therefore never matched a
 * distribution rule. Consolidate onto the canonical `otp`: carry the party
 * contact_roles across, repoint every reference, retire the duplicate. Slug-lookup
 * based (ids differ per env) + idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $otp = DB::table('document_types')->where('slug', 'otp')->first();
        $old = DB::table('document_types')->where('slug', 'offer_to_purchase')->whereNull('deleted_at')->first();
        if (! $otp || ! $old) {
            return; // already consolidated, or a catalogue without the duplicate — no-op
        }

        // Party contact_roles are the filing authority (AT-254 decision B) — carry the
        // retired slug's [seller_owner, buyer] onto the canonical otp if it lacks them.
        if (empty($otp->contact_roles) && ! empty($old->contact_roles)) {
            DB::table('document_types')->where('id', $otp->id)->update(['contact_roles' => $old->contact_roles]);
        }

        // Repoint every FK reference from the retired slug → otp.
        DB::table('documents')->where('document_type_id', $old->id)->update(['document_type_id' => $otp->id]);
        DB::table('docuperfect_templates')->where('document_type_id', $old->id)->update(['document_type_id' => $otp->id]);

        // Retire the duplicate — soft-delete + inactive (admin-recoverable; no hard delete).
        DB::table('document_types')->where('id', $old->id)->update([
            'is_active'  => false,
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Best-effort: reactivate the retired slug. The FK repoints are a one-way data
        // merge and are NOT reversed (the two types are now one).
        DB::table('document_types')->where('slug', 'offer_to_purchase')->update([
            'is_active'  => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ]);
    }
};
