<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureRollout\FeatureRolloutFeatureCopy;
use App\Services\FeatureRollout\FeatureRolloutCopy;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Dev Settings → Feature rollout → Feature wording & videos (AT-478, spec agency-feature-rollout.md §19.4).
 * What the rollout pop-ups say about each feature: summary, full explanation, video link, extra links.
 * Platform-wide, owner-only (route group + owner() here). An edit is a row; "go back to the default"
 * archives it (soft delete) and Restore brings it back — never hard-deleted.
 */
class FeatureRolloutCopyController extends Controller
{
    private const SORTS = ['name', 'category', 'updated'];
    private const SHOWS = ['all', 'edited', 'default', 'video', 'no_video', 'reverted'];

    /** https:// only, or a CoreX page path ("/corex/…", never "//host"). */
    private const LINK_PATTERN = '#^(https://[^\s<>"]+|/(?!/)[^\s<>"]*)$#i';

    public function __construct(
        private readonly FeatureRolloutService $rollout,
        private readonly AgencyFeatureService $features,
        private readonly FeatureRolloutCopy $copy,
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
            'q'    => 'nullable|string|max:100',
            'show' => ['nullable', Rule::in(self::SHOWS)],
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'dir'  => 'nullable|in:asc,desc',
        ]);
        $show = $filters['show'] ?? 'all';
        $sort = $filters['sort'] ?? 'name';
        $dir = $filters['dir'] ?? ($sort === 'updated' ? 'desc' : 'asc');

        $catalogue = $this->features->catalogue();
        $edits = FeatureRolloutFeatureCopy::withTrashed()->get()->keyBy('feature_key');

        $rows = collect($this->rollout->waveableFeatures())->map(function (string $label, string $key) use ($catalogue, $edits) {
            $edit = $edits[$key] ?? null;
            $live = $edit && ! $edit->trashed();
            $c = $this->copy->for($key);

            return [
                'key'      => $key,
                'label'    => $label,
                'category' => (string) ($catalogue[$key]['category'] ?? 'Other'),
                'summary'  => $c['summary'],
                'edited'   => $live,
                'reverted' => $edit && $edit->trashed(),
                'video'    => $live && $edit->video_url,
                'links'    => $live ? count((array) $edit->links) : 0,
                'updated'  => $edit?->trashed() ? $edit->deleted_at : $edit?->updated_at,
            ];
        })->values();

        $rows = $rows
            ->when(! empty($filters['q']), function ($c) use ($filters) {
                $q = mb_strtolower($filters['q']);
                return $c->filter(fn ($r) => str_contains(mb_strtolower($r['label'] . ' ' . $r['key'] . ' ' . $r['summary'] . ' ' . implode(' ', $this->copy->for($r['key'])['paragraphs'])), $q));
            })
            ->filter(fn ($r) => match ($show) {
                'edited'   => $r['edited'],
                'default'  => ! $r['edited'],
                'video'    => $r['video'],
                'no_video' => ! $r['video'],
                'reverted' => $r['reverted'],
                default    => true,
            })
            ->when(! empty($filters['from']), fn ($c) => $c->filter(fn ($r) => $r['updated'] && $r['updated']->toDateString() >= $filters['from']))
            ->when(! empty($filters['to']), fn ($c) => $c->filter(fn ($r) => $r['updated'] && $r['updated']->toDateString() <= $filters['to']));

        $sorted = match ($sort) {
            'category' => $rows->sortBy(fn ($r) => mb_strtolower($r['category'] . ' ' . $r['label'])),
            'updated'  => $rows->sortBy(fn ($r) => $r['updated']?->timestamp ?? 0),
            default    => $rows->sortBy(fn ($r) => mb_strtolower($r['label'])),
        };
        if ($dir === 'desc') {
            $sorted = $sorted->reverse();
        }
        $sorted = $sorted->values();

        $page = max(1, (int) $request->query('page', 1));
        $paged = new LengthAwarePaginator($sorted->forPage($page, 25)->values(), $sorted->count(), 25, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('admin.dev-settings.feature-rollout-copy.index', [
            'rows'    => $paged,
            'filters' => $filters + ['show' => $show, 'sort' => $sort, 'dir' => $dir],
            'editedCount' => $edits->filter(fn ($e) => ! $e->trashed())->count(),
            'total'   => count($this->rollout->waveableFeatures()),
        ]);
    }

    public function edit(Request $request, string $feature)
    {
        $this->owner($request);
        $label = $this->label($feature);
        $edit = FeatureRolloutFeatureCopy::where('feature_key', $feature)->first();

        return view('admin.dev-settings.feature-rollout-copy.edit', [
            'feature'            => $feature,
            'label'              => $label,
            'edit'               => $edit,
            'defaultSummary'     => $this->copy->defaultSummary($feature),
            'defaultDescription' => $this->copy->defaultDescription($feature),
            'preview'            => $this->copy->for($feature),
            'maxLinks'           => FeatureRolloutFeatureCopy::MAX_LINKS,
        ]);
    }

    public function update(Request $request, string $feature)
    {
        $this->owner($request);
        $this->label($feature);

        $data = $request->validate([
            'summary'       => 'nullable|string|max:500',
            'description'   => 'nullable|string|max:10000',
            'video_url'     => ['nullable', 'string', 'max:500', 'regex:#^https://[^\s<>"]+$#i'],
            'links'         => 'nullable|array|max:' . FeatureRolloutFeatureCopy::MAX_LINKS,
            'links.*.label' => 'nullable|string|max:80',
            'links.*.url'   => ['nullable', 'string', 'max:500', 'regex:' . self::LINK_PATTERN],
        ], [
            'video_url.regex' => 'The video link must start with https://.',
            'links.*.url.regex' => 'Each link must start with https:// or be a CoreX page starting with /.',
        ]);

        $links = [];
        foreach ((array) ($data['links'] ?? []) as $i => $l) {
            $labelText = trim((string) ($l['label'] ?? ''));
            $url = trim((string) ($l['url'] ?? ''));
            if ($labelText === '' && $url === '') {
                continue;
            }
            if ($labelText === '' || $url === '') {
                return back()->withInput()->withErrors(["links.$i.label" => 'Each link needs both a label and a link.']);
            }
            $links[] = ['label' => $labelText, 'url' => $url];
        }

        $edit = FeatureRolloutFeatureCopy::withTrashed()->firstOrNew(['feature_key' => $feature]);
        $edit->fill([
            // Blank = use the default; a value identical to the default is stored as blank so later default
            // improvements still reach this feature.
            'summary'     => $this->unlessDefault($data['summary'] ?? null, $this->copy->defaultSummary($feature)),
            'description' => $this->unlessDefault($data['description'] ?? null, $this->copy->defaultDescription($feature)),
            'video_url'   => trim((string) ($data['video_url'] ?? '')) ?: null,
            'links'       => $links ?: null,
            'updated_by'  => $request->user()->id,
        ]);
        $edit->deleted_at = null;
        $edit->save();
        $this->copy->flush();

        return redirect()->route('admin.feature-rollout-defaults.copy.edit', $feature)->with('success', 'Saved. The pop-ups show this wording from now on, for every agency.');
    }

    /** "Go back to the default wording" — archives the edit (soft delete); Restore brings it back. */
    public function destroy(Request $request, string $feature)
    {
        $this->owner($request);
        $this->label($feature);
        FeatureRolloutFeatureCopy::where('feature_key', $feature)->firstOrFail()->delete();

        return redirect()->route('admin.feature-rollout-defaults.copy.edit', $feature)->with('success', 'Back to the default wording. Your edit is kept under "Reverted edits" if you want it back.');
    }

    public function restore(Request $request, string $feature)
    {
        $this->owner($request);
        $this->label($feature);
        FeatureRolloutFeatureCopy::onlyTrashed()->where('feature_key', $feature)->firstOrFail()->restore();

        return redirect()->route('admin.feature-rollout-defaults.copy.edit', $feature)->with('success', 'Your edit is back.');
    }

    private function label(string $feature): string
    {
        $waveable = $this->rollout->waveableFeatures();
        abort_unless(isset($waveable[$feature]), 404);

        return $waveable[$feature];
    }

    private function unlessDefault(?string $value, string $default): ?string
    {
        $value = trim(str_replace("\r\n", "\n", (string) $value));

        return $value === '' || $value === trim(str_replace("\r\n", "\n", $default)) ? null : $value;
    }
}
