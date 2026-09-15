<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Round 16 — Johan's panel redesign: "we need the heading, the
 * highlighter and a tick - unpaid transactions on bank statement... an
 * applicant with declined transactions are generally immediately
 * declined... the tick tells the auth that this is a dangerous app."
 * A single boolean, not a list of amounts — the agent marks individual
 * declined lines on the document itself (cc6's highlighter); this flag
 * is the one-glance red flag the authoriser's screen (cc5) reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->boolean('has_unpaid_transactions')->default(false)->after('statement_months');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->dropColumn('has_unpaid_transactions');
        });
    }
};
