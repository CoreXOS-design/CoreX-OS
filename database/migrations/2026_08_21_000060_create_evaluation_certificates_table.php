<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation Certificate — Phase 1 foundation (/tools/cma redesign, spec:
 * .ai/specs/EVALUATION_CERTIFICATE_REDESIGN.md).
 *
 * TERMINOLOGY (legal, non-negotiable): "evaluation", never "valuation" — the
 * spec is explicit that "valuation" carries a legal meaning agents may not use.
 *
 * A persisted, editable record — NOT a snapshot clone of the source Property.
 * When linked (property_id set), the certificate's own fields are prefilled
 * from the property at creation time but are then independently editable and
 * saved without ever writing back to/clobbering the property row.
 *
 * Authorisation chain (candidate → full-status practitioner, mirrors the
 * e-sign principal-authorise flow — built in a later phase, this migration
 * only carries the columns the state machine needs):
 *   draft -> pending_authorisation -> authorised
 *                                  -> rejected (reject_note explains why;
 *                                     candidate fixes + resubmits -> draft)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluation_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();

            // Optional links — a certificate may be captured manually with no
            // property/contact on file yet (per spec item 1: "or manual").
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Editable evaluation fields — independent of the source property
            // once captured; prefilled from it at creation time only.
            $table->string('address');
            $table->string('property_type')->nullable();
            $table->date('analysis_date')->nullable();
            $table->unsignedBigInteger('estimated_market_value')->nullable(); // whole Rand, matches properties.price
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('parking')->nullable();
            $table->text('key_features')->nullable();

            // Authorisation-chain state.
            $table->string('status')->default('draft'); // draft|pending_authorisation|authorised|rejected
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reject_note')->nullable();
            $table->string('signed_pdf_path')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_certificates');
    }
};
