<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_url_snapshots', function (Blueprint $table) {
            $table->text('response_headers_json')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_url_snapshots', function (Blueprint $table) {
            $table->dropColumn('response_headers_json');
        });
    }
};
