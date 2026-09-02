<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Communications\AgentCaptureConsent;
use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve gap fix (2026-09-02) — Johan: "my whatsapp consent - can we
 * put a couple of fakes here?"
 *
 * `agent_capture_consent` is a per-(agent, contact) decision governing
 * whether that agent's WhatsApp chat with that matched contact gets
 * archived — NOT a generic consent table. Real status vocabulary (model
 * constants, verbatim): 'pending' | 'opted_in' | 'opted_out'. There is no
 * "revoked" state in this system — using it would invent a status the code
 * doesn't recognise, so this seeder sticks to the three that exist.
 *
 * Confirmed inert: no observer/listener/booted() hook on this model
 * anywhere in the codebase — inserting rows directly cannot trigger any
 * WhatsApp/WAHA call. Downstream consumers only ever READ status to decide
 * whether to archive an already-inbound message; nothing reacts to a write.
 *
 * Idempotent: unique on (agency_id, agent_user_id, contact_id) — firstOrCreate
 * on that exact key.
 */
final class DemoWhatsappConsentSeeder
{
    private const PLAN = [
        // [agentCursor, contactCursor, status, reason]
        ['agentCursor' => 0, 'contactCursor' => 20, 'status' => 'opted_in', 'reason' => null],
        ['agentCursor' => 1, 'contactCursor' => 21, 'status' => 'opted_in', 'reason' => null],
        ['agentCursor' => 2, 'contactCursor' => 22, 'status' => 'pending', 'reason' => null],
        ['agentCursor' => 0, 'contactCursor' => 23, 'status' => 'pending', 'reason' => null],
        ['agentCursor' => 3, 'contactCursor' => 24, 'status' => 'opted_out', 'reason' => 'Contact asked to be reached by phone only.'],
        ['agentCursor' => 1, 'contactCursor' => 25, 'status' => 'opted_out', 'reason' => 'Prefers email — requested no WhatsApp archiving.'],
    ];

    public function run(int $agencyId): array
    {
        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $contactIds = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(40)
            ->pluck('id')
            ->all();

        if (empty($agentIds) || count($contactIds) < 6) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} lacks agents or enough contacts."];
        }

        $inserted = 0;

        foreach (self::PLAN as $plan) {
            $agentId = $agentIds[$plan['agentCursor'] % count($agentIds)];
            $contactId = $contactIds[$plan['contactCursor'] % count($contactIds)];
            $decided = $plan['status'] !== 'pending';

            $consent = AgentCaptureConsent::firstOrCreate(
                ['agency_id' => $agencyId, 'agent_user_id' => $agentId, 'contact_id' => $contactId],
                [
                    'status' => $plan['status'],
                    'reason' => $plan['reason'],
                    'decided_at' => $decided ? now()->subDays(random_int(1, 60)) : null,
                    'decided_by_user_id' => $decided ? $agentId : null,
                ]
            );
            if ($consent->wasRecentlyCreated) {
                $inserted++;
            }
        }

        return ['inserted' => $inserted];
    }
}
