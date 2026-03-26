<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FicaPublicController extends Controller
{
    /**
     * Show the FICA form to the recipient (token-based, no auth).
     */
    public function form(string $token)
    {
        $submission = $this->resolveSubmission($token);

        // Already submitted — show confirmation
        if (in_array($submission->status, ['submitted', 'under_review', 'approved'])) {
            return redirect()->route('fica.confirmation', $token);
        }

        $contact = $submission->contact;
        $agency  = $submission->agency;

        return view('fica.form', compact('submission', 'contact', 'agency', 'token'));
    }

    /**
     * Validate and save form data + signature.
     */
    public function submit(Request $request, string $token)
    {
        $submission = $this->resolveSubmission($token);

        $validated = $request->validate([
            // Section 1 — Personal details
            'full_name'           => 'required|string|max:255',
            'id_number'           => 'required|string|max:50',
            'date_of_birth'       => 'required|date',
            'nationality'         => 'required|string|max:100',
            'residential_address' => 'required|string|max:1000',
            'postal_address'      => 'nullable|string|max:1000',
            'phone'               => 'required|string|max:30',
            'email'               => 'required|email|max:255',

            // Section 2 — Source of funds
            'payment_method'      => 'required|string|max:2000',
            'cash_over_50k'       => 'required|in:yes,no',
            'source_of_income'    => 'required|string|max:2000',
            'occupation'          => 'required|string|max:255',
            'employer'            => 'nullable|string|max:255',

            // Section 3 — Purpose
            'transaction_purpose' => 'required|string|max:255',
            'purpose_other'       => 'nullable|string|max:500',

            // Section 4 — Entity type
            'entity_type'         => 'required|in:natural,company,trust,partnership',

            // Entity sub-fields (conditionally required via form logic)
            'company_name'            => 'nullable|string|max:255',
            'company_reg_number'      => 'nullable|string|max:100',
            'company_address'         => 'nullable|string|max:1000',
            'directors'               => 'nullable|array',
            'directors.*.name'        => 'nullable|string|max:255',
            'directors.*.id_number'   => 'nullable|string|max:50',

            'trust_name'              => 'nullable|string|max:255',
            'trust_number'            => 'nullable|string|max:100',
            'trustees'                => 'nullable|array',
            'trustees.*.name'         => 'nullable|string|max:255',
            'trustees.*.id_number'    => 'nullable|string|max:50',
            'beneficiaries'           => 'nullable|array',
            'beneficiaries.*.name'    => 'nullable|string|max:255',
            'beneficiaries.*.id_number' => 'nullable|string|max:50',

            'partnership_name'        => 'nullable|string|max:255',
            'partners'                => 'nullable|array',
            'partners.*.name'         => 'nullable|string|max:255',
            'partners.*.id_number'    => 'nullable|string|max:50',
            'authority_reference'     => 'nullable|string|max:255',

            // Section 5 — PEP
            'pep_domestic'        => 'required|in:yes,no',
            'pep_foreign'         => 'required|in:yes,no',
            'pep_family'          => 'required|in:yes,no',
            'pep_associate'       => 'required|in:yes,no',
            'pep_details'         => 'nullable|string|max:2000',

            // Section 7 — Signature
            'signature_data'      => 'required|string',
        ]);

        $submission->update([
            'entity_type'    => $validated['entity_type'],
            'form_data'      => $validated,
            'signature_data' => $validated['signature_data'],
            'signed_at'      => now(),
            'status'         => 'submitted',
        ]);

        return redirect()->route('fica.confirmation', $token);
    }

    /**
     * Handle file uploads via AJAX.
     */
    public function uploadDocument(Request $request, string $token)
    {
        $submission = $this->resolveSubmission($token);

        $request->validate([
            'file'          => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,heic',
            'document_type' => 'required|string|in:id_copy,proof_of_address,authority,bank_statement,tax_clearance,company_registration,trust_deed,other',
        ]);

        $file = $request->file('file');
        $dir  = "fica/{$submission->id}";
        $path = $file->store($dir, 'local');

        $doc = FicaDocument::create([
            'fica_submission_id' => $submission->id,
            'document_type'      => $request->input('document_type'),
            'file_path'          => $path,
            'file_name'          => $file->getClientOriginalName(),
            'file_size'          => $file->getSize(),
            'mime_type'          => $file->getMimeType(),
            'status'             => 'uploaded',
            'uploaded_at'        => now(),
        ]);

        return response()->json([
            'success' => true,
            'id'      => $doc->id,
            'name'    => $doc->file_name,
            'size'    => $doc->file_size,
        ]);
    }

    /**
     * Thank-you page after submission.
     */
    public function confirmation(string $token)
    {
        $submission = FicaSubmission::where('token', $token)->firstOrFail();
        $agency     = $submission->agency;

        return view('fica.confirmation', compact('submission', 'agency'));
    }

    /**
     * Resolve and validate a FICA submission by token.
     */
    private function resolveSubmission(string $token): FicaSubmission
    {
        $submission = FicaSubmission::where('token', $token)->firstOrFail();

        abort_if($submission->isExpired(), 410, 'This FICA form link has expired. Please contact your agent for a new link.');

        return $submission;
    }
}
