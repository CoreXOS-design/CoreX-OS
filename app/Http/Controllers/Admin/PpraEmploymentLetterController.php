<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Compliance\PpraEmploymentLetterFile;
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
 * Wet-ink flow (spec §20, 2026-10-06): there is no electronic signing. The letter is printed, signed on paper and the
 * signed copy is uploaded — from here or from the agent's My Portal; both go through
 * PpraEmploymentLetterService::attachSignedCopy() so it is ONE record on both screens.
 * Create-on-behalf (2026-10-05, Johan) lets an admin/principal START a letter for an agent
 * in their own scope; the agent-picker is bounded by the SAME own/branch/
 * all scope as the list (ppra_employment_letters.view's stored scope), and
 * the created letter runs through the identical missing-data validation and
 * status machine as PpraEmploymentLetterService::create() — there is no
 * second code path, only a different caller.
 *
 * Gate (2026-10-06): ONE rule for this entire register — the user holds
 * ppra_employment_letters.manage (Role Manager). The sidebar link, the
 * route middleware and assertAdminAccess() on every action below all
 * resolve through PpraEmploymentLetter::userCanUseAdminRegister(), so the
 * list can never open for someone New letter 403s. Everyone else keeps
 * using My Portal for their own letter. ppra_employment_letters.view's
 * own/branch/all value now only narrows WHICH letters a manager sees.
 */
class PpraEmploymentLetterController extends Controller
{
    /** Every action here is for users who manage letters for others — everyone else uses My Portal, never here, not even by direct URL. */
    private function assertAdminAccess(User $user): void
    {
        abort_unless(PpraEmploymentLetter::userCanUseAdminRegister($user), 403);
    }

    /** The scoped agent picker for create-on-behalf. */
    public function create(Request $request)
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

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
        $this->assertAdminAccess($user);

        $agency = Agency::withoutGlobalScopes()->find($user->effectiveAgencyId());
        abort_unless($agency, 422, 'No agency context.');

        $validated = $request->validate([
            'user_id'           => ['required', 'integer'],
            'principal_user_id' => ['nullable', 'integer'],
        ]);

        // A browser posts form values as strings and validate() returns them unchanged, while the roster ids are
        // integers — a strict comparison on the raw value 403'd every real form submit (tests post ints).
        $agentId     = (int) $validated['user_id'];
        $principalId = isset($validated['principal_user_id']) ? (int) $validated['principal_user_id'] : null;

        $agentIds = $this->scopedRoster($user, $agency->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(in_array($agentId, $agentIds, true), 403, 'That agent is not in your scope.');

        $agent = User::withoutGlobalScopes()->where('agency_id', $agency->id)->findOrFail($agentId);

        $missing = $service->missingFieldsFor($agent, $agency);
        if ($missing !== []) {
            return back()->withInput()->with('error', 'This agent is missing information needed before a letter can be started: '
                . implode('; ', array_column($missing, 'label')) . '.');
        }

        $letter = $service->create($agent, $user, $principalId);

        return redirect()->route('admin.ppra-employment-letters.show', $letter->id)
            ->with('success', 'Letter started for ' . $agent->name . ' — print it, have it signed, then upload the signed copy.');
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
        $this->assertAdminAccess($user);

        $showArchived = $request->boolean('archived');

        $query = PpraEmploymentLetter::query()
            ->visibleTo($user)
            ->with(['user', 'principal', 'branch', 'files', 'currentFile']);

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
            $query->withStatusGroup((string) $status);
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
            'statuses'     => PpraEmploymentLetter::FILTER_STATUSES,
        ]);
    }

    public function show(Request $request, int $letter)
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)
            ->with(['user', 'principal', 'branch', 'createdBy', 'files.uploader'])
            ->findOrFail($letter);

        return view('admin.ppra-employment-letters.show', ['letter' => $record, 'files' => $record->files]);
    }

    public function download(Request $request, int $letter, PpraEmploymentLetterPdfService $pdfService): Response
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)->findOrFail($letter);

        $filename = 'PPRA-Confirmation-of-Employment-' . $record->id . '.pdf';
        $inline = $request->boolean('inline', true); // the admin detail page always previews inline by default

        // LEGACY: a letter signed through the retired PIN ceremony keeps the PDF baked at the time, untouched.
        if ($record->isSigned() && $record->signed_pdf_path && Storage::exists($record->signed_pdf_path)) {
            return $inline
                ? Storage::response($record->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($record->signed_pdf_path, $filename);
        }

        $agency = \App\Models\Agency::withoutGlobalScopes()->find($record->agency_id);
        // Printed for wet-ink signing: signature lines stay empty.
        $pdfPath = $pdfService->generate($record, $record->user, $record->principal, $agency, null, null);

        return response()->download($pdfPath, $filename, [], $inline ? 'inline' : 'attachment')->deleteFileAfterSend(true);
    }

    /**
     * Upload the wet-ink signed copy of any letter in the manager's scope (spec §20). The letter is resolved through
     * visibleTo() — out of scope or another agency's letter is a 404; an archived letter takes no upload.
     */
    public function uploadSignedCopy(Request $request, int $letter, PpraEmploymentLetterService $service): RedirectResponse
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)->findOrFail($letter);

        if ($record->trashed()) {
            return back()->with('error', 'This letter is archived — restore it before uploading a signed copy.');
        }

        $request->validate([
            'signed_copy' => ['required', 'file', 'mimes:' . PpraEmploymentLetterService::UPLOAD_MIMES, 'max:' . PpraEmploymentLetterService::MAX_UPLOAD_KB],
        ], [
            'signed_copy.required' => 'Choose the signed letter to upload.',
            'signed_copy.mimes'    => 'The signed copy must be a PDF, JPG or PNG file.',
            'signed_copy.max'      => 'The signed copy must be 10 MB or smaller.',
            'signed_copy.uploaded' => 'The file could not be uploaded — it may be larger than 10 MB.',
        ]);

        $service->attachSignedCopy($record, $request->file('signed_copy'), $user, PpraEmploymentLetterFile::VIA_ADMIN);

        return back()->with('success', 'Signed copy uploaded and filed.');
    }

    /** Stream one signed copy (current or superseded) — manage + visibleTo(), then the shared streamer. */
    public function signedCopy(Request $request, int $letter, int $file, PpraEmploymentLetterService $service): Response
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)->findOrFail($letter);
        $scan   = $record->files()->whereKey($file)->firstOrFail();

        return $service->streamSignedCopy($scan, $request->boolean('inline'));
    }

    public function archive(Request $request, int $letter): RedirectResponse
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::visibleTo($user)->findOrFail($letter);
        $record->delete();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter archived.');
    }

    public function restore(Request $request, int $letter): RedirectResponse
    {
        $user = $request->user();
        $this->assertAdminAccess($user);

        $record = PpraEmploymentLetter::onlyTrashed()->visibleTo($user)->findOrFail($letter);
        $record->restore();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter restored.');
    }
}
