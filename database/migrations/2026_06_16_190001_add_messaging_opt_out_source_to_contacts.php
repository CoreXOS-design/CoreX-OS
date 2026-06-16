<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-49 — opt-out provenance.
 *
 * Distinguishes a self-service-link opt-out (recipient tapped the per-send link,
 * no authenticated user) from an agent-recorded one. NULL = legacy / agent-marked
 * (back-compatible with every opt-out written before AT-49, where the recorder is
 * carried by messaging_opt_out_recorded_by_user_id instead).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('messaging_opt_out_source', 30)
                ->nullable()
                ->after('messaging_opt_out_recorded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('messaging_opt_out_source');
        });
    }
};
