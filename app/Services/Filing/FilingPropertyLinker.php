<?php

declare(strict_types=1);

namespace App\Services\Filing;

use App\Models\Property;
use App\Models\User;
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
     * AT-253-class bug, found by Johan on qa1 and fixed here: this used to filter on the raw
     * `$user->agency_id`. An owner/super-admin carries `agency_id = NULL`, which cast to the
     * sentinel 0 — so the picker searched agency 0, found nothing, and the dropdown never
     * opened. The search looked "completely broken" while being, technically, a correct query
     * against a tenant that does not exist.
     *
     * Visibility now goes through `Property::scopeVisibleTo()` — the same canonical scope the
     * DR2 picker uses — so a super-admin sees everything they are entitled to, an agency user
     * sees their agency's stock, and nobody hand-rolls a tenant filter again.
     *
     * @return Collection<int,Property>
     */
    public function candidates(?string $address, User $user, int $limit = 15): Collection
    {
        $address = trim((string) $address);
        if (mb_strlen($address) < 2) {
            return collect();
        }

        return Property::query()
            ->visibleTo($user)
            ->searchAddress($address)
            ->with(['agent', 'branch'])
            ->limit($limit)
            ->get();
    }

    /** Can this user legitimately see (and therefore link) this property? */
    public function isVisibleTo(Property $property, User $user): bool
    {
        return Property::query()
            ->visibleTo($user)
            ->whereKey($property->getKey())
            ->exists();
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
     * Everything the filing form can DERIVE once a property is picked.
     *
     * The property is the lead selector, so it answers every question it can: which branch and
     * which agent this listing belongs to, who the seller is (from the property-link roles),
     * and when the mandate expires. The clerk is then left with the one fact the system cannot
     * know — the file number.
     *
     * All of it is a SUGGESTION, filled once into the row's own columns, where the user may
     * overrule any of it. The expiry in particular is not a live mirror: a property holds one
     * expiry_date, but the register legitimately holds several mandate documents per property
     * with their own lifespans (on qa1, 68 addresses carry more than one OA/EA, several with
     * genuinely different dates). Mirroring would collapse them and falsify what was filed.
     *
     * @return array<string,mixed>
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
            // The listing's own context — the branch it is listed under and the agent who
            // holds it. These are what the clerk would otherwise have to know and retype.
            'branch_id'         => $property->branch_id,
            'branch_name'       => $property->branch?->name,
            'agent_id'          => $property->agent_id,
            'agent_name'        => $property->agent?->name,
        ];
    }
}
