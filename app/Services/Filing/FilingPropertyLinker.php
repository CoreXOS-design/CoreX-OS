<?php

declare(strict_types=1);

namespace App\Services\Filing;

use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * AT-238 — what the filing register's property/seller pickers are allowed to know.
 *
 * There is no address MATCHER here, on purpose. Auto-matching the register to properties was
 * investigated against the real data and rejected (Johan, 2026-07-13): a lone address match
 * is not a correct one — "32 Queen View" returns only 29 Queens View; "10 Wingate Avenue"
 * returns only 7 Wingate Avenue — because the canonical search matches each token as a
 * substring across every address field. A confidently wrong link on a legal filing record is
 * worse than the free text it would have replaced. So a link is made by a human looking at a
 * list, or it is not made at all. The 2,069 historical rows stay as they are, forever.
 *
 * Searching goes through Property::scopeSearchAddress — the ONE canonical property search
 * every picker in CoreX is required to use — rather than a private matcher that would drift.
 */
class FilingPropertyLinker
{
    /**
     * Properties a human may pick from, for what they have typed so far.
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
     * The seller(s) CoreX already knows for a property — sourced from the property-link roles
     * (`contact_property.role`), which is the standing doctrine for "who are the parties on
     * this property". Never a name typed twice.
     *
     * @return Collection<int,\App\Models\Contact>
     */
    public function sellerCandidates(Property $property): Collection
    {
        return $property->contactsForRole('seller_owner');
    }

    /**
     * What linking a property should PREFILL on a new filing row.
     *
     * The expiry is a SUGGESTION, filled once into the row's own column, where the user may
     * overrule it. It is not a live mirror: a property holds one expiry_date, but the register
     * legitimately holds several mandate documents per property with their own lifespans (on
     * qa1, 68 addresses carry more than one OA/EA, several with genuinely different dates).
     * Mirroring would collapse them and falsify what was filed.
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
