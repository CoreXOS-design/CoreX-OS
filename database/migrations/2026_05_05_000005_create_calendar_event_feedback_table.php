<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_event_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outcome_option_id')->nullable()
                  ->constrained('agency_feedback_options')->nullOnDelete();
            $table->json('concern_option_ids')->nullable();
            $table->text('seller_visible_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('next_action_notes')->nullable();
            $table->foreignId('captured_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['calendar_event_id', 'contact_id'], 'cef_event_contact_unique');
            $table->index(['agency_id', 'captured_at'], 'cef_agency_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_feedback');
    }
};
