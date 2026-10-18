<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — the invitation trail. An invitation given OFF the
 * system (a phone call, a WhatsApp typed by hand, in person) is recorded as `event = invitation_manual`,
 * `channel = manual` with the real time it was given (`occurred_at`), who recorded it
 * (`sent_by_user_id`) and how (`method`, free text). `subject_snapshot` keeps the subject line of an
 * e-mail the system itself sent, so the record shows what was sent even if the template later changes.
 *
 * Existing rows are untouched (all four columns nullable); `occurred_at` for them is read as
 * `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_by_user_id')->nullable()->after('error');
            $table->string('subject_snapshot')->nullable()->after('sent_by_user_id');
            $table->timestamp('occurred_at')->nullable()->after('subject_snapshot');
            $table->string('method', 60)->nullable()->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_notifications', function (Blueprint $table) {
            $table->dropColumn(['sent_by_user_id', 'subject_snapshot', 'occurred_at', 'method']);
        });
    }
};
