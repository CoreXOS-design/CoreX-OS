<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — Johan, QA1: "on returned applications theres statuses at the
 * top, but theres no way to mark application status to what it is?" A
 * decision on a tenant application needs a trail — who changed the status,
 * when, from what to what. Mirrors fica_status_history's shape (the
 * existing append-only status-trail pattern in this codebase) rather than
 * the field-diff-style contact_audit_log, since this only ever records one
 * thing: a status transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['rental_application_id', 'created_at'], 'rap_status_history_app_created_idx');
            $table->index(['agency_id', 'created_at'], 'rap_status_history_agency_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_status_history');
    }
};
