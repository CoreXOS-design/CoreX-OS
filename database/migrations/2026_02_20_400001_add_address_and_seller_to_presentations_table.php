<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            // Nullable so existing rows are not broken (no default required)
            $table->string('property_address')->nullable()->after('title');
            $table->string('seller_name')->nullable()->after('property_address');
            $table->string('seller_email')->nullable()->after('seller_name');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn(['property_address', 'seller_name', 'seller_email']);
        });
    }
};
