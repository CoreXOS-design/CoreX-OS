<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 authoriser flow — Johan: "on declined email - that will be a
 * setup part for each agency to do. we can have a suggestion for them, but
 * each agency will want their own wording on declined." Mirrors
 * rental_application_qualifying_settings exactly (one row per agency,
 * unique agency_id, never created until the agency actually saves — see
 * RentalApplicationDeclineEmailSetting::forAgency()) rather than
 * RentalReminderSetting's email_subject/email_body columns, which are the
 * right SHAPE but are a global singleton (no agency_id at all) and have no
 * mailer actually consuming them yet — not a genuine working precedent to
 * copy here, where "each agency wants their own wording" is the explicit
 * point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_decline_email_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unique('agency_id');

            $table->string('subject', 500)->nullable();
            $table->text('body')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_decline_email_settings');
    }
};
