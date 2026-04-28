<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->foreignId('notified_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('notification_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['contact_match_id', 'property_id'], 'cmn_match_property_unique');
            $table->index('property_id', 'cmn_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_notifications');
    }
};
