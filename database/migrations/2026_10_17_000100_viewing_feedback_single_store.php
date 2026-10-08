<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viewing feedback — ONE store (Johan's rulings R1-R9, 2026-10-08).
 * Spec: .ai/specs/calendar-viewing-feedback.md
 *
 *  - calendar_event_feedback gains the per-property viewing STATUS (viewed / did_not_happen /
 *    declined_on_arrival), "last edited by/when" (the original capturer + time are never overwritten)
 *    and who archived a row (soft delete via the existing deleted_at).
 *  - calendar_event_feedback_log: a field-level change log (property, contact, field, old, new, who, when)
 *    for every create / edit / archive / restore.
 *  - viewing_feedback_migration_log: before/after snapshot of every row the one-off
 *    `viewing-feedback:migrate-single-store` command rewrites, so it is reversible and idempotent.
 *
 * Purely additive: nothing is dropped or rewritten here; existing rows keep every value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_event_feedback', function (Blueprint $t) {
            $t->string('viewing_status', 24)->default('viewed')->after('feedback_kind');
            $t->unsignedBigInteger('last_edited_by_user_id')->nullable()->after('captured_at');
            $t->timestamp('last_edited_at')->nullable()->after('last_edited_by_user_id');
            $t->unsignedBigInteger('archived_by_user_id')->nullable()->after('last_edited_at');
            $t->string('archive_reason', 60)->nullable()->after('archived_by_user_id');
        });

        Schema::create('calendar_event_feedback_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('feedback_id')->index();
            $t->unsignedBigInteger('calendar_event_id')->index();
            $t->unsignedBigInteger('property_id')->nullable()->index();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->string('action', 16);            // created | edited | archived | restored
            $t->string('field', 40)->nullable(); // viewing_status | outcome | concerns | seller_comment | internal_comment | next_action
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('note', 120)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('viewing_feedback_migration_log', function (Blueprint $t) {
            $t->id();
            $t->string('run_token', 40)->index();
            $t->unsignedBigInteger('feedback_id')->index();
            $t->json('before');
            $t->json('after');
            $t->timestamp('applied_at')->useCurrent();
            $t->timestamp('reversed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_feedback_migration_log');
        Schema::dropIfExists('calendar_event_feedback_log');
        Schema::table('calendar_event_feedback', function (Blueprint $t) {
            $t->dropColumn(['viewing_status', 'last_edited_by_user_id', 'last_edited_at', 'archived_by_user_id', 'archive_reason']);
        });
    }
};
