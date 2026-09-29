<?php

namespace App\Services\Properties;

use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;

/**
 * ONE seam for "may this user change a property's status TO or FROM Other
 * Agency Stock" — .ai/specs/other-agency-stock.md §7. Every status-change
 * path (property edit form, status quick-picker, API/mobile update, import
 * endpoint) must call canChange() before persisting the write; the model-
 * layer vocabulary guard (Property::isAllowedStatus, enforced in
 * PropertyObserver::saving) governs WHETHER a status string is valid at
 * all, not WHO may set this particular one — that's this gate's job.
 *
 * TODAY: backed by a plain permission key
 * (other_agency_stock.change_status) through the existing
 * config/corex-permissions.php role system — no hardcoded roles.
 *
 * FUTURE: Andre's upcoming "only authorised users can turn syndication on"
 * setting is expected to supersede or extend this check once it lands (see
 * .ai/specs/other-agency-stock.md §7) — Other Agency Stock's entire reason
 * for existing is that it must NEVER be syndicated, so the same
 * authorisation question ("who may change something that touches
 * syndication eligibility") applies here too. Whoever wires that setting in
 * should read this class first rather than adding a second, parallel gate.
 */
class OtherAgencyStockStatusGate
{
    public const PERMISSION_KEY = 'other_agency_stock.change_status';

    /**
     * $newStatus is the status being WRITTEN. Safe to call either before or
     * after assigning $property->status = $newStatus — deliberately reads
     * getOriginal('status'), NOT the possibly-already-mutated current
     * attribute, so the FROM-other_agency_stock direction is still caught
     * correctly when this is invoked from PropertyObserver::saving() (where
     * $property->status is already the new value by the time saving() fires).
     */
    public static function canChange(User $user, Property $property, string $newStatus): bool
    {
        $originalStatus = strtolower(trim((string) $property->getOriginal('status')));
        $target = strtolower(trim($newStatus));

        $touchesOtherAgencyStock = $originalStatus === Property::STATUS_OTHER_AGENCY_STOCK
            || $target === Property::STATUS_OTHER_AGENCY_STOCK;

        if (! $touchesOtherAgencyStock) {
            return true; // not this gate's concern
        }

        return PermissionService::userHasPermission($user, self::PERMISSION_KEY);
    }

    /**
     * .ai/specs/other-agency-stock.md §8a — may $user approve/decline an
     * unlock request, re-lock, or edit the advert content directly without
     * requesting? SAME permission key as canChange() today, deliberately —
     * Johan: "it will delegate to Andre's authorised-users setting later."
     * Kept as its own named method (not just an alias) so that future
     * delegation can diverge from canChange() without another call-site sweep.
     */
    public static function canAuthoriseAdvertEdit(User $user): bool
    {
        return PermissionService::userHasPermission($user, self::PERMISSION_KEY);
    }

    /**
     * Every active user in $agencyId who currently holds the authoring
     * permission — used to fan out the unlock-request notification/task to
     * every authorised user, not just one. Small per-agency scale (tens of
     * users), so a plain filter is fine; no caching.
     */
    public static function authorisedUsersFor(int $agencyId): \Illuminate\Support\Collection
    {
        return User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => self::canAuthoriseAdvertEdit($u))
            ->values();
    }
}
