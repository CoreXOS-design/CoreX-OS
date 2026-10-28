<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use Illuminate\Http\Request;

/**
 * .ai/specs/esign-template-transfer.md §13.4 — the owner's Releases tab: the releases list and the release log (verify and release live on the
 * Document workshop hub). CoreX owner only (route: owner_only + templates.release; the service checks again). Plain-language errors, never a stack trace.
 */
class TemplateReleaseController extends Controller
{
    private const PER_PAGE = 20;
    private const RELEASE_SORTS = ['released_at', 'template', 'agency', 'state', 'version'];
    private const LOG_SORTS = ['created_at', 'event', 'agency'];

    public function __construct(private readonly TemplateReleaseService $service)
    {
    }

    public function index(Request $request)
    {
        $this->authorise($request);
        // The old "Workshop" search view is now the Document workshop hub (Documents menu), which lists every template.
        if ($request->query('view') === 'workshop') {
            return redirect()->route('docuperfect.workshop.index');
        }
        $view = $request->query('view') === 'log' ? 'log' : 'releases';

        $data = ['view' => $view, 'agencies' => Agency::withoutGlobalScopes()->orderBy('name')->get(['id', 'name']), 'states' => TemplateRelease::STATES];
        if ($view === 'log') {
            $data += $this->logData($request);
        } else {
            $data += $this->releaseData($request);
        }

        return view('docuperfect.releases.index', $data);
    }

    public function show(Request $request, int $id)
    {
        $this->authorise($request);
        $release = TemplateRelease::query()->with(['sourceTemplate', 'template', 'agency', 'releasedByUser', 'decidedByUser', 'previous', 'events'])->findOrFail($id);

        return view('docuperfect.releases.show', ['release' => $release, 'mode' => 'owner', 'canDecide' => false]);
    }

    public function verify(Request $request, int $id)
    {
        $this->authorise($request);
        $template = Template::findOrFail($id);
        try {
            $this->service->verify($template, $request->user(), $request->boolean('i_have_looked'));
        } catch (TemplateReleaseException $e) {
            return $this->back($request)->withErrors(['release' => $e->getMessage()])->with('error_details', $e->details);
        }

        return $this->back($request)->with('status', '"' . $template->name . '" is marked as verified. It can now be released to an agency.');
    }

    public function release(Request $request, int $id)
    {
        $this->authorise($request);
        $data = $request->validate(['agency_id' => 'required|integer'], ['agency_id.required' => 'Choose the agency to release to.', 'agency_id.integer' => 'Choose the agency to release to.']);
        $template = Template::findOrFail($id);
        $agency = Agency::withoutGlobalScopes()->find((int) $data['agency_id']);
        if (! $agency) {
            return $this->back($request)->withErrors(['release' => 'That agency does not exist.']);
        }
        try {
            $release = $this->service->release($template, $agency, $request->user());
        } catch (TemplateReleaseException $e) {
            return $this->back($request)->withErrors(['release' => $e->getMessage()])->with('error_details', $e->details);
        }

        return redirect()->route('docuperfect.template-releases.show', $release->id)
            ->with('status', '"' . $template->name . '" (version ' . $release->version . ') was released to ' . $agency->name . '. It is not live until their authorisers approve it.');
    }

    public function withdraw(Request $request, int $id)
    {
        $this->authorise($request);
        $release = TemplateRelease::query()->findOrFail($id);
        try {
            $this->service->withdraw($release, $request->user(), (string) $request->input('note', ''));
        } catch (TemplateReleaseException $e) {
            return $this->back($request)->withErrors(['release' => $e->getMessage()]);
        }

        return redirect()->route('docuperfect.template-releases.show', $release->id)->with('status', 'The release was withdrawn. Release it again when it is ready — that makes the next version.');
    }

    // ───────────────────────────── data ─────────────────────────────

    private function releaseData(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::RELEASE_SORTS, true) ? $request->query('sort') : 'released_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q = trim((string) $request->query('q', ''));
        $state = in_array($request->query('state'), TemplateRelease::STATES, true) ? $request->query('state') : null;
        $agencyId = (int) $request->query('agency_id', 0);
        [$from, $to] = $this->dates($request);

        $query = TemplateRelease::query()
            ->leftJoin('docuperfect_templates as st', 'st.id', '=', 'docuperfect_template_releases.source_template_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 'docuperfect_template_releases.agency_id')
            ->select('docuperfect_template_releases.*')
            ->with(['sourceTemplate', 'agency', 'releasedByUser', 'decidedByUser']);

        if ($q !== '') {
            $like = '%' . $this->escapeLike($q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('st.name', 'like', $like)
                    ->orWhere('ag.name', 'like', $like)
                    ->orWhere('docuperfect_template_releases.decision_note', 'like', $like)
                    ->orWhereIn('docuperfect_template_releases.released_by', \App\Models\User::withoutGlobalScopes()->where('name', 'like', $like)->select('id'));
            });
        }
        if ($state) {
            $query->where('docuperfect_template_releases.state', $state);
        }
        if ($agencyId > 0) {
            $query->where('docuperfect_template_releases.agency_id', $agencyId);
        }
        if ($from) {
            $query->where('docuperfect_template_releases.released_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('docuperfect_template_releases.released_at', '<=', $to . ' 23:59:59');
        }

        $col = ['released_at' => 'docuperfect_template_releases.released_at', 'template' => 'st.name', 'agency' => 'ag.name', 'state' => 'docuperfect_template_releases.state', 'version' => 'docuperfect_template_releases.version'][$sort];
        $query->orderBy($col, $dir)->orderByDesc('docuperfect_template_releases.id');

        return [
            'releases' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => ['q' => $q, 'state' => $state, 'agency_id' => $agencyId ?: null, 'from' => $from, 'to' => $to],
            'sort' => $sort, 'dir' => $dir,
            'filtered' => $q !== '' || $state || $agencyId > 0 || $from || $to,
        ];
    }

    private function logData(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::LOG_SORTS, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q = trim((string) $request->query('q', ''));
        $event = in_array($request->query('event'), TemplateReleaseEvent::EVENTS, true) ? $request->query('event') : null;
        $agencyId = (int) $request->query('agency_id', 0);
        [$from, $to] = $this->dates($request);

        $query = TemplateReleaseEvent::query()
            ->leftJoin('docuperfect_templates as t', 't.id', '=', 'docuperfect_template_release_events.template_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 'docuperfect_template_release_events.agency_id')
            ->select('docuperfect_template_release_events.*', 't.name as template_name', 'ag.name as agency_name');

        if ($q !== '') {
            $like = '%' . $this->escapeLike($q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('t.name', 'like', $like)->orWhere('ag.name', 'like', $like)
                    ->orWhere('docuperfect_template_release_events.actor_name', 'like', $like)
                    ->orWhere('docuperfect_template_release_events.note', 'like', $like);
            });
        }
        if ($event) {
            $query->where('docuperfect_template_release_events.event', $event);
        }
        if ($agencyId > 0) {
            $query->where('docuperfect_template_release_events.agency_id', $agencyId);
        }
        if ($from) {
            $query->where('docuperfect_template_release_events.created_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('docuperfect_template_release_events.created_at', '<=', $to . ' 23:59:59');
        }
        $col = ['created_at' => 'docuperfect_template_release_events.created_at', 'event' => 'docuperfect_template_release_events.event', 'agency' => 'ag.name'][$sort];
        $query->orderBy($col, $dir)->orderByDesc('docuperfect_template_release_events.id');

        return [
            'events' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'eventTypes' => TemplateReleaseEvent::EVENTS,
            'filters' => ['q' => $q, 'event' => $event, 'agency_id' => $agencyId ?: null, 'from' => $from, 'to' => $to],
            'sort' => $sort, 'dir' => $dir,
            'filtered' => $q !== '' || $event || $agencyId > 0 || $from || $to,
        ];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function authorise(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole() && $u->hasPermission('templates.release'), 403);
    }

    private function back(Request $request)
    {
        return redirect()->to(url()->previous() ?: route('docuperfect.template-releases.index'));
    }

    /** @return array{0: ?string, 1: ?string} valid Y-m-d dates or null */
    private function dates(Request $request): array
    {
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;

        return [$ok($request->query('from')), $ok($request->query('to'))];
    }

    private function escapeLike(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
