<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Syndication Approval Gate (layer 3) — the request/decision trail.
 * Spec: .ai/specs/syndication-approval-gate.md §4.2
 *
 * One row per request; the latest row for a property is the current request.
 * History is never overwritten — a cancel writes `withdrawn`, a revoke writes
 * a further `withdrawn` row with its reason, so the trail reads as a
 * continuous story. Soft deletes only (Non-negotiable #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_syndication_approvals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('agency_id')->index();
            // Stamped from the property at request time — drives branch-scoped
            // visibility (spec §7.3) without a join back to properties.
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->index();

            $table->enum('status', ['pending', 'approved', 'rejected', 'withdrawn'])->default('pending');

            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->text('request_note')->nullable();

            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // When the approver email actually went out. NULL after a send
            // failure, so a silent mail failure is visible rather than assumed.
            $table->timestamp('notified_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'status', 'requested_at'], 'psa_agency_status_requested_idx');
            $table->index(['property_id', 'id'], 'psa_property_latest_idx');

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('decided_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_syndication_approvals');
    }
};
