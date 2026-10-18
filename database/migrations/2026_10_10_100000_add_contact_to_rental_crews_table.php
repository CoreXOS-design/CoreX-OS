<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 1 / §14.28 — a crew gets an
 * email address and a contact number, used to share a job card with the
 * crew. Both nullable: an existing crew has neither, and a crew with no
 * address can still be sent a link to any address the agent types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_crews', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_crews', 'email')) {
                $table->string('email', 191)->nullable()->after('name');
            }
            if (! Schema::hasColumn('rental_crews', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_crews', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone']);
        });
    }
};
