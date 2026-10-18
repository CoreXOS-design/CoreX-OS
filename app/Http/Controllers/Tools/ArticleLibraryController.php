<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\AgentArticle;
use App\Models\ArticleSource;
use App\Models\LibraryArticle;
use App\Support\Text\PlainText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Article Library — the agency's side (Tools → Ad Manager → Article Library, AT-456 Part C, spec §C.5).
 *
 * The library is one platform-wide shelf (LibraryArticle is global by design and read-only here).
 * What an agency sees is LibraryArticle::visibleToAgencies(): ready, from a source that is not
 * archived. "Use this" copies one into the signed-in user's OWN article drafts — an AgentArticle,
 * agency-scoped, unpublished, never published for them. Two agencies using the same article get
 * two separate private drafts; the same user using it twice gets their existing copy, not a second.
 *
 * Gate (routes): access_ad_manager + feature:ad-manager + agency.required; "Use this" also needs
 * edit_own_profile, the permission that manages own articles.
 */
class ArticleLibraryController extends Controller
{
    private const PER_PAGE = 15;

    /** `order` query value => [sort column, direction]. The first is the default (newest first). */
    private const ORDERS = [
        'date-desc'   => ['date', 'desc'],
        'date-asc'    => ['date', 'asc'],
        'title-asc'   => ['title', 'asc'],
        'title-desc'  => ['title', 'desc'],
        'source-asc'  => ['source', 'asc'],
        'source-desc' => ['source', 'desc'],
    ];

    public function index(Request $request): View
    {
        $user = $request->user();

        $q        = trim((string) $request->query('q', ''));
        $sourceId = (int) $request->query('source', 0);
        $tag      = trim((string) $request->query('tag', ''));
        $unused   = $request->boolean('unused');
        $order    = array_key_exists((string) $request->query('order'), self::ORDERS) ? (string) $request->query('order') : 'date-desc';
        [$sort, $dir] = self::ORDERS[$order];
        $from     = $this->validDate($request->query('from'));
        $to       = $this->validDate($request->query('to'));

        // Library article id => the user's own (non-deleted) copy id. AgentArticle is agency-scoped.
        $mine = AgentArticle::query()
            ->where('user_id', $user->id)
            ->whereNotNull('library_article_id')
            ->pluck('id', 'library_article_id');

        $dateSql = LibraryArticle::dateSql();
        $query   = LibraryArticle::query()->visibleToAgencies();

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w
                ->where('title', 'like', $like)
                ->orWhere('source_name', 'like', $like)
                ->orWhere('tags_json', 'like', $like));
        }
        if ($sourceId > 0) {
            $query->where('source_id', $sourceId);
        }
        if ($tag !== '') {
            $query->whereJsonContains('tags_json', $tag);
        }
        if ($from) {
            $query->whereRaw("DATE($dateSql) >= ?", [$from]);
        }
        if ($to) {
            $query->whereRaw("DATE($dateSql) <= ?", [$to]);
        }
        if ($unused && $mine->isNotEmpty()) {
            $query->whereNotIn('library_articles.id', $mine->keys()->all());
        }

        match ($sort) {
            'title'  => $query->orderBy('title', $dir),
            'source' => $query->orderBy('source_name', $dir),
            default  => $query->orderByRaw("$dateSql $dir"),
        };
        $query->orderBy('library_articles.id', 'desc');

        $articles = $query->paginate(self::PER_PAGE)->withQueryString();

        // Filter choices come from what an agency can actually see.
        $visible = LibraryArticle::query()->visibleToAgencies();
        $tags    = LibraryArticle::visibleTags();

        return view('tools.ad-manager.articles', [
            'articles'     => $articles,
            'libraryEmpty' => ! (clone $visible)->exists(),
            'sources'      => ArticleSource::query()
                ->whereIn('id', (clone $visible)->select('source_id'))->orderBy('name')->get(['id', 'name']),
            'tags'         => $tags,
            'mine'         => $mine,
            'canUse'       => $user->hasPermission('edit_own_profile'),
            'canPortal'    => $user->hasPermission('access_my_portal'),
            'filters'      => [
                'q' => $q, 'source' => $sourceId, 'tag' => $tag, 'unused' => $unused,
                'order' => $order, 'from' => $from, 'to' => $to,
            ],
        ]);
    }

    /**
     * POST — copy a library article into the user's own unpublished drafts. Never publishes.
     * A hidden article, or one from an archived source, is a 404: the agency cannot see it, so it
     * cannot be used by id either.
     */
    public function use(Request $request, int $article): RedirectResponse
    {
        $user    = $request->user();
        $library = LibraryArticle::query()->visibleToAgencies()->findOrFail($article);

        try {
            return Cache::lock('library-use:' . $user->id . ':' . $library->id, 10)->block(5, function () use ($user, $library) {
            $existing = AgentArticle::query()
                ->where('user_id', $user->id)
                ->where('library_article_id', $library->id)
                ->first();

            if ($existing !== null) {
                return $this->toPortal($user, 'You already have this article in your drafts — here it is. A second copy was not made.');
            }

            // Plain text in, plain text out: articles stored before the cleaner was fixed may still carry
            // a literal tag, and this copy is what the website API later returns.
            $title = trim(PlainText::strip((string) $library->title));
            $user->articles()->create([
                'title'              => Str::limit($title, 200, ''),
                'slug'               => Str::slug($title) ?: null,
                'excerpt'            => $library->excerpt !== null ? PlainText::strip((string) $library->excerpt) : null,
                // The credit travels with the copy: the original publisher and the link to it.
                'body'               => PlainText::strip((string) $library->body) . "\n\n" . PlainText::strip($library->creditLine()),
                'link_url'           => mb_strlen($library->source_url) <= 255 ? $library->source_url : null,
                'tags'               => $library->tagList() === [] ? null : Str::limit(implode(', ', $library->tagList()), 500, ''),
                'is_published'       => false,
                'library_article_id' => $library->id,
            ]);

            return $this->toPortal($user, 'Added to your articles as a draft. Nothing is published until you publish it yourself.');
            });
        } catch (LockTimeoutException) {
            return back()->with('error', 'That is already being added — give it a moment, then check your articles.');
        }
    }

    private function toPortal($user, string $message): RedirectResponse
    {
        if ($user->hasPermission('access_my_portal')) {
            return redirect(route('agent.portal') . '#profile')->with('success', $message . ' Open the Articles tab under Profile.');
        }

        return redirect()->route('tools.ad-manager.articles')->with('success', $message);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
