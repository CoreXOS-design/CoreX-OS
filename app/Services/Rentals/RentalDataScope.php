<?php

declare(strict_types=1);

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\PermissionService;

/**
 * One place that turns "how wide may this user see <module>?" into a usable own/branch/all
 * for every rentals scopeVisibleTo().
 *
 * PermissionService::getDataScope() answers NULL for "no access" (a role with no scope row for
 * the module, or an unseeded grants table — AT-265), and PermissionService::clampScope() takes a
 * STRING ceiling, so passing the null straight through was a TypeError — an HTTP 500 for a user
 * who simply has no access. No scope means no access: refuse with a plain 403.
 */
final class RentalDataScope
{
    /** The widest scope the user's role grants for $module; 403 when the role grants none. */
    public static function ceiling(User $user, string $module): string
    {
        $max = PermissionService::getDataScope($user, $module);
        abort_if($max === null, 403, 'Your role does not have access to this part of Rentals.');

        return $max;
    }

    /**
     * Owner agency lock (Johan, 2026-10-18; same rule as PropertyMarketingPost::scopeVisibleTo). AgencyScope is lifted for an
     * owner-role account that has not used the agency switcher - and for every bearer-token (mobile API) call, which carries no
     * session - so an owner working inside agency A would otherwise read agency B's rentals. Owners are therefore pinned to the
     * agency they are working in (effectiveAgencyId()); no agency at all means nothing. Everyone else is already pinned by
     * AgencyScope, so this is a no-op for them. $column is the qualified agency column of the query being narrowed.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function pinOwnerToWorkingAgency($query, User $user, string $column)
    {
        if (! $user->isOwnerRole()) {
            return $query;
        }

        $agencyId = (int) $user->effectiveAgencyId();

        return $agencyId > 0 ? $query->where($column, $agencyId) : $query->whereRaw('1 = 0');
    }

    /** $requested narrowed (never widened) to the user's ceiling for $module. */
    public static function resolve(User $user, string $module, ?string $requested = null): string
    {
        return PermissionService::clampScope($requested, self::ceiling($user, $module));
    }
}
