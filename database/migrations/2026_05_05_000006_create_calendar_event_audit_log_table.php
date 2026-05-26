<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_event_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['calendar_event_id', 'performed_at'], 'cea_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_audit_log');
    }
};
