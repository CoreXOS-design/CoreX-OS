<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day (2026-09-03) — "does this look like a property a real agency
 * is actively marketing?" Every one of demo's 348 sale + 15 rental
 * properties had a completely empty `description` column — a real listing
 * always has marketing copy; a blank one reads as an unfinished record no
 * matter how populated everything else on the page is.
 *
 * Procedurally generated (not hand-written per row) from a bank of opening/
 * closing phrases + the property's own real attributes (beds/baths/type/
 * suburb/price), deterministically varied by crc32(property id) so the same
 * property always gets the same description on reseed, and neighbouring
 * properties don't read as copy-pasted.
 *
 * Scope: every property currently ACTIVELY MARKETED (sale status='active',
 * rental status='to_let') — the ones a prospect would actually be shown.
 * Draft/sold/withdrawn stock intentionally left blank: a sold listing
 * having no live marketing copy is realistic, not a gap.
 *
 * Idempotent: only fills properties where description is currently NULL or
 * empty — never overwrites a real agent's own copy.
 */
final class DemoPropertyDescriptionsSeeder
{
    /** Every template takes (%s type phrase, %s suburb) in that fixed order. */
    private const OPENERS = [
        'Set in the heart of %2$s, this %1$s offers the perfect blend of comfort and lifestyle.',
        'A rare opportunity to secure this %1$s in one of %2$s\'s most sought-after pockets.',
        'Welcome to this beautifully presented %1$s in %2$s — ready for its next owners.',
        'Discover easy coastal living in this %1$s, ideally located in %2$s.',
        'This %1$s in %2$s ticks every box for buyers looking for space, light, and location.',
        'Positioned in a quiet, established part of %2$s, this %1$s is not to be missed.',
    ];

    private const FEATURE_LINES = [
        'sale' => 'The home offers %d spacious bedroom%s and %d bathroom%s, with %s parking for %d vehicle%s.',
        'rental' => 'This rental offers %d bedroom%s and %d bathroom%s, with %s parking for %d vehicle%s — available for immediate occupation.',
    ];

    private const CLOSERS = [
        'Close to schools, shops and the beach — this one won\'t last long.',
        'Move-in ready and priced to sell — call your agent to arrange a viewing today.',
        'A must-see for anyone serious about the KZN South Coast lifestyle.',
        'Perfectly positioned for the growing family or the savvy investor alike.',
        'Contact the listing agent today to arrange your private viewing.',
        'Book your viewing today — properties like this move quickly on the South Coast.',
    ];

    public function run(int $agencyId): array
    {
        $updated = 0;

        $updated += $this->fillFor($agencyId, 'sale', 'active');
        $updated += $this->fillFor($agencyId, 'rental', 'to_let');

        return ['updated' => $updated];
    }

    private function fillFor(int $agencyId, string $listingType, string $status): int
    {
        $properties = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('listing_type', $listingType)
            ->where('status', $status)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('description')->orWhere('description', '');
            })
            ->get(['id', 'address', 'suburb', 'property_type', 'beds', 'baths', 'garages']);

        $updated = 0;
        foreach ($properties as $p) {
            $seed = crc32('desc|' . $p->id);
            $opener = self::OPENERS[$seed % count(self::OPENERS)];
            $closer = self::CLOSERS[intdiv($seed, 7) % count(self::CLOSERS)];

            // Every template already places "this %1$s" — bare type, no article.
            $typeLabel = strtolower($p->property_type ?: 'property');
            $openerLine = sprintf($opener, $typeLabel, $p->suburb ?: 'the area');

            $beds = (int) $p->beds;
            $baths = (int) $p->baths;
            $garages = (int) $p->garages;
            $parkingWord = $garages > 0 ? 'secure garage' : 'off-street';
            $featureTemplate = self::FEATURE_LINES[$listingType];
            $featureLine = $beds > 0
                ? sprintf(
                    $featureTemplate,
                    $beds, $beds === 1 ? '' : 's',
                    max($baths, 1), max($baths, 1) === 1 ? '' : 's',
                    $parkingWord, max($garages, 1), max($garages, 1) === 1 ? '' : 's'
                )
                : 'A versatile stand ready for your vision, in a well-established residential pocket.';

            $description = trim($openerLine . ' ' . $featureLine . ' ' . $closer);

            DB::table('properties')->where('id', $p->id)->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return $updated;
    }
}
