<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Widens the "owner already linked to a Contact" demo story on the Deeds
 * Capture screen beyond the single example DemoTvaCapturesSeeder produces.
 *
 * Johan: "I need contacts linked to a property on the list to show the
 * contact linking... Ideally show a mix: some linked, some not yet linked."
 * The screen only shows the green "✓ Already a contact — view record" badge
 * (resources/views/corex/deeds-capture/index.blade.php:596-603) when a
 * `tracked_property_owners` row has `matched_contact_at` set AND a
 * `contact_id` — never from `tracked_properties.owner_contact_id` alone
 * (that column only drives the plain-text fallback with no badge). One
 * example (from DemoTvaCapturesSeeder) is thin for a live demo; this adds
 * two more, on DIFFERENT rows so the story isn't clustered on one property.
 * Every other deeds-captured row (the other ~15) is left untouched — still
 * plain owner text, no badge — which IS the "not yet linked" half of the
 * mix Johan asked for.
 *
 * IDEMPOTENT BY CONSTRUCTION: guarded by an explicit existence check on
 * (tracked_property_id, id_number) before insert, same pattern as
 * DemoTvaCapturesSeeder::ensureOwnerRow().
 */
class DemoDeedsOwnerLinkSeeder
{
    /** @return array{linked:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        // 5th and 6th deeds-captured tracked_properties by id — distinct from
        // the first 3 (DemoTvaCapturesSeeder) and the 4th (DemoDeedsPropertyMatchSeeder).
        $targets = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->skip(4)
            ->limit(2)
            ->get(['id', 'owner_contact_id']);

        if ($targets->count() < 2) {
            return ['linked' => 0, 'note' => 'Skipped — fewer than 6 deeds-captured tracked_properties present.'];
        }

        $linked = 0;
        foreach ($targets as $tp) {
            if (!$tp->owner_contact_id) {
                continue;
            }
            $contact = DB::table('contacts')->where('id', $tp->owner_contact_id)->first(['id', 'first_name', 'last_name', 'id_number']);
            if (!$contact || !$contact->id_number) {
                continue;
            }

            $exists = DB::table('tracked_property_owners')
                ->where('tracked_property_id', $tp->id)
                ->where('id_number', $contact->id_number)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('tracked_property_owners')->insert([
                'tracked_property_id' => $tp->id,
                'contact_id'          => $contact->id,
                'matched_contact_at'  => now(),
                'name'                => trim($contact->first_name . ' ' . $contact->last_name),
                'id_number'           => $contact->id_number,
                'id_type'             => 'sa_id',
                'is_primary'          => true,
                'role'                => 'owner',
                'ownership_status'    => 'current',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
            $linked++;
        }

        $note = "Owner links: +{$linked} additional 'already a contact' examples on the deeds list.";

        return ['linked' => $linked, 'note' => $note];
    }
}
