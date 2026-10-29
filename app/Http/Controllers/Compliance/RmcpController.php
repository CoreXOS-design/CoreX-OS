<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\CompliancePdf;
use App\Services\Compliance\RmcpBoardApprovalService;
use App\Services\Compliance\RmcpReSignService;
use App\Services\Compliance\RmcpVariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RmcpController extends Controller
{
    protected RmcpVariableResolver $resolver;

    public function __construct(RmcpVariableResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * List RMCP versions for current agency.
     */
    public function index(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('access_rmcp'), 403);

        $query = RmcpVersion::with(['approver', 'creator'])
            ->orderByDesc('version_number');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('version_number', 'like', "%{$search}%");
            });
        }

        $sort = $request->query('sort', 'version_number');
        $direction = $request->query('direction', 'desc');
        $allowed = ['version_number', 'status', 'approved_at', 'effective_from', 'next_review_due'];
        if (in_array($sort, $allowed)) {
            $query->reorder($sort, $direction);
        }

        $versions = $query->paginate(20)->withQueryString();

        return view('compliance.rmcp.index', compact('versions', 'sort', 'direction'));
    }

    /**
     * Show rendered RMCP.
     */
    public function show(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_rmcp'), 403);

        $version->load('sections', 'approver', 'creator');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        // Board approval panel (draft: send / track; approved: the signed letter it was approved with). Only for people who
        // manage or approve the RMCP - everyone else with access_rmcp sees the RMCP as before.
        $board = Auth::user()->hasAnyPermission(['manage_rmcp_board_approval', 'approve_rmcp'])
            ? app(RmcpBoardApprovalService::class)->panel($version)
            : null;
        $myBoardLine = $board && $version->canBeEdited()
            ? app(RmcpBoardApprovalService::class)->openLineForUser($version, Auth::user())
            : null;

        // Signed copy + officer sign-off (everyone who can view sees it) and the staff-training button (approvers only).
        $signedCopies  = app(\App\Services\Compliance\SignedCopyService::class);
        $signed        = $signedCopies->describe($version);
        $canManageSigned = $signedCopies->canManage(Auth::user(), (int) $version->agency_id);
        $canAttachSigned = $signedCopies->canAttachTo($version);
        $training      = app(\App\Services\Compliance\StaffTrainingService::class);
        $trainingCounts = $version->status === 'active' ? $training->counts($version) : null;
        $canSendTraining = $trainingCounts !== null && Auth::user()->hasPermission('approve_rmcp');

        return view('compliance.rmcp.show', compact(
            'version', 'agency', 'variables', 'board', 'myBoardLine',
            'signed', 'canManageSigned', 'canAttachSigned', 'trainingCounts', 'canSendTraining'
        ));
    }

    /**
     * Create new draft version (clone latest or HFC template).
     */
    public function create()
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);

        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();

        // Get latest version number for this agency
        $latestVersion = RmcpVersion::where('agency_id', $agencyId)
            ->max('version_number') ?? 0;

        $newVersionNumber = $latestVersion + 1;

        // Find the source to clone from
        $source = RmcpVersion::where('agency_id', $agencyId)
            ->orderByDesc('version_number')
            ->first();

        $version = RmcpVersion::create([
            'agency_id'      => $agencyId,
            'version_number' => $newVersionNumber,
            'title'          => $source->title ?? 'Risk Management and Compliance Programme',
            'status'         => 'draft',
            'created_by'     => $user->id,
            'change_notes'   => "Cloned from v{$latestVersion}",
        ]);

        // Clone sections from source
        if ($source) {
            foreach ($source->sections as $section) {
                RmcpSection::create([
                    'rmcp_version_id'          => $version->id,
                    'section_type'             => $section->section_type,
                    'display_order'            => $section->display_order,
                    'section_number'           => $section->section_number,
                    'title'                    => $section->title,
                    'body_html'                => $section->body_html,
                    'requires_acknowledgement' => $section->requires_acknowledgement,
                    'acknowledgement_prompt'   => $section->acknowledgement_prompt,
                ]);
            }
        }

        return redirect()->route('compliance.rmcp.edit', $version)
            ->with('success', "RMCP v{$newVersionNumber} draft created.");
    }

    /**
     * Edit draft version — section-by-section editor.
     */
    public function edit(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        $version->load('sections');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        // Get all available variable keys for the sidebar reference
        $variableKeys = array_keys($variables);

        return view('compliance.rmcp.edit', compact('version', 'agency', 'variables', 'variableKeys'));
    }

    /**
     * Update a section (AJAX per STANDARDS Rule 2).
     */
    public function update(Request $request, RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        $validated = $request->validate([
            'section_id' => 'required|exists:rmcp_sections,id',
            'title'      => 'required|string|max:500',
            'body_html'  => 'required|string',
        ]);

        $section = RmcpSection::where('rmcp_version_id', $version->id)
            ->findOrFail($validated['section_id']);

        $section->update([
            'title'     => $validated['title'],
            'body_html' => $validated['body_html'],
        ]);

        return response()->json(['success' => true, 'message' => 'Section saved.']);
    }

    /**
     * Show approval form.
     */
    public function approveForm(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_rmcp'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be approved.');

        $check = app(RmcpBoardApprovalService::class)->problemFor($version);
        $boardLetter = $check['letter'];
        $boardProblem = $check['problem'];
        $boardFacts = $boardLetter ? app(RmcpBoardApprovalService::class)->approvalFacts($boardLetter) : null;

        return view('compliance.rmcp.approve', compact('version', 'boardLetter', 'boardProblem', 'boardFacts'));
    }

    /**
     * Process approval.
     */
    public function approve(Request $request, RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_rmcp'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be approved.');

        $board = app(RmcpBoardApprovalService::class);

        // Two ways to show the board approved: the letter the board signed in CoreX, or a signed PDF uploaded as before.
        // The button on the form decides; neither one silently stands in for the other.
        if ($request->boolean('use_board_letter')) {
            $letter = $board->usableLetter($version);
            if (! $letter) {
                $problem = $board->problemFor($version)['problem'];

                return redirect()->to(route('compliance.rmcp.show', $version) . '#board-approval')
                    ->with('error', 'The signed board letter cannot be used yet. ' . $problem);
            }

            $validated = $request->validate(['approval_notes' => 'nullable|string|max:2000']);
            $facts = $board->approvalFacts($letter);

            $version->update([
                'effective_from'  => $letter->effective_from,
                'next_review_due' => $letter->next_review_due ?? $letter->effective_from->copy()->addYear(),
            ]);
            $version->approve(Auth::user(), $facts['titles'] ?: 'Board', $letter->signed_pdf_path, $validated['approval_notes'] ?? null, $letter->id);
            $board->recordApproval($version, $letter, Auth::user(), 'board_letter');
        } else {
            $validated = $request->validate([
                'approver_title'           => 'required|string|max:100',
                'board_approval_document'  => 'required|file|mimes:pdf|max:10240',
                'effective_from'           => 'required|date|after_or_equal:today',
                'next_review_due'          => 'required|date|after:effective_from',
                'approval_notes'           => 'nullable|string|max:2000',
            ], [
                'board_approval_document.required' => 'Add the signed board approval as a PDF, or have the board sign the letter in CoreX from the Board approval panel on the RMCP page.',
            ]);

            // Upload board approval document
            $documentPath = $request->file('board_approval_document')
                ->store("rmcp/{$version->agency_id}", 'local');

            // Update lifecycle dates
            $version->update([
                'effective_from'  => $validated['effective_from'],
                'next_review_due' => $validated['next_review_due'],
            ]);

            // Approve (supersedes previous active version)
            $version->approve(
                Auth::user(),
                $validated['approver_title'],
                $documentPath,
                $validated['approval_notes'] ?? null
            );
            $board->recordApproval($version, null, Auth::user(), 'uploaded_document');
        }

        Log::info('RMCP version approved', [
            'version_id' => $version->id,
            'agency_id'  => $version->agency_id,
            'user_id'    => Auth::id(),
        ]);

        // AT-506 — staff must re-sign the new version: the earlier acknowledgements are marked superseded and every
        // active staff member is told in-app. A failure here never undoes the approval (the service logs it).
        $resign = app(RmcpReSignService::class)->afterApproval($version->fresh(), Auth::user());

        return redirect()->route('compliance.rmcp.show', $version)
            ->with('success', "RMCP v{$version->version_number} approved and now active. {$resign['notified']} staff member(s) have been asked to acknowledge it.");
    }

    /**
     * Download PDF of rendered RMCP.
     */
    public function downloadPdf(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_rmcp'), 403);

        $version->load('sections', 'approver');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        // Cover line of the approved text — "… · FIC registration number … · PPRA FFC …" — under the agency name already on the cover.
        $coverLine = implode(' · ', array_filter([
            ($variables['agency.fic_no'] ?? '') !== '' ? 'FIC registration number ' . $variables['agency.fic_no'] : null,
            ($variables['agency.ffc_no'] ?? '') !== '' ? 'PPRA FFC ' . $variables['agency.ffc_no'] : null,
        ]));

        $pdf   = app(CompliancePdf::class);
        $title = 'Risk Management & Compliance Programme';
        $html  = $pdf->versionHtml(
            'Financial Intelligence Centre Act 38 of 2001',
            'Prepared in terms of Section 42 of the FIC Act',
            $version,
            $agency,
            $variables,
            $version->title ?: $title,
            $coverLine,
        );
        // Footer of every page: "<agency> · RMCP v2 · page x of y · Initials ____" (AT-506).
        $bytes = $pdf->render(
            $html,
            ($variables['agency.name'] ?? $agency->name) . ' · RMCP v' . $version->version_number,
            'rmcp-' . $version->id,
            'page',
            'Initials ____'
        );

        $response = $pdf->download($bytes, CompliancePdf::fileName(
            'rmcp', $version->version_number, $agency->name,
            ($version->approved_at ?? $version->created_at)?->format('Y-m-d'), (string) $version->status
        ));

        return $response ?? redirect()->route('compliance.rmcp.show', $version)
            ->with('error', 'The PDF could not be created just now. Please try again; if it keeps failing, tell your administrator.');
    }

    /**
     * Variables management page.
     */
    public function variables()
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();
        $agency = Agency::findOrFail($agencyId);

        $variables = $this->resolver->resolve($agency);

        // Get manual variables from DB
        $manualVars = RmcpVariable::where('agency_id', $agencyId)->get();

        // Build display list with source info
        $variableList = [];
        $agencyFields = ['agency.name', 'agency.trading_name', 'agency.reg_no', 'agency.vat_no', 'agency.ffc_no', 'agency.ppra_number', 'agency.fic_no', 'agency.address', 'agency.phone', 'agency.email'];
        $coFields = ['compliance_officer.full_name', 'compliance_officer.id_number', 'compliance_officer.cell', 'compliance_officer.email', 'compliance_officer.title', 'compliance_officer.appointed_on'];
        $computedFields = RmcpVariableResolver::COMPUTED_KEYS;

        foreach ($variables as $key => $value) {
            $source = 'manual';
            if (in_array($key, $agencyFields)) {
                $source = 'agency_column';
            } elseif (in_array($key, $coFields)) {
                $source = 'compliance_officer_column';
            } elseif (in_array($key, $computedFields)) {
                $source = 'computed';
            }

            $manualVar = $manualVars->firstWhere('variable_key', $key);

            $variableList[] = [
                'key'       => $key,
                'value'     => $value,
                'source'    => $source,
                // A computed value (e.g. the lease count) can be overridden: it is editable once its override row exists.
                'editable'  => $source === 'manual' || ($source === 'computed' && $manualVar !== null),
                'db_id'     => $manualVar ? $manualVar->id : null,
            ];
        }

        return view('compliance.rmcp.variables', compact('variableList', 'agency'));
    }

    /**
     * AT-506 — add a variable (or an override of a computed one) for this agency. The key is group.name in lowercase;
     * adding a key that already has a manual row updates that row instead of duplicating it.
     */
    public function storeVariable(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);

        $agencyId = Auth::user()->effectiveAgencyId();
        abort_unless($agencyId, 403, 'No agency context.');

        $validated = $request->validate([
            'variable_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)+$/'],
            'value'        => 'nullable|string|max:2000',
        ], [
            'variable_key.regex' => 'Use a key like profile.active_leases — lowercase letters, digits and underscores, with a dot between the parts.',
        ], ['variable_key' => 'key']);

        RmcpVariable::updateOrCreate(
            ['agency_id' => $agencyId, 'variable_key' => $validated['variable_key']],
            ['value' => $validated['value'] ?? '', 'data_source' => 'manual']
        );

        return redirect()->route('compliance.rmcp.variables')
            ->with('success', 'Variable {{' . $validated['variable_key'] . '}} saved.');
    }

    /**
     * Update a manual variable (AJAX).
     */
    public function updateVariable(Request $request, RmcpVariable $variable)
    {
        abort_unless(Auth::user()->hasPermission('edit_rmcp'), 403);

        $validated = $request->validate([
            'value' => 'nullable|string|max:2000',
        ]);

        $variable->update(['value' => $validated['value']]);

        return response()->json(['success' => true, 'message' => 'Variable updated.']);
    }
}
