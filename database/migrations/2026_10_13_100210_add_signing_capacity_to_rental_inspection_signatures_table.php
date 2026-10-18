<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — a landlord's (or tenant's) representative can sign
 * on the party's own row. `signed_by_name` is the person who actually put pen to the pad and
 * `signing_capacity` how they stood to the party (matches an attendance row's `attended_as`). Both
 * nullable: null = the party signed themselves, exactly as every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->string('signed_by_name')->nullable()->after('party_contact_id');
            $table->string('signing_capacity', 20)->nullable()->after('signed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropColumn(['signed_by_name', 'signing_capacity']);
        });
    }
};
