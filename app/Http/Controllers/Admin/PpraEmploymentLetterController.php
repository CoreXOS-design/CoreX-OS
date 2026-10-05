<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * Creation is deliberately NOT exposed here — a letter is always
 * self-service, started by the agent it is about (My Portal), never
 * generated on their behalf by an admin (the PIN signing ceremony that
 * follows can only ever be completed by the real agent/principal anyway).
 * Admin's role is oversight: list, view, download, archive, restore.
 */
class PpraEmploymentLetterController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.view'), 403);

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

        $record = PpraEmploymentLetter::withTrashed()->visibleTo($user)
            ->with(['user', 'principal', 'branch', 'createdBy'])
            ->findOrFail($letter);

        return view('admin.ppra-employment-letters.show', ['letter' => $record]);
    }

    public function download(Request $request, int $letter, PpraEmploymentLetterPdfService $pdfService): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.view'), 403);

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

        $record = PpraEmploymentLetter::visibleTo($user)->findOrFail($letter);
        $record->delete();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter archived.');
    }

    public function restore(Request $request, int $letter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('ppra_employment_letters.manage'), 403);

        $record = PpraEmploymentLetter::onlyTrashed()->visibleTo($user)->findOrFail($letter);
        $record->restore();

        return redirect()->route('admin.ppra-employment-letters.index')->with('success', 'Letter restored.');
    }
}
