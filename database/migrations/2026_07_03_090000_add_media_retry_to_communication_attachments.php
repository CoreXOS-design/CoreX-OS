<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-148 media retry — track download attempts so a media attachment can never
 * sit on "processing" forever: retry a bounded number of times, then mark it
 * terminally 'failed' with a visible Retry affordance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('media_status');
            $table->string('last_media_error', 500)->nullable()->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'last_media_error']);
        });
    }
};
