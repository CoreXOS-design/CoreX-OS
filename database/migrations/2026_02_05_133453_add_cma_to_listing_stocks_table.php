<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_stocks', function (Blueprint $table) {
            $table->bigInteger('cma_price_cents')->nullable()->after('price_cents');
            $table->timestamp('cma_updated_at')->nullable()->after('cma_price_cents');

            $table->index(['user_id', 'cma_price_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('listing_stocks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'cma_price_cents']);
            $table->dropColumn(['cma_price_cents', 'cma_updated_at']);
        });
    }
};
