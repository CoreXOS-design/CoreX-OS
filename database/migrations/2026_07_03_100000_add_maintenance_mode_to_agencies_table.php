<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agency maintenance mode (AT-93, re-scoped).
 *
 * Maintenance is a TENANT-level state, not a platform-level one: putting one
 * agency into maintenance must never affect any other agency, and the CoreX
 * login must always stay reachable. The flag therefore lives on the agency
 * row and is enforced after login by AgencyMaintenanceGate.
 *
 * Reversible state flag — toggling off restores access. No hard delete.
 * Spec: .ai/specs/maintenance-mode.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('maintenance_mode')->default(false)->after('is_demo');
            $table->text('maintenance_message')->nullable()->after('maintenance_mode');
            $table->timestamp('maintenance_started_at')->nullable()->after('maintenance_message');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['maintenance_mode', 'maintenance_message', 'maintenance_started_at']);
        });
    }
};
