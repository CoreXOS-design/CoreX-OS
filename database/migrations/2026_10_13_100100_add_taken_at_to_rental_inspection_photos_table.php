<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.3 (Build I-1) — when a photo was TAKEN, as opposed to
 * `created_at`, which is when the server RECEIVED it (an offline 9am photo uploaded at 2pm used to
 * read 2pm everywhere). `taken_at_source` says where the time came from — `client` (the app sent
 * its own capture time), `exif` (read from the original file's metadata) or `server` (neither was
 * available or believable, so this is only the upload time and is displayed as "Uploaded", never as
 * a capture time). Immutable once set (RentalInspectionPhoto::boot()).
 *
 * Existing rows are backfilled `taken_at = created_at`, source `server` — honest: for every photo
 * that exists today the received time is the only time anyone ever recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->dateTime('taken_at')->nullable()->after('file_size_bytes');
            $table->string('taken_at_source', 10)->nullable()->after('taken_at');
        });

        DB::table('rental_inspection_photos')
            ->whereNull('taken_at')
            ->update(['taken_at' => DB::raw('created_at'), 'taken_at_source' => 'server']);
    }

    public function down(): void
    {
        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->dropColumn(['taken_at', 'taken_at_source']);
        });
    }
};
