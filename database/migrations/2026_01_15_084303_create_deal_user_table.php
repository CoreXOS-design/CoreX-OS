<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_user', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('user_id');

            // listing or selling
            $table->enum('side', ['listing', 'selling']);

            // optional override split (percent of that side for this agent)
            $table->decimal('agent_split_percent', 5, 2)->nullable();

            $table->timestamps();

            $table->foreign('deal_id')->references('id')->on('deals')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['deal_id', 'user_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_user');
    }
};
