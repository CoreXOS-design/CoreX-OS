<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who hears about new auction activity (.ai/specs/auctions.md §19). One place, so the emails, the alerts and the tests agree.
 *
 * EMAILS go to the addresses the agency typed into Settings -> Auctions, or - when it typed none - to every administrator of the agency.
 * ALERTS (in-app) go to the person the item belongs to (the listing agent / assigned agent), or every administrator when nobody owns it.
 */
class AuctionActivityRecipients
{
    /** @return array<int, string> valid, lower-cased, de-duplicated */
    public function emails(int $agencyId): array
    {
        $typed = AgencyAuctionSettings::notifyEmailAddressesFor($agencyId);

        return $typed !== [] ? $typed : $this->administrators($agencyId)->pluck('email')
            ->map(fn ($e) => strtolower(trim((string) $e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    /** @return Collection<int, User> */
    public function alertUsers(int $agencyId, ?int $ownerUserId): Collection
    {
        if ($ownerUserId) {
            $owner = User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereKey($ownerUserId)->first();
            if ($owner && $owner->is_active !== false) {
                return collect([$owner]);
            }
        }

        return $this->administrators($agencyId);
    }

    /** @return Collection<int, User> */
    public function administrators(int $agencyId): Collection
    {
        return User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))->get();
    }
}
