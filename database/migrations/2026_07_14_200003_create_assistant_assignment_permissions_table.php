<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistants, Prompt A.
 *
 * The per-assignment permission matrix. Spec §6.4.
 *
 * On assignment this is seeded as a COPY of the Assigned Agent's permissions
 * (granted = true), except the property-upload locked set (granted = false,
 * is_locked = true). The agent then switches things OFF from their My Assistants
 * page. The matrix can only ever SUBTRACT from the agent's live ceiling — the
 * resolver intersects it against the agent's real permissions on every check.
 *
 * Short FK/index names are mandatory: MySQL's 64-char identifier limit bites on
 * this table name (same reason contact_access_log needed `cal_impersonator_fk`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_assignment_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_assignment_id')
                ->constrained('assistant_assignments', 'id', 'aap_assignment_fk')
                ->cascadeOnDelete();

            $table->string('permission_key', 190);
            $table->boolean('granted')->default(false);

            // own|branch|all — only meaningful for keys ending in '.view'.
            $table->string('scope', 10)->nullable();

            // The property-upload locked set. Never editable, never granted.
            $table->boolean('is_locked')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['assistant_assignment_id', 'permission_key'], 'aap_assignment_key_unique');
            $table->index('permission_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_assignment_permissions');
    }
};
