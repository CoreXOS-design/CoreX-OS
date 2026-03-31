<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_interest_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('property_name', 255);
            $table->decimal('deposit_amount', 12, 2);
            $table->date('invest_date');
            $table->date('refund_date');
            $table->json('topups')->nullable();
            $table->decimal('total_deposited', 12, 2);
            $table->decimal('total_interest', 12, 2);
            $table->decimal('grand_total', 12, 2);
            $table->json('breakdown');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_interest_calculations');
    }
};
