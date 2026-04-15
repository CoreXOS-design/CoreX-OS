<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p24_import_rows', function (Blueprint $table) {
            $table->string('confirmed_via')->nullable()->after('confirmed_by');
            $table->unsignedBigInteger('confirmed_by_portal_id')->nullable()->after('confirmed_via')->index();
            // Set when a queued confirm job is in-flight; cleared when the job completes.
            $table->timestamp('processing_at')->nullable()->after('confirmed_by_portal_id');
        });
    }

    public function down(): void
    {
        Schema::table('p24_import_rows', function (Blueprint $table) {
            $table->dropColumn(['confirmed_via', 'confirmed_by_portal_id', 'processing_at']);
        });
    }
};
