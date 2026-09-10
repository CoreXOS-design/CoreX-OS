<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — coordinator-directed fixes, both visible in the
 * first three seconds of clicking Contacts:
 *
 * 1. 24 contacts named "[DEMO] Spine Buyer #0".."#11" / "[DEMO] Spine
 *    Seller #0".."#11" sat at the very top of the default alphabetical
 *    contacts sort (`#` sorts before letters) — internal
 *    stageSpine_threadFullLifecycle() QA fixtures, never intended as demo
 *    show pieces. Confirmed before touching: zero communication_links,
 *    zero calendar_event_links reference them; only contact_notes (48
 *    rows, itself an internal audit trail) does. Soft-deleted, not hard
 *    deleted — standing no-hard-delete rule. Admin can recover.
 *
 * 2. Two duplicate-name collisions where the flagship record (rich real
 *    story, already named in Johan's run sheet) shares a name with one or
 *    more bare/thin records — a name search during the webinar could land
 *    on the empty one. Confirmed the flagship by data richness before
 *    renaming anything:
 *      - "Anele Botha": contact 31 (viewing pack 21, 3 comms, 3 property
 *        views) is the flagship — confirmed via viewing_packs.contact_id.
 *        Contacts 91 and 151 (2 comms, 0 comms respectively) renamed.
 *      - "Zanele Bezuidenhout": contact 45 (6 comms, 3 property views) is
 *        the richest — kept. Contacts 105 and 165 renamed.
 *    Replacement names checked against the full contacts table first —
 *    neither combination existed anywhere in the dataset.
 *
 * Idempotent: the spine soft-delete only ever touches rows currently
 * NULL on deleted_at (a re-run finds none left). The renames are keyed by
 * contact id and are naturally idempotent (re-applying the same name is a
 * no-op in effect).
 */
final class DemoContactRealismCleanupSeeder
{
    private const SPINE_CONTACT_IDS = [
        247, 248, 249, 250, 251, 252, 253, 254, 255, 256, 257, 258,
        259, 260, 261, 262, 263, 264, 265, 266, 267, 268, 269, 270,
    ];

    private const RENAMES = [
        // id => [first_name, last_name, email]
        91  => ['[DEMO] Nomvula', 'Radebe', 'nomvula91@example.com'],
        151 => ['[DEMO] Andile', 'Mabaso', 'andile151@example.com'],
        105 => ['[DEMO] Palesa', 'Ngcobo', 'palesa105@example.com'],
        165 => ['[DEMO] Bheki', 'Zungu', 'bheki165@example.com'],
    ];

    public function run(int $agencyId): array
    {
        $spineArchived = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereIn('id', self::SPINE_CONTACT_IDS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        $renamed = 0;
        foreach (self::RENAMES as $id => [$firstName, $lastName, $email]) {
            $updated = DB::table('contacts')
                ->where('agency_id', $agencyId)
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update([
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $email,
                    'updated_at' => now(),
                ]);
            $renamed += $updated;
        }

        return [
            'spine_contacts_archived' => $spineArchived,
            'duplicate_names_renamed' => $renamed,
        ];
    }
}
