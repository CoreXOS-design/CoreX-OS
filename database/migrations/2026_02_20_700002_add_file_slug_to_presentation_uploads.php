<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_uploads', function (Blueprint $table) {
            $table->string('file_slug', 200)->nullable()->after('storage_path');
            $table->char('content_hash', 64)->nullable()->after('file_slug');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_uploads', function (Blueprint $table) {
            $table->dropColumn(['file_slug', 'content_hash']);
        });
    }
};
