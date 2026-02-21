<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('source_snapshot_id')->nullable()->after('source_upload_id');
            $table->string('extraction_method', 30)->nullable()->after('parser_version');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->dropColumn(['source_snapshot_id', 'extraction_method']);
        });
    }
};
