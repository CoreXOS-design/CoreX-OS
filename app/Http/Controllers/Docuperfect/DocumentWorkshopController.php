<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/esign-template-transfer.md §13.4a — the Document workshop hub: the one page where the CoreX owner sees every template and where
 * it is on the road build → check readiness → demo view → verify → release → agency approves, with the action each row needs next.
 * CoreX owner only (route: owner_only + templates.release). It only lists and links: Verify and Release post to the existing release routes,
 * which re-check authority and the readiness rules themselves.
 *
 * Only ORIGINAL templates are listed. A copy that exists because it was released, copied or imported to an agency carries a release_status
 * and is followed on the Releases screen instead.
 */
class DocumentWorkshopController extends Controller
{
    private const PER_PAGE = 15;
    private const SORTS = ['name', 'agency', 'verified', 'last_release', 'updated'];
    private const STAGES = ['not_verified', 'verified', 'waiting', 'live'];

    public function __construct(private readonly TemplateReadinessReport $readiness)
    {
    }

    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole() && $u->hasPermission('templates.release'), 403);

        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : ($request->query('dir') === 'asc' ? 'asc' : ($sort === 'name' || $sort === 'agency' ? 'asc' : 'desc'));
        $q = trim((string) $request->query('q', ''));
        $stage = in_array($request->query('stage'), self::STAGES, true) ? $request->query('stage') : null;
        $agencyId = (int) $request->query('agency_id', 0);

        $t = (new Template)->getTable();
        $rel = (new TemplateRelease)->getTable();
        $lastRelease = "(select max(r.released_at) from {$rel} r where r.source_template_id = {$t}.id and r.deleted_at is null)";

        $query = Template::query()
            ->leftJoin('agencies as ag', 'ag.id', '=', "{$t}.agency_id")
            ->select("{$t}.*")
            ->whereNull("{$t}.archived_at")
            ->whereNull("{$t}.release_status");

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where("{$t}.name", 'like', $like)->orWhere('ag.name', 'like', $like));
        }
        if ($agencyId > 0) {
            $query->where("{$t}.agency_id", $agencyId);
        }
        $exists = fn (string $state) => "exists (select 1 from {$rel} r where r.source_template_id = {$t}.id and r.deleted_at is null and r.state = '{$state}')";
        match ($stage) {
            'not_verified' => $query->whereNull("{$t}.workshop_verified_at"),
            'verified' => $query->whereNotNull("{$t}.workshop_verified_at"),
            'waiting' => $query->whereRaw($exists(TemplateRelease::STATE_AWAITING)),
            'live' => $query->whereRaw($exists(TemplateRelease::STATE_LIVE)),
            default => null,
        };

        $col = ['name' => "{$t}.name", 'agency' => 'ag.name', 'verified' => "{$t}.workshop_verified_at", 'last_release' => DB::raw($lastRelease), 'updated' => "{$t}.updated_at"][$sort];
        $query->orderBy($col, $dir)->orderBy("{$t}.name")->orderBy("{$t}.id");

        $page = $query->paginate(self::PER_PAGE)->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();

        // Latest release of each listed template, and how many are live / waiting, in two queries for the whole page.
        $releases = TemplateRelease::withoutGlobalScopes()->whereIn('source_template_id', $ids)->with('agency')
            ->orderByDesc('released_at')->orderByDesc('id')->get()->groupBy('source_template_id');
        $agencyNames = Agency::withoutGlobalScopes()->whereIn('id', $page->getCollection()->pluck('agency_id')->filter())->pluck('name', 'id');

        $page->setCollection($page->getCollection()->map(function (Template $tpl) use ($releases, $agencyNames) {
            $r = $this->readiness->forTemplate($tpl);
            $mine = $releases->get($tpl->id, collect());
            $verified = $tpl->workshop_verified_at === null ? 'workshop' : ($tpl->isVerifiedAndCurrent() ? 'verified' : 'stale');
            $live = $mine->where('state', TemplateRelease::STATE_LIVE)->count();
            $waiting = $mine->where('state', TemplateRelease::STATE_AWAITING)->count();

            return [
                'template' => $tpl,
                'agency_name' => $agencyNames[$tpl->agency_id] ?? 'No agency (shared)',
                'verdict' => $r['verdict'],
                'counts' => $r['counts'],
                'verified' => $verified,
                'stage' => $live > 0 ? 'live' : ($waiting > 0 ? 'released' : $verified),
                'live' => $live,
                'waiting' => $waiting,
                'last' => $mine->first(),
            ];
        }));

        $waitingTotal = TemplateRelease::withoutGlobalScopes()->where('state', TemplateRelease::STATE_AWAITING)->count();

        return view('docuperfect.workshop.index', [
            'rows' => $page,
            'agencies' => Agency::withoutGlobalScopes()->orderBy('name')->get(['id', 'name']),
            'filters' => ['q' => $q, 'stage' => $stage, 'agency_id' => $agencyId ?: null],
            'filtered' => $q !== '' || $stage || $agencyId > 0,
            'sort' => $sort, 'dir' => $dir,
            'waitingTotal' => $waitingTotal,
        ]);
    }
}
