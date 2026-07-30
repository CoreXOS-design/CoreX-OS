<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-350 — the LOSS record.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §4.2
 *
 * Deliberately SEPARATE from property_sold_records. Those two tables answer two
 * different questions and must never be confused:
 *
 *   property_sold_records    — "a sale happened here" (market fact, feeds CMA
 *                              comps and suburb intelligence regardless of who
 *                              wrote the OTP)
 *   property_third_party_sales — "we lost this one" (agency fact: which
 *                              competitor beat us, at what price against our
 *                              asking, after how long, on what mandate, and why)
 *
 * One row per LOSS EVENT, not per property: a property that is lost, re-listed
 * and lost again produces two rows. The history is the asset.
 *
 * The our_listing_price / our_mandate_type / days_on_market columns are
 * SNAPSHOTS, not joins. The property stays editable after the loss — it can be
 * re-priced and re-listed — so a join would silently rewrite history and make
 * "were we priced above the winner?" answer differently every month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_third_party_sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // ── What we know about THEIR sale. All nullable by design (spec D4):
            // we frequently only hear THAT it sold. A required field here would
            // push the agent back to "Withdrawn" and we'd lose the intel entirely.
            $table->string('sold_by_agency', 200)->nullable();
            $table->decimal('sold_price', 14, 2)->nullable();
            $table->date('sold_date')->nullable();

            // ── Snapshots of OUR position at the moment we lost it.
            $table->decimal('our_listing_price', 14, 2)->nullable();
            $table->string('our_mandate_type', 50)->nullable();
            $table->unsignedInteger('days_on_market')->nullable();

            // ── Why we lost it. Constant set on the model (spec D5 — declared
            // deviation from SYSTEM.md §3, mirroring PresentationOutcome).
            $table->string('loss_reason', 50)->nullable();
            $table->text('notes')->nullable();

            // The comp this loss produced, when it produced one (price AND date
            // known — spec §4.4). Held as an explicit link rather than re-matching
            // on property_id: a property lost, re-listed and lost again yields TWO
            // genuine sales at different prices, and a match-based upsert would
            // overwrite the first comp with the second.
            $table->foreignId('sold_record_id')->nullable()
                ->constrained('property_sold_records')->nullOnDelete();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();

            // Stamped when the property leaves the status (re-listed). NULL = the
            // open/current loss. The row is NEVER deleted on revert — losing the
            // loss history is exactly the failure this feature exists to fix.
            $table->timestamp('reverted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'sold_date'], 'ptps_agency_sold_date_idx');
            $table->index(['property_id', 'reverted_at'], 'ptps_property_open_idx');
            $table->index(['agency_id', 'loss_reason'], 'ptps_agency_reason_idx');
            $table->index(['agency_id', 'sold_by_agency'], 'ptps_agency_competitor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_third_party_sales');
    }
};
