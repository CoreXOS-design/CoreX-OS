<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §3.4 — escalation as a RATE, not only the new rand
 * amount. Johan: if renewal only stores the new figure, the rate is lost,
 * and the rate is what an owner asks about at renewal and what gets
 * reported on across a portfolio.
 *
 * escalation_rate_percent is computed AND stored at entry time, not
 * re-derived later from amount history — a stored rate survives correctly
 * even if source amounts are later corrected (the correction gets its own
 * new row; the original stays a true historical fact). Append-only, same
 * evidence-integrity principle as rental_inspection_observations — no
 * updated_at, no deleted_at, nothing here is ever edited or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->date('effective_date');
            $table->decimal('previous_rental_amount', 12, 2);
            $table->decimal('new_rental_amount', 12, 2);
            $table->decimal('escalation_rate_percent', 6, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lease_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_escalations');
    }
};
