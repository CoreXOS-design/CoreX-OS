<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Confirmation of Employment letter must reproduce the agency's Word letter
 * (.ai/specs/ppra-ffc-employment-letter.md §18):
 *  - full_first_names: legal first names as on the ID ("Elizabeth Petronella"), which `name` ("Elize")
 *    cannot hold. Nullable, populated for nobody — admins capture it; the letter falls back to the first
 *    word of `name` while blank.
 *  - ppra_category: the practitioner's PPRA registration category for the RE line and signature title,
 *    distinct from `designation` (a job title such as "CEO").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_first_names', 150)->nullable()->after('designation');
            $table->string('ppra_category', 100)->nullable()->after('full_first_names');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['full_first_names', 'ppra_category']);
        });
    }
};
