<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Mail\FicaRequestMail;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaComplianceOfficer;
use App\Models\FicaDocument;
use App\Models\FicaResendLog;
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
            'draft'                  => (clone $countBase)->where('status', 'draft')->count(),
            'submitted'              => (clone $countBase)->where('status', 'submitted')->count(),
            'agent_approved'         => (clone $countBase)->where('status', 'agent_approved')->count(),
            'approved'               => (clone $countBase)->where('status', 'approved')->count(),
            'corrections_requested'  => (clone $countBase)->where('status', 'corrections_requested')->count(),
            'rejected'               => (clone $countBase)->where('status', 'rejected')->count(),
            'cancelled'              => (clone $countBase)->where('status', 'cancelled')->count(),
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
            'fica_expires_at'      => now()->addMonths(24),
            'co_verified_by'       => Auth::id(),
            'co_verified_at'       => now(),
            'co_verification_data' => $coChecklistData,
            'co_notes'             => $validated['co_notes'] ?? null,
            'co_signature_data'    => $validated['co_signature_data'],
        ]);

        // Update the contact record with form data
        $this->updateContactFromSubmission($submission);

        // File FICA documents to the contact's document drive
        $this->fileDocumentsToContact($submission);

        Log::info('FICA compliance officer approved', [
            'submission_id' => $submission->id,
            'co_id'         => Auth::id(),
            'contact_id'    => $submission->contact_id,
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission approved by compliance officer. Contact record updated and documents filed.');
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
                'status'         => 'corrections_requested',
                'co_notes'       => $validated['reviewer_notes'],
                'co_verified_by' => Auth::id(),
                'co_verified_at' => now(),
            ]);

            Log::info('FICA corrections requested by CO', [
                'submission_id' => $submission->id,
                'co_id'         => Auth::id(),
                'notes'         => $validated['reviewer_notes'],
            ]);

            return redirect()->route('compliance.fica.index', ['tab' => 'corrections_requested'])
                ->with('success', 'Corrections requested — returned to agent for re-review.');
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
     * Resend FICA link email (draft/corrections_requested only, online intake only).
     */
    public function resend(FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_if($submission->isWetInk(), 400, 'Cannot resend wet-ink submissions.');
        abort_unless(in_array($submission->status, ['draft', 'corrections_requested']), 400, 'Cannot resend at this stage.');

        $submission->update([
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(14),
        ]);

        FicaResendLog::create([
            'fica_submission_id' => $submission->id,
            'resent_by'          => Auth::id(),
            'resent_at'          => now(),
        ]);

        if ($submission->contact && $submission->contact->email) {
            Mail::to($submission->contact->email)->send(
                new FicaRequestMail($submission, Auth::user())
            );
        }

        return back()->with('success', "FICA link resent to {$submission->contact->email}.");
    }

    /**
     * Cancel a FICA request (voids the client link).
     */
    public function cancel(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_if(in_array($submission->status, ['approved', 'rejected', 'cancelled']), 400, 'Cannot cancel a completed submission.');

        $reason = $request->input('reason', 'No reason provided');

        $submission->update([
            'status'           => 'cancelled',
            'token'            => null,
            'token_expires_at' => null,
            'reviewer_notes'   => ($submission->reviewer_notes ? $submission->reviewer_notes . "\n\n" : '')
                . '[CANCELLED by ' . Auth::user()->name . ' on ' . now()->format('d M Y H:i') . '] ' . $reason,
        ]);

        return redirect()->route('compliance.fica.index', ['tab' => 'cancelled'])
            ->with('success', 'FICA request cancelled.');
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
     * Agent resubmits after addressing CO corrections.
     */
    public function resubmitCorrections(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless($submission->status === 'corrections_requested', 400, 'Submission is not in corrections requested state.');

        $user = Auth::user();
        abort_unless(
            $submission->requested_by === $user->id || $user->isOwnerRole() || $user->hasPermission('manage_compliance'),
            403,
            'Only the requesting agent or an admin can resubmit corrections.'
        );

        $submission->update([
            'status' => 'agent_approved',
        ]);

        Log::info('FICA corrections resubmitted by agent', [
            'submission_id' => $submission->id,
            'agent_id'      => Auth::id(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Corrections addressed — resubmitted for compliance officer review.');
    }

    /**
     * CO/admin reopens a rejected submission for corrections.
     */
    public function reopenRejected(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $user = Auth::user();
        abort_unless(
            $user->isComplianceOfficer()
            || $user->isOwnerRole()
            || $user->hasPermission('compliance.fica.approve')
            || in_array($user->role, ['admin', 'super_admin']),
            403,
            'Only compliance officers or admins can reopen rejected submissions.'
        );

        abort_unless(
            $submission->status === 'rejected',
            422,
            'Only rejected submissions can be reopened.'
        );

        $validated = $request->validate([
            'reopen_notes' => 'required|string|min:10|max:2000',
        ]);

        $previousRejectionNotes = $submission->reviewer_notes;
        $previousRejectedAt = $submission->co_verified_at;
        $previousRejectedBy = $submission->co_verified_by;

        $submission->update([
            'status'         => 'corrections_requested',
            'reviewer_notes' => $validated['reopen_notes'],
            'co_notes'       => sprintf(
                "REOPENED on %s by %s.\n\nOriginal rejection (%s by %s): %s\n\nReopen reason: %s",
                now()->format('Y-m-d H:i'),
                $user->name,
                $previousRejectedAt ? $previousRejectedAt->format('Y-m-d H:i') : 'unknown',
                $previousRejectedBy ? (User::find($previousRejectedBy)?->name ?? "user #{$previousRejectedBy}") : 'unknown',
                $previousRejectionNotes ?? '(none)',
                $validated['reopen_notes']
            ),
            'co_verified_at' => now(),
            'co_verified_by' => $user->id,
        ]);

        Log::info('FICA submission reopened from rejected', [
            'submission_id'        => $submission->id,
            'contact_id'           => $submission->contact_id,
            'reopened_by_user_id'  => $user->id,
            'reopen_reason'        => $validated['reopen_notes'],
            'original_rejected_by' => $previousRejectedBy,
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Submission reopened — agent can now make corrections and resubmit.');
    }

    /**
     * Agent uploads a document to a FICA submission.
     */
    public function agentUpload(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);
        abort_unless(
            in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']),
            400,
            'Cannot upload documents at this stage.'
        );

        $user = Auth::user();
        abort_unless(
            $submission->requested_by === $user->id || $user->isOwnerRole() || $user->hasPermission('manage_compliance'),
            403,
            'Only the requesting agent or an admin can upload documents.'
        );

        // Multiple supporting documents per submission, each with its own
        // type (ID copy, proof of address, …). fica_documents is already a
        // one-to-many w/ SoftDeletes — every upload is a NEW row, nothing is
        // overwritten. Arrays are index-parallel: document_type[i] ↔ file[i].
        $validated = $request->validate([
            'file'            => 'required|array|min:1',
            'file.*'          => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,heic',
            'document_type'   => 'required|array|min:1',
            'document_type.*' => 'required|string|in:id_copy,proof_of_address,fica_form,authority,bank_statement,tax_clearance,company_registration,trust_deed,supporting,other',
        ]);

        $files = $request->file('file');
        $types = $validated['document_type'];
        abort_unless(count($files) === count($types), 422, 'Each uploaded file must have a document type.');

        $ids = [];
        foreach ($files as $i => $file) {
            $type = $types[$i] ?? null;
            if (! $type) {
                continue;
            }
            $path = $file->store("fica/{$submission->id}", 'public');

            $doc = FicaDocument::create([
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
            $ids[] = $doc->id;
        }

        Log::info('FICA agent uploaded documents', [
            'submission_id' => $submission->id,
            'document_ids'  => $ids,
            'count'         => count($ids),
            'agent_id'      => Auth::id(),
        ]);

        return back()->with('success', count($ids) === 1
            ? 'Document uploaded successfully.'
            : count($ids) . ' documents uploaded successfully.');
    }

    /**
     * Soft-delete (archive) a supporting document. Per CoreX no-hard-delete
     * rule, FicaDocument uses SoftDeletes — the row is archived and remains
     * admin-recoverable; the documents() relation excludes it thereafter.
     */
    public function removeDocument(Request $request, FicaSubmission $submission, FicaDocument $document)
    {
        $this->authorizeAgency($submission);
        abort_unless((int) $document->fica_submission_id === (int) $submission->id, 404);
        abort_unless(
            in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']),
            400,
            'Cannot remove documents at this stage.'
        );

        $user = Auth::user();
        abort_unless(
            $submission->requested_by === $user->id || $user->isOwnerRole() || $user->hasPermission('manage_compliance'),
            403,
            'Only the requesting agent or an admin can remove documents.'
        );

        $document->delete(); // SoftDeletes — archived, admin-recoverable. Never a hard delete.

        Log::info('FICA agent removed (soft-deleted) document', [
            'submission_id' => $submission->id,
            'document_id'   => $document->id,
            'agent_id'      => $user->id,
        ]);

        return back()->with('success', 'Document removed (archived — an admin can recover it).');
    }

    /**
     * File approved FICA documents to the contact's document drive.
     */
    private function fileDocumentsToContact(FicaSubmission $submission): void
    {
        $contact = $submission->contact;
        if (! $contact) {
            return;
        }

        $submission->loadMissing('documents');
        if ($submission->documents->isEmpty()) {
            return;
        }

        $typeMap = [
            'fica_form'            => 'fica',
            'id_copy'              => 'ids',
            'proof_of_address'     => 'por',
            'authority'            => 'power_of_attorney',
            'bank_statement'       => 'bank_statement',
            'tax_clearance'        => 'tax_clearance',
            'company_registration' => 'company_registration',
            'trust_deed'           => 'trust_deed',
            'supporting'           => 'other',
            'other'                => 'other',
        ];

        $slugToId = DocumentType::pluck('id', 'slug')->toArray();
        $filed = [];

        foreach ($submission->documents as $ficaDoc) {
            $slug = $typeMap[$ficaDoc->document_type] ?? 'other';
            $docTypeId = $slugToId[$slug] ?? ($slugToId['other'] ?? null);

            if (! $docTypeId) {
                continue;
            }

            // Copy file to contact-documents directory
            $sourceDisk = Storage::disk('public');
            $localDisk  = Storage::disk('local');

            if (! $sourceDisk->exists($ficaDoc->file_path)) {
                // Try local disk (online uploads use local)
                $sourceDisk = $localDisk;
                if (! $sourceDisk->exists($ficaDoc->file_path)) {
                    Log::warning('FICA document file not found for contact filing', [
                        'fica_document_id' => $ficaDoc->id,
                        'file_path'        => $ficaDoc->file_path,
                    ]);
                    continue;
                }
            }

            $ext = pathinfo($ficaDoc->file_name, PATHINFO_EXTENSION) ?: 'pdf';
            $newPath = "contact-documents/{$contact->id}/" . Str::uuid() . ".{$ext}";
            $localDisk->put($newPath, $sourceDisk->get($ficaDoc->file_path));

            $document = Document::create([
                'agency_id'        => $submission->agency_id,
                'original_name'    => $ficaDoc->file_name,
                'storage_path'     => $newPath,
                'disk'             => 'local',
                'mime_type'        => $ficaDoc->mime_type,
                'size'             => $ficaDoc->file_size,
                'document_type_id' => $docTypeId,
                'source_type'      => 'fica',
                'source_id'        => $submission->id,
                'uploaded_by'      => Auth::id(),
            ]);

            $document->contacts()->attach($contact->id);
            $filed[] = $document->id;
        }

        if (! empty($filed)) {
            Log::info('FICA documents filed to contact drive', [
                'contact_id'    => $contact->id,
                'submission_id' => $submission->id,
                'documents'     => $filed,
                'filed_by'      => Auth::id(),
                'filed_at'      => now()->toDateTimeString(),
            ]);
        }
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
