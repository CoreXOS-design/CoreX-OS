<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Agency Setup Progress → one agency (AT-478 Part 4, spec agency-feature-rollout.md §5.5).
 * The CoreX team's view of an agency's setup + gradual introduction, and the place they change that
 * agency's plan (add / move / switch on / skip features, add / edit / reorder / archive groups, pace,
 * pause / resume / release next / everything on / reset to the default list).
 *
 * Cross-agency by design (platform tooling, multi-tenancy rule #5): the agency comes from the URL and is
 * read without scopes; a wave or item id is only ever resolved INSIDE that agency's plan. owner_only at
 * the route AND owner() in every action — agency users can never reach it. Agency admins change their
 * own plan only through Settings → Feature rollout (agency-scoped).
 */
class AgencyRolloutAdminController extends Controller
{
    public function __construct(private readonly FeatureRolloutService $rollout)
    {
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    private function agency(int $agencyId): Agency
    {
        return Agency::withoutGlobalScopes()->findOrFail($agencyId);
    }

    private function plan(Agency $agency): AgencyFeatureRollout
    {
        $plan = $this->rollout->forAgency((int) $agency->id);
        abort_unless($plan !== null, 404);

        return $plan;
    }

    private function wave(AgencyFeatureRollout $plan, int $waveId): AgencyRolloutWave
    {
        return AgencyRolloutWave::queryWithoutAgencyScope()->withTrashed()
            ->where('rollout_id', $plan->id)->findOrFail($waveId);
    }

    private function item(AgencyFeatureRollout $plan, int $itemId): AgencyRolloutItem
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->findOrFail($itemId);
    }

    private function back(Agency $agency, string $message): RedirectResponse
    {
        return redirect()->route('admin.agency-setup-progress.show', $agency->id)->with('success', $message);
    }

    /** Runs a change; a refused change comes back as a plain-language error, never a 500. */
    private function attempt(Agency $agency, callable $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (\DomainException $e) {
            return redirect()->route('admin.agency-setup-progress.show', $agency->id)->with('error', $e->getMessage());
        }

        return $this->back($agency, $success);
    }

    // ── The page ───────────────────────────────────────────────────────────────

    public function show(Request $request, int $agency)
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $filters = $request->validate([
            'q'      => 'nullable|string|max:100',
            'status' => 'nullable|in:all,upcoming,released,archived',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'sort'   => 'nullable|in:date,name',
        ]);
        $filters['status'] ??= 'all';
        $filters['sort'] ??= 'date';

        $features = app(AgencyFeatureService::class);
        $labels = collect($features->catalogue())->map(fn ($d, $k) => $d['label'] ?? $k)->all();
        $setup = AgencyOnboardingSetup::queryWithoutAgencyScope()->where('agency_id', $agency->id)->latest('id')->first();
        $plan = $this->rollout->forAgency((int) $agency->id);
        $isBoth = AgencyTypeFeatureSeeder::normalise($agency->agency_type) === 'both';

        $waves = collect();
        $totals = ['features' => 0, 'released' => 0];
        $openWaves = collect();
        $addable = [];
        $diffs = [];
        $reviewers = collect();
        if ($plan) {
            ['waves' => $waves, 'totals' => $totals] = $this->rollout->listWaves($plan, $filters, $labels);
            $openWaves = $this->rollout->wavesOf($plan)->whereNull('released_at')->values();
            $inPlan = AgencyRolloutItem::queryWithoutAgencyScope()->withTrashed()->where('rollout_id', $plan->id)->pluck('feature_key')->flip();
            foreach ($this->rollout->waveableFeatures() as $key => $label) {
                if (! isset($inPlan[$key]) && $features->enabled($key, $agency)) {
                    $addable[$key] = $label;
                }
            }
            asort($addable);
            foreach ($waves as $w) {
                $diffs[$w->id] = $this->rollout->differences($w);
            }
            $reviewers = User::withoutGlobalScopes()->whereIn('id', $waves->pluck('access_reviewed_by')->filter()->unique())->pluck('name', 'id');
        }

        $page = max(1, (int) $request->query('page', 1));
        $paged = new LengthAwarePaginator($waves->forPage($page, 25)->values(), $waves->count(), 25, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        $history = $plan ? app(\App\Services\FeatureRollout\FeatureRolloutHistory::class)->forRequest($request, (int) $agency->id) : [];

        return view('admin.agency-setup-progress.show', $history + [
            'agency'    => $agency,
            'setup'     => $setup,
            'steps'     => AgencyOnboardingSetup::activeSteps($agency),
            'plan'      => $plan,
            'open'      => $plan && $this->rollout->isOpen($plan),
            'next'      => $plan ? $this->rollout->nextWavePerLane($plan)->filter(fn ($w) => $w->scheduled_for)->sortBy(fn ($w) => $w->scheduled_for->timestamp)->first() : null,
            'waves'     => $paged,
            'filters'   => $filters,
            'labels'    => $labels,
            'totals'    => $totals,
            'openWaves' => $openWaves,
            'addable'   => $addable,
            'diffs'     => $diffs,
            'reviewers' => $reviewers,
            'tours'     => app(FeatureTourCoverage::class)->toursByFeature(),
            'isBoth'    => $isBoth,
            'intervals' => (array) config('feature-rollout.interval_choices', []),
            'intervalMax' => $this->rollout->intervalMax(),
            'defaults'  => $this->rollout->defaults(),
            'preview'   => $plan ? [] : $this->rollout->holdPreview($agency),
            'usage'     => $plan ? \App\Http\Controllers\CoreX\FeatureRolloutController::usageFor($agency, $paged->getCollection()) : [],
            'lowUse'    => $plan ? app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->lowUse($plan) : null,
        ]);
    }

    // ── Starting and pace ──────────────────────────────────────────────────────

    /**
     * Start gradual introduction for this agency. Unlike the agency's own wizard choice this is allowed for
     * an agency already up and running — it is a deliberate CoreX-team action, so it must name how many
     * features switch off for everyone right now and be confirmed.
     */
    public function start(Request $request, int $agency): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        abort_if($this->rollout->forAgency((int) $agency->id) !== null, 409, 'This agency already has a gradual introduction.');

        $data = $request->validate([
            'interval_days' => 'required|integer|min:1|max:' . $this->rollout->intervalMax(),
            'confirm'       => 'accepted',
        ], ['confirm.accepted' => 'Tick the box to confirm these features switch off for everyone at this agency now.']);

        $this->rollout->start($agency, [
            'interval_days' => (int) $data['interval_days'],
            'weekdays_only' => $request->boolean('weekdays_only'),
            'skip_holidays' => $request->boolean('skip_holidays'),
            'side_by_side'  => $request->boolean('side_by_side'),
        ], $request->user()->id);

        return $this->back($agency, 'Gradual introduction started for ' . $agency->name . '.');
    }

    public function pace(Request $request, int $agency): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $plan = $this->plan($agency);
        $data = $request->validate([
            'interval_days' => 'required|integer|min:1|max:' . $this->rollout->intervalMax(),
            'release_time'  => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $this->rollout->updateSettings($plan, [
            'interval_days' => (int) $data['interval_days'],
            'release_time'  => $data['release_time'],
            // §6.1 — each checkbox ships with a hidden "0" companion; only what was rendered is written.
            ...($request->has('weekdays_only') ? ['weekdays_only' => $request->boolean('weekdays_only')] : []),
            ...($request->has('skip_holidays') ? ['skip_holidays' => $request->boolean('skip_holidays')] : []),
            ...($request->has('side_by_side') ? ['side_by_side' => $request->boolean('side_by_side')] : []),
        ]);

        return $this->back($agency, 'Pace saved. Every group still to come has been re-dated from today.');
    }

    // ── Plan controls ──────────────────────────────────────────────────────────

    public function control(Request $request, int $agency, string $action): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $plan = $this->plan($agency);
        $userId = $request->user()->id;

        return match ($action) {
            'pause'        => $this->attempt($agency, fn () => $this->rollout->pause($plan), 'Paused. Nothing switches on until it is resumed.'),
            'resume'       => $this->attempt($agency, fn () => $this->rollout->resume($plan), 'Resumed. Groups still to come are dated from today.'),
            'release-next' => $this->attempt($agency, fn () => $this->rollout->releaseNext($plan, $userId), 'The next group is switched on.'),
            'finish'       => $this->attempt($agency, fn () => $this->rollout->finish($plan, $userId), 'Everything is switched on and the introduction is finished.'),
            'reset'        => $this->attempt($agency, fn () => $this->rollout->resetToDefault($plan), 'Groups still to come were rebuilt from the default list.'),
            default        => abort(404),
        };
    }

    // ── Groups ─────────────────────────────────────────────────────────────────

    public function storeWave(Request $request, int $agency): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $plan = $this->plan($agency);
        $data = $request->validate([
            'title'      => 'required|string|max:120',
            'intro_text' => 'nullable|string|max:1000',
            'lane'       => 'nullable|in:1,2,3',
        ]);

        return $this->attempt($agency, fn () => $this->rollout->addWave($plan, $data['title'], $data['intro_text'] ?? null, (int) ($data['lane'] ?? 1)),
            "Group \"{$data['title']}\" added. Add its features below.");
    }

    public function updateWave(Request $request, int $agency, int $wave): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $wave = $this->wave($this->plan($agency), $wave);
        $data = $request->validate(['title' => 'required|string|max:120', 'intro_text' => 'nullable|string|max:1000']);

        return $this->attempt($agency, fn () => $this->rollout->updateWave($wave, $data['title'], $data['intro_text'] ?? null), 'Group saved.');
    }

    public function moveWave(Request $request, int $agency, int $wave): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $wave = $this->wave($this->plan($agency), $wave);
        $data = $request->validate(['direction' => 'required|in:up,down']);

        return $this->attempt($agency, fn () => $this->rollout->moveWave($wave, $data['direction'] === 'up' ? -1 : 1), 'Order saved and dates updated.');
    }

    public function archiveWave(Request $request, int $agency, int $wave): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $wave = $this->wave($this->plan($agency), $wave);

        return $this->attempt($agency, fn () => $this->rollout->archiveWave($wave), "Group \"{$wave->title}\" archived.");
    }

    public function restoreWave(Request $request, int $agency, int $wave): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $wave = $this->wave($this->plan($agency), $wave);
        abort_unless($wave->trashed(), 404);

        return $this->attempt($agency, fn () => $this->rollout->restoreWave($wave), "Group \"{$wave->title}\" restored at the end of the list.");
    }

    // ── Features ───────────────────────────────────────────────────────────────

    public function addFeature(Request $request, int $agency, int $wave): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $wave = $this->wave($this->plan($agency), $wave);
        $data = $request->validate(['feature_key' => 'required|string|max:64']);

        return $this->attempt($agency, fn () => $this->rollout->addFeature($wave, $data['feature_key'], $request->user()->id),
            'Feature added. It is switched off now and comes back with this group.');
    }

    public function itemAction(Request $request, int $agency, int $item, string $action): RedirectResponse
    {
        $this->owner($request);
        $agency = $this->agency($agency);
        $plan = $this->plan($agency);
        $item = $this->item($plan, $item);

        if ($action === 'move') {
            $data = $request->validate(['wave_id' => 'required|integer']);
            $to = AgencyRolloutWave::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->find((int) $data['wave_id']);
            if (! $to) {
                throw ValidationException::withMessages(['wave_id' => 'Choose one of this agency\'s groups.']);
            }

            return $this->attempt($agency, fn () => $this->rollout->moveFeature($item, $to), 'Feature moved. It now switches on with that group.');
        }

        return match ($action) {
            'on'   => $this->attempt($agency, fn () => $item->state === AgencyRolloutItem::STATE_HELD
                ? $this->rollout->switchOnNow($item, $request->user()->id)
                : $this->rollout->switchOnAwaiting($item, $request->user()->id), 'Feature switched on now.'),
            'skip' => $this->attempt($agency, function () use ($item) {
                $wave = AgencyRolloutWave::queryWithoutAgencyScope()->findOrFail($item->wave_id);
                if ($wave->released_at !== null && $item->state !== AgencyRolloutItem::STATE_AWAITING_ADMIN) {
                    throw new \DomainException('That group has already switched on.');
                }
                $this->rollout->skipItem($item);
            }, 'Feature skipped. It stays off; it can still be switched on from the agency\'s Features page.'),
            default => abort(404),
        };
    }
}
