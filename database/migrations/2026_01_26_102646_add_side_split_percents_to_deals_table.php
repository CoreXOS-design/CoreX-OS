<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('listing_split_percent', 5, 2)->default(50)->after('selling_external_agency');
            $table->decimal('selling_split_percent', 5, 2)->default(50)->after('listing_split_percent');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['listing_split_percent', 'selling_split_percent']);
        });
    }
};
