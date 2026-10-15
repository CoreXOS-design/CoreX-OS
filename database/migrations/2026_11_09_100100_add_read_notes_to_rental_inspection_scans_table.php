<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-463 — what the reader noticed about a scan that is not a failure: pages of the printed form that were not in
 * the upload, pages it could only place by their position in the file (no readable QR), a duplicated page. A JSON
 * list of plain sentences, shown on the review screen (rental-inspections.md §60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_scans', function (Blueprint $table) {
            $table->json('read_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_scans', function (Blueprint $table) {
            $table->dropColumn('read_notes');
        });
    }
};
