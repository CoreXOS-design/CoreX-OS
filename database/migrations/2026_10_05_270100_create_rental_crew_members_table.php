<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-05 — named people on a crew, e.g. "Team 1" with two named
 * members, or a crew with zero members (a plain team, nothing more than
 * the name). Reporting on which member did what is a later build — this
 * table exists now so that's possible without a schema change then
 * (Johan: "Reporting on which crew did what comes later; build so that is
 * possible, but do not build reports now.").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_crew_id')->constrained(indexName: 'rcm_crew_fk')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('phone', 30)->nullable();
            $table->string('role', 100)->nullable();
            // e.g. "Plumber", "Electrician" — free text, not a catalogue
            // vocabulary; a crew member's trade doesn't drive any pricing
            // or filtering logic today.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_crew_id'], 'rcm_agency_crew_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_crew_members');
    }
};
