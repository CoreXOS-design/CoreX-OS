<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-516 — correcting applicant-entered details.
 *
 * rental_application_field_changes: one append-only row per field an agent corrects after the applicant has submitted. The
 * applicant's signed answers stay sealed in rental_application_generations; this is the trail of what the agent changed on
 * the live values since (old -> new, who, when, why). `batch_uuid` groups the rows of one save so one email covers them.
 *
 * rental_applications.correction_fields: the field keys an agent ticked when sending the application back with the reason
 * "Correct details". Cleared when the applicant resubmits or the reopen is cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_field_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained();
            $table->char('batch_uuid', 36);
            $table->unsignedInteger('generation')->nullable();
            $table->string('field_key', 120);
            $table->string('field_label', 255);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['rental_application_id', 'created_at'], 'rap_field_changes_app_created_idx');
            $table->index('batch_uuid', 'rap_field_changes_batch_idx');
            $table->index('agency_id', 'rap_field_changes_agency_idx');
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->json('correction_fields')->nullable()->after('reopened_note');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('correction_fields');
        });
        Schema::dropIfExists('rental_application_field_changes');
    }
};
