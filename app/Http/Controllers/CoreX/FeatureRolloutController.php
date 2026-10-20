<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Feature rollout (AT-478, spec agency-feature-rollout.md §5.2) — an agency admin's own
 * gradual-introduction plan: the progress tracker and its controls, and the onboarding choice.
 *
 * Every action works on the signed-in user's OWN agency only (effectiveAgencyId); there is no agency or
 * plan id in any URL, and a wave/item id is always resolved inside that agency's plan, so another
 * agency's rows cannot be reached by id. Permission: manage_feature_rollout (route + every action).
 *
 * saveChoice() is ALSO the wizard saver for the capabilities step (config/agency-onboarding-copy.php).
 * It runs after FeatureSettingsController@update in that step, so the plan holds back exactly the
 * features the admin left on. §6.1: it writes nothing unless its own block was rendered
 * (feature_rollout_present), and every boolean is has()-guarded.
 */
class FeatureRolloutController extends Controller
{
    public function __construct(private readonly FeatureRolloutService $rollout)
    {
    }

    private function agency(): Agency
    {
        $user = Auth::user();
        abort_unless($user?->hasPermission('manage_feature_rollout'), 403);
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 404);

        return Agency::findOrFail($agencyId);
    }

    private function plan(Agency $agency): ?AgencyFeatureRollout
    {
        return $this->rollout->forAgency((int) $agency->id);
    }

    /**
     * Can this agency still CHOOSE gradual introduction? Only before a plan exists and while its setup
     * guide is not finished — it is a first-start choice (spec §10), never something that hides an
     * up-and-running agency's tools by accident.
     */
    public static function canStart(Agency $agency): bool
    {
        if (app(FeatureRolloutService::class)->forAgency((int) $agency->id)) {
            return false;
        }
        $setup = AgencyOnboardingSetup::where('agency_id', $agency->id)->latest('id')->first();

        return ! $setup?->isComplete();
    }

    // ── The panel ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $agency = $this->agency();
        $filters = $request->validate([
            'q'      => 'nullable|string|max:100',
            'status' => 'nullable|in:all,upcoming,released',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'sort'   => 'nullable|in:date,name',
        ]);
        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'date';

        $plan = $this->plan($agency);
        $labels = collect(app(AgencyFeatureService::class)->catalogue())->map(fn ($d, $k) => $d['label'] ?? $k)->all();
        $waves = collect();
        $totals = ['features' => 0, 'released' => 0];

        if ($plan) {
            ['waves' => $waves, 'totals' => $totals] = $this->rollout->listWaves($plan, ['status' => $status, 'sort' => $sort] + $filters, $labels);
        }

        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $paged = new \Illuminate\Pagination\LengthAwarePaginator(
            $waves->forPage($page, $perPage)->values(), $waves->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $history = $plan ? app(\App\Services\FeatureRollout\FeatureRolloutHistory::class)->forRequest($request, (int) $agency->id) : [];

        return view('corex.feature-rollout.index', $history + [
            'agency'     => $agency,
            'plan'       => $plan,
            'next'       => $plan ? $this->rollout->nextWavePerLane($plan)->filter(fn ($w) => $w->scheduled_for)->sortBy(fn ($w) => $w->scheduled_for->timestamp)->first() : null,
            'waves'      => $paged,
            'filters'    => $filters + ['status' => $status, 'sort' => $sort],
            'labels'     => $labels,
            'totals'     => $totals,
            'tours'      => app(FeatureTourCoverage::class)->toursByFeature(),
            'canStart'   => self::canStart($agency),
            'isBoth'     => AgencyTypeFeatureSeeder::normalise($agency->agency_type) === 'both',
            'intervals'  => (array) config('feature-rollout.interval_choices', []),
            'intervalMax'=> $this->rollout->intervalMax(),
            'defaults'   => $this->rollout->defaults(),
            'preview'    => self::canStart($agency) ? $this->rollout->holdPreview($agency) : [],
            'readiness'  => $plan ? $this->readinessFor($agency, $waves) : [],
            'usage'      => $plan ? $this->usageFor($agency, $waves) : [],
            'lowUse'     => $plan ? app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->lowUse($plan) : null,
        ]);
    }

    // ── The choice (wizard saver + panel settings form) ────────────────────────

    public function saveChoice(Request $request)
    {
        // Checked BEFORE the permission: the block (and its marker) is only rendered for users who hold
        // manage_feature_rollout, so a wizard user without it saves the rest of the step untouched instead
        // of the whole step failing on a 403 from a block they never saw.
        if (! $request->has('feature_rollout_present')) {
            return back(); // this block was not rendered — leave everything alone (§6.1)
        }
        $agency = $this->agency();

        $data = $request->validate([
            'feature_rollout_mode'     => 'nullable|in:gradual,all',
            'feature_rollout_interval' => 'nullable|integer|min:1|max:' . $this->rollout->intervalMax(),
        ]);
        $values = [];
        if (isset($data['feature_rollout_interval'])) {
            $values['interval_days'] = (int) $data['feature_rollout_interval'];
        }
        if ($request->has('feature_rollout_weekdays')) {
            $values['weekdays_only'] = $request->boolean('feature_rollout_weekdays');
        }
        if ($request->has('feature_rollout_side_by_side')) {
            $values['side_by_side'] = $request->boolean('feature_rollout_side_by_side');
        }

        $plan = $this->plan($agency);
        $mode = $data['feature_rollout_mode'] ?? null;

        if (! $plan) {
            if ($mode !== 'gradual' || ! self::canStart($agency)) {
                return back(); // "everything now" (the default) changes nothing
            }
            $holding = $this->rollout->holdPreview($agency, (bool) ($values['side_by_side'] ?? false));
            if ($holding !== [] && ! $request->boolean('feature_rollout_confirm')) {
                throw ValidationException::withMessages([
                    'feature_rollout_confirm' => 'Tick the box to confirm: ' . count($holding) . ' features will be switched off for everyone now and switched back on a few at a time.',
                ]);
            }
            $this->rollout->start($agency, $values, Auth::id());

            return back()->with('success', 'Gradual introduction started.');
        }

        if ($mode === 'all' && in_array($plan->status, [AgencyFeatureRollout::STATUS_ACTIVE, AgencyFeatureRollout::STATUS_PAUSED], true)) {
            $this->rollout->finish($plan, Auth::id());

            return back()->with('success', 'Everything is switched on.');
        }

        $this->rollout->updateSettings($plan, $values);

        return back()->with('success', 'Feature rollout updated.');
    }

    // ── Panel actions ──────────────────────────────────────────────────────────

    public function pause()
    {
        $plan = $this->requirePlan();
        $this->rollout->pause($plan);

        return back()->with('success', 'Paused. Nothing new switches on until you resume.');
    }

    public function resume()
    {
        $plan = $this->requirePlan();
        $this->rollout->resume($plan);

        return back()->with('success', 'Resumed. The next features are dated from today.');
    }

    public function releaseNext()
    {
        $plan = $this->requirePlan(true);
        $this->rollout->releaseNext($plan, Auth::id());

        return back()->with('success', 'The next features are switched on.');
    }

    public function finish()
    {
        $plan = $this->requirePlan(true);
        $this->rollout->finish($plan, Auth::id());

        return back()->with('success', 'Everything is switched on.');
    }

    public function skipItem(int $item)
    {
        $plan = $this->requirePlan(true);
        $this->rollout->skipItem($this->itemOf($plan, $item));

        return back()->with('success', 'Skipped. It stays off; you can switch it on from Features any time.');
    }

    public function switchOn(int $item)
    {
        $plan = $this->requirePlan();
        $this->rollout->switchOnAwaiting($this->itemOf($plan, $item), Auth::id());

        return back()->with('success', 'Switched on.');
    }

    /** §12.6 — opened / tour taken, for the switched-on features on this page of the list. */
    public static function usageFor(Agency $agency, $waves): array
    {
        $keys = collect($waves)->flatMap(fn ($w) => $w->items->whereIn('state', [AgencyRolloutItem::STATE_RELEASED, AgencyRolloutItem::STATE_RELEASED_EARLY])->pluck('feature_key'))->unique()->values()->all();

        return app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->stats($agency, $keys);
    }

    /** §12.1 — what each "Set up first" feature still needs, keyed by feature. */
    private function readinessFor(Agency $agency, $waves): array
    {
        $out = [];
        foreach ($waves as $w) {
            foreach ($w->items->where('state', AgencyRolloutItem::STATE_AWAITING_SETUP) as $item) {
                $out[$item->feature_key] = app(\App\Services\FeatureRollout\FeatureReadiness::class)->missing($agency, $item->feature_key);
            }
        }

        return $out;
    }

    private function requirePlan(bool $mustBeRunning = false): AgencyFeatureRollout
    {
        $plan = $this->plan($this->agency());
        abort_unless($plan, 404);
        if ($mustBeRunning) {
            abort_unless(in_array($plan->status, [AgencyFeatureRollout::STATUS_ACTIVE, AgencyFeatureRollout::STATUS_PAUSED], true), 404);
        }

        return $plan;
    }

    /** An item id resolved INSIDE this agency's plan — another agency's id is a 404. */
    private function itemOf(AgencyFeatureRollout $plan, int $id): AgencyRolloutItem
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()
            ->where('rollout_id', $plan->id)->where('agency_id', $plan->agency_id)
            ->findOrFail($id);
    }
}
