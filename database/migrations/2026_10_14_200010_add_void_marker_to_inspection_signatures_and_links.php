<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §47 — a signature voided by "Edit report" is kept as history: it is marked superseded
 * (so it never counts and never prints as a signature, exactly like a corrected wet-ink upload) AND points at the reopen
 * that voided it. A signing link revoked by a reopen says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('voided_by_reopen_id')->nullable()->index();
        });
        Schema::table('rental_inspection_signing_links', function (Blueprint $table) {
            $table->string('revoked_reason', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropColumn('voided_by_reopen_id');
        });
        Schema::table('rental_inspection_signing_links', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });
    }
};
