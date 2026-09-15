<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Highlighter collection expansion, 2026-09-09 — Johan: "we expand
 * highlighter to settings where the colours live and we allow an agency to
 * set up which highlighters they want... like it, build it." Replaces the
 * fixed six colours (RentalApplicationMarkColorSetting, three-for-agent /
 * three-for-authoriser hardcoded) with a proper agency-owned collection: as
 * many highlighters as an agency wants, each its own label, colour, and
 * which role(s) may use it.
 *
 * `deleted_at` IS the archived flag — same convention this codebase already
 * uses for exactly this shape of agency-owned list item (see
 * PropertyTypeOption / PropertyTypesController::archive()). Johan: "archived
 * is the only removal... an archived highlighter still renders its existing
 * marks perfectly, it simply cannot be chosen for new ones." That is
 * precisely what SoftDeletes gives for free: excluded from every default
 * (picker) query, still reachable via withTrashed() wherever a mark's
 * colour needs resolving regardless of archived state, restorable.
 *
 * No `category` column — that concept is retired. A highlighter's label is
 * free text the agency chooses ("each with its own label"); nothing here
 * hardcodes income/expense/unpaid. The starting six are seed DATA (see the
 * next migration + RentalApplicationHighlighter::seedDefaultsFor()), not a
 * schema-level concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_highlighters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('color', 7);
            $table->enum('role_scope', ['agent', 'authoriser', 'both']);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'role_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_highlighters');
    }
};
