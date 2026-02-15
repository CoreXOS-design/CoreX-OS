<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_amount_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from');

            $table->decimal('rent_incl', 12, 2);
            $table->decimal('rent_excl', 12, 2);

            $table->decimal('commission_incl', 12, 2);
            $table->decimal('commission_excl', 12, 2);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['rental_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_amount_versions');
    }
};
