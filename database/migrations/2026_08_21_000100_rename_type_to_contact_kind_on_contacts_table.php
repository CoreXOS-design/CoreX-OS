<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INCIDENT FIX — 2026_08_21_000080 added a raw `type` column to `contacts`,
 * which shadowed the PRE-EXISTING Contact::type() relationship
 * (belongsTo(ContactType::class, 'contact_type_id')) — Eloquent resolves a
 * real attribute/column before falling through to a same-named relationship
 * method, so every `$contact->type` access across the app (blades, e-sign
 * role resolution, exports, mobile API, calendar) silently started reading
 * this new string instead of the ContactType model, crashing anywhere code
 * chained ->name/->color/->esign_role off it (confirmed live 500 on
 * GET /corex/properties/6060, spec: .ai/specs/contact-entity-type.md).
 *
 * Fix: rename off the reserved word so the old relationship is unshadowed.
 * `type` was already migrated + committed on QA1 (0f1a79e49) — this is an
 * additive rename migration rather than editing the original in place, so
 * it replays correctly regardless of who else has already pulled/migrated
 * that commit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->renameColumn('type', 'contact_kind');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->renameColumn('contact_kind', 'type');
        });
    }
};
