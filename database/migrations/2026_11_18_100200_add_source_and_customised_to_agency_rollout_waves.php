<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 Part 4 — the owner's per-agency rollout page (spec agency-feature-rollout.md §5.5).
 *
 *  source_wave_id — the Dev Settings default wave this agency wave was copied from (null = a wave the
 *                   CoreX team added for this agency). Drives "show differences from the default".
 *  customised_at  — set when the CoreX team changes the wave for this agency (the "changed" badge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_rollout_waves', function (Blueprint $table) {
            $table->foreignId('source_wave_id')->nullable()->after('rollout_id')
                ->constrained('feature_rollout_waves')->nullOnDelete();
            $table->timestamp('customised_at')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('agency_rollout_waves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_wave_id');
            $table->dropColumn('customised_at');
        });
    }
};
