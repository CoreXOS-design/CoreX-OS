<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('requester_role');
            $table->enum('status', ['pending', 'approved', 'denied', 'expired', 'cancelled'])
                ->default('pending');
            $table->text('reason')->nullable();
            $table->text('denial_reason')->nullable();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('granted_session_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['target_agency_id', 'status']);
            $table->index(['requester_user_id', 'status']);
            $table->index('expires_at');
        });

        // Pivot — which admins of the target agency the requester selected.
        Schema::create('agency_access_request_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')
                ->constrained('agency_access_requests')
                ->cascadeOnDelete();
            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['request_id', 'admin_user_id'], 'aar_admins_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_access_request_admins');
        Schema::dropIfExists('agency_access_requests');
    }
};
