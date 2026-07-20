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
    public function form(Request $request, string $token)
    {
        $submission = $this->resolveSubmission($token);

        $returnUrl = $request->query('return_url', '');

        // Already submitted — show confirmation
        if (in_array($submission->status, ['submitted', 'under_review', 'approved'])) {
            return redirect()->route('fica.confirmation', ['token' => $token, 'return_url' => $returnUrl]);
        }

        $contact = $submission->contact;
        $agency  = $submission->agency;

        return view('fica.form', compact('submission', 'contact', 'agency', 'token', 'returnUrl'));
    }

    /**
     * Validate and save form data + signature.
     */
    public function submit(Request $request, string $token)
    {
        $submission = $this->resolveSubmission($token);

        $entityType = $request->input('entity_type');

        $rules = [
            // Section 1 — Entity type
            'entity_type' => 'required|in:natural,company,trust,partnership',

            // Section 2 — Person completing form
            'personal.full_name'           => 'required|string|max:255',
            'personal.id_number'           => 'required|string|max:50',
            'personal.sa_citizen'          => 'required|in:yes,no',
            'personal.residential_address' => 'required|string|max:2000',
            'personal.phone'              => 'required|string|max:30',
            'personal.email'              => 'required|email|max:255',
            'personal.tax_number'         => 'nullable|string|max:50',

            // Section 6 — Service & payment
            'service.transaction_purpose' => 'required|string|max:100',
            'service.purpose_other'       => 'nullable|string|max:500',
            'service.payment_method'      => 'required|string|max:2000',
            'service.cash_over_50k'       => 'required|in:yes,no',

            // Section 7 — PEP
            'pep.is_foreign_pep'           => 'required|in:yes,no',
            'pep.foreign_pep'              => 'nullable|array',
            'pep.foreign_pep.*'            => 'string|max:50',
            'pep.is_domestic_pep'          => 'required|in:yes,no',
            'pep.domestic_pep'             => 'nullable|array',
            'pep.domestic_pep.*'           => 'string|max:50',
            'pep.is_family_associate'      => 'required|in:yes,no',
            'pep.family_associate_details' => 'nullable|required_if:pep.is_family_associate,yes|string|max:2000',
            'pep.source_of_wealth'         => 'nullable|string|max:2000',

            // Section 9 — Declaration & signature
            'declaration.signed_at_location' => 'required|string|max:255',
            'signature_data'                 => 'required|string',
        ];

        // Section 3 — Company/CC
        if ($entityType === 'company') {
            $rules += [
                'entity.company_name'                  => 'required|string|max:255',
                'entity.company_reg_number'            => 'required|string|max:100',
                'entity.company_sa_presence'            => 'required|string|max:2000',
                'entity.company_stock_exchange'         => 'nullable|string|max:255',
                'entity.company_tax_number'             => 'nullable|string|max:50',
                'entity.company_vat_number'             => 'nullable|string|max:50',
                'entity.company_address'                => 'required|string|max:2000',
                'entity.company_authority_source'       => 'required|string|max:2000',
                'entity.company_business_description'   => 'required|string|max:2000',
                'entity.company_ownership_structure'    => 'required|string|max:2000',
                'entity.beneficial_owner_method'        => 'required|in:method_1,method_2,method_3',
                'entity.beneficial_owners'              => 'required|array|min:1',
                'entity.beneficial_owners.*.name'       => 'required|string|max:255',
                'entity.beneficial_owners.*.id_number'  => 'required|string|max:50',
                'entity.beneficial_owners.*.address'    => 'required|string|max:2000',
                'entity.beneficial_owners.*.phone'      => 'nullable|string|max:30',
                'entity.beneficial_owners.*.email'      => 'nullable|string|max:255',
            ];
        }

        // Section 3 — Trust
        if ($entityType === 'trust') {
            $rules += [
                'entity.trust_name'                => 'required|string|max:255',
                'entity.trust_master_ref'          => 'required|string|max:100',
                'entity.trust_sa_presence'         => 'required|string|max:2000',
                'entity.trust_master_court'        => 'required|string|max:255',
                'entity.trust_tax_number'          => 'nullable|string|max:50',
                'entity.trust_vat_number'          => 'nullable|string|max:50',
                'entity.trust_authority_source'    => 'required|string|max:2000',
                'entity.trust_purpose'             => 'required|string|max:2000',
                'entity.donor_name'                => 'required|string|max:255',
                'entity.donor_id_number'           => 'required|string|max:50',
                'entity.donor_address'             => 'required|string|max:2000',
                'entity.has_named_beneficiaries'   => 'required|in:yes,no',
                'entity.beneficiary_determination' => 'nullable|required_if:entity.has_named_beneficiaries,no|string|max:2000',
                'entity.trustees'                  => 'required|array|min:1',
                'entity.trustees.*.name'           => 'required|string|max:255',
                'entity.trustees.*.id_number'      => 'required|string|max:50',
                'entity.trustees.*.address'        => 'required|string|max:2000',
                'entity.beneficiaries'             => 'nullable|array',
                'entity.beneficiaries.*.name'      => 'nullable|string|max:255',
                'entity.beneficiaries.*.id_number' => 'nullable|string|max:50',
                'entity.beneficiaries.*.address'   => 'nullable|string|max:2000',
            ];
        }

        // Section 3 — Partnership
        if ($entityType === 'partnership') {
            $rules += [
                'entity.partnership_name'                => 'required|string|max:255',
                'entity.partnership_sa_presence'          => 'required|string|max:2000',
                'entity.partnership_authority_source'     => 'required|string|max:2000',
                'entity.partnership_business_description' => 'required|string|max:2000',
                'entity.is_professional_partnership'      => 'required|in:yes,no',
                'entity.executive_partners'              => 'nullable|required_if:entity.is_professional_partnership,yes|string|max:2000',
                'entity.partnership_ownership_structure'  => 'required|string|max:2000',
                'entity.partnership_tax_number'           => 'nullable|string|max:50',
                'entity.partnership_vat_number'           => 'nullable|string|max:50',
                'entity.partners'                        => 'required|array|min:1',
                'entity.partners.*.name'                 => 'required|string|max:255',
                'entity.partners.*.id_number'            => 'required|string|max:50',
                'entity.partners.*.address'              => 'required|string|max:2000',
                'entity.partners.*.phone'                => 'nullable|string|max:30',
                'entity.partners.*.email'                => 'nullable|string|max:255',
            ];
        }

        // Section 4/5 — Principal & Representative (natural person only)
        if ($entityType === 'natural') {
            $rules += [
                'principal.acting_on_behalf'    => 'required|in:yes,no',
                'principal.full_name'           => 'nullable|required_if:principal.acting_on_behalf,yes|string|max:255',
                'principal.id_number'           => 'nullable|required_if:principal.acting_on_behalf,yes|string|max:50',
                'principal.sa_citizen'          => 'nullable|required_if:principal.acting_on_behalf,yes|in:yes,no',
                'principal.residential_address' => 'nullable|required_if:principal.acting_on_behalf,yes|string|max:2000',
                'principal.phone'               => 'nullable|required_if:principal.acting_on_behalf,yes|string|max:30',
                'principal.email'               => 'nullable|required_if:principal.acting_on_behalf,yes|email|max:255',
                'principal.tax_number'          => 'nullable|string|max:50',
                'principal.authority_source'    => 'nullable|required_if:principal.acting_on_behalf,yes|string|max:2000',
                'representative.has_representative' => 'required|in:yes,no',
                'representative.full_name'          => 'nullable|required_if:representative.has_representative,yes|string|max:255',
                'representative.id_number'          => 'nullable|required_if:representative.has_representative,yes|string|max:50',
                'representative.authority_source'   => 'nullable|required_if:representative.has_representative,yes|string|max:2000',
            ];
        }

        $validated = $request->validate($rules);

        // Defensive self-heal: a legacy / e-sign-reused submission may
        // still carry a NULL agency_id / branch_id, which would keep it
        // out of the strictly-scoped FICA compliance pipeline even though
        // its status is now 'submitted'. Backfill from the linked contact
        // on completion so it becomes pipeline-visible.
        $heal = [];
        if (empty($submission->agency_id) && $submission->contact?->agency_id) {
            $heal['agency_id'] = $submission->contact->agency_id;
        }
        if (empty($submission->branch_id) && $submission->contact?->branch_id) {
            $heal['branch_id'] = $submission->contact->branch_id;
        }

        $submission->update($heal + [
            'entity_type'    => $validated['entity_type'],
            'form_data'      => $validated,
            'signature_data' => $validated['signature_data'],
            'signed_at'      => now(),
            'status'         => 'submitted',
        ]);

        $returnUrl = $request->input('return_url', '');

        return redirect()->route('fica.confirmation', ['token' => $token, 'return_url' => $returnUrl]);
    }

    /**
     * Handle file uploads via AJAX.
     */
    public function uploadDocument(Request $request, string $token)
    {
        $submission = $this->resolveSubmission($token);

        $request->validate([
            'file'          => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,heic',
            'document_type' => 'required|string|max:50',
        ]);

        $file = $request->file('file');
        $dir  = "fica/{$submission->id}";
        // AT-173 — encrypt on write (private disk) via the FICA storage seam.
        $path = app(\App\Services\Compliance\FicaDocumentStorage::class)->putUploaded($file, $dir);

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
    public function confirmation(Request $request, string $token)
    {
        $submission = FicaSubmission::where('token', $token)->firstOrFail();
        $agency     = $submission->agency;
        $returnUrl  = $request->query('return_url', '');

        return view('fica.confirmation', compact('submission', 'agency', 'returnUrl'));
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
