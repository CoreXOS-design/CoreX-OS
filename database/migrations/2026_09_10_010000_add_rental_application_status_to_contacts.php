<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 — a rental-application outcome status on Contact, named distinctly
 * from buyer_state (buyer-pipeline journey stage) and contact_type_id
 * (role-in-transaction category) per .ai/specs/rental-applications.md
 * §"Contact status, approval-email matching, and the auto-send toggle".
 * Derived/cached, kept in sync via domain events — never hand-edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('rental_application_status', 20)->default('none')->after('buyer_source');
            $table->timestamp('rental_application_status_updated_at')->nullable()->after('rental_application_status');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['rental_application_status', 'rental_application_status_updated_at']);
        });
    }
};
