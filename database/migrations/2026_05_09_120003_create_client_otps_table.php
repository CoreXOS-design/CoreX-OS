<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('purpose')->default('activation'); // activation | recovery
            $table->string('code_hash');
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['email', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_otps');
    }
};
