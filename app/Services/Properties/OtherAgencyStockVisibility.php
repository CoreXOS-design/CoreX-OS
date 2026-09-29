<?php

namespace App\Services\Properties;

use App\Models\Agency;
use App\Models\User;

/**
 * Whether a viewer may see Other Agency Stock properties at all — an
 * agency-configurable ROLE setting (Johan: "each agency configures which
 * roles can see Other Agency Stock. Use the existing role/permission
 * system."), never hardcoded. Pattern mirrors
 * App\Services\CommandCenter\Calendar\CalendarVisibilityResolver: a JSON
 * string[] of role names on the agency, an 'all' wildcard, direct role
 * match.
 *
 * A null/guest viewer (a buyer on a public shared match page, or the
 * finished viewing-pack PDF/view) is ALWAYS visible — this setting gates
 * which INTERNAL CoreX roles see the stock in their own working screens
 * (Properties list, property show, the viewing-pack ad-hoc picker, Core
 * Matches). It has nothing to do with what a buyer is shown; Johan's ruling
 * is explicit that a buyer sees Other Agency Stock in a viewing pack like
 * any other property, with no "other agency" label at all.
 *
 * .ai/specs/other-agency-stock.md §6
 */
class OtherAgencyStockVisibility
{
    public static function canSee(?User $viewer): bool
    {
        if (! $viewer) {
            return true;
        }

        $agencyId = $viewer->effectiveAgencyId();
        if (! $agencyId) {
            return true;
        }

        $agency = Agency::find($agencyId);
        $roles = $agency?->other_agency_stock_visible_roles;

        // Default: visible to all roles. Nothing disappears from any
        // agency's screens the moment this ships — an agency narrows this
        // explicitly, from Settings.
        if ($roles === null || $roles === []) {
            return true;
        }

        if (in_array('all', $roles, true)) {
            return true;
        }

        $role = $viewer->role ?? null;

        return $role !== null && in_array($role, $roles, true);
    }
}
