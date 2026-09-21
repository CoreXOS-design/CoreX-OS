<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the inspections rework. Marks that a property's inspection
 * form has been seeded from its advertising details ONCE — Johan: "the
 * seeding happens ONCE, when the form is first created... after that the
 * form is the property's own." An advertising edit after this timestamp
 * is set never re-seeds automatically, in either direction (no silent
 * add, no silent remove) — it only ever surfaces a candidate-addition
 * suggestion for the agent to act on, never a rewrite. This column is the
 * single source of truth for "has this already happened."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('rental_inspection_form_seeded_at')->nullable()->after('spaces_json');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_inspection_form_seeded_at');
        });
    }
};
