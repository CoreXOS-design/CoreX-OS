<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when an agent invite was last sent.
 *
 * The P24 importer's bulk "Send invite links" action (Property Onboarding
 * review page) sends to every agent imported for an agency in one press.
 * Without a stamp there is no way to tell an already-invited agent from a
 * never-invited one, so a second press would re-mail the whole agency.
 * Stamped by SendAgentInviteJob after the notification is delivered — the
 * single choke point both the bulk and per-agent paths run through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invited_at');
        });
    }
};
