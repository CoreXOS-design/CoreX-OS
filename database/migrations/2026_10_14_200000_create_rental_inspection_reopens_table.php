<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §47 — "Edit report" on a signed inspection. One row per time the agent reopened a
 * signed report: who, when, why (required), the report exactly as the signers saw it (the report is locked while signed,
 * so its state at the moment of reopening IS what they signed), which signatures it voided and which links it revoked.
 * Append-only history — never edited, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_reopens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('rental_inspection_id')->index();
            $table->unsignedBigInteger('reopened_by_user_id')->nullable();
            $table->timestamp('reopened_at');
            $table->text('reason');
            $table->string('previous_status', 30);
            $table->string('report_fingerprint', 64);
            $table->json('report_snapshot');
            $table->json('voided_signatures');
            $table->json('revoked_link_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_reopens');
    }
};
