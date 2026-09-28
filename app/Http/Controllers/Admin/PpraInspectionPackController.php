<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\PpraInspectionGapNote;
use App\Services\Compliance\PpraInspectionPackChecklistService;
use App\Services\Compliance\PpraInspectionReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PPRA Inspection Pack — Phase A. .ai/specs/ppra-inspection-pack.md
 * Pure Admin feature (Johan's ruling, 2026-09-28) — every action here is
 * gated ppra_inspection_pack.* (admin/super_admin only, see routes/web.php).
 */
class PpraInspectionPackController extends Controller
{
    public function __construct(
        private PpraInspectionPackChecklistService $checklist = new PpraInspectionPackChecklistService(),
        private PpraInspectionReportPdfService $reportPdf = new PpraInspectionReportPdfService(),
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

    private function resolveAgency(Request $request): Agency
    {
        $user = $request->user() ?? Auth::user();
        $agency = $user->agency ?? Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        return $agency;
    }
}
