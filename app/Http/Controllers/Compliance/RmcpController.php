<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;
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

        return view('compliance.rmcp.show', compact('version', 'agency', 'variables'));
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

        return view('compliance.rmcp.approve', compact('version'));
    }

    /**
     * Process approval.
     */
    public function approve(Request $request, RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_rmcp'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be approved.');

        $validated = $request->validate([
            'approver_title'           => 'required|string|max:100',
            'board_approval_document'  => 'required|file|mimes:pdf|max:10240',
            'effective_from'           => 'required|date|after_or_equal:today',
            'next_review_due'          => 'required|date|after:effective_from',
            'approval_notes'           => 'nullable|string|max:2000',
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

        Log::info('RMCP version approved', [
            'version_id' => $version->id,
            'agency_id'  => $version->agency_id,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('compliance.rmcp.show', $version)
            ->with('success', "RMCP v{$version->version_number} approved and now active.");
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

        // Render as printable HTML view (Puppeteer handles PDF conversion server-side)
        return view('compliance.rmcp.show', [
            'version'   => $version,
            'agency'    => $agency,
            'variables' => $variables,
            'pdfMode'   => true,
        ]);
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
        $agencyFields = ['agency.name', 'agency.trading_name', 'agency.reg_no', 'agency.vat_no', 'agency.ffc_no', 'agency.fic_no', 'agency.address', 'agency.phone', 'agency.email'];
        $coFields = ['compliance_officer.full_name', 'compliance_officer.id_number', 'compliance_officer.cell', 'compliance_officer.email', 'compliance_officer.title', 'compliance_officer.appointed_on'];
        $computedFields = ['today.date', 'today.year', 'rmcp.version_number', 'rmcp.approved_on', 'rmcp.effective_from', 'rmcp.next_review_due'];

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
                'editable'  => $source === 'manual',
                'db_id'     => $manualVar ? $manualVar->id : null,
            ];
        }

        return view('compliance.rmcp.variables', compact('variableList', 'agency'));
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
