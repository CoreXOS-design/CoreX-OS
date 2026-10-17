<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleSource;
use App\Models\DevSetting;
use App\Models\LibraryArticle;
use App\Services\Articles\ArticleLibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Article Library — the platform owner's side (AT-456 Part C, spec §C.4). Lives in the system-dev
 * area (Dev Settings → Article Library). OWNER ONLY: the route group is `owner_only`, and every
 * action re-checks isOwnerRole() — no permission key on purpose (a grantable key could hand an
 * agency admin a screen that spends platform money and edits what every agency sees).
 *
 *   Sources   — the RSS link list: search / sort / filter / page, Add · Edit · Archive · Restore, Run now.
 *   Articles  — what the pipeline wrote: Hide / Unhide (hidden items vanish from every agency).
 *   Settings  — schedule (off · nightly · every 3 days) and max new articles per run.
 *
 * Run now is SYNCHRONOUS (QA2 has no queue worker): it runs the pipeline inside the request, bounded
 * by a time budget, and shows the result. Its AI spend is the platform bucket (agency NULL), never
 * the owner's own agency — see ArticleLibraryService.
 */
class ArticleLibraryController extends Controller
{
    private const PER_PAGE = 15;

    /** Seconds a Run now request may keep starting new briefs — stays inside nginx's 300s with room to spare. */
    private const RUN_BUDGET_SECONDS = 200;

    private const SOURCE_SORTS  = ['name', 'last_run', 'status', 'articles'];
    private const ARTICLE_SORTS = ['date', 'title', 'source', 'status', 'used', 'cost'];

    public function __construct(private readonly ArticleLibraryService $library)
    {
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    // ── Sources ──────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $this->owner($request);

        $q      = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active';
        $sort   = in_array($request->query('sort'), self::SOURCE_SORTS, true) ? (string) $request->query('sort') : 'name';
        $dir    = in_array($request->query('direction'), ['asc', 'desc'], true)
            ? (string) $request->query('direction')
            : ($sort === 'name' ? 'asc' : 'desc');
        $from   = $this->validDate($request->query('from'));
        $to     = $this->validDate($request->query('to'));

        $query = ArticleSource::query()->withCount('articles');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('url', 'like', $like));
        }
        if ($from) {
            $query->whereDate('last_run_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('last_run_at', '<=', $to);
        }

        match ($sort) {
            'last_run' => $query->orderBy('last_run_at', $dir),
            'status'   => $query->orderBy('last_status', $dir),
            'articles' => $query->orderBy('articles_count', $dir),
            default    => $query->orderBy('name', $dir),
        };
        $query->orderBy('id');

        return view('admin.article-library.sources', [
            'sources'  => $query->paginate(self::PER_PAGE)->withQueryString(),
            'hasAny'   => ArticleSource::withTrashed()->exists(),
            'settings' => $this->library->settings(),
            'filters'  => compact('q', 'status', 'sort', 'from', 'to') + ['direction' => $dir],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner($request);

        $data = $request->validate($this->sourceRules(null), $this->sourceMessages());

        ArticleSource::create([
            'name'       => trim($data['name']),
            'url'        => trim($data['url']),
            'type'       => 'rss',
            'is_active'  => $request->boolean('is_active', true),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.article-library.index')->with('success', 'Source added. Press Run now to fetch it.');
    }

    public function update(Request $request, int $source): RedirectResponse
    {
        $this->owner($request);
        $model = ArticleSource::withTrashed()->findOrFail($source);

        $data = $request->validate($this->sourceRules($model->id), $this->sourceMessages());

        $model->update([
            'name'      => trim($data['name']),
            'url'       => trim($data['url']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Source updated.');
    }

    public function archive(Request $request, int $source): RedirectResponse
    {
        $this->owner($request);
        $model = ArticleSource::findOrFail($source);
        $model->delete();

        return back()->with('success', "“{$model->name}” archived. It is no longer fetched, and its articles are hidden from agencies. You can restore it.");
    }

    public function restore(Request $request, int $source): RedirectResponse
    {
        $this->owner($request);
        $model = ArticleSource::onlyTrashed()->findOrFail($source);
        $model->restore();

        return back()->with('success', "“{$model->name}” restored.");
    }

    /** Run now — every active source, or just one. Synchronous. */
    public function run(Request $request, ?int $source = null): RedirectResponse
    {
        $this->owner($request);

        $only = $source !== null ? ArticleSource::findOrFail($source) : null; // archived → 404

        if ($only !== null && ! $only->is_active) {
            return back()->with('error', "“{$only->name}” is paused — tick “Fetch this source” on it first.");
        }

        @set_time_limit(self::RUN_BUDGET_SECONDS + 80);

        $result = $this->library->run($only, self::RUN_BUDGET_SECONDS);

        $flash = in_array($result['status'], ['paused', 'busy', 'failed'], true)
            ? 'error'
            : (($result['errors'] !== [] || $result['status'] === 'stopped_early') ? 'warning' : 'success');

        return back()->with($flash, $result['message'])
            ->with('run_errors', array_slice($result['errors'], 0, 10));
    }

    // ── Articles ─────────────────────────────────────────────────────────

    public function articles(Request $request): View
    {
        $this->owner($request);

        $q        = trim((string) $request->query('q', ''));
        $status   = in_array($request->query('status'), [LibraryArticle::STATUS_READY, LibraryArticle::STATUS_HIDDEN, 'all'], true) ? (string) $request->query('status') : 'all';
        $sourceId = (int) $request->query('source', 0);
        $sort     = in_array($request->query('sort'), self::ARTICLE_SORTS, true) ? (string) $request->query('sort') : 'date';
        $dir      = in_array($request->query('direction'), ['asc', 'desc'], true)
            ? (string) $request->query('direction')
            : (in_array($sort, ['title', 'source'], true) ? 'asc' : 'desc');
        $from     = $this->validDate($request->query('from'));
        $to       = $this->validDate($request->query('to'));

        $dateSql = LibraryArticle::dateSql();

        $query = LibraryArticle::query()->select('library_articles.*')
            ->selectSub(
                DB::table('agent_articles')->selectRaw('COUNT(*)')->whereColumn('agent_articles.library_article_id', 'library_articles.id'),
                'used_count'
            );

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('source_name', 'like', $like));
        }
        if ($sourceId > 0) {
            $query->where('source_id', $sourceId);
        }
        if ($from) {
            $query->whereRaw("DATE($dateSql) >= ?", [$from]);
        }
        if ($to) {
            $query->whereRaw("DATE($dateSql) <= ?", [$to]);
        }

        match ($sort) {
            'title'  => $query->orderBy('title', $dir),
            'source' => $query->orderBy('source_name', $dir),
            'status' => $query->orderBy('status', $dir),
            'used'   => $query->orderBy('used_count', $dir),
            'cost'   => $query->orderBy('cost_zar', $dir),
            default  => $query->orderByRaw("$dateSql $dir"),
        };
        $query->orderBy('library_articles.id', 'desc');

        return view('admin.article-library.articles', [
            'articles' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'hasAny'   => LibraryArticle::query()->exists(),
            'sources'  => ArticleSource::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'filters'  => compact('q', 'status', 'sort', 'from', 'to') + ['source' => $sourceId, 'direction' => $dir],
        ]);
    }

    public function hide(Request $request, int $article): RedirectResponse
    {
        $this->owner($request);
        $model = LibraryArticle::findOrFail($article);
        $model->update(['status' => LibraryArticle::STATUS_HIDDEN]);

        return back()->with('success', 'Hidden — it no longer shows in any agency\'s Article Library. You can unhide it.');
    }

    public function unhide(Request $request, int $article): RedirectResponse
    {
        $this->owner($request);
        $model = LibraryArticle::findOrFail($article);
        $model->update(['status' => LibraryArticle::STATUS_READY]);

        return back()->with('success', 'Unhidden — agencies can use it again.');
    }

    // ── Settings ─────────────────────────────────────────────────────────

    public function settings(Request $request): View
    {
        $this->owner($request);

        return view('admin.article-library.settings', [
            'settings'  => $this->library->settings(),
            'schedules' => ArticleLibraryService::SCHEDULES,
            'maxLimit'  => ArticleLibraryService::MAX_PER_RUN_LIMIT,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->owner($request);

        $data = $request->validate([
            'schedule'    => ['required', Rule::in(array_keys(ArticleLibraryService::SCHEDULES))],
            'max_per_run' => ['required', 'integer', 'min:1', 'max:' . ArticleLibraryService::MAX_PER_RUN_LIMIT],
        ], [
            'schedule.required'    => 'Choose how often the library should refresh.',
            'schedule.in'          => 'Choose how often the library should refresh.',
            'max_per_run.required' => 'Enter a whole number from 1 to ' . ArticleLibraryService::MAX_PER_RUN_LIMIT . '.',
            'max_per_run.integer'  => 'Enter a whole number from 1 to ' . ArticleLibraryService::MAX_PER_RUN_LIMIT . '.',
            'max_per_run.min'      => 'Enter a whole number from 1 to ' . ArticleLibraryService::MAX_PER_RUN_LIMIT . '.',
            'max_per_run.max'      => 'Enter a whole number from 1 to ' . ArticleLibraryService::MAX_PER_RUN_LIMIT . '.',
        ]);

        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, $data['schedule']);
        DevSetting::set(ArticleLibraryService::KEY_MAX_PER_RUN, (string) (int) $data['max_per_run']);

        return back()->with('success', 'Article Library settings saved.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function sourceRules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // unique across archived rows too (no deleted_at filter) — the same link twice would just double-fetch.
            'url'  => ['required', 'string', 'max:500', 'url:http,https', Rule::unique('article_sources', 'url')->ignore($ignoreId)],
        ];
    }

    /** @return array<string, string> */
    private function sourceMessages(): array
    {
        return [
            'name.required' => 'Give the source a name — it is shown as the credit on every article.',
            'url.required'  => 'Paste the feed link.',
            'url.url'       => 'That does not look like a web link (it must start with http:// or https://).',
            'url.unique'    => 'That link is already on the list (it may be archived — restore it instead).',
        ];
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
