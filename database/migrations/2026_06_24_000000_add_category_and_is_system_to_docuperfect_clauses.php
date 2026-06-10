<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ES-9 residue (gap 1) — clause library categorisation + system-default flag.
 *
 * `docuperfect_clauses` shipped with only name/text/is_global/owner_id. ES-9's
 * clause picker needs to GROUP the library (bond / occupation / fittings /
 * compliance / fees / notice / general) and to distinguish CoreX-shipped
 * defaults from agency-authored clauses.
 *
 *   - `category`  : nullable string, indexed. Grouping key for the picker UIs.
 *                   Nullable so the 21 pre-existing rows keep working untouched
 *                   (they read as "general" at the UI default, no backfill that
 *                   would mislabel an agency's own clause).
 *   - `is_system` : boolean, default false. true ⇒ CoreX-shipped default
 *                   (DocuperfectSystemClauseSeeder). Existing rows default to
 *                   false (agency-authored), which is correct.
 *
 * Soft delete (deleted_at) is already in place and is NOT touched.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('docuperfect_clauses', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('text')->index();
            $table->boolean('is_system')->default(false)->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_clauses', function (Blueprint $table) {
            // dropIndex by convention name so the rollback is deterministic
            // regardless of how the index was auto-named.
            $table->dropIndex('docuperfect_clauses_category_index');
            $table->dropColumn(['category', 'is_system']);
        });
    }
};
