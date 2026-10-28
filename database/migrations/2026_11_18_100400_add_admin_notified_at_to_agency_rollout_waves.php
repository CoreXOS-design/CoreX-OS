<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 Part 5 (§12.4) — when the agency's admins were told, the release day before, that this group
 * switches on next. Sent once per group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_rollout_waves', function (Blueprint $table) {
            $table->timestamp('admin_notified_at')->nullable()->after('access_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('agency_rollout_waves', function (Blueprint $table) {
            $table->dropColumn('admin_notified_at');
        });
    }
};
