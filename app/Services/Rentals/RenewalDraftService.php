<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\WebTemplateDataService;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §5(a) — "Copy forward": the current lease
 * was e-signed through CoreX (leases.source_document_id set), so the
 * renewal draft reuses the SAME template and pre-fills property/
 * recipients/lease terms, landing the agent straight into the existing
 * e-sign wizard (Fill & Review onward) instead of a blank Step 1.
 *
 * Deliberately does NOT touch ESignWizardController/SignatureService (the
 * recipient signing pipeline) — it only inserts a `flows` row in exactly
 * the shape ESignWizardController::store()/saveStep() already write, so
 * the existing wizard screens read it unchanged. No new document-creation
 * code path, no pipeline-gated file touched.
 */
class RenewalDraftService
{
    /**
     * @param array{start_date:string,end_date?:?string,rental_amount:float,deposit_amount?:?float,is_month_to_month?:bool} $terms
     * @return array{lease: Lease, flow: Flow}
     */
    public function copyForward(Lease $current, array $terms, User $user): array
    {
        if ($current->source !== 'esign_document' || !$current->source_document_id) {
            throw ValidationException::withMessages([
                'lease' => 'This lease was not e-signed through CoreX — use the template-draft or manual-upload renewal path instead.',
            ]);
        }

        $document = Document::find($current->source_document_id);
        if (!$document) {
            throw ValidationException::withMessages([
                'lease' => 'The original signed document could not be found.',
            ]);
        }

        $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($current, $terms, $user);

        $flow = app(LeaseSigningLauncher::class)->buildFlow($newTerm, (int) $document->template_id, $user);

        // LEASE-AGREEMENT (leases.md §15.10 M2 — Build L1): renewal_draft_flow_id is kept and mirrored
        // into signing_flow_id; the lease now carries the same "agreement prepared" state M5 gives an
        // existing renewal draft.
        $newTerm->update([
            'renewal_draft_flow_id' => $flow->id,
            'signing_flow_id' => $flow->id,
            'signing_status' => Lease::SIGNING_PREPARED,
            'agreement_template_id' => (int) $document->template_id,
            'source' => 'esign_document',
        ]);

        return ['lease' => $newTerm->fresh(), 'flow' => $flow];
    }

    /**
     * .ai/specs/rental-renewals.md §5(b) — GATE 1. Agency has a mapped
     * template (§4) and the data needed to fill it is complete — checked
     * by resolving the SAME step_data buildDraftFlow() would send against
     * WebTemplateDataService, the authoritative field-resolution service
     * every other e-sign path already uses. $terms lets the caller check
     * against the new rent/dates the agent is about to submit, not stale
     * current-lease values.
     *
     * @param array{start_date?:string,end_date?:?string,rental_amount?:float,deposit_amount?:?float} $terms
     * @return string[] human-readable labels of every blank required field
     */
    public function missingRequiredFields(Lease $lease, RentalLeaseTemplate $rentalLeaseTemplate, array $terms, User $user): array
    {
        $template = \App\Models\Docuperfect\Template::find($rentalLeaseTemplate->docuperfect_template_id);
        if (!$template) {
            return ['Template could not be found.'];
        }

        $stepData = app(LeaseSigningLauncher::class)->buildStepData($lease, $terms, $user);
        // WebTemplateDataService::resolve() itself branches to
        // resolveCdsTemplate() internally when template_type === 'cds' —
        // one call site here covers both template shapes.
        $resolved = app(WebTemplateDataService::class)->resolve($template->id, $stepData, $user);

        $required = [
            'property_address' => 'Property address',
            'lessor_name' => 'Landlord name',
            'lessee_name' => 'Tenant name',
            'rental_amount' => 'Rent',
            'lease_start' => 'Start date',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if (trim((string) ($resolved[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * §5(b) — draft a fresh e-sign document from the agency's own mapped
     * template (RentalLeaseTemplate), pre-filled from the current lease's
     * data exactly like copyForward() — same buildDraftFlow(), different
     * template source. Blocked (ValidationException) if any required
     * field is still blank — the agent fills the gap before a draft is
     * ever created, per the gate's own "block send until filled" rule.
     *
     * @param array{start_date:string,end_date?:?string,rental_amount:float,deposit_amount?:?float,is_month_to_month?:bool} $terms
     * @return array{lease: Lease, flow: Flow}
     */
    public function draftFromTemplate(Lease $current, RentalLeaseTemplate $rentalLeaseTemplate, array $terms, User $user): array
    {
        if ((int) $rentalLeaseTemplate->agency_id !== (int) $current->agency_id) {
            throw ValidationException::withMessages(['template' => 'This template does not belong to your agency.']);
        }
        if (!$rentalLeaseTemplate->is_active) {
            throw ValidationException::withMessages(['template' => 'This template is archived.']);
        }

        $missing = $this->missingRequiredFields($current, $rentalLeaseTemplate, $terms, $user);
        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'template' => 'This lease is missing: ' . implode(', ', $missing) . '. Fill these in before sending.',
            ]);
        }

        $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($current, $terms, $user);

        $flow = app(LeaseSigningLauncher::class)->buildFlow($newTerm, (int) $rentalLeaseTemplate->docuperfect_template_id, $user);

        // LEASE-AGREEMENT (leases.md §15.10 M2 — Build L1): mirrored, see copyForward().
        $newTerm->update([
            'renewal_draft_flow_id' => $flow->id,
            'signing_flow_id' => $flow->id,
            'signing_status' => Lease::SIGNING_PREPARED,
            'agreement_template_id' => (int) $rentalLeaseTemplate->docuperfect_template_id,
        ]);

        return ['lease' => $newTerm->fresh(), 'flow' => $flow];
    }
}
