<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §15 (GATE 2, approved 2026-10-04 WITH the
 * no-new-property-status change) — rows 6/7 restore "the status the
 * property had before it was let." Nothing today stores that fact —
 * captured once, at lease activation (LeaseActivationService::
 * flipPropertyToLeasedOut()), and cleared once restored so the NEXT
 * lease cycle captures fresh rather than reusing a stale value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('status_before_letting', 40)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('status_before_letting');
        });
    }
};
