<?php

namespace App\Services\Rentals;

use App\Models\Lease;

/**
 * .ai/specs/leases.md §12 / rental-renewals.md — AT-444/AT-441 follow-up
 * (conductor, 2026-10-05). Which "Lease actions" dialog (if any) the Lease
 * Hub should auto-open on page load: either because the page was reached
 * with a ?action= query param (e.g. a Command Centre row/queue link), or
 * because a just-submitted dialog's form failed validation and must
 * reopen with the entered values. Pure logic — no request/session access
 * — so it is testable without booting a request.
 */
class LeaseActionDialogResolver
{
    public const RENEW = 'renew';
    public const MONTH_TO_MONTH = 'month-to-month';
    public const TENANT_NOTICE = 'tenant-notice';
    public const LANDLORD_NOTICE = 'landlord-notice';
    public const CHANGE_NOTICE_OUTCOME = 'change-notice-outcome';
    /** Not a dialog: opens the "Lease actions" menu itself, showing every outcome (renewed / month-to-month / ended). */
    public const OUTCOMES = 'outcomes';

    /**
     * The only actions a URL ?action= param (or the hidden _lease_action
     * reopen marker) is ever allowed to open. Reverse-notice, reverse-
     * month-to-month, cancel, and change-notice-outcome are reachable only
     * from the menu itself — deliberately not URL-triggerable.
     */
    public const URL_ACTIONS = [self::RENEW, self::MONTH_TO_MONTH, self::TENANT_NOTICE, self::LANDLORD_NOTICE, self::OUTCOMES];

    /**
     * Validity mirrors the EXACT conditions the "Lease actions" menu itself
     * uses to decide which items to render (show.blade.php) — a dialog is
     * never openable (by URL or by reopen-on-error) in a state where the
     * menu itself wouldn't offer it.
     *
     * @return array<string,bool>
     */
    public static function validActionsFor(Lease $lease): array
    {
        if ($lease->status !== Lease::STATUS_ACTIVE) {
            return [];
        }

        $valid = [self::RENEW => true, self::OUTCOMES => true];

        if (!$lease->is_month_to_month) {
            $valid[self::MONTH_TO_MONTH] = true;
        }

        if (!$lease->hasActiveNotice()) {
            $valid[self::TENANT_NOTICE] = true;
            $valid[self::LANDLORD_NOTICE] = true;
        } else {
            // Menu-only (see URL_ACTIONS) — but still a valid REOPEN target
            // so a failed "Change notice outcome" submission reopens with
            // the entered values instead of silently closing.
            $valid[self::CHANGE_NOTICE_OUTCOME] = true;
        }

        return $valid;
    }

    /**
     * A just-failed submission (identified by the hidden `_lease_action`
     * field echoed back via old()) always wins over ?action=, so the agent
     * sees their own mistake corrected, not a different dialog. An action
     * that is not valid for the lease's CURRENT state is ignored (returns
     * null) either way, never force-opened.
     */
    public static function resolve(Lease $lease, ?string $requestedAction, bool $hasValidationErrors, ?string $reopenAction): ?string
    {
        $valid = self::validActionsFor($lease);

        if ($hasValidationErrors && $reopenAction !== null && isset($valid[$reopenAction])) {
            return $reopenAction;
        }

        if (!$hasValidationErrors && $requestedAction !== null
            && in_array($requestedAction, self::URL_ACTIONS, true) && isset($valid[$requestedAction])) {
            return $requestedAction;
        }

        return null;
    }
}
