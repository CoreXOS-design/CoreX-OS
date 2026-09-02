<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Configuration sweep addendum (2026-09-02, webinar prep) — Settings →
 * Contacts → Sources/Tags/Identifier Labels were all completely empty
 * (0 rows agency-wide), and none of the 290 demo contacts had a
 * contact_source_id set. Contact Types (Lessee/Lessor/Seller/Buyer/Owner/
 * Other) were already backfilled in an earlier pass — this rounds out the
 * rest of that same settings screen.
 *
 * IDEMPOTENT BY CONSTRUCTION: sources/tags are firstOrCreate on
 * (agency_id, name); contact backfill only ever touches contacts whose
 * contact_source_id is currently null.
 */
class DemoContactSourcesTagsSeeder
{
    private const SOURCES = ['Property24', 'Referral', 'Walk-in', 'Website Enquiry', 'Facebook'];
    private const TAGS = ['Hot Lead', 'VIP', 'Investor'];

    /** @return array{sources_created:int, tags_created:int, contacts_tagged:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $sourcesCreated = 0;
        $sourceIds = [];
        foreach (self::SOURCES as $i => $name) {
            $row = DB::table('contact_sources')->where('agency_id', $agencyId)->where('name', $name)->first();
            if (!$row) {
                $id = DB::table('contact_sources')->insertGetId([
                    'agency_id'  => $agencyId,
                    'name'       => $name,
                    'color'      => '#6366f1',
                    'sort_order' => $i,
                    'is_active'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sourcesCreated++;
            } else {
                $id = $row->id;
            }
            $sourceIds[] = $id;
        }

        $tagsCreated = 0;
        foreach (self::TAGS as $i => $name) {
            $exists = DB::table('contact_tags')->where('agency_id', $agencyId)->where('name', $name)->exists();
            if (!$exists) {
                DB::table('contact_tags')->insert([
                    'agency_id'       => $agencyId,
                    'contact_type_id' => null,
                    'name'            => $name,
                    'color'           => '#f59e0b',
                    'sort_order'      => $i,
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                $tagsCreated++;
            }
        }

        $contactsTagged = 0;
        if (!empty($sourceIds)) {
            $untaggedContacts = DB::table('contacts')
                ->where('agency_id', $agencyId)
                ->whereNull('contact_source_id')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id']);

            foreach ($untaggedContacts as $idx => $contact) {
                DB::table('contacts')->where('id', $contact->id)->update([
                    'contact_source_id' => $sourceIds[$idx % count($sourceIds)],
                    'updated_at'        => now(),
                ]);
                $contactsTagged++;
            }
        }

        $note = "Contact sources/tags: +{$sourcesCreated} sources, +{$tagsCreated} tags, {$contactsTagged} contacts backfilled with a source.";

        return ['sources_created' => $sourcesCreated, 'tags_created' => $tagsCreated, 'contacts_tagged' => $contactsTagged, 'note' => $note];
    }
}
