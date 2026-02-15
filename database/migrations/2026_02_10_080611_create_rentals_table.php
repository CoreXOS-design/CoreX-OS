<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->text('lease_address');

            $table->date('lease_start_date');
            $table->date('lease_end_date')->nullable();

            $table->boolean('is_month_to_month')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_rental_assist')->default(false);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
            $table->index(['lease_start_date', 'lease_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
