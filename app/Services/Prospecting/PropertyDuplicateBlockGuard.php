<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\User;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * Single authorisation checkpoint for every hard block (active_blocked, no_go).
 * Johan asked whether an admin should be able to override a block and had NOT
 * confirmed — this existed so that decision, once made, would be ONE function
 * to change, not blocking logic scattered through DeedsCaptureController.
 *
 * 2026-08-24 — confirmed, as part of the Edinburgh erf 364 remediation: an
 * agent can never self-clear a hard block; branch_manager or admin can. The
 * $band parameter is kept (not collapsed to a bare role check) because a
 * future band-specific rule — e.g. NO_GO overridable but ACTIVE_BLOCKED never
 * — is a real possibility Johan has not ruled out; today every band resolves
 * the same way (role-only), but callers already pass the band, so that future
 * rule stays a change to this one function, not a new checkpoint.
 *
 * This authorises WHO may attempt an override. It does not log the attempt —
 * callers (ComposeSellerService::linkSellerToProperty() today) are
 * responsible for recording who/when/why once an override is actually used,
 * via PropertyMatchDecisionService + an application log line, so the record
 * lives with the actual write, not with the yes/no check.
 */
class PropertyDuplicateBlockGuard
{
    public function authorizeOverride(User $user, string $band): bool
    {
        return in_array($user->effectiveRole(), ['branch_manager', 'admin'], true);
    }
}
