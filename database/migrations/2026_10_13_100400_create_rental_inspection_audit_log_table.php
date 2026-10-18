<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.8 (Build I-6b) — one append-only trail of WHO did WHAT to an inspection
 * and WHEN: header edits, status changes, archive / restore (so `archived_by_user_id`, which a restore clears,
 * is never the only record), the public link issued / revoked, attendance and signature and finding
 * corrections, and the automatic actions (completion copies sent). Rows are written, never edited and never
 * deleted (the model refuses both); the inspection itself is only ever archived, so there is no cascade.
 * `user_id` null = the system acted (a scheduled job, an automatic send). `before` / `after` are the changed
 * fields only. `summary` is the one plain-language line the History panel prints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_inspection_id');
            $table->string('event', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rental_inspection_id', 'id'], 'ria_log_inspection_index');
            $table->index(['rental_inspection_id', 'event'], 'ria_log_event_index');
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_audit_log');
    }
};
