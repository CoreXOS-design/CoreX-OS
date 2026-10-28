<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Services\FeatureRollout\FeatureRolloutAccess;
use App\Services\FeatureRollout\FeatureRolloutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-478 Part 3 — the gradual-rollout pop-ups' two endpoints (spec agency-feature-rollout.md §5.3, §5.4).
 * The pop-ups are server-rendered by layouts/partials/feature-rollout-popups, so there is no GET.
 *
 * Everything is the signed-in user's OWN agency: the plan comes from effectiveAgencyId(), never from
 * input, and wave ids are only honoured inside that plan.
 */
class FeatureRolloutPopupController extends Controller
{
    /** Session key: waves an admin put off with "Later" — skipped until their next sign-in. */
    public const LATER_SESSION_KEY = 'feature_rollout.review_later';

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly FeatureRolloutAccess $access,
    ) {
    }

    private function plan(Request $request): ?AgencyFeatureRollout
    {
        $agencyId = $request->user()?->effectiveAgencyId();

        return $agencyId ? $this->rollout->forAgency((int) $agencyId) : null;
    }

    /**
     * The release pop-up was closed (or a tour opened from it). Self-scoped and forgiving like the
     * System Updates dismissal: unknown or other-agency ids are ignored, never an error.
     */
    public function seen(Request $request): JsonResponse
    {
        $data = $request->validate(['wave_ids' => 'required|array|max:50', 'wave_ids.*' => 'integer']);
        $plan = $this->plan($request);
        if ($plan) {
            $this->access->markSeen($request->user(), $plan, array_map('intval', $data['wave_ids']));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The admin access review. keep = reviewed, nothing changed; save = write exactly the posted
     * feature × role ticks, then reviewed; hold = pause the plan (upcoming waves only, stays
     * unreviewed so it asks again after Resume); later = not now, ask at next sign-in.
     */
    public function review(Request $request): JsonResponse
    {
        $plan = $this->plan($request);
        abort_unless($plan !== null, 404);

        $data = $request->validate([
            'action'     => ['required', Rule::in(['keep', 'save', 'hold', 'later'])],
            'wave_ids'   => 'required|array|max:50',
            'wave_ids.*' => 'integer',
            'access'     => 'nullable|array',
            'access.*'   => 'array',
            // §21 — the Role Manager's own settings: role => permission key => on, and role => view key => scope.
            'settings'   => 'nullable|array',
            'settings.*' => 'array',
            'scopes'     => 'nullable|array',
            'scopes.*'   => 'array',
        ]);

        // Only waves that are genuinely waiting for a review in THIS agency's plan.
        $pending = $this->access->pendingReviews($plan);
        $all = $pending['released']->concat($pending['upcoming']);
        $waves = $all->whereIn('id', array_map('intval', $data['wave_ids']))->values();
        if ($waves->isEmpty()) {
            return response()->json(['ok' => true, 'changed' => 0]); // already reviewed elsewhere
        }
        $waveIds = $waves->pluck('id')->all();

        if ($data['action'] === 'later') {
            $request->session()->put(self::LATER_SESSION_KEY, array_values(array_unique(array_merge(
                (array) $request->session()->get(self::LATER_SESSION_KEY, []), $waveIds
            ))));

            return response()->json(['ok' => true, 'changed' => 0]);
        }

        if ($data['action'] === 'hold') {
            abort_unless($waves->every(fn ($w) => $w->released_at === null), 422, 'Only a group that has not switched on yet can be held.');
            $this->rollout->pause($plan);

            return response()->json(['ok' => true, 'changed' => 0]);
        }

        $changed = 0;
        $changes = [];
        if ($data['action'] === 'save') {
            $features = $waves->flatMap(fn ($w) => $this->access->liveFeatures($w))->unique()->all();
            $roles = $this->access->editableRoles((int) $plan->agency_id)->pluck('name')->all();
            // §3.1 / §6.1 — only features of these waves and only roles that were posted are touched.
            foreach ((array) ($data['access'] ?? []) as $feature => $byRole) {
                if (! in_array($feature, $features, true)) {
                    continue;
                }
                foreach ((array) $byRole as $role => $on) {
                    $want = filter_var($on, FILTER_VALIDATE_BOOLEAN);
                    if (in_array($role, $roles, true)
                        && $this->access->setRoleAccess((int) $plan->agency_id, (string) $role, (string) $feature, $want)) {
                        $changed++;
                        $changes[] = ['feature' => (string) $feature, 'role' => (string) $role, 'on' => $want];
                    }
                }
            }

            // §21 — single permissions and Data Scope, exactly the ones the admin changed, for these waves' features only.
            foreach ($this->access->applySettings((int) $plan->agency_id, $features, (array) ($data['settings'] ?? []), (array) ($data['scopes'] ?? [])) as $c) {
                $changed++;
                $changes[] = $c;
            }
        }

        $this->access->markReviewed($plan, $waveIds, $request->user()->id, $data['action'], $changes);

        return response()->json(['ok' => true, 'changed' => $changed]);
    }
}
