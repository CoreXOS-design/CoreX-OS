<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-05 — Johan's ruling: agents and staff are never maintenance
 * crew. Crew are people (or a named team) with NO CoreX access, set up by
 * the agency admin, pickable on job cards — "a crew can be several people
 * or just a named team ('Team 1')." Full CRUD per BUILD_STANDARD §1a-§1d.
 * No DB-level unique constraint on (agency_id, name) — Johan: "unique per
 * agency among active" implies an archived crew's name must be reusable
 * by a new one, which a plain composite unique index cannot express (it
 * would also collide against the trashed row); enforced instead at the
 * application layer (RentalCrewController::validated(), Rule::unique()
 * ->whereNull('deleted_at')), matching this codebase's own documented
 * reasoning for why a bare UNIQUE + SoftDeletes combination is the wrong
 * tool here (CLAUDE.md non-negotiable #12a's own DEFINER writeup covers a
 * different gotcha, but the same "MySQL's unique index has no soft-delete
 * awareness" principle applies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_crews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'name'], 'rc_agency_name_idx');
            $table->index(['agency_id', 'is_active'], 'rc_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_crews');
    }
};
