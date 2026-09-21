<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-property-tab.md §2/§7, Part 1 — agency-defined fields on a
 * property's Rental Details tab. Mirrors rental_application_custom_fields
 * column-for-column (same reasoning throughout: field_type a plain string,
 * not a MySQL enum, so a future type never needs a migration; key
 * system-generated and immutable, namespaced so it can never collide with a
 * real property column; shown separate from soft-delete, so an agency can
 * hide a field from new listings without losing already-captured values;
 * no DB-level unique constraint on (agency_id, key) for the same
 * NULL-uniqueness reason documented on the sibling table) — deliberately a
 * PARALLEL table, not a shared one: the value side belongs to `properties`
 * (rental_details_custom_field_values, added in Part 2), not to
 * rental_applications, and RentalApplicationCustomField's own key-dedup and
 * TYPE_FILE handling are hard-wired to that other host. See the spec's §2
 * for the full reasoning against sharing the existing table.
 *
 * `advertise` is the one column this table has that its sibling doesn't —
 * Johan's own "a tick on each" mechanism (§4.1): whether this field's value
 * contributes a line to the generated advert block (§4), independent of
 * `shown` (visible on the tab at all) and `required`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_rental_details_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->string('label', 150);
            $table->text('help_text')->nullable();
            $table->string('field_type', 20);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('shown')->default(true);
            $table->boolean('advertise')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_rental_details_custom_fields');
    }
};
