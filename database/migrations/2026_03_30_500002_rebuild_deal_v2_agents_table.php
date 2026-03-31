<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('deal_v2_agents');

        Schema::create('deal_v2_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('side', ['listing', 'selling']);
            $table->decimal('agent_split_percent', 5, 2)->nullable();
            $table->decimal('agent_cut_percent', 8, 2)->nullable();
            $table->string('paye_method')->nullable();
            $table->decimal('paye_value', 12, 2)->nullable();
            $table->decimal('deductions', 12, 2)->nullable();
            $table->string('deductions_description')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->text('sliding_granted_month')->nullable();
            $table->integer('sliding_sequence_in_month')->nullable();
            $table->decimal('sliding_applied_cut_percent', 5, 2)->nullable();
            $table->dateTime('sliding_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['deal_id', 'user_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_v2_agents');

        Schema::create('deal_v2_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['listing_agent', 'selling_agent', 'referral_agent']);
            $table->decimal('commission_split', 5, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
};
