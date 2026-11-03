<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 (road-to-sold.md 23.25 / 23.11): the off-ramp reasons belong to a popup STEP (1-9) and say what an off-ramp counts as. Additive: three columns on the
 * agency's existing reason list; a row with no step is not offered in the popup (it is still the station reason it was). The default for counts_as is "lost" so every
 * reason that exists today behaves exactly as before; the defaults seeded for a step set their own. No data is written here - the defaults are seeded per agency on first
 * use (ParkReasonService::ensureStepReasons, idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        // the table is created by 2026_12_20_100000 (future-dated, so on a from-scratch build it does not exist yet when this runs - and is then created with these columns)
        if (! Schema::hasTable('rts_park_reasons')) {
            return;
        }
        Schema::table('rts_park_reasons', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_park_reasons', 'step')) {
                $table->unsignedTinyInteger('step')->nullable()->after('station');
            }
            if (! Schema::hasColumn('rts_park_reasons', 'counts_as')) {
                $table->string('counts_as', 20)->default('lost')->after('kind');
            }
            if (! Schema::hasColumn('rts_park_reasons', 'is_other')) {
                $table->boolean('is_other')->default(false)->after('is_system');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rts_park_reasons')) {
            return;
        }
        Schema::table('rts_park_reasons', function (Blueprint $table) {
            foreach (['step', 'counts_as', 'is_other'] as $c) {
                if (Schema::hasColumn('rts_park_reasons', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
