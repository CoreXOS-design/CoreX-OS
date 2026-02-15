<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // created | status_changed | commission_status_changed | remark_added | system
            $table->string('event_type', 50);

            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->text('message')->nullable();

            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_logs');
    }
};
