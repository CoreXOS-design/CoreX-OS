<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Services\FeatureRollout\FeatureRolloutAccess;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Services\FeatureRollout\RolloutCalendar;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $archivedView = ($filters['status'] ?? 'active') === 'archived';
        $sort = $filters['sort'] ?? 'position';
        $dir = $filters['dir'] ?? ($sort === 'updated_at' ? 'desc' : 'asc');
        $tourCoverage = $this->tours->toursByFeature();

        // Archived waves keep the full list screen (search, filters, sort, pagination). Active waves are the
        // ordered, dated board (spec §20): its order IS the sort, so it is never filtered or paginated.
        $archived = null;
        if ($archivedView) {
            $query = FeatureRolloutWave::onlyTrashed();
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
            $archived = $query->orderBy($sort, $dir)->orderBy('id')->paginate(25)->withQueryString();
        }

        // §12.7 — every agency on gradual rollout, needing-attention first. Own query names (o_*, opage).
        $o = $request->validate(['o_show' => 'nullable|in:attention,all', 'o_q' => 'nullable|string|max:100']);
        $o['o_show'] ??= 'attention';
        $overview = app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->overview()
            ->when($o['o_show'] === 'attention', fn ($c) => $c->filter(fn ($r) => $r['flags'] !== []))
            ->when(! empty($o['o_q']), fn ($c) => $c->filter(fn ($r) => stripos($r['agency']->name, $o['o_q']) !== false))
            ->sortBy([fn ($a, $b) => [$a['flags'] === [] ? 1 : 0, $a['next']?->timestamp ?? PHP_INT_MAX] <=> [$b['flags'] === [] ? 1 : 0, $b['next']?->timestamp ?? PHP_INT_MAX]])
            ->values();
        $opage = max(1, (int) $request->query('opage', 1));
        $overviewPage = new \Illuminate\Pagination\LengthAwarePaginator($overview->forPage($opage, 25)->values(), $overview->count(), 25, $opage,
            ['path' => $request->url(), 'pageName' => 'opage', 'query' => $request->query()]);

        $defaults = $this->rollout->defaults();

        return view('admin.dev-settings.feature-rollout', [
            'waves'      => $archived,
            'filters'    => $filters + ['sort' => $sort, 'dir' => $dir],
            'defaults'   => $defaults,
            'intervals'  => (array) config('feature-rollout.interval_choices', []),
            'intervalMax'=> $this->rollout->intervalMax(),
            'tracks'     => FeatureRolloutWave::TRACKS,
            'labels'     => $waveable,
            'checks'     => $this->checks($waveable),
            'tourGap'    => $this->tourGap($waveable, $tourCoverage),
            'tourCoverage' => $tourCoverage,
            'archivedCount' => FeatureRolloutWave::onlyTrashed()->count(),
            'overview'      => $overviewPage,
            'overviewFilters' => $o,
            'board'         => $archivedView ? null : $this->board($waveable, $tourCoverage, $defaults, $request),
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

    /**
     * Save the wave planner board in one go (spec §20.2): the whole ordered list of active waves. New waves are
     * created, existing ones updated (position = order), active waves left out are archived. Running plans hold
     * their own copy, so none of this touches them.
     */
    public function saveLayout(Request $request)
    {
        $this->owner($request);
        $payload = json_decode((string) $request->input('layout'), true);
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['layout' => 'The plan could not be read. Reload the page and try again.']);
        }

        $waveable = $this->rollout->waveableFeatures();
        $access = app(FeatureRolloutAccess::class);
        $existing = FeatureRolloutWave::pluck('id')->map(fn ($id) => (int) $id)->all();
        $fail = fn (string $message) => throw ValidationException::withMessages(['layout' => $message]);

        // An empty plan archives every wave (a new agency would then get every feature at once): only on purpose.
        if ($payload === [] && $existing !== [] && ! $request->boolean('confirm_empty')) {
            $fail('This would archive every wave, so a new agency would get every feature at once. Reload the page; if you do mean to archive them all, confirm it when you press Save plan.');
        }
        // Only waves the page actually showed can be archived by leaving them out: a wave someone else added
        // after this page was loaded is kept, never silently archived.
        $known = json_decode((string) $request->input('known'), true);
        $known = is_array($known) ? array_map('intval', $known) : null;

        $rows = [];
        $seen = [];
        $usedIds = [];
        foreach ($payload as $w) {
            $w = is_array($w) ? $w : [];
            $title = trim((string) ($w['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                $fail('Every wave needs a name (up to 255 characters).');
            }
            $track = (string) ($w['track'] ?? '');
            if (! array_key_exists($track, FeatureRolloutWave::TRACKS)) {
                $fail("“{$title}” has a group that is not recognised.");
            }
            $intro = trim((string) ($w['intro_text'] ?? ''));
            if (mb_strlen($intro) > 2000) {
                $fail("The message for “{$title}” is too long (up to 2000 characters).");
            }

            $keys = array_values(array_unique(array_filter((array) ($w['feature_keys'] ?? []), 'is_string')));
            $unknown = array_values(array_diff($keys, array_keys($waveable)));
            if ($unknown !== []) {
                $fail("“{$title}” has features that cannot be part of a wave: " . implode(', ', $unknown) . '.');
            }
            // A feature belongs to one wave only: a repeat keeps its earliest place.
            $keys = array_values(array_diff($keys, $seen));
            $seen = array_merge($seen, $keys);
            if ($keys === []) {
                $fail("“{$title}” has no features. Drag at least one in, or archive the wave.");
            }
            if ($track === 'admin') {
                $open = array_values(array_filter($keys, fn ($k) => ! $access->canBeLimited($k)));
                if ($open !== []) {
                    $names = implode(', ', array_map(fn ($k) => $waveable[$k] ?? $k, $open));
                    $fail("These have no access setting of their own — everyone who can open their area has them — so they cannot be kept for the admins: {$names}. Put them in another wave.");
                }
            }

            $id = isset($w['id']) && $w['id'] !== null && $w['id'] !== '' ? (int) $w['id'] : null;
            if ($id !== null && (! in_array($id, $existing, true) || in_array($id, $usedIds, true))) {
                $fail('A wave on this page was changed or archived by someone else. Reload the page and try again.');
            }
            if ($id !== null) {
                $usedIds[] = $id;
            }

            $rows[] = ['id' => $id, 'admin' => $track === 'admin', 'data' => [
                'title' => $title, 'track' => $track, 'intro_text' => $intro === '' ? null : $intro, 'feature_keys' => $keys,
            ]];
        }

        // "Admin decides" waves always come last, whatever order they arrived in.
        usort($rows, fn ($a, $b) => (int) $a['admin'] <=> (int) $b['admin']);

        $archived = DB::transaction(function () use ($rows, $known) {
            $existing = FeatureRolloutWave::lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $keep = [];
            foreach ($rows as $n => $r) {
                $data = $r['data'] + ['position' => ($n + 1) * 10];
                if ($r['id'] !== null) {
                    FeatureRolloutWave::findOrFail($r['id'])->update($data);
                    $keep[] = $r['id'];
                } else {
                    $keep[] = FeatureRolloutWave::create($data)->id;
                }
            }
            $gone = array_values(array_diff($known === null ? $existing : array_intersect($existing, $known), $keep));
            FeatureRolloutWave::whereIn('id', $gone)->get()->each->delete();

            return count($gone);
        });

        return redirect()->route('admin.feature-rollout-defaults.index')
            ->with('success', 'Plan saved. New plans use it; running plans are unchanged.' . ($archived ? " {$archived} " . ($archived === 1 ? 'wave was' : 'waves were') . ' archived.' : ''));
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

        // §19.1.2 — "Admin decides" can only hold features that can be kept to some roles.
        if ($data['track'] === 'admin') {
            $access = app(FeatureRolloutAccess::class);
            $open = array_values(array_filter($data['feature_keys'], fn ($k) => ! $access->canBeLimited($k)));
            if ($open !== []) {
                $names = implode(', ', array_map(fn ($k) => $this->rollout->waveableFeatures()[$k] ?? $k, $open));
                throw ValidationException::withMessages(['feature_keys' => "These have no access setting of their own — everyone who can open their area has them — so they cannot be kept for the admins: {$names}. Put them in another wave."]);
            }
        }

        return $data;
    }

    /**
     * Everything the wave planner board needs, as one JSON-able array (spec §20). Dates are worked out in the
     * browser with the same rule as RolloutCalendar, from this holiday list and the live pace fields.
     */
    private function board(array $waveable, array $tourCoverage, array $defaults, Request $request): array
    {
        $catalogue = $this->features->catalogue();
        $access = app(FeatureRolloutAccess::class);
        $features = [];
        foreach ($waveable as $key => $label) {
            $features[] = [
                'k' => $key,
                'l' => $label,
                'c' => $catalogue[$key]['category'] ?? 'Other',
                'd' => array_values(array_filter((array) ($catalogue[$key]['depends_on'] ?? []), fn ($p) => isset($waveable[$p]))),
                't' => ! empty($tourCoverage[$key]),
                'lim' => $access->canBeLimited($key),
            ];
        }

        $calendar = app(RolloutCalendar::class);
        $saved = FeatureRolloutWave::orderBy('position')->orderBy('id')->get()
            ->map(fn ($w) => ['id' => $w->id, 'title' => $w->title, 'track' => $w->track, 'intro' => (string) $w->intro_text, 'keys' => array_values((array) $w->feature_keys)])
            ->all();

        // After a refused save the browser's own edits come back (the form was flashed), so nothing typed is lost.
        $draft = json_decode((string) $request->old('layout'), true);

        return [
            'features' => $features,
            'waves'    => $saved,
            'draft'    => is_array($draft) ? array_map(fn ($w) => [
                'id' => $w['id'] ?? null, 'title' => (string) ($w['title'] ?? ''), 'track' => (string) ($w['track'] ?? 'general'),
                'intro' => (string) ($w['intro_text'] ?? ''), 'keys' => array_values((array) ($w['feature_keys'] ?? [])),
            ], $draft) : null,
            'holidays' => $calendar->holidayMap($defaults['holiday_country']),
            'today'    => $calendar->today()->toDateString(),
            'tracks'   => array_diff_key(FeatureRolloutWave::TRACKS, ['admin' => 1]),
        ];
    }

    /**
     * Problems with the saved "Admin decides" waves that only the server can know (spec §19.1, §5.1). The other order
     * problems (features in no wave, a feature placed before one it needs) are shown live on the board itself.
     *
     * @return array{notLimitable: list<array{feature:string,wave:string}>, shared: list<array{feature:string,other:string,otherWave:string}>}
     */
    private function checks(array $waveable): array
    {
        // §19.1 — "Admin decides" problems: a feature that cannot be kept to some roles (left on from day one
        // for new agencies), and a feature whose way in is shared with a feature in another group.
        $access = app(FeatureRolloutAccess::class);
        $notLimitable = [];
        $shared = [];
        $waveOf = [];   // key => [wave id, is admin]
        foreach (FeatureRolloutWave::orderBy('position')->orderBy('id')->get() as $w) {
            foreach ((array) $w->feature_keys as $key) {
                $waveOf[$key] ??= [$w->id, $w->track === 'admin', $w->title];
                if ($w->track === 'admin' && ! $access->canBeLimited($key)) {
                    $notLimitable[$key] = ['feature' => $waveable[$key] ?? $key, 'wave' => $w->title];
                }
            }
        }
        foreach ($waveOf as $key => [$waveId, $isAdmin, $title]) {
            if (! $isAdmin) {
                continue;
            }
            foreach ($access->sharesWayInWith($key, array_keys($waveOf)) as $other) {
                if ($waveOf[$other][0] !== $waveId) {
                    $shared[] = ['feature' => $waveable[$key] ?? $key, 'other' => $waveable[$other] ?? $other, 'otherWave' => $waveOf[$other][2]];
                }
            }
        }

        return [
            'notLimitable' => array_values($notLimitable),
            'shared'       => $shared,
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
