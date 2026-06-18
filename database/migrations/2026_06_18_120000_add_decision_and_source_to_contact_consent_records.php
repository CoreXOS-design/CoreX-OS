<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tri-state consent ledger (spec: .ai/specs/contact-consent.md §3).
 *
 * Adds `decision` (given|declined) so an explicit refusal — "do not contact me
 * this way" — is recordable as distinct from "never captured", and `source` so
 * we know whether the agent, the client app, or an import wrote it. Makes
 * `given_by_user_id` nullable because a client self-service write has no User.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_consent_records', function (Blueprint $table) {
            $table->enum('decision', ['given', 'declined'])
                ->default('given')
                ->after('consent_type');
            $table->string('source', 30)
                ->nullable()
                ->after('method');
        });

        // Self-service (client app) writes have no User actor.
        Schema::table('contact_consent_records', function (Blueprint $table) {
            $table->foreignId('given_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contact_consent_records', function (Blueprint $table) {
            $table->dropColumn(['decision', 'source']);
        });
    }
};
