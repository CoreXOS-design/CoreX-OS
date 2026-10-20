<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dev Settings → Feature rollout (AT-478, spec agency-feature-rollout.md §5.1). The default order and
 * pace every agency that chooses gradual rollout starts from. Editing never changes a plan already
 * running — those hold their own copy. owner-only (route middleware + owner() here), like timeline defaults.
 */
class FeatureRolloutDefaultsController extends Controller
{
    private const SORTS = ['position', 'title', 'updated_at'];

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly AgencyFeatureService $features,
        private readonly FeatureTourCoverage $tours,
    ) {
    }

    private function owner(Request $r): void
    {
        abort_unless($r->user() && $r->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function index(Request $request)
    {
        $this->owner($request);
        $filters = $request->validate([
            'q'      => 'nullable|string|max:100',
            'track'  => ['nullable', Rule::in(array_keys(FeatureRolloutWave::TRACKS))],
            'status' => 'nullable|in:active,archived',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'sort'   => ['nullable', Rule::in(self::SORTS)],
            'dir'    => 'nullable|in:asc,desc',
        ]);

        $waveable = $this->rollout->waveableFeatures();
        $sort = $filters['sort'] ?? 'position';
        $dir = $filters['dir'] ?? ($sort === 'updated_at' ? 'desc' : 'asc');

        $query = ($filters['status'] ?? 'active') === 'archived'
            ? FeatureRolloutWave::onlyTrashed()
            : FeatureRolloutWave::query();

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            // A search matches the wave title or the NAME of any feature in it.
            $matchingKeys = array_keys(array_filter($waveable, fn ($label, $key) => stripos($label, $q) !== false || stripos($key, $q) !== false, ARRAY_FILTER_USE_BOTH));
            $query->where(function ($w) use ($q, $matchingKeys) {
                $w->where('title', 'like', '%' . $q . '%');
                foreach ($matchingKeys as $key) {
                    $w->orWhereJsonContains('feature_keys', $key);
                }
            });
        }
        if (! empty($filters['track'])) {
            $query->where('track', $filters['track']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('updated_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('updated_at', '<=', $filters['to']);
        }

        $waves = $query->orderBy($sort, $dir)->orderBy('id')->paginate(25)->withQueryString();
        $tourCoverage = $this->tours->toursByFeature();

        return view('admin.dev-settings.feature-rollout', [
            'waves'      => $waves,
            'filters'    => $filters + ['sort' => $sort, 'dir' => $dir],
            'defaults'   => $this->rollout->defaults(),
            'intervals'  => (array) config('feature-rollout.interval_choices', []),
            'intervalMax'=> $this->rollout->intervalMax(),
            'tracks'     => FeatureRolloutWave::TRACKS,
            'grouped'    => $this->groupedWaveable($waveable),
            'labels'     => $waveable,
            'checks'     => $this->checks($waveable),
            'tourGap'    => $this->tourGap($waveable, $tourCoverage),
            'tourCoverage' => $tourCoverage,
            'archivedCount' => FeatureRolloutWave::onlyTrashed()->count(),
        ]);
    }

    public function savePacing(Request $request)
    {
        $this->owner($request);
        $data = $request->validate([
            'interval_days'   => 'required|integer|min:1|max:' . $this->rollout->intervalMax(),
            'holiday_country' => ['required', 'string', 'size:2', 'alpha'],
            'release_time'    => ['required', 'date_format:H:i'],
        ]);
        // Both checkboxes are always rendered on this form, so an absent one genuinely means "off".
        $data['weekdays_only'] = $request->boolean('weekdays_only');
        $data['skip_holidays'] = $request->boolean('skip_holidays');
        $this->rollout->saveDefaults($data);

        return back()->with('success', 'Pace saved. New plans use it; running plans keep their own.');
    }

    public function store(Request $request)
    {
        $this->owner($request);
        $data = $this->validated($request);
        $data['position'] = (int) FeatureRolloutWave::withTrashed()->max('position') + 10;
        FeatureRolloutWave::create($data);

        return back()->with('success', 'Wave added. New plans will include it; running plans are unchanged.');
    }

    public function update(Request $request, int $wave)
    {
        $this->owner($request);
        FeatureRolloutWave::findOrFail($wave)->update($this->validated($request));

        return back()->with('success', 'Wave saved. Running plans are unchanged.');
    }

    public function move(Request $request, int $wave)
    {
        $this->owner($request);
        $request->validate(['direction' => 'required|in:up,down']);
        $wave = FeatureRolloutWave::findOrFail($wave);
        $all = FeatureRolloutWave::orderBy('position')->orderBy('id')->get()->values();
        $i = $all->search(fn ($w) => $w->id === $wave->id);
        $j = $request->direction === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($all[$j])) {
            foreach ($all as $n => $w) {
                $w->position = ($n + 1) * 10;
            }
            [$all[$i]->position, $all[$j]->position] = [$all[$j]->position, $all[$i]->position];
            $all->each->save();
        }

        return back();
    }

    public function destroy(Request $request, int $wave)
    {
        $this->owner($request);
        FeatureRolloutWave::findOrFail($wave)->delete();

        return back()->with('success', 'Wave archived. New plans will not include it. Restore it from "Archived".');
    }

    public function restore(Request $request, int $wave)
    {
        $this->owner($request);
        FeatureRolloutWave::onlyTrashed()->findOrFail($wave)->restore();

        return back()->with('success', 'Wave restored.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        $waveable = array_keys($this->rollout->waveableFeatures());
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'intro_text'     => 'nullable|string|max:2000',
            'track'          => ['required', Rule::in(array_keys(FeatureRolloutWave::TRACKS))],
            'feature_keys'   => 'required|array|min:1',
            'feature_keys.*' => ['string', Rule::in($waveable)],
        ], [
            'feature_keys.required' => 'Pick at least one feature for this wave.',
        ]);
        $data['feature_keys'] = array_values(array_unique($data['feature_keys']));

        return $data;
    }

    /** @return array<string, array<string,string>> category => [key => label] */
    private function groupedWaveable(array $waveable): array
    {
        $out = [];
        foreach ($this->features->catalogue() as $key => $def) {
            if (isset($waveable[$key])) {
                $out[$def['category'] ?? 'Other'][$key] = $waveable[$key];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Problems with the default order, in plain words (spec §5.1):
     *  - features in no wave are switched on from day one for every new agency;
     *  - a feature in two waves only ever arrives with the first;
     *  - a feature placed before a feature it depends on cannot be used until that one arrives.
     *
     * @return array{unassigned: array<string,string>, duplicates: array<string,list<string>>, early: list<array{feature:string,parent:string}>}
     */
    private function checks(array $waveable): array
    {
        $catalogue = $this->features->catalogue();
        $firstWave = [];   // key => position of the first wave containing it
        $inWaves = [];     // key => wave titles
        foreach (FeatureRolloutWave::orderBy('position')->orderBy('id')->get() as $w) {
            foreach ((array) $w->feature_keys as $key) {
                $inWaves[$key][] = $w->title;
                $firstWave[$key] ??= $w->position;
            }
        }

        $unassigned = array_diff_key($waveable, $inWaves);
        $duplicates = array_filter($inWaves, fn ($titles) => count($titles) > 1);

        $early = [];
        foreach ($firstWave as $key => $pos) {
            foreach ((array) ($catalogue[$key]['depends_on'] ?? []) as $parent) {
                if (isset($firstWave[$parent]) && $firstWave[$parent] > $pos) {
                    $early[] = ['feature' => $waveable[$key] ?? $key, 'parent' => $waveable[$parent] ?? $parent];
                }
            }
        }

        return [
            'unassigned' => $unassigned,
            'duplicates' => $duplicates,
            'early'      => $early,
        ];
    }

    /**
     * Tour gap report (spec §12.11): every feature placed in a wave that has no guided tour, earliest
     * wave first — the order the tours should be written in.
     *
     * @return array{missing: list<array{key:string,label:string,wave:string}>, waved:int, covered:int}
     */
    private function tourGap(array $waveable, array $byFeature): array
    {
        $seen = [];
        $missing = [];
        $covered = 0;

        foreach (FeatureRolloutWave::orderBy('position')->orderBy('id')->get() as $w) {
            foreach ((array) $w->feature_keys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (! empty($byFeature[$key])) {
                    $covered++;
                    continue;
                }
                $missing[] = ['key' => $key, 'label' => $waveable[$key] ?? $key, 'wave' => $w->title];
            }
        }

        return ['missing' => $missing, 'waved' => count($seen), 'covered' => $covered];
    }
}
