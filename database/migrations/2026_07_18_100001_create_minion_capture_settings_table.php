<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-284 — per-agency cadence/config for the Chrome minion. Rollback: drop table.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('minion_capture_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->boolean('enabled')->default(false);            // nightly schedule master switch
            $table->unsignedSmallInteger('targets_per_night')->default(8);
            $table->unsignedSmallInteger('cycle_days')->default(7);
            $table->string('run_at', 5)->default('02:30');
            $table->json('run_days')->nullable();                  // ["Mon",...]
            $table->unsignedSmallInteger('pace_min_seconds')->default(20);
            $table->unsignedSmallInteger('pace_max_seconds')->default(55);
            $table->boolean('alert_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minion_capture_settings');
    }
};
