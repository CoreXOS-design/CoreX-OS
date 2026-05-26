<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('password_must_change')->default(false);
            $table->timestamp('password_set_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('preferred_agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('locked_to_agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('current_agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('last_ip')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
