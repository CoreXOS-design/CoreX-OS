<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §15.10 M1 (Build L1 — foundation) — the structured copy of what a lease
 * agreement says beyond rent and dates: one row per lease TERM. The printed, sealed document stays
 * the legal record; this row is what the capture screen pre-fills from and what a later renewal
 * copies forward (§15.7.2).
 *
 * `lease_id` is UNIQUE and the table soft-deletes, so a soft-deleted row must be RESTORED, not
 * re-inserted — LeaseAgreementTerms::forLease() does exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lease_agreement_terms')) {
            return;
        }

        Schema::create('lease_agreement_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->unique()->constrained('leases')->cascadeOnDelete();
            $table->unsignedSmallInteger('adults')->nullable();
            $table->unsignedSmallInteger('max_other_persons')->nullable();
            $table->string('pets', 255)->nullable();
            $table->decimal('escalation_percent', 5, 2)->nullable();
            $table->boolean('no_escalation')->default(false);
            $table->unsignedTinyInteger('escalation_month')->nullable();
            $table->date('earliest_termination_date')->nullable();
            $table->unsignedSmallInteger('renewal_option_months')->nullable();
            $table->string('electricity_arrangement', 500)->nullable();
            $table->text('other_conditions')->nullable();
            // Template-specific values the agency's own field map declares (e.g. a fee schedule).
            $table->json('extra')->nullable();
            // captured | carried_forward | esign_harvest | confirmed
            $table->string('source', 30)->default('captured');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'lease_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_agreement_terms');
    }
};
