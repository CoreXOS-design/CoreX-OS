<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — the tenant wishlist drawer (rental-application review screen)
 * prefills from the application's own occupation_date/rental_term_months,
 * but ContactMatch had nowhere to receive them. Rental-only, nullable,
 * optional — a sale wishlist never sets these. Spec:
 * .ai/specs/rental-applications.md "Tenant wishlist drawer — rental-mode
 * wiring and hard approved-amount cap".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->date('move_in_date')->nullable()->after('price_max');
            $table->unsignedSmallInteger('rental_term_months')->nullable()->after('move_in_date');
        });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropColumn(['move_in_date', 'rental_term_months']);
        });
    }
};
