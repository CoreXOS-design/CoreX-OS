<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-463 / spec §62 - what the reader made of EACH page of an uploaded scan: placed or not, how (corner marks / QR), the
 * rows read and flagged, and - when a page could not be read - the reason in plain words. Shown on the review screen so
 * a page that could not be read is never silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_scans', function (Blueprint $table) {
            $table->json('page_results')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_scans', function (Blueprint $table) {
            $table->dropColumn('page_results');
        });
    }
};
