<?php

namespace App\Services\Rentals;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\LeaseTenant;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\ContactScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WHO the portal is talking to, once a login is signed in.
 *
 * A portal login is one person (one email) but can carry several CONTACT rows in the agency - the same person captured twice (once
 * as the tenant, once as the owner of another property), or two people who share one address (a couple on one lease). Until
 * 8 Oct 2026 every portal call took the lowest-id contact of the login whatever side was on screen, so on a shared browser the
 * header greeted the Owner view with the tenant contact's name, and a fault reported from the owner side was filed under the other
 * contact. This is the ONE place that picks the contact:
 *
 *   1. the SIDE being served (tenant / landlord): only a contact that actually holds that side is a candidate;
 *   2. the contact the LINK named (the `c` in a signed link, sent as the X-Portal-Contact header) when it is one of this login's
 *      own contacts in the agency and holds that side;
 *   3. otherwise the lowest-id candidate - the old behaviour, so a login with one contact (nearly all of them) is unchanged.
 *
 * The hint is never trusted for access - it can only choose BETWEEN the login's own contacts (the scope service already unions
 * all of them for what the person may see), so it changes a name and an attribution, never a permission.
 *
 * Spec: .ai/specs/rental-portal-access.md section 28.
 */
class PortalIdentityService
{
    public const SIDE_TENANT = 'tenant';
    public const SIDE_LANDLORD = 'landlord';

    public const HINT_HEADER = 'X-Portal-Contact';

    /** The side an API route serves, from its name (`client.rentals.landlord.*` = owner, any other `client.rentals.*` = tenant). */
    public static function sideForRoute(?string $routeName): ?string
    {
        if ($routeName === null || ! str_starts_with($routeName, 'client.rentals.')) {
            return null;
        }

        return str_starts_with($routeName, 'client.rentals.landlord.') ? self::SIDE_LANDLORD : self::SIDE_TENANT;
    }

    /** The contact a link named, as the page sends it back. Null when absent or not a number. */
    public static function hintFrom(Request $request): ?int
    {
        $raw = $request->header(self::HINT_HEADER, $request->query('contact'));
        $id = is_scalar($raw) && ctype_digit((string) $raw) ? (int) $raw : 0;

        return $id > 0 ? $id : null;
    }

    public static function normaliseSide(?string $side): ?string
    {
        return match (strtolower((string) $side)) {
            'tenant' => self::SIDE_TENANT,
            'landlord', 'owner' => self::SIDE_LANDLORD,
            default => null,
        };
    }

    /** The contact to act as for this side. Null only when the login has no contact at all in the agency. */
    public function contactFor(ClientUser $login, int $agencyId, ?string $side = null, ?int $hintContactId = null): ?Contact
    {
        $candidates = $this->candidates($login, $agencyId);
        if ($candidates->isEmpty()) {
            return null;
        }

        $side = self::normaliseSide($side);
        $pool = $side ? $candidates->filter(fn (Contact $c) => $this->holds($c, $side))->values() : collect();
        if ($pool->isEmpty()) {
            $pool = $candidates;   // this login does not hold that side at all: fall back to the whole set (the side's lists are empty anyway)
        }

        if ($hintContactId) {
            $hinted = $pool->first(fn (Contact $c) => (int) $c->id === $hintContactId);
            if ($hinted) {
                return $hinted;
            }
        }

        return $pool->first();
    }

    /**
     * The contact for each side, for the header: ['tenant' => Contact|null, 'landlord' => Contact|null]. A side the login does not
     * hold is null.
     *
     * @return array{tenant:?Contact, landlord:?Contact}
     */
    public function contactsBySide(ClientUser $login, int $agencyId, ?int $hintContactId = null): array
    {
        $candidates = $this->candidates($login, $agencyId);
        $out = [self::SIDE_TENANT => null, self::SIDE_LANDLORD => null];
        foreach ([self::SIDE_TENANT, self::SIDE_LANDLORD] as $side) {
            $holders = $candidates->filter(fn (Contact $c) => $this->holds($c, $side))->values();
            if ($holders->isEmpty()) {
                continue;
            }
            $out[$side] = ($hintContactId ? $holders->first(fn (Contact $c) => (int) $c->id === $hintContactId) : null) ?? $holders->first();
        }

        return $out;
    }

    /** Is this contact one of the login's own (in any agency)? Used to tell "this link is for you" without comparing addresses. */
    public function ownsContact(ClientUser $login, int $contactId): bool
    {
        return $contactId > 0 && Contact::withoutGlobalScopes()->whereNull('deleted_at')->whereKey($contactId)->where('client_user_id', $login->id)->exists();
    }

    /** @return Collection<int,Contact> the login's own live contacts in the agency, lowest id first */
    private function candidates(ClientUser $login, int $agencyId): Collection
    {
        return Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('client_user_id', $login->id)
            ->where('agency_id', $agencyId)
            ->orderBy('id')
            ->get();
    }

    private function holds(Contact $contact, string $side): bool
    {
        if ($side === self::SIDE_TENANT) {
            return LeaseTenant::query()->where('contact_id', $contact->id)->exists();
        }

        return $contact->properties()->wherePivotIn('role', ['landlord', 'lessor'])->exists();
    }
}
