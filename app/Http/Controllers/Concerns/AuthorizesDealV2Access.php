<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Per-record data-scope authorization for Deal Register V2 — the single-record sibling of
 * DealV2::scopeVisibleTo().
 *
 * AT-267 C3 (audit 2026-07-21): DealV2 settlement (money) and update bound a DealV2 by id and
 * checked only the permission key (deals_v2.edit) — DealV2::scopeVisibleTo is a LOCAL scope that
 * never runs on route-model binding, so any deals_v2.edit holder could rewrite the settlement or
 * edit the price/commission of ANY deal in the agency by id, and an assistant of such an agent was
 * not pinned to the agent's OWN deals.
 *
 * A write path (edit/settle) pins an assistant to the assigned agent's OWN deals (mutationScope
 * clamps assistants to 'own'); a pure read views at the agent's full breadth. The ownership tests
 * mirror scopeVisibleTo() EXACTLY (listing/selling/created agent columns) so LIST and OPEN can
 * never disagree.
 */
trait AuthorizesDealV2Access
{
    protected function authorizeDealV2(DealV2 $deal, bool $forEdit = true): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = $forEdit
            ? PermissionService::mutationScope($user, 'deals_v2')
            : PermissionService::getDataScope($user, 'deals_v2');

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'branch' && (int) $deal->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }

        if ($scope === 'own') {
            // Mirrors DealV2::scopeVisibleTo() 'own' tier exactly. dataIdentityIds() = the assigned
            // agent's ids for an assistant, [$user->id] otherwise.
            $ids = $user->dataIdentityIds();
            if (in_array((int) $deal->listing_agent_id, $ids, true)
                || in_array((int) $deal->selling_agent_id, $ids, true)
                || in_array((int) $deal->created_by_id, $ids, true)) {
                return;
            }
        }

        abort(403);
    }
}
