<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_url_snapshots', function (Blueprint $table) {
            $table->text('final_url')->nullable()->after('url');
            $table->string('content_type', 100)->nullable()->after('http_status');
            $table->unsignedInteger('content_bytes')->nullable()->after('content_type');
            $table->string('blocked_reason', 255)->nullable()->after('content_bytes');
            $table->boolean('timed_out')->default(false)->after('blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_url_snapshots', function (Blueprint $table) {
            $table->dropColumn(['final_url', 'content_type', 'content_bytes', 'blocked_reason', 'timed_out']);
        });
    }
};
