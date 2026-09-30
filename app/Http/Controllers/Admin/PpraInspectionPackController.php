<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePpraInspectionPackJob;
use App\Models\Agency;
use App\Models\Compliance\PpraInspectionGapNote;
use App\Models\Compliance\PpraInspectionPack;
use App\Services\Compliance\PpraFinancialYearListService;
use App\Services\Compliance\PpraInspectionPackChecklistService;
use App\Services\Compliance\PpraInspectionReportPdfService;
use App\Services\Compliance\PpraLetterheadSampleService;
use App\Services\Compliance\PpraMandateRegisterService;
use App\Services\Compliance\PpraPractitionerRegisterPdfService;
use App\Services\Compliance\PpraSalesRentalsPdfService;
use App\Services\Compliance\PractitionerFfcRosterService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use ZipArchive;

/**
 * PPRA Inspection Pack — Phase A + B + C + D + E. .ai/specs/ppra-inspection-pack.md
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
        private PpraFinancialYearListService $fyList = new PpraFinancialYearListService(),
        private PpraSalesRentalsPdfService $salesRentalsPdfSvc = new PpraSalesRentalsPdfService(),
        private PpraMandateRegisterService $mandateRegister = new PpraMandateRegisterService(),
    ) {
    }

    public function index(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $rows = $this->checklist->checklistFor($agency);
        $latestPack = PpraInspectionPack::where('agency_id', $agency->id)->latest('created_at')->first();

        // A job killed mid-run (timeout/OOM/deploy) never writes 'failed' — treat a
        // 'generating' pack that has outlived the job timeout as failed so it can be regenerated.
        if ($latestPack && $latestPack->status === 'generating' && $latestPack->updated_at && $latestPack->updated_at->lt(now()->subMinutes(20))) {
            $latestPack->update(['status' => 'failed', 'error_message' => 'Generation did not finish (timed out or was interrupted). Please regenerate.']);
        }

        return view('admin.ppra-inspection-pack.index', compact('agency', 'rows', 'latestPack'));
    }

    /**
     * "Download full inspection pack" (§6.9) — queues GeneratePpraInspectionPackJob
     * and returns immediately; the checklist page polls/shows "Generating…".
     * Regeneration contract: if sample_*_ids are already set on the current
     * draft (from a prior generation or a picker visit), the job reuses them
     * without reopening the picker.
     */
    public function generate(Request $request)
    {
        $agency = $this->resolveAgency($request);
        $user = $request->user();

        // currentDraftFor() only ever returns a queued/generated_at=null row
        // — reuse it as-is if one exists. Otherwise this is a REGENERATION
        // (the most recent pack already reached ready/failed): a fresh row
        // still carries forward that pack's sample ids, per the
        // regeneration contract (§6.9) — never silently drops a saved
        // picker selection just because the pack it was saved on finished.
        $draft = PpraInspectionPack::currentDraftFor($agency);
        if ($draft) {
            $pack = $draft;
        } else {
            $previous = PpraInspectionPack::where('agency_id', $agency->id)->latest('created_at')->first();
            $pack = PpraInspectionPack::create([
                'agency_id'            => $agency->id,
                'requested_by_user_id' => $user->id,
                'status'               => 'queued',
                'sample_deal_ids'      => $previous?->sample_deal_ids,
                'sample_rental_ids'    => $previous?->sample_rental_ids,
                'sample_listing_ids'   => $previous?->sample_listing_ids,
            ]);
        }

        GeneratePpraInspectionPackJob::dispatch($pack->id);

        return redirect()->route('admin.ppra-inspection-pack.index')
            ->with('success', 'Generating your PPRA inspection pack — you\'ll be notified when it\'s ready.');
    }

    /**
     * Download a finished pack's ZIP. Agency-scope re-checked (mirrors
     * AgencyDocumentsViewerController::download()'s multi-tenant check) AND
     * ppra_inspection_pack.generate re-checked — no public/unauthenticated
     * share link (§6.9).
     */
    public function download(Request $request, PpraInspectionPack $pack)
    {
        $agency = $this->resolveAgency($request);
        abort_unless($pack->agency_id === $agency->id, 403);
        abort_unless($pack->status === 'ready' && $pack->zip_path, 404);
        abort_unless(is_file($pack->zip_path), 404);

        return response()->download($pack->zip_path, 'PPRA-Inspection-Pack-' . $pack->id . '.zip');
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
            'assigned_to_user_id'  => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('agency_id', $agency->id)],
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
     * (users.is_principal_practitioner, via PractitionerFfcRosterService::principalsFor() — Phase E).
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

    /**
     * Item (j) — current FY sales/rentals list, "active and advertised". §6.7.
     * FY-bound by default (agencies.financial_year_start_month); a custom
     * date_from/date_to pair overrides it for this view only (not persisted).
     */
    public function salesRentals(Request $request)
    {
        $agency = $this->resolveAgency($request);

        [$from, $to] = $this->fyList->resolveRange($agency, $request->input('date_from'), $request->input('date_to'));

        $sales = $this->fyList->advertisedListings($agency, 'sale', $from, $to);
        $rentals = $this->fyList->advertisedListings($agency, 'rental', $from, $to);

        if ($search = $request->input('search')) {
            $needle = strtolower($search);
            $filter = fn ($p) => str_contains(strtolower($p->address ?? ''), $needle);
            $sales = $sales->filter($filter)->values();
            $rentals = $rentals->filter($filter)->values();
        }

        $perPage = 25;
        $salesCurrentPage = (int) $request->input('sales_page', 1);
        $rentalsCurrentPage = (int) $request->input('rentals_page', 1);
        $salesPage = new LengthAwarePaginator($sales->forPage($salesCurrentPage, $perPage)->values(), $sales->count(), $perPage, $salesCurrentPage, ['pageName' => 'sales_page', 'path' => $request->url(), 'query' => $request->query()]);
        $rentalsPage = new LengthAwarePaginator($rentals->forPage($rentalsCurrentPage, $perPage)->values(), $rentals->count(), $perPage, $rentalsCurrentPage, ['pageName' => 'rentals_page', 'path' => $request->url(), 'query' => $request->query()]);

        $rangeLabel = $this->fyList->rangeLabel($from, $to);
        $isCustomRange = (bool) ($request->input('date_from') && $request->input('date_to'));

        return view('admin.ppra-inspection-pack.sales-rentals', compact('agency', 'salesPage', 'rentalsPage', 'rangeLabel', 'isCustomRange', 'from', 'to'));
    }

    public function salesRentalsPdf(Request $request)
    {
        $agency = $this->resolveAgency($request);
        [$from, $to] = $this->fyList->resolveRange($agency, $request->input('date_from'), $request->input('date_to'));

        $sales = $this->fyList->advertisedListings($agency, 'sale', $from, $to);
        $rentals = $this->fyList->advertisedListings($agency, 'rental', $from, $to);

        $pdfPath = $this->salesRentalsPdfSvc->generate($agency, $sales, $rentals, $this->fyList->rangeLabel($from, $to));

        return response()->download($pdfPath, basename($pdfPath))->deleteFileAfterSend(true);
    }

    public function salesRentalsCsv(Request $request)
    {
        $agency = $this->resolveAgency($request);
        [$from, $to] = $this->fyList->resolveRange($agency, $request->input('date_from'), $request->input('date_to'));

        $sales = $this->fyList->advertisedListings($agency, 'sale', $from, $to);
        $rentals = $this->fyList->advertisedListings($agency, 'rental', $from, $to);

        $filename = 'sales-rentals-' . now()->format('Ymd-His') . '.csv';

        $callback = function () use ($sales, $rentals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Address', 'Suburb', 'Listed Date']);
            foreach ($sales as $p) {
                fputcsv($out, ['Sale', $p->address, $p->suburb ?? '', optional($p->listed_date)->format('Y-m-d') ?? '']);
            }
            foreach ($rentals as $p) {
                fputcsv($out, ['Rental', $p->address, $p->suburb ?? '', optional($p->listed_date)->format('Y-m-d') ?? '']);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Item m — mandate/MDF/FICA register (§6.8e). Full CRUD-list floor:
     * search/sort/filter/pagination over every active advertised listing.
     * Distinct from the pack's sampled item (m) evidence (§6.8d) — this is
     * the gap-monitoring feed that drives the checklist's live status.
     */
    public function mandateRegister(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $filters = $request->only(['search', 'branch_id', 'agent_id', 'date_from', 'date_to', 'status', 'sort']);
        if ($request->has('advertised_only')) {
            $filters['advertised_only'] = $request->input('advertised_only');
        }

        $page = (int) $request->input('page', 1);
        $rows = $this->mandateRegister->search($agency, $filters, $page, 25);

        $branches = \App\Models\Branch::where('agency_id', $agency->id)->orderBy('name')->get(['id', 'name']);

        return view('admin.ppra-inspection-pack.mandate-register', compact('agency', 'rows', 'branches', 'filters'));
    }

    /**
     * "Download ZIP of all mandates + MDFs" (§6.8e) — bulk export of the
     * CURRENT filtered view, capped at the agency's configured max-files.
     * Distinct artifact from the pack's item (m) sample — never conflated.
     */
    public function mandateRegisterZip(Request $request)
    {
        $agency = $this->resolveAgency($request);

        $filters = $request->only(['search', 'branch_id', 'agent_id', 'date_from', 'date_to', 'status']);
        if ($request->has('advertised_only')) {
            $filters['advertised_only'] = $request->input('advertised_only');
        }

        $result = $this->mandateRegister->mandateMdfDocumentsForZip($agency, $filters);

        if ($result['documents']->isEmpty()) {
            return redirect()->back()->with('error', 'No mandate or MDF files match the current filters — nothing to download.');
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $zipPath = $tempDir . '/mandate-register-' . $agency->id . '-' . now()->format('Ymd-His') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return redirect()->back()->with('error', 'Could not create the ZIP file.');
        }
        $usedNames = [];
        $added = 0;
        foreach ($result['documents'] as $document) {
            $diskPath = \Illuminate\Support\Facades\Storage::disk($document->disk ?: 'local')->path($document->storage_path);
            if (! is_file($diskPath)) {
                continue;
            }
            $name = basename(str_replace('\\', '/', (string) ($document->original_name ?: $diskPath))) ?: 'file';
            $suffix = 1;
            $unique = $name;
            while (in_array($unique, $usedNames, true)) {
                $unique = pathinfo($name, PATHINFO_FILENAME) . "-{$suffix}." . pathinfo($name, PATHINFO_EXTENSION);
                $suffix++;
            }
            $usedNames[] = $unique;
            $zip->addFile($diskPath, $unique);
            $added++;
        }

        if ($added === 0) {
            $zip->close();
            @unlink($zipPath);

            return redirect()->back()->with('error', 'None of the matching files are available on disk — nothing to download.');
        }

        if (! $zip->close() || ! is_file($zipPath)) {
            return redirect()->back()->with('error', 'Could not finalise the ZIP file.');
        }

        $message = $result['capped']
            ? "Included {$result['documents']->count()} of {$result['total_available']} available files (capped at the agency's configured max-files-per-ZIP)."
            : "Included all {$result['documents']->count()} matching files.";

        return response()->download($zipPath, basename($zipPath))->deleteFileAfterSend(true)
            ->header('X-Mandate-Register-Zip-Summary', $message);
    }

    private function resolveAgency(Request $request): Agency
    {
        $user = $request->user() ?? Auth::user();
        $agency = Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        return $agency;
    }
}
