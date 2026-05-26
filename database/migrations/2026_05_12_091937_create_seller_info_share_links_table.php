<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_info_share_links', function (Blueprint $table) {
            $table->id();
            $table->enum('tier', ['tier_1', 'tier_2', 'tier_3']);
            $table->string('seller_name')->nullable();
            $table->string('seller_email')->nullable();
            $table->text('agent_message')->nullable();
            $table->foreignId('property_id')->nullable()->constrained();
            $table->foreignId('contact_id')->nullable()->constrained();
            $table->foreignId('sent_by_user_id')->constrained('users');
            $table->foreignId('agency_id')->constrained();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->useCurrent();
            $table->unsignedInteger('accessed_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_info_share_links');
    }
};
