<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-13 (round 3) — Johan, a legal position: "technically we
 * not allowed to work with anyone if did not fica... submit and complete
 * fica forces them to complete fica whilst we receive the application
 * back. agent can then push them to complete fica."
 *
 * The application is ALWAYS received regardless of whether FICA is ever
 * finished — see RentalApplicationSigningController::submit()'s FICA
 * hand-off. Whether FICA must be complete before an application can go to
 * the authoriser is this one agency setting; HFC's own answer is yes
 * (default true), another agency's may differ.
 *
 * Deliberately its own migration, separate from the return-gate settings
 * (item 1, same session) — Johan/conductor: FICA ships and is verified on
 * QA1 first, the gate lands after, while he tests FICA. A shared migration
 * would have made that impossible to split cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('require_fica_before_authorisation')->nullable()->after('autosave_request_rate_limit_window_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['require_fica_before_authorisation']);
        });
    }
};
