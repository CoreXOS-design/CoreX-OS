<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-row ROLE on a deed capture (entity model, Johan 2026-08-14). A company
 * is the sole OWNER; its directors are captured on the same deed so agents can
 * still work them, but as REPRESENTATIVES (role='director'), never as owners.
 * Existing rows default to 'owner' (unchanged behaviour). Deeds-capture renders
 * the two groups distinctly so a director never reads as personally owning the
 * property.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->string('role', 20)->default('owner')->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
