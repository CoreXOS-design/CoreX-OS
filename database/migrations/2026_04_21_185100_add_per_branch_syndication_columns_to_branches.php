<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 branch-isolation: per-branch PP / P24 syndication credentials
 * (spec §10). When `syndication_override_enabled` is true, the PP and
 * P24 adapters resolve their credentials from the branch record instead
 * of the agency. Otherwise they fall back to the existing agency-level
 * credentials — zero behaviour change for agencies that don't split.
 *
 * `p24_agency_id` already exists on branches (added 2026_04_18_120000),
 * so we only add the new override flag, encrypted credential payloads,
 * and the PP agency-id column.
 *
 * Credential columns use Laravel's `encrypted` cast at the model layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'syndication_override_enabled')) {
                $table->boolean('syndication_override_enabled')
                    ->default(false)
                    ->after('p24_agency_id');
            }
            if (!Schema::hasColumn('branches', 'pp_agency_id')) {
                $table->string('pp_agency_id')
                    ->nullable()
                    ->after('syndication_override_enabled');
            }
            if (!Schema::hasColumn('branches', 'pp_credentials')) {
                $table->text('pp_credentials')
                    ->nullable()
                    ->after('pp_agency_id');
            }
            if (!Schema::hasColumn('branches', 'p24_credentials')) {
                $table->text('p24_credentials')
                    ->nullable()
                    ->after('pp_credentials');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            foreach (['p24_credentials', 'pp_credentials', 'pp_agency_id', 'syndication_override_enabled'] as $col) {
                if (Schema::hasColumn('branches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
