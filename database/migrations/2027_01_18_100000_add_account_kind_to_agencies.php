<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solo Agent Accounts (.ai/specs/solo-agent-accounts.md section 9.1, build step 1).
 *
 * Marks whether an agency is a normal agency or a single-agent ("solo") account. A solo agent is an agency
 * with exactly one user, so every tenant mechanism (AgencyScope, billing, suspension, e-sign) keeps working.
 *
 * The column carries a default of 'agency', so EVERY existing agency (HFC included) is a normal agency the
 * moment the column exists - no data backfill statement, no agency id in code, and nothing about an existing
 * agency's behaviour changes. Deliberately not the existing agencies.agency_type (sales | rentals | both).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'account_kind')) {
            return;
        }
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('account_kind', 10)->default('agency')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'account_kind')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropIndex(['account_kind']);
                $table->dropColumn('account_kind');
            });
        }
    }
};
