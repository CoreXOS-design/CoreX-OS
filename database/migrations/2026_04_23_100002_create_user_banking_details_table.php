<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_banking_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('account_holder', 100);
            $table->string('bank_name', 100);
            $table->string('branch_code', 10);
            $table->string('account_number', 30);
            $table->enum('account_type', ['cheque', 'savings', 'transmission']);
            $table->boolean('is_primary')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_banking_details');
    }
};
