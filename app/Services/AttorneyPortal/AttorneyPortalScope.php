<?php

namespace App\Services\AttorneyPortal;

use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSetting;
use App\Models\Deal;
use App\Models\DealV2\DealStageMove;
use App\Models\DealV2\DealV2;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * .ai/specs/attorney-portal.md §3 + §9 - THE one place that decides which deals an attorney access may see.
 *
 *  - Person-level (R2): a deal is theirs only when THIS supplier contact is the deal's chosen transfer attorney
 *    (deals.attorney_contact_id) or bond attorney (deals.bond_attorney_contact_id) AND the firm matches. A colleague
 *    at the same firm who is not the chosen person sees nothing of it; a deal with a firm but no person shows to nobody.
 *  - Agency-level: the portal has no signed-in staff user, so the global AgencyScope does not apply. Every query here
 *    is filtered on the access's agency_id explicitly (withoutGlobalScopes also drops soft-delete, so that is explicit too).
 *  - Time (R6): open deals show; an ended one (registered / declined / cancelled) shows until the agency's grace days pass.
 */
class AttorneyPortalScope
{
    public const ROLE_TRANSFER = 'transfer_attorney';
    public const ROLE_BOND     = 'bond_attorney';

    /** Every deal this person is assigned to, ended or not (no time window). */
    public function assignedDeals(AttorneyPortalAccess $access): Collection
    {
        $contactId = (int) $access->service_provider_contact_id;
        $firmId    = (int) $access->service_provider_id;

        return Deal::withoutGlobalScopes()
            ->whereNull('deals.deleted_at')
            ->where('deals.agency_id', $access->agency_id)
            ->where(function ($q) use ($contactId, $firmId) {
                $q->where(fn ($t) => $t->where('deals.attorney_contact_id', $contactId)->where('deals.attorney_provider_id', $firmId))
                  ->orWhere(fn ($b) => $b->where('deals.bond_attorney_contact_id', $contactId)->where('deals.bond_attorney_provider_id', $firmId));
            })
            ->get();
    }

    /** The deals the attorney can currently see (assigned + inside the time window). */
    public function visibleDeals(AttorneyPortalAccess $access): Collection
    {
        $grace = AttorneyPortalSetting::forAgency((int) $access->agency_id)->graceDays();

        return $this->assignedDeals($access)
            ->filter(fn (Deal $d) => $this->isVisible($d, $grace))
            ->values();
    }

    /** One deal if (and only if) the attorney may see it - null otherwise, so callers answer 404. */
    public function find(AttorneyPortalAccess $access, int $dealId): ?Deal
    {
        $grace = AttorneyPortalSetting::forAgency((int) $access->agency_id)->graceDays();

        return $this->assignedDeals($access)
            ->first(fn (Deal $d) => (int) $d->id === $dealId && $this->isVisible($d, $grace));
    }

    /** The roles this person holds on the deal: transfer_attorney and/or bond_attorney. */
    public function rolesOn(Deal $deal, AttorneyPortalAccess $access): array
    {
        $contactId = (int) $access->service_provider_contact_id;
        $firmId    = (int) $access->service_provider_id;
        $roles = [];
        if ((int) $deal->attorney_contact_id === $contactId && (int) $deal->attorney_provider_id === $firmId) {
            $roles[] = self::ROLE_TRANSFER;
        }
        if ((int) $deal->bond_attorney_contact_id === $contactId && (int) $deal->bond_attorney_provider_id === $firmId) {
            $roles[] = self::ROLE_BOND;
        }

        return $roles;
    }

    public function twin(Deal $deal): ?DealV2
    {
        if (! $deal->deal_v2_id) {
            return null;
        }

        return DealV2::withoutGlobalScopes()->whereNull('deals_v2.deleted_at')
            ->where('agency_id', $deal->agency_id)->find($deal->deal_v2_id);
    }

    public function isCancelled(Deal $deal, ?DealV2 $twin = null): bool
    {
        $twin ??= $this->twin($deal);

        return $deal->accepted_status === 'D' || in_array($twin?->status, ['cancelled', 'declined'], true);
    }

    public function isRegistered(Deal $deal): bool
    {
        return ! empty($deal->registration_date) || $deal->accepted_status === 'R';
    }

    /** The moment the deal ended (registered / cancelled), or null while it is open. */
    public function endedAt(Deal $deal): ?Carbon
    {
        $twin = $this->twin($deal);

        if ($this->isCancelled($deal, $twin)) {
            $move = $twin
                ? DealStageMove::withoutGlobalScopes()->whereNull('deleted_at')->where('deal_id', $twin->id)
                    ->whereIn('to_status', ['declined', 'cancelled'])->where('state', 'applied')->latest('moved_at')->first()
                : null;

            return $move?->moved_at ? Carbon::parse($move->moved_at) : ($deal->updated_at ?? now());
        }

        if ($this->isRegistered($deal)) {
            return $deal->registration_date ? Carbon::parse($deal->registration_date)->endOfDay() : ($deal->updated_at ?? now());
        }

        return null;
    }

    /** Last day the attorney can still reach an ended deal; null while it is open. */
    public function visibleUntil(Deal $deal, ?int $graceDays = null): ?Carbon
    {
        $ended = $this->endedAt($deal);
        if (! $ended) {
            return null;
        }
        $graceDays ??= AttorneyPortalSetting::forAgency((int) $deal->agency_id)->graceDays();

        return $ended->copy()->addDays($graceDays);
    }

    private function isVisible(Deal $deal, int $graceDays): bool
    {
        $until = $this->visibleUntil($deal, $graceDays);

        return $until === null || $until->isFuture();
    }
}
