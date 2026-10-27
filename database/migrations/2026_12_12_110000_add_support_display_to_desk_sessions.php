<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk: the support person as the agent sees them - "{name} — {organisation}, {designation}" - frozen at join time so the audit stays true
 * if a title changes later (.ai/specs/corex-desk.md section 4c).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('desk_sessions', 'support_display')) {
                $table->string('support_display', 190)->nullable()->after('support_user_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('desk_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('desk_sessions', 'support_display')) {
                $table->dropColumn('support_display');
            }
        });
    }
};
