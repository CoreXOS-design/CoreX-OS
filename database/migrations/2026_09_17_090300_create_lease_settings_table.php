<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §5.2 — the CPA expiry-notice obligation. Johan is
 * having his compliance officer confirm whether a written notice is
 * legally required before a fixed-term lease ends, and what a tenant's
 * cancellation rights look like. DO NOT default this to any specific
 * number of weeks — this column stays null until the real figure is
 * confirmed. No SA law was researched to write this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unique('agency_id');

            $table->unsignedSmallInteger('expiry_notice_window_days')->nullable();
            // Nullable, no default — "no automatic notice yet" until the agency's
            // compliance officer confirms the real figure. Never wired to any
            // notification-sending logic in this build; see leases.md §5.2.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_settings');
    }
};
