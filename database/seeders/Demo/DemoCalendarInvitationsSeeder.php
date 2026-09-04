<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Johan-adjacent, 2026-09-03 — the "reads as live" sweep found Calendar
 * Invitations (corex/command-center/calendar/invitations) sitting at 0
 * rows despite 1,455 real calendar events. A team calendar with zero
 * invitations ever sent looks like nobody has used it. Seeds 5 real
 * invitations on real near-future events, real agent pairs within the
 * same branch, a realistic status mix (pending/accepted/declined/tentative).
 *
 * IDEMPOTENT: skips any target event that already has an invitation row.
 */
class DemoCalendarInvitationsSeeder extends Seeder
{
    private const TARGET_EVENT_IDS_STATUSES = [
        327 => 'pending',
        304 => 'accepted',
        181 => 'accepted',
        378 => 'declined',
        260 => 'tentative',
    ];

    public function run(int $agencyId = 1): array
    {
        $created = 0;
        foreach (self::TARGET_EVENT_IDS_STATUSES as $eventId => $status) {
            $event = DB::table('calendar_events')->where('id', $eventId)->where('agency_id', $agencyId)->first();
            if (!$event) {
                continue;
            }
            if (DB::table('calendar_event_invitations')->where('event_id', $eventId)->exists()) {
                continue;
            }

            $owner = DB::table('users')->where('id', $event->user_id)->first();
            if (!$owner) {
                continue;
            }
            $invitee = DB::table('users')
                ->where('branch_id', $owner->branch_id)
                ->where('id', '!=', $owner->id)
                ->whereIn('role', ['agent', 'branch_manager'])
                ->orderBy('id')
                ->first();
            if (!$invitee) {
                continue;
            }

            $responded = in_array($status, ['accepted', 'declined', 'tentative'], true);
            DB::table('calendar_event_invitations')->insert([
                'event_id' => $eventId,
                'agency_id' => $agencyId,
                'invitee_user_id' => $invitee->id,
                'inviter_user_id' => $owner->id,
                'status' => $status,
                'acknowledged_at' => $responded ? now() : null,
                'response_at' => $responded ? now() : null,
                'response_notes' => $status === 'declined' ? 'Clashes with another appointment.' : null,
                'notified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return ['created' => $created];
    }
}
