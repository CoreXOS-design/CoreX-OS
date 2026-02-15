<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deal_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('side'); // listing | selling

            // Overrides for the settlement screen (per deal, per agent, per side)
            $table->decimal('share_percent', 8, 2)->default(0);
            $table->decimal('agent_cut_percent', 8, 2)->default(0);

            $table->string('paye_method')->default('percentage'); // percentage | fixed
            $table->decimal('paye_value', 12, 2)->default(0);

            $table->decimal('deductions', 12, 2)->default(0);
            $table->string('deductions_description')->nullable();

            $table->timestamps();

            $table->unique(['deal_id', 'user_id', 'side']);
            $table->index(['deal_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_settlements');
    }
};
