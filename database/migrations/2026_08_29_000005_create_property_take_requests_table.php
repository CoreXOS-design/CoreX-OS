<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). The smallest possible approval tier: a pending state
 * plus notify-and-confirm, shaped after the e-signature SupervisorApprovalMail flow.
 *
 * Created when a deeds capture matches an existing property whose off-market age
 * falls in the admin/BM-approval band (X–Y days). Nothing is promoted or reassigned
 * until an admin/BM decides — "no silent reassignment, ever" (Johan).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_take_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('tracked_property_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            // Snapshot of the decision inputs AT REQUEST TIME — an admin reviewing days
            // later sees what the agent actually saw, not a number that's since moved.
            $table->unsignedInteger('age_days');
            $table->string('date_field_used', 40); // last_human_touch|status_changed_at|expiry_date|created_at
            $table->boolean('date_is_fallback')->default(false);
            $table->string('matched_property_status', 40);
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index('tracked_property_id');
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_take_requests');
    }
};
