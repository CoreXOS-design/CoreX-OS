<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §13 — the condition an agent taps for this
 * line at move-in (a chip from the agency's configured
 * RentalInventorySetting::condition_states vocabulary). Nullable and
 * optional, same as every other soft descriptive field on this table
 * (quantity, description are the only NOT-NULL contract here) — never a
 * hard requirement to save a line, per the lazy-but-valid-shortcut rule
 * (BUILD_STANDARD section 2): an agent typing fast is not blocked from
 * adding an item just because they haven't picked a condition for it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->string('condition_key', 60)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->dropColumn('condition_key');
        });
    }
};
