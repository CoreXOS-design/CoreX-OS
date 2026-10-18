<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 6 — a crew photo carries an
 * optional caption and where it came from (`crew_link` / `crew_page` /
 * `office`). Existing photos have neither (null = uploaded before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_work_order_photos', 'caption')) {
                $table->string('caption', 255)->nullable()->after('photo_type');
            }
            if (! Schema::hasColumn('rental_work_order_photos', 'uploaded_via')) {
                $table->string('uploaded_via', 20)->nullable()->after('caption');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            $table->dropColumn(['caption', 'uploaded_via']);
        });
    }
};
