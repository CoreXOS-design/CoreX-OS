<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer pipeline notes (Johan, 2026-08-20) — "dropdown quick picks and free
 * text", both optional, neither mandatory-with-the-other. A plain string
 * column, NOT an enum: MySQL strict mode turns an out-of-list ENUM value
 * into a hard failure (already bitten this codebase's test suite this
 * week — see .ai/audits/2026-08-19-test-suite-triage.md Rank 5), and an
 * enum requires a migration every time the quick-pick list changes. The
 * allowed-values list lives in App\Models\ContactNote::QUICK_PICK_TYPES and
 * is enforced at the application layer (ContactNoteController::store()),
 * not the database — Johan wants the list to be a one-line edit, not a
 * migration.
 *
 * Nullable is non-negotiable: 7,873+ existing rows on live have no type and
 * must stay valid, untyped notes forever (a type is optional going forward
 * too — "Contacted" with no body, or a body with no type, must both work).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->string('type', 40)->nullable()->after('user_id');
            $table->index(['agency_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->dropIndex(['agency_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
