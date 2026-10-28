<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\TemplateRelease;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use Illuminate\Http\Request;

/**
 * .ai/specs/esign-template-transfer.md §13.4 — the agency's Approvals tab. Own agency only, at the query layer (TemplateRelease is
 * BelongsToAgency; a release of another agency is a 404 by direct URL). Only the people the agency named in "Who may authorise documents"
 * (or its administrators until it names anyone) see the Approve / Decline buttons; the CoreX owner sees the screen read-only.
 */
class TemplateApprovalController extends Controller
{
    private const PER_PAGE = 20;
    private const SORTS = ['released_at', 'template', 'state'];

    public function __construct(private readonly TemplateReleaseService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $this->viewer($request);
        $agencyId = (int) $user->effectiveAgencyId();

        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'released_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q = trim((string) $request->query('q', ''));
        // Default filter = waiting for approval; "all" shows every state.
        $stateParam = $request->query('state', TemplateRelease::STATE_AWAITING);
        $state = in_array($stateParam, TemplateRelease::STATES, true) ? $stateParam : null;
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        $from = $ok($request->query('from'));
        $to = $ok($request->query('to'));

        $query = TemplateRelease::query()
            ->leftJoin('docuperfect_templates as st', 'st.id', '=', 'docuperfect_template_releases.source_template_id')
            ->select('docuperfect_template_releases.*')
            ->with(['template', 'releasedByUser', 'decidedByUser', 'agency']);

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('st.name', 'like', $like)->orWhere('docuperfect_template_releases.decision_note', 'like', $like));
        }
        if ($state) {
            $query->where('docuperfect_template_releases.state', $state);
        }
        if ($from) {
            $query->where('docuperfect_template_releases.released_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('docuperfect_template_releases.released_at', '<=', $to . ' 23:59:59');
        }
        $col = ['released_at' => 'docuperfect_template_releases.released_at', 'template' => 'st.name', 'state' => 'docuperfect_template_releases.state'][$sort];
        $query->orderBy($col, $dir)->orderByDesc('docuperfect_template_releases.id');

        $setting = $agencyId > 0 ? AgencyDocumentAuthorisers::settings($agencyId) : ['user_ids' => [], 'roles' => []];

        return view('docuperfect.releases.approvals', [
            'releases' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => ['q' => $q, 'state' => $state, 'from' => $from, 'to' => $to],
            'states' => TemplateRelease::STATES,
            'sort' => $sort, 'dir' => $dir,
            'filtered' => $q !== '' || ($state !== TemplateRelease::STATE_AWAITING) || $from || $to,
            'canDecide' => ! $user->isOwnerRole() && AgencyDocumentAuthorisers::canAuthorise($user, $agencyId),
            'ownerView' => $user->isOwnerRole(),
            'authoriserNotSet' => ! $user->isOwnerRole() && $agencyId > 0 && ! AgencyDocumentAuthorisers::isConfigured($agencyId),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $this->viewer($request);
        $release = TemplateRelease::query()->with(['sourceTemplate', 'template', 'agency', 'releasedByUser', 'decidedByUser', 'previous', 'events'])->findOrFail($id);

        return view('docuperfect.releases.show', [
            'release' => $release, 'mode' => 'agency',
            'canDecide' => ! $user->isOwnerRole() && AgencyDocumentAuthorisers::canAuthorise($user, (int) $release->agency_id),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $user = $this->viewer($request);
        $release = TemplateRelease::query()->findOrFail($id);
        try {
            $result = $this->service->approve($release, $user, $request->boolean('i_have_looked'), (string) $request->input('note', ''));
        } catch (TemplateReleaseException $e) {
            abort_if($e->status === 403, 403, $e->getMessage());

            return back()->withErrors(['release' => $e->getMessage()]);
        }
        $msg = '"' . ($release->sourceTemplate?->name ?? 'The document') . '" is approved and now live for your agency.';
        if ($result['lease_message']) {
            $msg .= ' ' . $result['lease_message'];
        }

        return redirect()->route('docuperfect.template-approvals.show', $release->id)->with('status', $msg);
    }

    public function decline(Request $request, int $id)
    {
        $user = $this->viewer($request);
        $release = TemplateRelease::query()->findOrFail($id);
        try {
            $this->service->decline($release, $user, (string) $request->input('reason', ''));
        } catch (TemplateReleaseException $e) {
            abort_if($e->status === 403, 403, $e->getMessage());

            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return redirect()->route('docuperfect.template-approvals.show', $release->id)->with('status', 'The document was declined. The person who prepared it will see your reason.');
    }

    /** Authorisers and the owner only; the tab hides itself from everyone else, this is the direct-URL check. */
    private function viewer(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwnerRole() || AgencyDocumentAuthorisers::canAuthorise($user, (int) $user->effectiveAgencyId())), 403);

        return $user;
    }
}
