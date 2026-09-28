<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\PpraInspectionGapNote;
use App\Services\Compliance\PpraInspectionPackChecklistService;
use App\Services\Compliance\PpraInspectionReportPdfService;
use App\Services\Compliance\PpraLetterheadSampleService;
use App\Services\Compliance\PpraPractitionerRegisterPdfService;
use App\Services\Compliance\PractitionerFfcRosterService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * PPRA Inspection Pack — Phase A + B + C. .ai/specs/ppra-inspection-pack.md
 * Pure Admin feature (Johan's ruling, 2026-09-28) — every action here is
 * gated ppra_inspection_pack.* (admin/super_admin only, see routes/web.php).
 */
class PpraInspectionPackController extends Controller
{
    public function __construct(
        private PpraInspectionPackChecklistService $checklist = new PpraInspectionPackChecklistService(),
        private PpraInspectionReportPdfService $reportPdf = new PpraInspectionReportPdfService(),
        private PractitionerFfcRosterService $practitionerRoster = new PractitionerFfcRosterService(),
        private PpraLetterheadSampleService $letterheadPdf = new PpraLetterheadSampleService(),
        private PpraPractitionerRegisterPdfService $practitionerPdf = new PpraPractitionerRegisterPdfService(),
    ) {
    }

    public function index(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $rows = $this->checklist->checklistFor($agency);

        return view('admin.ppra-inspection-pack.index', compact('agency', 'rows'));
    }

    /**
     * Preview / download the Inspection Report — synchronous, always live.
     */
    public function report(Request $request)
    {
        $agency = $this->resolveAgency($request);
        $user = $request->user();

        $pdfPath = $this->reportPdf->generate($agency, $user);

        $filename = basename($pdfPath);

        return response()->download($pdfPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Set (or update) the remediation date/note for an open checklist item.
     * Editing creates a NEW row (versioned — §4.4), never mutates one in place.
     */
    public function storeGapNote(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $validated = $request->validate([
            'checklist_item_slug'  => 'required|string|in:a,b,c,d,e,f,g,h,i,j,k,l,m',
            'remediation_due_date' => 'nullable|date',
            'note'                 => 'nullable|string|max:2000',
            'assigned_to_user_id'  => 'nullable|integer|exists:users,id',
        ]);

        $user = $request->user();

        // Superseding a prior open note for the same item: resolve it first
        // so "current" (§4.4) stays unambiguous — never two open notes for
        // the same agency+item at once.
        PpraInspectionGapNote::where('agency_id', $agency->id)
            ->forItem($validated['checklist_item_slug'])
            ->open()
            ->update(['resolved_at' => now()]);

        PpraInspectionGapNote::create([
            'agency_id'             => $agency->id,
            'checklist_item_slug'   => $validated['checklist_item_slug'],
            'remediation_due_date'  => $validated['remediation_due_date'] ?? null,
            'note'                  => $validated['note'] ?? null,
            'assigned_to_user_id'   => $validated['assigned_to_user_id'] ?? null,
            'created_by_user_id'    => $user->id,
        ]);

        return redirect()->route('admin.ppra-inspection-pack.index')
            ->with('success', 'Remediation note saved.');
    }

    /** Manually mark a gap note resolved before its underlying item actually clears. */
    public function resolveGapNote(Request $request, PpraInspectionGapNote $gapNote)
    {
        $agency = $this->resolveAgency($request);
        abort_unless($gapNote->agency_id === $agency->id, 403);

        $gapNote->update(['resolved_at' => now()]);

        return redirect()->route('admin.ppra-inspection-pack.index')
            ->with('success', 'Marked resolved.');
    }

    /** Archive (soft-delete) a gap note added in error. */
    public function destroyGapNote(Request $request, PpraInspectionGapNote $gapNote)
    {
        $agency = $this->resolveAgency($request);
        abort_unless($gapNote->agency_id === $agency->id, 403);

        $gapNote->delete();

        return redirect()->route('admin.ppra-inspection-pack.index')
            ->with('success', 'Remediation note archived.');
    }

    /**
     * The Remediation Log — full CRUD-list floor (§4.4): search, sort,
     * filter, pagination over every gap note (open, resolved, and archived
     * history is reachable via the resolved/overdue filters; soft-deleted
     * rows stay out of the default list, matching every other archive
     * pattern in CoreX).
     */
    public function remediationLog(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $query = PpraInspectionGapNote::with(['assignedTo', 'createdBy'])
            ->where('agency_id', $agency->id);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('checklist_item_slug', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        if ($request->input('status') === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($request->input('status') === 'resolved') {
            $query->whereNotNull('resolved_at');
        } elseif ($request->input('status') === 'overdue') {
            $query->whereNull('resolved_at')
                ->whereNotNull('remediation_due_date')
                ->where('remediation_due_date', '<', now()->toDateString());
        }

        $sort = $request->input('sort', 'due_date');
        match ($sort) {
            'created' => $query->orderByDesc('created_at'),
            default   => $query->orderByRaw('remediation_due_date IS NULL')->orderBy('remediation_due_date'),
        };

        $notes = $query->paginate(25)->withQueryString();

        return view('admin.ppra-inspection-pack.remediation-log', compact('agency', 'notes'));
    }

    /**
     * Practitioner FFC register — full CRUD-list floor (§6.6). Item (f) is
     * this screen unfiltered; item (c) is this screen with ?principal=1
     * (§6.6a's designation-LIKE match, via PractitionerFfcRosterService::principalsFor()).
     */
    public function practitioners(Request $request)
    {
        $agency = $this->resolveAgency($request);
        $principalOnly = $request->boolean('principal');

        $roster = $principalOnly
            ? $this->practitionerRoster->principalsFor($agency->id)
            : $this->practitionerRoster->rosterFor($agency->id);

        if ($search = $request->input('search')) {
            $needle = strtolower($search);
            $roster = $roster->filter(fn (array $row) => str_contains(strtolower($row['name']), $needle)
                || str_contains(strtolower($row['designation'] ?? ''), $needle));
        }

        if ($role = $request->input('role')) {
            $roster = $roster->filter(fn (array $row) => $row['role'] === $role);
        }

        if ($statusFilter = $request->input('status')) {
            $roster = $roster->filter(fn (array $row) => $row['ffc']['status'] === $statusFilter);
        }

        $rank = ['red' => 0, 'amber' => 1, 'green' => 2];
        $roster = $request->input('sort') === 'name'
            ? $roster->sortBy(fn (array $row) => strtolower($row['name']))->values()
            : $roster->sortBy([
                fn (array $a, array $b) => ($rank[$a['ffc']['status']] ?? 3) <=> ($rank[$b['ffc']['status']] ?? 3),
                fn (array $a, array $b) => strcasecmp($a['name'], $b['name']),
            ])->values();

        $page = (int) $request->input('page', 1);
        $perPage = 25;
        $roster = new LengthAwarePaginator(
            $roster->forPage($page, $perPage)->values(),
            $roster->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.ppra-inspection-pack.practitioners', compact('agency', 'roster', 'principalOnly'));
    }

    /**
     * Item f — practitioner FFC register export (full roster). §6.6.
     */
    public function practitionerRegisterPdf(Request $request)
    {
        $agency = $this->resolveAgency($request);
        $roster = $this->practitionerRoster->rosterFor($agency->id);

        $pdfPath = $this->practitionerPdf->generate($agency, $roster);

        return response()->download($pdfPath, basename($pdfPath))->deleteFileAfterSend(true);
    }

    public function practitionerRegisterCsv(Request $request)
    {
        $agency = $this->resolveAgency($request);
        $roster = $this->practitionerRoster->rosterFor($agency->id);

        $filename = 'practitioner-register-' . now()->format('Ymd-His') . '.csv';

        $callback = function () use ($roster) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Role', 'Designation', 'FFC Number', 'Status', 'Expiry Date']);
            foreach ($roster as $agent) {
                fputcsv($out, [
                    $agent['name'],
                    ucwords(str_replace('_', ' ', $agent['role'])),
                    $agent['designation'] ?? '',
                    $agent['ffc_number'] ?? '',
                    ucfirst($agent['ffc']['status']),
                    $agent['ffc']['expiry_date'] ?? '',
                ]);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Item g — plain letterhead PDF (v3: no "SAMPLE" watermark/demo copy). §6.6b.
     */
    public function letterhead(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $pdfPath = $this->letterheadPdf->generate($agency);

        return response()->download($pdfPath, basename($pdfPath))->deleteFileAfterSend(true);
    }

    private function resolveAgency(Request $request): Agency
    {
        $user = $request->user() ?? Auth::user();
        $agency = $user->agency ?? Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        return $agency;
    }
}
