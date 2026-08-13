<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate-authorisation flow — the candidate PIN-signs their part and submits for
 * a full-status practitioner to authorise + sign. The final immutable PDF is baked at
 * the AUTHORISER's sign, when the candidate is no longer present to unlock their saved
 * signature — so the candidate's signature image is SNAPSHOTTED here at submit time
 * (encrypted at rest, same as agent_signatures) and baked into the "Evaluated & signed
 * by" slot at authorisation.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('evaluation_certificates', function (Blueprint $table) {
            $table->longText('candidate_signature_image')->nullable()->after('reject_note');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_certificates', function (Blueprint $table) {
            $table->dropColumn('candidate_signature_image');
        });
    }
};
