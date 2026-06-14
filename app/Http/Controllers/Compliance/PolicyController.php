<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicySection;
use App\Models\Compliance\PolicyVersion;
use App\Services\Compliance\PolicyVariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Policy authoring + versioning (AT-29). Mirrors RmcpController, generalised
 * across agency_policies. NOTE: no seed/publish gate is bypassed here — this
 * only creates DRAFT policies/versions; approve() is a deliberate human step.
 */
class PolicyController extends Controller
{
    protected PolicyVariableResolver $resolver;

    public function __construct(PolicyVariableResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * List policies (each with its versions) for the current agency.
     */
    public function index()
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        $policies = AgencyPolicy::with(['versions' => fn($q) => $q->orderByDesc('version_number')])
            ->orderBy('name')
            ->get();

        return view('compliance.policy.index', compact('policies'));
    }

    /**
     * Form to create a new policy.
     */
    public function createPolicy()
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);

        return view('compliance.policy.create');
    }

    /**
     * Store a new policy + its first draft version (with starter sections).
     */
    public function storePolicy(Request $request)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);

        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'policy_key'  => 'required|string|max:64|regex:/^[a-z0-9_]+$/',
            'description' => 'nullable|string|max:1000',
        ], [
            'policy_key.regex' => 'The policy key may only contain lowercase letters, numbers and underscores.',
        ]);

        $exists = AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $validated['policy_key'])
            ->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['policy_key' => 'A policy with this key already exists.']);
        }

        $policy = AgencyPolicy::create([
            'agency_id'   => $agencyId,
            'policy_key'  => $validated['policy_key'],
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => true,
        ]);

        $version = PolicyVersion::create([
            'agency_id'      => $agencyId,
            'policy_id'      => $policy->id,
            'version_number' => 1,
            'title'          => $validated['name'],
            'status'         => 'draft',
            'created_by'     => $user->id,
        ]);

        // Starter sections so the version is editable and signable.
        PolicySection::create([
            'agency_id'                => $agencyId,
            'policy_version_id'        => $version->id,
            'section_type'             => 'section',
            'display_order'            => 1,
            'section_number'           => '1',
            'title'                    => 'Introduction',
            'body_html'                => '<p>Replace this with the first section of the policy.</p>',
            'requires_acknowledgement' => true,
            'acknowledgement_prompt'   => 'I have read and understood this section',
        ]);
        PolicySection::create([
            'agency_id'                => $agencyId,
            'policy_version_id'        => $version->id,
            'section_type'             => 'acknowledgement',
            'display_order'            => 99,
            'section_number'           => 'A',
            'title'                    => 'Acknowledgement',
            'body_html'                => '<p>I confirm that I have read and understood this policy in full and acknowledge my obligations under it.</p>',
            'requires_acknowledgement' => false,
        ]);

        return redirect()->route('compliance.policy.edit', $version)
            ->with('success', "Policy '{$policy->name}' created with a v1 draft.");
    }

    /**
     * Create a new draft version of an existing policy (clone latest sections).
     */
    public function createVersion(AgencyPolicy $policy)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);

        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();

        $latestNumber = PolicyVersion::where('policy_id', $policy->id)->max('version_number') ?? 0;
        $newNumber = $latestNumber + 1;

        $source = PolicyVersion::where('policy_id', $policy->id)
            ->orderByDesc('version_number')
            ->first();

        $version = PolicyVersion::create([
            'agency_id'      => $agencyId,
            'policy_id'      => $policy->id,
            'version_number' => $newNumber,
            'title'          => $source->title ?? $policy->name,
            'status'         => 'draft',
            'created_by'     => $user->id,
            'change_notes'   => "Cloned from v{$latestNumber}",
        ]);

        if ($source) {
            foreach ($source->sections as $section) {
                PolicySection::create([
                    'agency_id'                => $agencyId,
                    'policy_version_id'        => $version->id,
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

        return redirect()->route('compliance.policy.edit', $version)
            ->with('success', "{$policy->name} v{$newNumber} draft created.");
    }

    public function show(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        $version->load('policy', 'sections', 'approver', 'creator');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        return view('compliance.policy.show', compact('version', 'agency', 'variables'));
    }

    public function edit(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        $version->load('policy', 'sections');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);
        $variableKeys = array_keys($variables);

        return view('compliance.policy.edit', compact('version', 'agency', 'variables', 'variableKeys'));
    }

    /**
     * Update a section (AJAX).
     */
    public function update(Request $request, PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        $validated = $request->validate([
            'section_id' => 'required|exists:policy_sections,id',
            'title'      => 'required|string|max:500',
            'body_html'  => 'required|string',
        ]);

        $section = PolicySection::where('policy_version_id', $version->id)
            ->findOrFail($validated['section_id']);

        $section->update([
            'title'     => $validated['title'],
            'body_html' => $validated['body_html'],
        ]);

        return response()->json(['success' => true, 'message' => 'Section saved.']);
    }

    /**
     * Add a section to a draft version.
     */
    public function addSection(Request $request, PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        $validated = $request->validate([
            'section_number'           => 'required|string|max:20',
            'title'                    => 'required|string|max:500',
            'section_type'             => 'required|in:section,schedule,annexure,acknowledgement',
            'requires_acknowledgement' => 'nullable|boolean',
        ]);

        $maxOrder = PolicySection::where('policy_version_id', $version->id)->max('display_order') ?? 0;

        PolicySection::create([
            'agency_id'                => $version->agency_id,
            'policy_version_id'        => $version->id,
            'section_type'             => $validated['section_type'],
            'display_order'            => $maxOrder + 1,
            'section_number'           => $validated['section_number'],
            'title'                    => $validated['title'],
            'body_html'                => '<p>New section — edit this content.</p>',
            'requires_acknowledgement' => (bool) ($validated['requires_acknowledgement'] ?? true),
            'acknowledgement_prompt'   => ($validated['requires_acknowledgement'] ?? true) ? 'I have read and understood this section' : null,
        ]);

        return redirect()->route('compliance.policy.edit', $version)->with('success', 'Section added.');
    }

    /**
     * Archive (soft-delete) a section on a draft version.
     */
    public function deleteSection(PolicyVersion $version, PolicySection $section)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');
        abort_unless($section->policy_version_id === $version->id, 404);

        $section->delete();

        return redirect()->route('compliance.policy.edit', $version)->with('success', 'Section removed.');
    }

    public function approveForm(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be approved.');

        $version->load('policy');

        return view('compliance.policy.approve', compact('version'));
    }

    public function approve(Request $request, PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be approved.');

        $validated = $request->validate([
            'approver_title'          => 'required|string|max:100',
            'board_approval_document' => 'nullable|file|mimes:pdf|max:10240',
            'effective_from'          => 'required|date|after_or_equal:today',
            'next_review_due'         => 'required|date|after:effective_from',
            'approval_notes'          => 'nullable|string|max:2000',
        ]);

        $documentPath = null;
        if ($request->hasFile('board_approval_document')) {
            $documentPath = $request->file('board_approval_document')
                ->store("policy/{$version->agency_id}/approvals", 'local');
        }

        $version->update([
            'effective_from'  => $validated['effective_from'],
            'next_review_due' => $validated['next_review_due'],
        ]);

        $version->approve(
            Auth::user(),
            $validated['approver_title'],
            $documentPath,
            $validated['approval_notes'] ?? null
        );

        Log::info('Policy version approved', [
            'version_id' => $version->id,
            'policy_id'  => $version->policy_id,
            'agency_id'  => $version->agency_id,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('compliance.policy.show', $version)
            ->with('success', "Policy v{$version->version_number} approved and now active. All staff must re-acknowledge.");
    }

    public function downloadPdf(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        $version->load('policy', 'sections', 'approver');
        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        return view('compliance.policy.show', [
            'version'   => $version,
            'agency'    => $agency,
            'variables' => $variables,
            'pdfMode'   => true,
        ]);
    }
}
