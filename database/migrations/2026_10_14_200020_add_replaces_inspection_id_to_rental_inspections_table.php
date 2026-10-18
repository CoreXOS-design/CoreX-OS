<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §47 — an inspection whose report has been sent to the parties can never be edited or
 * reopened; the only way forward is a NEW inspection that replaces it. This is the "replaces" half of that link (the
 * "replaced by" half is the inverse of this column). The old inspection is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->unsignedBigInteger('replaces_inspection_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn('replaces_inspection_id');
        });
    }
};
