<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 (Buyer CRM) foundation: lifecycle states, activity tracking.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Add buyer columns to contacts
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_buyer')->default(false)->after('last_consent_check_at');
            $table->string('buyer_state', 20)->nullable()->after('is_buyer');
            $table->timestamp('last_activity_at')->nullable()->after('buyer_state');
            $table->timestamp('buyer_pipeline_entered_at')->nullable()->after('last_activity_at');
            $table->text('buyer_pipeline_notes')->nullable()->after('buyer_pipeline_entered_at');

            $table->index(['agency_id', 'is_buyer', 'buyer_state'], 'contacts_buyer_pipeline_idx');
        });

        // 2. Create buyer_activity_log
        Schema::create('buyer_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->enum('activity_type', [
                'viewing_completed', 'presentation', 'contact_access',
                'note_added', 'call_logged', 'email_sent', 'whatsapp_sent', 'manual',
            ]);
            $table->timestamp('activity_date');
            $table->foreignId('related_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('related_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('related_feedback_id')->nullable()->constrained('calendar_event_feedback')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'activity_date']);
            $table->index(['agency_id', 'activity_type', 'activity_date']);
        });

        // 3. Create buyer_state_transitions
        Schema::create('buyer_state_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->enum('reason', ['auto_recompute', 'manual_override', 'first_activity']);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
        });

        // 4. Create buyer_property_views (denormalised cache)
        Schema::create('buyer_property_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->foreignId('most_recent_feedback_id')->nullable()->constrained('calendar_event_feedback')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'property_id']);
            $table->index(['property_id', 'last_viewed_at']);
        });

        // 5. Backfill: mark existing contacts with viewing feedback as buyers
        // Find contacts that are linked to viewing/presentation events via feedback
        DB::statement("
            UPDATE contacts
            INNER JOIN (
                SELECT DISTINCT cef.contact_id, MAX(cef.captured_at) as last_feedback
                FROM calendar_event_feedback cef
                INNER JOIN calendar_events ce ON ce.id = cef.calendar_event_id
                WHERE ce.category IN ('viewing', 'listing_presentation', 'property_evaluation')
                  AND cef.contact_id IS NOT NULL
                  AND cef.captured_at IS NOT NULL
                GROUP BY cef.contact_id
            ) buyers ON buyers.contact_id = contacts.id
            SET contacts.is_buyer = 1,
                contacts.last_activity_at = buyers.last_feedback,
                contacts.buyer_pipeline_entered_at = buyers.last_feedback,
                contacts.buyer_state = 'new'
            WHERE contacts.is_buyer = 0
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_property_views');
        Schema::dropIfExists('buyer_state_transitions');
        Schema::dropIfExists('buyer_activity_log');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_buyer_pipeline_idx');
            $table->dropColumn(['is_buyer', 'buyer_state', 'last_activity_at', 'buyer_pipeline_entered_at', 'buyer_pipeline_notes']);
        });
    }
};
