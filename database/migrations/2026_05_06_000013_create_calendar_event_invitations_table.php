<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_event_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'tentative', 'declined', 'cancelled'])->default('pending');
            $table->timestamp('response_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->json('conflict_at_invite')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'invitee_user_id']);
            $table->index(['invitee_user_id', 'status']);
        });

        // Backfill: existing agent_contact links → create accepted invitations
        $agentLinks = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\User')
            ->whereIn('role', ['agent_contact', 'attendee'])
            ->get(['calendar_event_id', 'linkable_id', 'created_by_user_id']);

        $now = now();
        foreach ($agentLinks as $link) {
            $event = DB::table('calendar_events')->where('id', $link->calendar_event_id)->first(['user_id']);
            if (!$event || (int) $link->linkable_id === (int) $event->user_id) continue; // Skip self-invite

            DB::table('calendar_event_invitations')->insertOrIgnore([
                'event_id' => $link->calendar_event_id,
                'invitee_user_id' => $link->linkable_id,
                'inviter_user_id' => $event->user_id,
                'status' => 'accepted',
                'response_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_invitations');
    }
};
