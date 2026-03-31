<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_v2_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('side');
            $table->decimal('share_percent', 8, 2)->default(0);
            $table->decimal('agent_cut_percent', 8, 2)->default(0);
            $table->string('paye_method')->default('percentage');
            $table->decimal('paye_value', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->string('deductions_description')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['deal_id', 'user_id', 'side']);
            $table->index(['deal_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_v2_settlements');
    }
};
