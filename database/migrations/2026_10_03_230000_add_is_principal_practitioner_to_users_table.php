<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase E — item (c), .ai/specs/ppra-inspection-pack.md
 * §6.6a (v3 correction, Johan 2026-09-28). Replaces the fragile
 * `designation LIKE '%Principal%'` match with a real flag: agency-scoped
 * (users.agency_id already exists), several users may hold it per agency,
 * edited by an admin on the user profile screen, audit-logged via the
 * existing domain-events ledger (see AgentPrincipalPractitionerFlagChanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_principal_practitioner')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_principal_practitioner')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_principal_practitioner');
        });
    }
};
