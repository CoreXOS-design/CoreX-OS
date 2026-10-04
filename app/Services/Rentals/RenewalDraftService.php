<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Lease;
use App\Models\User;
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

        $flow = $this->buildDraftFlow($newTerm, (int) $document->template_id, $user);

        $newTerm->update(['renewal_draft_flow_id' => $flow->id, 'source' => 'esign_document']);

        return ['lease' => $newTerm->fresh(), 'flow' => $flow];
    }

    private function buildDraftFlow(Lease $newTerm, int $templateId, User $user): Flow
    {
        $property = $newTerm->property;
        $landlords = $newTerm->landlordContacts();
        $tenants = $newTerm->tenants()->with('contact')->get();

        $recipients = [];
        $recipients[] = ['role' => 'agent', 'name' => $user->name, 'email' => $user->email ?? ''];

        foreach ($landlords as $landlord) {
            $recipients[] = [
                'role' => 'landlord',
                'name' => $landlord->full_name ?? trim(($landlord->first_name ?? '') . ' ' . ($landlord->last_name ?? '')),
                '_contact_id' => $landlord->id,
            ];
        }

        foreach ($tenants as $tenant) {
            $contact = $tenant->contact;
            if (!$contact) {
                continue;
            }
            $recipients[] = [
                'role' => 'tenant',
                'name' => $contact->full_name ?? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
                '_contact_id' => $contact->id,
            ];
        }

        $template = \App\Models\Docuperfect\Template::findOrFail($templateId);

        $stepData = [
            'template' => ['template_id' => $templateId],
            'fields' => $template->fields_json ?? [],
            'property' => [
                'property_id' => $property?->id,
                '_property_source' => 'properties',
                'title' => $property?->title,
                'suburb' => $property?->suburb,
            ],
            'recipients' => ['recipients' => $recipients],
            'details' => [
                'lease_start' => optional($newTerm->start_date)->toDateString(),
                'lease_end' => optional($newTerm->end_date)->toDateString(),
                'monthly_rental' => (string) $newTerm->rental_amount,
                'deposit' => (string) $newTerm->deposit_amount,
                'lease_type' => $newTerm->lease_type,
            ],
        ];

        return Flow::create([
            'type' => 'esign',
            'template_id' => $templateId,
            'user_id' => $user->id,
            'property_id' => $property?->id,
            'contact_id' => $tenants->first()?->contact_id,
            'current_step' => 2,
            'step_data' => $stepData,
            'status' => 'active',
        ]);
    }
}
