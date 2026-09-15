<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 "Dates on entries" (Johan, 2026-09-10) — "the purpose of all of
 * this is rental-payment behaviour — when rent was due versus when it was
 * actually paid." The applicant is asked which day of the month rent is
 * due on their CURRENT lease (a recurring day-of-month, e.g. "the 1st" —
 * not a one-off calendar date, since a lease's rent obligation repeats
 * every month); the agent can enter it on the applicant's behalf before
 * submission, or simply see it once the applicant has answered. Capture
 * only — no comparison against income_items.entry_date is built here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_rental_due_day')->nullable()->after('current_rental_still_living');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('current_rental_due_day');
        });
    }
};
