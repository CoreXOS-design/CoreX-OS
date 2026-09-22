<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-22 (property 4283) — has_deposit=1 with a blank
 * deposit_amount produced an empty Deposit field downstream. Rather than
 * rejecting the save on an existing record in that state,
 * PropertyController::applyDepositDefault() auto-fills a blank
 * deposit_amount from the agency's configured deposit multiple x the
 * rental amount. "Do not silently write a computed figure into the
 * database as if a human had entered it — if it is computed, it is
 * identifiable as computed until someone confirms it" — this flag is that
 * identification. False by default (a human-entered value, or no deposit
 * at all); true only when the last save computed the current
 * deposit_amount rather than a human typing it. Cleared the moment a real
 * figure is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('deposit_amount_is_default')->default(false)->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('deposit_amount_is_default');
        });
    }
};
