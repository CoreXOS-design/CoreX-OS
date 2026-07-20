<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Deal;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;

/**
 * Per-deal data-scope authorization — the single-record sibling of
 * Deal::scopeVisibleTo().
 *
 * AgencyScope + DealBranchScope (via route-model binding) block cross-agency and,
 * when split-branches is on, cross-branch access — but they do NOT enforce the
 * within-agency owner scope (own / branch / all). Deal::scopeVisibleTo() is a
 * LOCAL scope, so it never runs on route-model binding: without this guard any
 * user holding `deals.edit`/`create_deals` could open or mutate ANY deal in the
 * agency by id.
 *
 * AT-267 — 'own' means the acting user's deal book. For an ASSISTANT that is their
 * Assigned Agent's book (dataIdentityIds()), so an assistant may work exactly the
 * deals their agent is linked to in deal_user — and no other agent's. For everyone
 * else dataIdentityIds() is [$user->id], so an `own`-scope agent can no longer edit
 * a colleague's deal by id either (a pre-existing hole this closes).
 *
 * The membership tests mirror scopeVisibleTo() exactly so LIST visibility and
 * SINGLE-RECORD authorization can never disagree — the assistant sees their agent's
 * deal and is not then 403'd trying to open it.
 */
trait AuthorizesDealAccess
{
    protected function authorizeDeal(Deal $deal): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = PermissionService::getDataScope($user, 'deals');

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'branch') {
            $inBranch = DB::table('deal_user')
                ->join('users', 'users.id', '=', 'deal_user.user_id')
                ->where('deal_user.deal_id', $deal->id)
                ->where('users.branch_id', $user->effectiveBranchId())
                ->exists();
            if ($inBranch) {
                return;
            }
        }

        if ($scope === 'own') {
            $isOwn = DB::table('deal_user')
                ->where('deal_id', $deal->id)
                ->whereIn('user_id', $user->dataIdentityIds())
                ->exists();
            if ($isOwn) {
                return;
            }
        }

        abort(403);
    }
}
