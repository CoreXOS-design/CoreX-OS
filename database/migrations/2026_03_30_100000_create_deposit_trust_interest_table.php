<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_trust_interest', function (Blueprint $table) {
            $table->id();
            $table->date('interest_date')->unique();
            $table->decimal('total_invested_funds', 14, 2);
            $table->decimal('interest_earned', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index('interest_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_trust_interest');
    }
};
