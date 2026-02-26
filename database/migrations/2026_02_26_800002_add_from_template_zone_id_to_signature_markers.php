<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_markers', function (Blueprint $table) {
            $table->unsignedBigInteger('from_template_zone_id')->nullable()->after('required');
            $table->index('from_template_zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('signature_markers', function (Blueprint $table) {
            $table->dropIndex(['from_template_zone_id']);
            $table->dropColumn('from_template_zone_id');
        });
    }
};
