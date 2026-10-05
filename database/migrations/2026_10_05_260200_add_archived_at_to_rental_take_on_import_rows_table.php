<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §7 — "Archive batch" needs to know
 * exactly which created records IT archived, so "Restore" reverses that
 * same set rather than re-deriving "what did I touch" from the
 * migrated_from_* pointer alone (which could by then match something
 * unrelated after a since-reversed restore elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
