<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Models\SuburbMunicipality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-24/25 — the suburb report's join: an uploaded CMA report's own
 * "SHELLY BEACH / RAY NKONYENI" text needs to bind to our p24_suburb_id
 * records. Nothing did this before (see the creating migration's doc
 * comment for what was checked and ruled out — geocoding_cache in
 * particular, confirmed to return the wrong answer for this exact use).
 *
 * GLOBAL, not agency-scoped, and DYNAMIC, not a hardcoded id list — this
 * queries every agency's actual in-use p24_suburb_ids (properties +
 * active contact_matches wishlists) on every run, so a new agency's
 * suburbs are picked up on its next deploy with no code change here.
 * What DOES need a code change is CONFIRMING an unknown municipality —
 * that's a real geographic fact, not something to infer at deploy time.
 *
 * The municipality values below are hand-curated from established South
 * African municipal demarcation for the KZN South/North Coast (Ugu and
 * iLembe districts) and eThekwini Metro — NOT derived from any in-app
 * geocoding, which was explicitly ruled out as unreliable for this
 * purpose. Deliberately conservative: a genuine boundary case (the
 * Umkomaas cluster — historically contested between eThekwini and Ugu's
 * Umdoni LM — plus one anomalous P24 city grouping, "Bulwer" filed under
 * "Durban") is left `needs_review` rather than guessed. A wrong municipality
 * confidently applied is exactly the failure this build exists to avoid;
 * an honest "unconfirmed" is not.
 *
 * Idempotent: upserts by p24_suburb_id. Re-running never downgrades an
 * already-`confirmed` row (e.g. one an agent later corrected by hand) back
 * to a stale value from this list — see run()'s guard.
 */
class SuburbMunicipalitySeeder extends Seeder implements SyncableReferenceSeeder
{
    /**
     * p24_suburb_id => municipality. Confirmed, defensible source: current
     * SA municipal demarcation for this coastline. Suburbs not listed here
     * are seeded with municipality=null, confidence='needs_review' —
     * genuinely unconfirmed, not an oversight.
     */
    private const KNOWN_MUNICIPALITIES = [
        // Ray Nkonyeni (Ugu District) — core Hibiscus Coast + the towns
        // absorbed from the disestablished Umzumbe LM in the 2016 merger.
        16505 => 'Ray Nkonyeni', // Albersville
        13739 => 'Ray Nkonyeni', // Amanzimtoti -- NOTE: see eThekwini override below; P24 groups it oddly, real answer is eThekwini
        16506 => 'Ray Nkonyeni', // Anerley
        16489 => 'Ray Nkonyeni', // Banners Rest
        15548 => 'Ray Nkonyeni', // Beacon Rocks
        16490 => 'Ray Nkonyeni', // Black Rock
        14835 => 'Ray Nkonyeni', // Catalina Bay
        16491 => 'Ray Nkonyeni', // Doc Wilson Point
        16520 => 'Ray Nkonyeni', // Gamalakhe
        16493 => 'Ray Nkonyeni', // Glenmore
        16524 => 'Ray Nkonyeni', // Grosvenor
        14836 => 'Ray Nkonyeni', // Hibberdene
        16494 => 'Ray Nkonyeni', // Ivy Beach
        12    => 'Ray Nkonyeni', // Izotsha
        23873 => 'Ray Nkonyeni', // Lawrence Rocks
        15    => 'Ray Nkonyeni', // Leisure Bay
        16495 => 'Ray Nkonyeni', // Leisure Crest
        16531 => 'Ray Nkonyeni', // Louisianna
        6     => 'Ray Nkonyeni', // Manaba Beach
        16534 => 'Ray Nkonyeni', // Marburg
        16535 => 'Ray Nkonyeni', // Marburg Settlement
        5     => 'Ray Nkonyeni', // Margate
        23618 => 'Ray Nkonyeni', // Margate Beach
        26831 => 'Ray Nkonyeni', // Margate North Beach
        15551 => 'Ray Nkonyeni', // Marina Beach
        16496 => 'Ray Nkonyeni', // Meadow Brook
        16539 => 'Ray Nkonyeni', // Melville
        18    => 'Ray Nkonyeni', // Merlewood
        15670 => 'Ray Nkonyeni', // Mtwalume
        9     => 'Ray Nkonyeni', // Oslo Beach
        16498 => 'Ray Nkonyeni', // Palm Beach
        16500 => 'Ray Nkonyeni', // Port Edward
        16561 => 'Ray Nkonyeni', // Port Shepstone Central
        16562 => 'Ray Nkonyeni', // Port Shepstone Rural
        16563 => 'Ray Nkonyeni', // Pumula
        15549 => 'Ray Nkonyeni', // Ramsgate
        16565 => 'Ray Nkonyeni', // Rathboneville
        16501 => 'Ray Nkonyeni', // Rennies Beach
        16502 => 'Ray Nkonyeni', // Rocklands
        16503 => 'Ray Nkonyeni', // Salmon Bay
        16652 => 'Ray Nkonyeni', // San Lameer
        11    => 'Ray Nkonyeni', // Sea Park
        1     => 'Ray Nkonyeni', // Shelly Beach
        16666 => 'Ray Nkonyeni', // Southbroom
        16570 => 'Ray Nkonyeni', // Southport
        8     => 'Ray Nkonyeni', // St Michaels On Sea
        16572 => 'Ray Nkonyeni', // Sunwich Port
        16504 => 'Ray Nkonyeni', // Three Hills
        16767 => 'Ray Nkonyeni', // Trafalgar
        3     => 'Ray Nkonyeni', // Umbango
        17590 => 'Ray Nkonyeni', // Umzumbe
        16577 => 'Ray Nkonyeni', // Umtentweni
        2     => 'Ray Nkonyeni', // Uvongo
        10    => 'Ray Nkonyeni', // Uvongo Beach
        14838 => 'Ray Nkonyeni', // Woodgrange
        16497 => 'Ray Nkonyeni', // North Sand Bluff (Port Edward area)
        14382 => 'Ray Nkonyeni', // Bazley Beach (Hibberdene area, ex-Umzumbe LM)

        // KwaDukuza (iLembe District) — North Coast, Ballito/Umhlali area.
        13753 => 'KwaDukuza', // Ballito Central
        13758 => 'KwaDukuza', // Ballitoville
        13762 => 'KwaDukuza', // Caledon Estate
        13766 => 'KwaDukuza', // Dunkirk Estate
        13793 => 'KwaDukuza', // Shortens Country Estate
        13794 => 'KwaDukuza', // Simbithi Eco Estate
        13801 => 'KwaDukuza', // Umhlali
        13803 => 'KwaDukuza', // Umhlali Golf Estate

        // Umdoni (Ugu District) — Scottburgh/Pennington/Park Rynie cluster.
        16130 => 'Umdoni', // Kelso
        16131 => 'Umdoni', // Pennington
        16656 => 'Umdoni', // Freeland Park
        16657 => 'Umdoni', // Park Rynie
        16660 => 'Umdoni', // Scottburgh Central
        16661 => 'Umdoni', // Scottburgh South

        // eThekwini Metropolitan Municipality.
        15261 => 'eThekwini', // Illovo Beach (Kingsburgh)
        15270 => 'eThekwini', // Warner Beach (Kingsburgh)
        14280 => 'eThekwini', // North Beach
        14371 => 'eThekwini', // Durban North
        14380 => 'eThekwini', // Virginia
        15450 => 'eThekwini', // La Mercy
        17067 => 'eThekwini', // Herrwood Park
        17072 => 'eThekwini', // La Lucia
        17077 => 'eThekwini', // Sibaya Precinct
        17080 => 'eThekwini', // Sunningdale
        17082 => 'eThekwini', // Umhlanga Central
    ];

    /**
     * Overrides — suburbs deliberately kept OUT of the base table above
     * because their correct municipality doesn't match the block a reviewer
     * skimming the file would expect from their P24 "city" grouping.
     * Applied via array union (+), never array_merge() — see run()'s own
     * comment for why that distinction matters here.
     */
    private const OVERRIDES = [
        13739 => 'eThekwini',    // Amanzimtoti — P24 groups it under its own
                                  // "Amanzimtoti" city, but the real municipality
                                  // is eThekwini (Kingsburgh/South Durban area).
        13787 => 'KwaDukuza',    // Shakas Rock — P24 groups it under "Ballito",
                                  // and it IS the same North Coast cluster, not
                                  // the Ray Nkonyeni South Coast one its name
                                  // alone (or a careless read of this file)
                                  // might suggest.
    ];

    /**
     * Deliberately NOT assigned — genuine boundary uncertainty, not an
     * oversight. See class doc comment.
     */
    private const NEEDS_REVIEW_NOTES = [
        17091 => 'Umkomaas cluster — historically contested eThekwini/Umdoni boundary, not resolved here.',
        17093 => 'Umkomaas cluster — historically contested eThekwini/Umdoni boundary, not resolved here.',
        17095 => 'Umkomaas cluster — historically contested eThekwini/Umdoni boundary, not resolved here.',
        17097 => 'Umkomaas cluster — historically contested eThekwini/Umdoni boundary, not resolved here.',
        17099 => 'Umkomaas cluster — historically contested eThekwini/Umdoni boundary, not resolved here.',
        14131 => 'P24 files this under city "Durban" but Bulwer is a KZN Midlands town, not metro Durban — grouping looks wrong, not confirming either way.',
    ];

    public function run(): void
    {
        $suburbIds = $this->inUseSuburbIds();
        if ($suburbIds->isEmpty()) {
            return;
        }

        // NEVER array_merge() here — both arrays are keyed by p24_suburb_id
        // (integer keys). array_merge() silently RENUMBERS integer keys
        // sequentially (0, 1, 2, ...) instead of preserving them, which
        // would scramble every id => municipality pairing into an
        // unrelated one by declaration-order coincidence — exactly the
        // "wrong data confidently applied" failure this build exists to
        // avoid. The union operator (+) preserves integer keys and keeps
        // the LEFT array's value on collision, so OVERRIDES correctly wins.
        $municipalities = self::OVERRIDES + self::KNOWN_MUNICIPALITIES;

        $names = DB::table('p24_suburbs')
            ->whereIn('id', $suburbIds)
            ->pluck('name', 'id');

        $now = now();
        foreach ($suburbIds as $suburbId) {
            $existing = SuburbMunicipality::where('p24_suburb_id', $suburbId)->first();

            // Never downgrade an already-confirmed row (e.g. an agent's own
            // manual correction, or a prior run's confirmed value) back to
            // this list's value — this list is a seed, not a live override.
            if ($existing && $existing->confidence === SuburbMunicipality::CONFIDENCE_CONFIRMED) {
                continue;
            }

            $municipality = $municipalities[$suburbId] ?? null;
            $confidence   = $municipality !== null
                ? SuburbMunicipality::CONFIDENCE_CONFIRMED
                : SuburbMunicipality::CONFIDENCE_NEEDS_REVIEW;

            SuburbMunicipality::updateOrCreate(
                ['p24_suburb_id' => $suburbId],
                [
                    'suburb_name'  => $names[$suburbId] ?? ('#' . $suburbId),
                    'municipality' => $municipality,
                    'confidence'   => $confidence,
                    'source'       => $municipality !== null
                        ? 'known_sa_municipal_demarcation_2026-08-25'
                        : null,
                    'updated_at'   => $now,
                ]
            );
        }
    }

    /**
     * Every p24_suburb_id in active use by ANY agency — own stock
     * (properties.p24_suburb_id) or active buyer demand
     * (contact_matches.p24_suburb_ids). Dynamic on every run, agency-
     * agnostic on purpose: this is what makes a new agency's suburbs need
     * no code change here.
     */
    private function inUseSuburbIds(): \Illuminate\Support\Collection
    {
        $fromProperties = DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('p24_suburb_id')
            ->distinct()
            ->pluck('p24_suburb_id');

        $fromWishlists = DB::table('contact_matches')
            ->whereNull('deleted_at')
            ->whereNotNull('p24_suburb_ids')
            ->pluck('p24_suburb_ids')
            ->flatMap(fn ($json) => json_decode((string) $json, true) ?? []);

        return $fromProperties->merge($fromWishlists)
            ->filter()
            ->unique()
            ->values();
    }
}
