<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-10-05 QA1 walk: a catalogue item only had `name` (doing
 * double duty as both a short code AND the description), so picking one on
 * a job card still left the agent typing the description by hand. Split
 * into a short `code` (what the agent recognises/searches by — "PLUMB-01")
 * and a full `description` (what prints on the quote/job card). Nullable
 * here so the backfill migration right after this one can populate every
 * existing row from its old `name` before the column-required migration
 * after THAT makes both mandatory and drops `name` — same three-step
 * shape already used for the type/unit Pastel enhancement on this same
 * table (2026_10_05_240200/240300/240400).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('rental_catalogue_item_type_id');
            $table->string('description', 500)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropColumn(['code', 'description']);
        });
    }
};
