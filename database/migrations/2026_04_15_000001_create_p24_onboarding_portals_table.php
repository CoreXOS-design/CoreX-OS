<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_onboarding_portals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('run_ids_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'revoked_at', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_onboarding_portals');
    }
};
