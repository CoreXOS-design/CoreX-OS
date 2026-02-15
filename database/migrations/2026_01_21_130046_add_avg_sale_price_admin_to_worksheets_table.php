<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            if (!Schema::hasColumn('worksheets', 'avg_sale_price_admin')) {
                $table->decimal('avg_sale_price_admin', 12, 2)->nullable()->after('avg_sale_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            if (Schema::hasColumn('worksheets', 'avg_sale_price_admin')) {
                $table->dropColumn('avg_sale_price_admin');
            }
        });
    }
};
