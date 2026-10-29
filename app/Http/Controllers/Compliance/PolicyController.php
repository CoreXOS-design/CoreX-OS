<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicySection;
use App\Models\Compliance\PolicyVersion;
use App\Services\Compliance\CompliancePdf;
use App\Services\Compliance\PolicyDocumentImporter;
use App\Services\Compliance\PolicyVariableResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /** Closing declaration of a policy made from an uploaded document (the typed starter has its own wording). */
    public const DOCUMENT_DECLARATION = 'I confirm that I have read and understood this policy in full and acknowledge my obligations under it.';

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
            'document'    => 'nullable|file|mimes:pdf,docx,doc|max:20480',
        ], [
            'policy_key.regex' => 'The policy key may only contain lowercase letters, numbers and underscores.',
            'document.mimes'   => 'Upload the policy as a PDF or a Word file (.docx).',
            'document.max'     => 'The document must be 20 MB or smaller.',
        ]);

        $exists = AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $validated['policy_key'])
            ->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['policy_key' => 'A policy with this key already exists.']);
        }

        DB::beginTransaction();

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

        // Made from an uploaded document: one section per page, read page by page, one signature at the end. A document
        // that cannot be read leaves nothing behind - the policy and its draft are removed so the form can be tried again.
        if ($request->hasFile('document')) {
            try {
                app(PolicyDocumentImporter::class)->import($version, $request->file('document'), self::DOCUMENT_DECLARATION);
            } catch (\RuntimeException $e) {
                DB::rollBack();

                return back()->withInput()->withErrors(['document' => $e->getMessage()]);
            }
            DB::commit();

            return redirect()->route('compliance.policy.show', $version)
                ->with('success', "Policy '{$policy->name}' created from the uploaded document ({$version->source_page_count} pages). Approve it to make it active and ask staff to read and sign it.");
        }

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

        DB::commit();

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
            // An uploaded-document policy keeps pointing at the same document until a new one is uploaded.
            'source_kind'            => $source?->source_kind ?? 'sections',
            'source_document_path'   => $source?->source_document_path,
            'source_document_name'   => $source?->source_document_name,
            'source_document_sha256' => $source?->source_document_sha256,
            'source_page_count'      => $source?->source_page_count,
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

        $signedCopies    = app(\App\Services\Compliance\SignedCopyService::class);
        $signed          = $signedCopies->describe($version);
        $canManageSigned = $signedCopies->canManage(Auth::user(), (int) $version->agency_id);
        $canAttachSigned = $signedCopies->canAttachTo($version);
        $trainingCounts  = $version->status === 'active' ? app(\App\Services\Compliance\StaffTrainingService::class)->counts($version) : null;
        $canSendTraining = $trainingCounts !== null && Auth::user()->hasPermission('approve_policy');

        return view('compliance.policy.show', compact(
            'version', 'agency', 'variables',
            'signed', 'canManageSigned', 'canAttachSigned', 'trainingCounts', 'canSendTraining'
        ));
    }

    public function edit(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);
        abort_unless($version->canBeEdited(), 403, 'Only draft versions can be edited.');

        if ($version->isDocument()) {
            return redirect()->route('compliance.policy.show', $version)
                ->with('error', 'This policy is an uploaded document, so there is no text to edit. Upload a new version of the document instead.');
        }

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

        // Same shared step as RMCP approval: earlier signatures on this policy stop counting and every active staff
        // member is told (in-app) to read and sign it. A failure here never undoes the approval (the service logs it).
        $sent = app(\App\Services\Compliance\StaffTrainingService::class)
            ->send($version->fresh(), Auth::user(), \App\Services\Compliance\StaffTrainingService::MODE_APPROVAL);

        return redirect()->route('compliance.policy.show', $version)
            ->with('success', "Policy v{$version->version_number} approved and now active. {$sent['notified']} staff member(s) have been asked to read and sign it.");
    }

    public function downloadPdf(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        $version->load('policy', 'sections', 'approver');

        if ($version->isDocument() && $version->source_document_path && Storage::disk('local')->exists($version->source_document_path)) {
            return Storage::disk('local')->download(
                $version->source_document_path,
                Str::slug((string) ($version->policy?->name ?: $version->title)) . '-v' . $version->version_number . '.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        $agency = Agency::findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);

        $pdf   = app(CompliancePdf::class);
        $name  = $version->policy?->name ?: $version->title;
        $html  = $pdf->versionHtml('Agency Policy', null, $version, $agency, $variables, $name);
        $bytes = $pdf->render($html, $name . ' v' . $version->version_number . ' — ' . $agency->name, 'policy-' . $version->id);

        $response = $pdf->download($bytes, CompliancePdf::fileName(
            $name, $version->version_number, $agency->name,
            ($version->approved_at ?? $version->created_at)?->format('Y-m-d'), (string) $version->status
        ));

        return $response ?? redirect()->route('compliance.policy.show', $version)
            ->with('error', 'The PDF could not be created just now. Please try again; if it keeps failing, tell your administrator.');
    }
    /**
     * A new DRAFT version of an uploaded-document policy from a newly uploaded file.
     */
    public function uploadVersion(Request $request, AgencyPolicy $policy)
    {
        abort_unless(Auth::user()->hasPermission('edit_policy'), 403);

        $request->validate([
            'document' => 'required|file|mimes:pdf,docx,doc|max:20480',
        ], [
            'document.required' => 'Choose the new document to upload.',
            'document.mimes'    => 'Upload the policy as a PDF or a Word file (.docx).',
            'document.max'      => 'The document must be 20 MB or smaller.',
        ]);

        $user   = Auth::user();
        $latest = PolicyVersion::where('policy_id', $policy->id)->max('version_number') ?? 0;

        DB::beginTransaction();
        $version = PolicyVersion::create([
            'agency_id'      => $policy->agency_id,
            'policy_id'      => $policy->id,
            'version_number' => $latest + 1,
            'title'          => $policy->name,
            'status'         => 'draft',
            'created_by'     => $user->id,
            'change_notes'   => 'New document uploaded',
        ]);

        try {
            app(PolicyDocumentImporter::class)->import($version, $request->file('document'), self::DOCUMENT_DECLARATION);
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return back()->withErrors(['document' => $e->getMessage()]);
        }
        DB::commit();

        return redirect()->route('compliance.policy.show', $version)
            ->with('success', "{$policy->name} v{$version->version_number} draft created from the uploaded document ({$version->source_page_count} pages).");
    }

    /**
     * One page of an uploaded-document policy, as the image the read-through shows. Private: signed in, same agency.
     */
    public function pageImage(PolicyVersion $version, int $page)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        $path = app(PolicyDocumentImporter::class)->pageImagePath($version, $page);
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }
}
