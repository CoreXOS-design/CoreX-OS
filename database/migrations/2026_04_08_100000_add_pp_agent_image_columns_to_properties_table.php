<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('pp_agent_image_path')->nullable()->after('pp_second_agent_id');
            $table->string('pp_second_agent_image_path')->nullable()->after('pp_agent_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['pp_agent_image_path', 'pp_second_agent_image_path']);
        });
    }
};
