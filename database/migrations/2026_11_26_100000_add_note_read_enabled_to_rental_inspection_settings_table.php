<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M7 (Johan's decision, 20 Oct 2026): AI reading of the handwritten notes on a scanned site form is a PER-AGENCY setting, ON by default
 * for every agency, existing and new (null reads as ON - no backfill needed). The platform-wide switch (services.anthropic.inspection_note_reading
 * + services.anthropic.enabled) stays as the master kill switch above it. Nullable, read-time default, like every other column of this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_inspection_settings', 'note_read_enabled')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->boolean('note_read_enabled')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_inspection_settings', 'note_read_enabled')) {
            Schema::table('rental_inspection_settings', function (Blueprint $table) {
                $table->dropColumn('note_read_enabled');
            });
        }
    }
};
