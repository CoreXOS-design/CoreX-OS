<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — until now "did not attend" was stored as a
 * REFUSAL with the preset reason `not_present` and printed as "Refused to sign". Attendance is now its
 * own recorded fact, so every live signature row of that shape gets a matching `did_not_attend`
 * attendance row (note `from signature record`), and the screens switch to "No signature — did not
 * attend". The signature rows themselves are left exactly as they are (evidence is never rewritten).
 *
 * Idempotent: a party that already has a live attendance row on that inspection is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rental_inspection_signatures')
            ->where('disposition', 'refused')
            ->where('refusal_reason_preset', 'not_present')
            ->whereNull('superseded_at')
            ->whereIn('party_role', ['tenant', 'landlord'])
            ->orderBy('id')
            ->chunkById(200, function ($signatures) {
                foreach ($signatures as $signature) {
                    $exists = DB::table('rental_inspection_attendances')
                        ->where('rental_inspection_id', $signature->rental_inspection_id)
                        ->where('party_role', $signature->party_role)
                        ->where('party_contact_id', $signature->party_contact_id)
                        ->whereNull('superseded_at')
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $when = $signature->disposition_recorded_at ?? now();
                    DB::table('rental_inspection_attendances')->insert([
                        'agency_id' => $signature->agency_id,
                        'rental_inspection_id' => $signature->rental_inspection_id,
                        'party_role' => $signature->party_role,
                        'party_contact_id' => $signature->party_contact_id,
                        'attended_as' => 'self',
                        'outcome' => 'did_not_attend',
                        'note' => 'from signature record',
                        'recorded_by_user_id' => $signature->recorded_by_user_id,
                        'recorded_at' => $when,
                        'created_at' => $when,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('rental_inspection_attendances')->where('note', 'from signature record')->delete();
    }
};
