<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')
                  ->constrained('calendar_events')
                  ->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->string('role')->default('attendee');
            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['calendar_event_id', 'linkable_type', 'linkable_id', 'role'],
                'cel_event_linkable_role_unique'
            );
            $table->index(['linkable_type', 'linkable_id'], 'cel_linkable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_links');
    }
};
