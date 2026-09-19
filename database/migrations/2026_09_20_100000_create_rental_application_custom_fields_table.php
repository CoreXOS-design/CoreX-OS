<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md §7 — custom fields, piece
 * (c)(1), the definition side only. An agency adding a field CoreX doesn't
 * ship — architecturally different from §1b's shown/hidden/label/order
 * config on the EXISTING shipped fields, and the larger piece of that spec.
 *
 * `field_type` is a plain string (validated in-app via
 * RentalApplicationCustomField::FIELD_TYPES), not a MySQL enum — Johan's
 * ordering puts file-upload LAST, as its own piece; a string column means
 * adding that type later never needs a schema migration just to widen an
 * enum, same reasoning RentalApplication::EMPLOYMENT_TYPES already uses.
 *
 * `key` is agent-facing but SYSTEM-GENERATED (slugified from the label,
 * namespaced `custom.` so it can never collide with a real shipped column
 * name), never hand-typed — it's the JSON key every captured answer is
 * stored under (rental_applications.custom_field_values, §7), so letting
 * an agent free-type it risks a malformed or colliding key reaching data
 * storage. Immutable after creation for the same reason: renaming it would
 * orphan every already-captured answer under the old key.
 *
 * `shown` is separate from `deleted_at` (retirement) — an agency may want
 * to temporarily hide a custom field from new applications without losing
 * it or its already-captured answers, exactly parallel to the shipped-
 * field hidden_field_keys mechanism (§1b). SoftDeletes is the real removal:
 * "retired custom fields must never break an application that already has
 * an answer to one" (Johan, 2026-09-20) — the deleted_at + withTrashed()
 * pattern this codebase already uses for RentalApplicationHighlighter is
 * exactly what gives that for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->string('label', 150);
            $table->text('help_text')->nullable();
            $table->string('field_type', 20);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('shown')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // No DB-level unique constraint on (agency_id, key) — MySQL
            // treats every NULL `deleted_at` as distinct for uniqueness
            // purposes, so a composite unique including it would NOT
            // actually stop two simultaneously-active rows sharing a key.
            // Enforced in the application layer instead (
            // RentalApplicationCustomFieldController::assertKeyNotDuplicated()),
            // exactly the same reason RentalApplicationHighlighter has no
            // DB-level uniqueness on `label` either — a retired
            // "custom.pet_deposit" must never block adding a fresh one
            // under the same generated key.
            $table->index(['agency_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_custom_fields');
    }
};
