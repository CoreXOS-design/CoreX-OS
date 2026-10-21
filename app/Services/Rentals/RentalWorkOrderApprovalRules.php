<?php

namespace App\Services\Rentals;

use App\Models\RentalWorkOrderSetting;

/**
 * AT-494 (Johan's ruling) - the ONE place that decides who may approve quotes on a work order.
 *
 * Two money-decision keys are governed together: `rental_work_orders.record_approval` (authorise a cost, record the owner's decision)
 * and `rental_work_orders.manage_quotes` (capture, choose and send a quote to the owner). Branch managers, admins and any custom role
 * keep exactly the grants Role Manager gives them. The DEFAULT `agent` role is the one the agency switch speaks to:
 * "Agents can approve quotes and send them to the owner" (RentalWorkOrderSetting::agentsApproveQuotesFor, ON by default) - when ON the
 * agent role effectively holds both keys; when OFF it holds neither, and the screens say "Ask your branch manager to approve".
 *
 * `rental_work_orders.record_emergency_approval` is a sub-permission of record_approval: it only counts for someone who can approve
 * at all. (It used to be granted to the agent role on its own, which left an emergency tick box with no Authorise button behind it.)
 *
 * Called from PermissionService::userHasPermission() so route middleware, controller checks and every blade @permission read the SAME
 * answer - nothing re-derives it per screen. It never writes anything and never widens the data scope (own/branch/agency): scope is
 * still resolved per record by guardRentalRecordScope().
 */
final class RentalWorkOrderApprovalRules
{
    public const APPROVAL = 'rental_work_orders.record_approval';
    public const QUOTES = 'rental_work_orders.manage_quotes';
    public const EMERGENCY = 'rental_work_orders.record_emergency_approval';

    /** The role the agency switch applies to - the default ordinary-agent role. */
    public const AGENT_ROLE = 'agent';

    /**
     * @param list<string> $rolePermissions every key the role holds (already resolved by the caller)
     * @param bool         $granted         whether the role holds $permissionKey itself
     */
    public static function resolve(string $role, ?int $agencyId, string $permissionKey, bool $granted, array $rolePermissions): bool
    {
        if (! in_array($permissionKey, [self::APPROVAL, self::QUOTES, self::EMERGENCY], true)) {
            return $granted;
        }

        $agentsMayApprove = $role === self::AGENT_ROLE && RentalWorkOrderSetting::agentsApproveQuotesFor($agencyId);

        return match ($permissionKey) {
            self::APPROVAL, self::QUOTES => $granted || $agentsMayApprove,
            // emergency approval is only usable by someone who can approve at all
            self::EMERGENCY => $granted && (in_array(self::APPROVAL, $rolePermissions, true) || $agentsMayApprove),
        };
    }

    /** The sentence shown in place of a control the person may not use. */
    public const ASK_MANAGER = 'Ask your branch manager to approve';
}
