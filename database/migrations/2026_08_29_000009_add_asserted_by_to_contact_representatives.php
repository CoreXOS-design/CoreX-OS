<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-26 — "the act of an agent picking someone in 'Replace this
 * party' IS how that relationship comes into existence in the real world."
 * ESignWizardController::resolveChainBindings() now creates the
 * contact_representatives row a "Replace this party" binding needs, instead
 * of demanding one already exist with no screen anywhere that could have
 * created it. asserted_by_user_id records which agent's pick created the
 * link — the person holding the letter of executorship/POA in front of
 * them, not a system inference.
 *
 * Nullable, no backfill: every existing row (created directly on the
 * contact record, or by an earlier feature) simply has no asserter on file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_representatives', function (Blueprint $table) {
            $table->foreignId('asserted_by_user_id')->nullable()->after('signs_as_proxy')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_representatives', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asserted_by_user_id');
        });
    }
};
