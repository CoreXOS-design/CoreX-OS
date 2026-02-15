<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_stock_agents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('listing_stock_id');
            $table->unsignedBigInteger('user_id');

            $table->timestamps();

            $table->unique(['listing_stock_id', 'user_id']);

            $table->foreign('listing_stock_id')
                ->references('id')->on('listing_stocks')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->index(['user_id']);
            $table->index(['listing_stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_stock_agents');
    }
};
