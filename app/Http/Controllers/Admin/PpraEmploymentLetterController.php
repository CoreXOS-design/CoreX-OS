<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use App\Services\Compliance\PpraEmploymentLetterService;
use App\Services\Compliance\PractitionerFfcRosterService;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPRA FFC renewal — Confirmation of Employment letter. Admin register.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * Full search/sort/filter/pagination/empty-state floor (BUILD_STANDARD §1b),
 * OWN/BRANCH/AGENCY scoping enforced at the query layer via
 * PpraEmploymentLetter::scopeVisibleTo() (§1c) on every action below,
 * including direct-URL-by-id access to show/download.
 *
 * Signing is always self-service — the agent and the principal each sign
 * with their own PIN, never an admin on their behalf. Create-on-behalf
 * (2026-10-05, Johan) lets an admin/principal START a letter for an agent
 * in their own scope; the agent-picker is bounded by the SAME own/branch/
 * all scope as the list (ppra_employment_letters.view's stored scope), and
 * the created letter runs through the identical missing-data validation and
 * status machine as PpraEmploymentLetterService::create() — there is no
 * second code path, only a different caller.
 *
 * Gate (2026-10-05, cc1's HR->Documents nav finding, flagged for closing
 * here): this ENTIRE admin register is the branch/all-scoped register —
 * an 'own'-scoped user (every agent is seeded 'own' on
 * ppra_employment_letters.view so they can see their OWN letter via My
 * Portal) must never reach it, including by direct URL. The sidebar link
 * was already fixed to stop OFFERING it to 'own'-scoped users; this closes
 * the matching route-level gap so a direct URL does not bypass that.
 * assertAdminScope() enforces this on every action below, in addition to
 * each action's own permission check.
 */
class PpraEmploymentLetterController extends Controller
{
    /** Every action here is for branch/all scope only — 'own' belongs in My Portal, never here, not even by direct URL. */
    private function assertAdminScope(User $user): void
    {
        abort_unless(
            in_array(PermissionService::getDataScope($user, 'ppra_employment_letters'), ['branch', 'all'], true),
            403
        );
    }

    /** The scoped agent picker for create-on-behalf. */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.manage'), 403);
        $this->assertAdminScope($user);

        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId());
        abort_unless($agency, 422, 'No agency context.');

        $resolved = app(PpraEmploymentLetterService::class)->resolvePrincipal($agency);

        return view('admin.ppra-employment-letters.create', [
            'agents'          => $this->scopedRoster($user, $agency->id),
            'principalStatus' => $resolved['status'],
            'principals'      => $resolved['principals'],
        ]);
    }

    public function store(Request $request, PpraEmploymentLetterService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.manage'), 403);

        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId());
        abort_unless($agency, 422, 'No agency context.');

        $validated = $request->validate([
            'user_id'           => ['required', 'integer'],
            'principal_user_id' => ['nullable', 'integer'],
        ]);

        $agentIds = $this->scopedRoster($user, $agency->id)->pluck('id')->all();
        abort_unless(in_array($validated['user_id'], $agentIds, true), 403, 'That agent is not in your scope.');

        $agent = User::withoutGlobalScopes()->where('agency_id', $agency->id)->findOrFail($validated['user_id']);

        $missing = $service->missingFieldsFor($agent, $agency);
        if ($missing !== []) {
            return back()->withInput()->with('error', 'This agent is missing information needed before a letter can be started: '
                . implode('; ', array_column($missing, 'label')) . '.');
        }

        $letter = $service->create($agent, $user, $validated['principal_user_id'] ?? null);

        return redirect()->route('admin.ppra-employment-letters.show', $letter->id)
            ->with('success', 'Letter started for ' . $agent->name . ' — they will sign it themselves from My Portal.');
    }

    /**
     * The agent-picker's candidate list: every active FFC-holding practitioner
     * in this agency, whatever their role (PractitionerFfcRosterService::letterCandidatesFor()),
     * narrowed to the admin's own/branch/all scope — the SAME scope
     * ppra_employment_letters.view resolves for the list screen
     * (PermissionService::getDataScope), so an admin can never start a
     * letter for an agent outside the scope they'd otherwise see.
     *
     * @return Collection<int, array{id:int,name:string,role:string,designation:?string,ffc_number:?string,ffc:array}>
     */
    private function scopedRoster(User $admin, int $agencyId): Collection
    {
        $roster = app(PractitionerFfcRosterService::class)->letterCandidatesFor($agencyId);
        $scope  = PermissionService::getDataScope($admin, 'ppra_employment_letters');

        if ($scope === 'all') {
            return $roster;
        }
        if ($scope === 'own') {
            return $roster->filter(fn ($a) => $a['id'] === $admin->id)->values();
        }
        if ($scope === 'branch') {
            $branchIds = User::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereIn('id', $roster->pluck('id'))
                ->pluck('branch_id', 'id');

            $adminBranch = $admin->effectiveBranchId();

            return $roster->filter(fn ($a) => ($branchIds[$a['id']] ?? null) === $adminBranch)->values();
        }

        return collect();
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.view'), 403);
        $this->assertAdminScope($user);

        $showArchived = $request->boolean('archived');

        $query = PpraEmploymentLetter::query()
            ->visibleTo($user)
            ->with(['user', 'principal', 'branch']);

        if ($showArchived) {
            $query->onlyTrashed();
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('principal', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($agentId = $request->input('agent_id')) {
            $query->where('user_id', (int) $agentId);
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', (int) $branchId);
        }

        if ($year = $request->input('year')) {
            $query->whereYear('created_at', (int) $year);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $sort = $request->input('sort', 'created_desc');
        if ($sort === 'agent_name') {
            $query->select('ppra_employment_letters.*')
                ->join('users as sort_agent', 'sort_agent.id', '=', 'ppra_employment_letters.user_id')
                ->orderBy('sort_agent.name');
        } else {
            match ($sort) {
                'created_asc' => $query->orderBy('ppra_employment_letters.created_at'),
                'status'      => $query->orderBy('ppra_employment_letters.status'),
                default       => $query->orderByDesc('ppra_employment_letters.created_at'),
            };
        }

        $letters = $query->paginate(25)->withQueryString();

        $agencyId = $user->effectiveAgencyId();
        $branches = Branch::where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']);
        $years = PpraEmploymentLetter::query()->visibleTo($user)
            ->selectRaw('DISTINCT YEAR(created_at) as y')->orderByDesc('y')->pluck('y');

        return view('admin.ppra-employment-letters.index', [
            'letters'      => $letters,
            'branches'     => $branches,
            'years'        => $years,
            'filters'      => $request->only(['search', 'status', 'agent_id', 'branch_id', 'year', 'date_from', 'date_to', 'sort']),
            'showArchived' => $showArchived,
            'statuses'     => PpraEmploymentLetter::STATUSES,
        ]);
    }

    public function show(Request $request, int $letter)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.view'), 403);
        $this->assertAdminScope($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)
            ->with(['user', 'principal', 'branch', 'createdBy'])
            ->findOrFail($letter);

        return view('admin.ppra-employment-letters.show', ['letter' => $record]);
    }

    public function download(Request $request, int $letter, PpraEmploymentLetterPdfService $pdfService): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.view'), 403);
        $this->assertAdminScope($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)->findOrFail($letter);

        $filename = 'PPRA-Confirmation-of-Employment-' . $record->id . '.pdf';
        $inline = $request->boolean('inline', true); // the admin detail page always previews inline by default

        if ($record->isSigned() && $record->signed_pdf_path && Storage::exists($record->signed_pdf_path)) {
            return $inline
                ? Storage::response($record->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($record->signed_pdf_path, $filename);
        }

        $agency = \App\Models\Agency::withoutGlobalScopes()->find($record->agency_id);
        $pdfPath = $pdfService->generate($record, $record->user, $record->principal, $agency, $record->agent_signature_image, null);

        return response()->download($pdfPath, $filename, [], $inline ? 'inline' : 'attachment')->deleteFileAfterSend(true);
    }

    public function archive(Request $request, int $letter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.manage'), 403);
        $this->assertAdminScope($user);

        $record = PpraEmploymentLetter::visibleTo($user)->findOrFail($letter);
        $record->delete();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter archived.');
    }

    public function restore(Request $request, int $letter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.manage'), 403);
        $this->assertAdminScope($user);

        $record = PpraEmploymentLetter::onlyTrashed()->visibleTo($user)->findOrFail($letter);
        $record->restore();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter restored.');
    }
}
