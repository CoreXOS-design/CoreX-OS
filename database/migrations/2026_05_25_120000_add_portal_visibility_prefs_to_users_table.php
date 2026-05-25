<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('portal_show_api_token')->default(true)->after('theme');
            $table->boolean('portal_show_social_accounts')->default(true)->after('portal_show_api_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['portal_show_api_token', 'portal_show_social_accounts']);
        });
    }
};
