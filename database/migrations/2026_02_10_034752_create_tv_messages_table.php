<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tv_messages', function (Blueprint $table) {
            $table->id();

            // NULL = global message (all branches)
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // creator attribution
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();

            // message content
            $table->string('title')->nullable();
            $table->text('message');

            // control flags
            $table->boolean('is_enabled')->default(true)->index();

            // optional scheduling
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            $table->timestamps();

            // useful composite index
            $table->index(['branch_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tv_messages');
    }
};
