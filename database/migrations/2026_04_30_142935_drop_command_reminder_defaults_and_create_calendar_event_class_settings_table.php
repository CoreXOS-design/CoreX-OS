<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('command_reminder_defaults');

        Schema::create('calendar_event_class_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('event_class', 60)->index();
            $table->boolean('is_active')->default(true);

            // Threshold days
            $table->unsignedSmallInteger('green_days');
            $table->unsignedSmallInteger('amber_days');
            $table->unsignedSmallInteger('red_days');
            $table->unsignedSmallInteger('show_days')->nullable();

            // Visibility per colour (JSON array of role slugs)
            $table->json('green_visibility');
            $table->json('amber_visibility');
            $table->json('red_visibility');

            // Notification routing per colour (JSON object: {role: [channels]})
            $table->json('green_notifications');
            $table->json('amber_notifications');
            $table->json('red_notifications');

            // Daily digest
            $table->boolean('daily_digest_enabled')->default(false);
            $table->json('daily_digest_roles')->nullable();

            // Display
            $table->string('label', 100);
            $table->string('description', 255)->nullable();

            $table->timestamps();

            $table->unique(['agency_id', 'event_class'], 'cecs_agency_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_class_settings');

        Schema::create('command_reminder_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('event_category');
            $table->json('reminder_offsets')->nullable();
            $table->boolean('escalation_enabled')->default(false);
            $table->integer('escalation_delay')->nullable();
            $table->string('escalation_to')->nullable();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->timestamps();
        });
    }
};
