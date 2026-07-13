<?php

declare(strict_types=1);

namespace App\Services\Filing;

use App\Models\DocumentFiling;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * AT-238 — the ONE place that decides "which property is this filing row about?".
 *
 * The form's typeahead, the historical backfill and the review queue all match through
 * here, so a link made by a human and a link proposed by the backfill mean the same
 * thing and are found the same way. (Fix the class: one search, not three.)
 *
 * It matches through `Property::scopeSearchAddress` — the canonical token-AND property
 * search every picker in CoreX is required to use — rather than inventing a private
 * address matcher that would drift from the rest of the system.
 */
class FilingPropertyLinker
{
    /**
     * Candidate properties for a free-text address, within one agency.
     *
     * Deliberately NOT fuzzy. The register carries real typos ("3 Forset Walk",
     * "Beacan Rocks") and a fuzzy matcher would happily attach a legal filing to a
     * plausible-but-wrong house. A miss that stays free text is honest; a confident
     * wrong link is a liability.
     *
     * @return Collection<int,Property>
     */
    public function candidates(?string $address, int $agencyId, int $limit = 15): Collection
    {
        $address = trim((string) $address);
        if (mb_strlen($address) < 2) {
            return collect();
        }

        return Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->searchAddress($address)
            ->with('agent')
            ->limit($limit)
            ->get();
    }

    /**
     * The three honest outcomes of matching one row.
     *
     *   exactly-one → link it (confidence 'exact')
     *   several     → a human must choose; we record the candidates, we do not guess
     *   none        → it stays free text. ~42% of the historical register lands here
     *                 (files that predate the property records); that is not a failure.
     *
     * @return array{status:'matched'|'ambiguous'|'unmatched', property:?Property, candidates:Collection<int,Property>}
     */
    public function match(DocumentFiling $filing): array
    {
        $candidates = $this->candidates($filing->property_address, (int) $filing->agency_id);

        if ($candidates->count() === 1) {
            return ['status' => 'matched', 'property' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'ambiguous', 'property' => null, 'candidates' => $candidates];
        }

        return ['status' => 'unmatched', 'property' => null, 'candidates' => $candidates];
    }

    /**
     * The seller(s) CoreX already knows for a property — sourced from the property-link
     * roles (`contact_property.role`), which is the standing doctrine for "who are the
     * parties on this property". Never a name typed twice.
     *
     * @return Collection<int,\App\Models\Contact>
     */
    public function sellerCandidates(Property $property): Collection
    {
        return $property->contactsForRole('seller_owner');
    }

    /**
     * What linking a property should PREFILL on a filing row.
     *
     * Expiry is prefilled from the property's mandate expiry, ONCE, into the row's own
     * column — it is not a live mirror. A property holds one expiry_date; the register
     * legitimately holds several mandate documents per property with their own lifespans
     * (on qa1, 68 addresses carry more than one OA/EA, several with different dates).
     * Mirroring would collapse them and falsify what was actually filed. So: suggest,
     * let the user overrule, then leave it alone forever.
     *
     * @return array{property_address:string, expiry_date:?string, seller_contact_id:?int, seller_name:?string}
     */
    public function suggestionsFor(Property $property): array
    {
        $seller = $this->sellerCandidates($property)->first();

        return [
            'property_address'  => $property->buildDisplayAddress() ?: '',
            'expiry_date'       => $property->expiry_date?->format('Y-m-d'),
            'seller_contact_id' => $seller?->id,
            'seller_name'       => $seller
                ? trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? ''))
                : null,
        ];
    }
}
