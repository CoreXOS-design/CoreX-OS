<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealRemark;
use App\Models\DealV2\DealV2;
use App\Services\PermissionService;
use Illuminate\Http\Request;

/**
 * AT-158 WS-V6 — free-form deal remarks (DR1 addRemark analogue). Agents may
 * remark on deals within their scope (own); BM/admin on any in scope. Soft-delete
 * only, with an audit entry in the immutable activity log. No notification (DR1
 * addRemark notifies no-one).
 */
class DealRemarkController extends Controller
{
    public function store(Request $request, DealV2 $deal)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_deal_register_v2'), 403);
        abort_unless($this->inScope($deal, $user), 403, 'You can only remark on deals within your scope.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $body = trim($data['body']);
        if ($body === '') {
            return back()->withErrors(['body' => 'A remark cannot be blank.']);
        }

        DealRemark::create([
            'agency_id' => $deal->agency_id,
            'deal_id'   => $deal->id,
            'user_id'   => $user->id,
            'body'      => $body,
        ]);

        return redirect()->route('deals-v2.show', $deal->id)->with('status', 'Remark added.');
    }

    public function destroy(Request $request, DealRemark $remark)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_deal_register_v2'), 403);

        $deal = $remark->deal;
        abort_if(! $deal, 404);
        abort_unless($this->inScope($deal, $user), 403);

        // The author may remove their own remark; a BM/admin (branch/all scope)
        // may moderate any remark on a deal within their scope.
        $isAuthor = (int) $remark->user_id === (int) $user->id;
        $canModerate = in_array(PermissionService::getDataScope($user, 'deals_v2'), ['branch', 'all'], true);
        abort_unless($isAuthor || $canModerate, 403, 'You can only remove your own remark.');

        $remark->delete(); // soft delete — no hard delete

        // Audit the removal in the immutable log (the remark row is now hidden).
        DealActivityLog::create([
            'agency_id'  => $remark->agency_id,
            'deal_id'    => $remark->deal_id,
            'user_id'    => $user->id,
            'action'     => 'remark_removed',
            'description' => 'A remark was removed' . ($isAuthor ? ' by its author' : ' by a manager'),
        ]);

        return redirect()->route('deals-v2.show', $remark->deal_id)->with('status', 'Remark removed.');
    }

    /** The deal must be within the actor's permitted data scope (clampScope). */
    private function inScope(DealV2 $deal, $user): bool
    {
        return DealV2::query()->whereKey($deal->id)->visibleTo($user)->exists();
    }
}
