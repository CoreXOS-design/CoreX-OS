<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §8 — append-only renewal/outcome event log for a
 * lease, mirroring PropertyAuditLog's shape (App\Services\Audit\PropertyAuditService)
 * but scoped to Lease: that table is hard-tied to property_id, not polymorphic, so
 * a new table is the smallest correct fix rather than reshaping a widely-used audit
 * table for an unrelated pillar.
 *
 * Why this table exists rather than deriving everything live (the way
 * LeaseTimelineService derives escalation/cancellation entries from the lease's own
 * columns): one-click outcomes (notice given, month-to-month) are REVERSIBLE —
 * reversing clears the lease's own notice_date/is_month_to_month columns so the
 * tenancy log must not lose the fact that the event happened. Escalations don't
 * have this problem (append-only LeaseEscalation rows are never cleared), which is
 * why this is new only for notice/month-to-month/renewal activity, not a wholesale
 * replacement of the existing live-derived entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->string('event_type', 40);
            // month_to_month_set | month_to_month_reversed | notice_recorded |
            // notice_reversed | renewal_draft_created | renewal_activated
            $table->string('description', 500);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['lease_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_events');
    }
};
