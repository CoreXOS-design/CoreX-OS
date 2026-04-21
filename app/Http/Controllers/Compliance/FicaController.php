<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Mail\FicaRequestMail;
use App\Models\Contact;
use App\Models\FicaComplianceOfficer;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FicaController extends Controller
{
    /**
     * List all FICA submissions for the agency.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isCO = $user->isComplianceOfficer();
        $isAdmin = $user->isOwnerRole() || $user->hasPermission('manage_compliance');
        $canSeeAll = $isCO || $isAdmin;
        $tab = $request->query('tab', $canSeeAll ? 'all' : 'submitted');

        // Base query — AgencyScope on FicaSubmission handles tenancy:
        // super_admin/owner with no switcher sees all, others see their agency.
        $baseQuery = FicaSubmission::query();
        if (! $canSeeAll) {
            $baseQuery->where('requested_by', $user->id);
        }

        // Counts (per status)
        $countBase = (clone $baseQuery);
        $counts = [
            'all'                    => (clone $countBase)->count(),
            'submitted'              => (clone $countBase)->where('status', 'submitted')->count(),
            'agent_approved'         => (clone $countBase)->where('status', 'agent_approved')->count(),
            'approved'               => (clone $countBase)->where('status', 'approved')->count(),
            'corrections_requested'  => (clone $countBase)->where('status', 'corrections_requested')->count(),
            'rejected'               => (clone $countBase)->where('status', 'rejected')->count(),
        ];
        // CO queue count (agency-scoped via global scope)
        $coQueueCount = $isCO
            ? FicaSubmission::where('status', 'agent_approved')->count()
            : 0;

        // Build filtered query
        $query = (clone $baseQuery)
            ->with(['contact', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy'])
            ->latest();

        if ($tab === 'co_queue') {
            // CO queue: agency-scoped via global scope, only agent_approved
            $query = FicaSubmission::where('status', 'agent_approved')
                ->with(['contact', 'requestedBy', 'agentVerifiedBy'])
                ->oldest('agent_verified_at');
        } elseif ($tab !== 'all') {
            $query->where('status', $tab);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('contact', function ($cq) use ($search) {
                    $cq->where('first_name', 'like', "%{$search}%")
                       ->orWhere('last_name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        $submissions = $query->paginate(20)->withQueryString();

        // CO queue stats
        $coQueueStats = null;
        if ($isCO && $coQueueCount > 0) {
            $oldest = FicaSubmission::where('status', 'agent_approved')
                ->min('agent_verified_at');
            $coQueueStats = [
                'count'       => $coQueueCount,
                'oldest_days' => $oldest ? (int) now()->diffInDays($oldest) : 0,
            ];
        }

        return view('compliance.fica.index', compact('submissions', 'counts', 'isCO', 'isAdmin', 'canSeeAll', 'tab', 'coQueueCount', 'coQueueStats'));
    }

    /**
     * Redirect to structured RMCP module.
     */
    public function rmcp()
    {
        return redirect()->route('compliance.rmcp.index');
    }

    /**
     * Show form to pick a contact and send FICA request.
     */
    public function create()
    {
        $contacts = Contact::orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        return view('compliance.fica.create', compact('contacts'));
    }

    /**
     * Create submission record, generate token, send email.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'contact_id' => 'required|exists:contacts,id',
        ]);

        $contact = Contact::findOrFail($validated['contact_id']);

        if (! $contact->email) {
            return back()->withErrors(['contact_id' => 'This contact does not have an email address.'])->withInput();
        }

        $agencyId = Auth::user()->effectiveAgencyId() ?? $contact->agency_id;

        if (! $agencyId) {
            return back()->withErrors(['contact_id' => 'Cannot determine the agency for this FICA request. Pick an active agency in the switcher and try again.'])->withInput();
        }

        $submission = FicaSubmission::create([
            'contact_id'       => $contact->id,
            'agency_id'        => $agencyId,
            'requested_by'     => Auth::id(),
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'status'           => 'draft',
        ]);

        Mail::to($contact->email)->send(
            new FicaRequestMail($submission, Auth::user())
        );

        return redirect()->route('compliance.fica.index')
            ->with('success', "FICA request sent to {$contact->full_name}.");
    }

    /**
     * Show wet-ink intake form.
     */
    public function createWetInk()
    {
        $contacts = Contact::orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'id_number']);

        return view('compliance.fica.create-wet-ink', compact('contacts'));
    }

    /**
     * Store a wet-ink FICA submission with uploaded documents.
     */
    public function storeWetInk(Request $request)
    {
        $validated = $request->validate([
            'contact_id'              => 'required|exists:contacts,id',
            'entity_type'             => 'required|in:natural,company,trust,partnership',
            'wet_ink_received_date'   => 'required|date|before_or_equal:today',
            'confirmed_signed_paper'  => 'required|accepted',
            'fica_form_file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'id_copy_file'            => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'proof_of_address_file'   => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'supporting_docs.*'       => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $contact = Contact::findOrFail($validated['contact_id']);
        $agencyId = Auth::user()->effectiveAgencyId() ?? $contact->agency_id;

        if (!$agencyId) {
            return back()->withErrors(['contact_id' => 'Cannot determine the agency. Pick an active agency in the switcher and try again.'])->withInput();
        }

        $submission = null;

        DB::transaction(function () use ($request, $validated, $contact, $agencyId, &$submission) {
            $submission = FicaSubmission::create([
                'contact_id'            => $contact->id,
                'agency_id'             => $agencyId,
                'requested_by'          => Auth::id(),
                'status'                => 'submitted',
                'intake_type'           => 'wet_ink',
                'entity_type'           => $validated['entity_type'],
                'wet_ink_received_date' => $validated['wet_ink_received_date'],
                'wet_ink_confirmed_by'  => Auth::id(),
                'signed_at'             => $validated['wet_ink_received_date'],
                'form_data'             => [
                    'personal' => [
                        'first_name' => $contact->first_name,
                        'last_name'  => $contact->last_name,
                        'id_number'  => $contact->id_number ?? null,
                        'email'      => $contact->email ?? null,
                        'phone'      => $contact->phone ?? null,
                    ],
                    'entity' => ['type' => $validated['entity_type']],
                    'intake' => [
                        'method'        => 'wet_ink',
                        'received_date' => $validated['wet_ink_received_date'],
                        'received_by'   => Auth::user()->name,
                    ],
                ],
            ]);

            $this->storeWetInkDocument($submission, $request->file('fica_form_file'), 'fica_form');
            $this->storeWetInkDocument($submission, $request->file('id_copy_file'), 'id_copy');
            $this->storeWetInkDocument($submission, $request->file('proof_of_address_file'), 'proof_of_address');

            if ($request->hasFile('supporting_docs')) {
                foreach ($request->file('supporting_docs') as $file) {
                    $this->storeWetInkDocument($submission, $file, 'supporting');
                }
            }
        });

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Wet-ink FICA created. Complete Section 10 verification next.');
    }

    /**
     * Store a single document for a wet-ink submission.
     */
    private function storeWetInkDocument(FicaSubmission $submission, $file, string $type): void
    {
        $path = $file->store("fica/wet-ink/{$submission->id}", 'public');

        FicaDocument::create([
            'fica_submission_id' => $submission->id,
            'document_type'      => $type,
            'file_path'          => $path,
            'file_name'          => $file->getClientOriginalName(),
            'file_size'          => $file->getSize(),
            'mime_type'          => $file->getMimeType(),
            'status'             => 'uploaded',
            'uploaded_at'        => now(),
            'uploaded_by'        => Auth::id(),
        ]);
    }

    /**
     * Staff review screen (agent).
     */
    public function show(FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $submission->load(['contact', 'requestedBy', 'verifiedBy', 'agentVerifiedBy', 'coVerifiedBy', 'documents']);

        return view('compliance.fica.show', compact('submission'));
    }

    /**
     * Agent approves — sets status to agent_approved, awaiting CO review.
     */
    public function agentApprove(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'risk_rating'         => 'required|integer|in:1,2,3',
            'verification_method' => 'required|array|min:1',
            'reviewer_notes'      => 'nullable|string|max:2000',
            'checklist'           => 'nullable|array',
        ]);

        $submission->update([
            'status'                  => 'agent_approved',
            'risk_rating'             => $validated['risk_rating'],
            'verification_method'     => $validated['verification_method'],
            'agent_verified_by'       => Auth::id(),
            'agent_verified_at'       => now(),
            'agent_verification_data' => $validated['checklist'] ?? null,
            'agent_notes'             => $validated['reviewer_notes'] ?? null,
        ]);

        Log::info('FICA agent approved', [
            'submission_id' => $submission->id,
            'agent_id'      => Auth::id(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Agent approval recorded. Awaiting compliance officer review.');
    }

    /**
     * Compliance officer review screen.
     */
    public function complianceReview(FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless(Auth::user()->isComplianceOfficer(), 403, 'Only compliance officers can access this page.');

        $submission->load(['contact', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy', 'documents']);

        return view('compliance.fica.compliance-review', compact('submission'));
    }

    /**
     * Compliance officer final approval.
     */
    public function complianceApprove(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless(Auth::user()->isComplianceOfficer(), 403);

        $validated = $request->validate([
            'risk_rating'        => 'required|integer|in:1,2,3',
            'co_checklist'       => 'nullable|array',
            'co_notes'           => 'nullable|string|max:2000',
            'co_signature_data'  => 'required|string',
            'tfs_screening'      => 'required|in:yes,no',
        ]);

        $coChecklistData = $validated['co_checklist'] ?? [];
        $coChecklistData['tfs_screening'] = $validated['tfs_screening'];

        $submission->update([
            'status'               => 'approved',
            'risk_rating'          => $validated['risk_rating'],
            'verified_by'          => Auth::id(),
            'verified_at'          => now(),
            'co_verified_by'       => Auth::id(),
            'co_verified_at'       => now(),
            'co_verification_data' => $coChecklistData,
            'co_notes'             => $validated['co_notes'] ?? null,
            'co_signature_data'    => $validated['co_signature_data'],
        ]);

        // NOW update the contact
        $this->updateContactFromSubmission($submission);

        Log::info('FICA compliance officer approved', [
            'submission_id' => $submission->id,
            'co_id'         => Auth::id(),
            'contact_id'    => $submission->contact_id,
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission approved by compliance officer. Contact record updated.');
    }

    /**
     * Compliance officer rejects or returns to agent.
     */
    public function complianceReject(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless(Auth::user()->isComplianceOfficer(), 403);

        $validated = $request->validate([
            'action'         => 'required|in:reject,return_to_agent',
            'reviewer_notes' => 'required|string|max:2000',
        ]);

        if ($validated['action'] === 'return_to_agent') {
            $submission->update([
                'status'        => 'submitted',
                'co_notes'      => $validated['reviewer_notes'],
                'co_verified_by' => Auth::id(),
                // Clear agent approval so agent must re-review
                'agent_verified_by'       => null,
                'agent_verified_at'       => null,
                'agent_verification_data' => null,
                'agent_notes'             => null,
            ]);

            return redirect()->route('compliance.fica.show', $submission)
                ->with('success', 'Returned to agent for re-review.');
        }

        $submission->update([
            'status'         => 'rejected',
            'reviewer_notes' => $validated['reviewer_notes'],
            'co_verified_by' => Auth::id(),
            'co_verified_at' => now(),
            'co_notes'       => $validated['reviewer_notes'],
            'verified_by'    => Auth::id(),
            'verified_at'    => now(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission rejected.');
    }

    /**
     * Reject submission (agent).
     */
    public function reject(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'reviewer_notes' => 'required|string|max:2000',
        ]);

        $submission->update([
            'status'         => 'rejected',
            'reviewer_notes' => $validated['reviewer_notes'],
            'verified_by'    => Auth::id(),
            'verified_at'    => now(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission rejected.');
    }

    /**
     * Request corrections — send back to recipient.
     */
    public function requestCorrections(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'reviewer_notes' => 'required|string|max:2000',
        ]);

        $submission->update([
            'status'           => 'corrections_requested',
            'reviewer_notes'   => $validated['reviewer_notes'],
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(14),
        ]);

        if ($submission->contact && $submission->contact->email) {
            Mail::to($submission->contact->email)->send(
                new FicaRequestMail($submission, Auth::user())
            );
        }

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Corrections requested — email sent to recipient.');
    }

    /**
     * Download PDF certificate for an approved submission.
     */
    public function downloadPdf(FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless($submission->status === 'approved', 404, 'PDF only available for approved submissions.');

        $submission->load(['contact', 'agency', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy', 'documents']);

        // Return the HTML template as a printable page (Puppeteer rendering is a server-side concern)
        return view('compliance.fica.pdf', compact('submission'));
    }

    /**
     * Save compliance officers (from settings).
     */
    public function saveComplianceOfficers(Request $request)
    {
        $validated = $request->validate([
            'officer_ids'   => 'nullable|array',
            'officer_ids.*' => 'exists:users,id',
        ]);

        $officerIds = $validated['officer_ids'] ?? [];

        // Remove officers not in the new list
        FicaComplianceOfficer::whereNotIn('user_id', $officerIds)->delete();

        // Add new officers
        foreach ($officerIds as $userId) {
            FicaComplianceOfficer::firstOrCreate(
                ['user_id' => $userId],
                ['assigned_by' => Auth::id(), 'assigned_at' => now()]
            );
        }

        return back()->with('success', 'Compliance officers updated.')->with('tab', 'user');
    }

    /**
     * Update the linked contact record with FICA form data.
     */
    private function updateContactFromSubmission(FicaSubmission $submission): void
    {
        $contact = $submission->contact;
        if (! $contact) {
            return;
        }

        $formData = $submission->form_data ?? [];
        $personal = $formData['personal'] ?? [];

        $mapping = [
            'phone'      => $personal['phone'] ?? null,
            'email'      => $personal['email'] ?? null,
            'id_number'  => $personal['id_number'] ?? null,
            'address'    => $personal['residential_address'] ?? null,
        ];

        $updated = [];
        foreach ($mapping as $field => $value) {
            if (! empty($value) && empty($contact->{$field})) {
                $contact->{$field} = $value;
                $updated[] = $field;
            }
        }

        if (! empty($updated)) {
            $contact->save();
            Log::info('FICA approval updated contact fields', [
                'contact_id'     => $contact->id,
                'submission_id'  => $submission->id,
                'fields_updated' => $updated,
                'approved_by'    => Auth::id(),
            ]);
        }
    }

    /**
     * Ensure submission belongs to the user's agency.
     * Super-admin / owner-role users bypass (they can access any agency).
     */
    private function authorizeAgency(FicaSubmission $submission): void
    {
        $user = Auth::user();
        if ($user->isOwnerRole()) {
            return;
        }
        abort_unless($submission->agency_id === $user->effectiveAgencyId(), 403);
    }
}
