<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_v2_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['listing_agent', 'selling_agent', 'referral_agent']);
            $table->decimal('commission_split', 5, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_v2_agents');
    }
};
