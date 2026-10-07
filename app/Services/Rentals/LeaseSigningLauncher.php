<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\RentalLeaseTemplate;
use App\Models\User;

/**
 * .ai/specs/leases.md §15.4 / §15.21 (Build L1 — foundation). The ONE place a lease's e-sign flow is
 * built: new lease and renewal both use it. In L1 it holds the builder extracted, behaviour-identical,
 * from RenewalDraftService — a flows row in exactly the shape ESignWizardController::store() /
 * saveStep() already write, so the existing wizard reads it unchanged and no e-sign controller or
 * pipeline-gated file is touched. The only addition is `flows.lease_id` (M3), which nothing reads yet.
 *
 * missing() / launch() are the "Create lease & prepare for signing" gate and launch (§15.4 steps 1–5):
 * their real bodies — and the fixed signing order agent → tenant(s) → landlord(s) (R4) — belong to
 * Build L3a. Until then they refuse loudly rather than pretend, and nothing calls them. (The recipient
 * order below is the ORDER THE RENEWAL FLOW HAS ALWAYS USED — agent, landlords, tenants — kept as-is
 * because L1 changes no behaviour; L3a replaces it.)
 */
class LeaseSigningLauncher
{
    /**
     * The step_data fragment (property + recipients + details) used to resolve a template's fields
     * BEFORE a draft term exists — $terms is the proposed rent/dates, not yet persisted. Shared with
     * RenewalDraftService::missingRequiredFields().
     *
     * @param array{start_date?:string,end_date?:?string,rental_amount?:float,deposit_amount?:?float} $terms
     */
    public function buildStepData(Lease $lease, array $terms, User $user): array
    {
        $property = $lease->property;
        $landlords = $lease->landlordContacts();
        $tenants = $lease->tenants()->with('contact')->get();

        $recipients = [];
        $recipients[] = ['role' => 'agent', 'name' => $user->name, 'email' => $user->email ?? ''];
        foreach ($landlords as $landlord) {
            $recipients[] = ['role' => 'landlord', 'name' => $landlord->full_name ?? '', '_contact_id' => $landlord->id];
        }
        foreach ($tenants as $tenant) {
            $contact = $tenant->contact;
            if (!$contact) {
                continue;
            }
            $recipients[] = ['role' => 'tenant', 'name' => $contact->full_name ?? '', '_contact_id' => $contact->id];
        }

        return [
            'property' => [
                'property_id' => $property?->id,
                '_property_source' => 'properties',
                'title' => $property?->title,
                'suburb' => $property?->suburb,
            ],
            'recipients' => ['recipients' => $recipients],
            'details' => [
                'lease_start' => $terms['start_date'] ?? optional($lease->start_date)->toDateString(),
                'lease_end' => $terms['end_date'] ?? optional($lease->end_date)->toDateString(),
                'monthly_rental' => (string) ($terms['rental_amount'] ?? $lease->rental_amount),
                'deposit' => (string) ($terms['deposit_amount'] ?? $lease->deposit_amount),
                'lease_type' => $lease->lease_type,
            ],
        ];
    }

    /**
     * Insert the e-sign `flows` row for an already-created lease term (the renewal copy-forward and
     * draft-from-template paths) — extracted verbatim from RenewalDraftService::buildDraftFlow().
     */
    public function buildFlow(Lease $newTerm, int $templateId, User $user): Flow
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

        $template = Template::findOrFail($templateId);

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
            'lease_id' => $newTerm->id,
        ]);
    }

    /**
     * §15.4 step 1 — everything still missing before the agreement can be prepared (landlord present,
     * every signer with email + ID/passport, the field map's required list). Build L3a.
     *
     * @return array<int, array{key: string, label: string, fix_url: ?string}>
     */
    public function missing(Lease $lease, RentalLeaseTemplate $agreement, array $terms, User $user): array
    {
        throw new \LogicException('LeaseSigningLauncher::missing() is built in Build L3a (leases.md §15.21).');
    }

    /**
     * §15.4 steps 2–5 — guard, then insert the flow with recipients in the fixed order agent →
     * tenant(s) → landlord(s) (R4) and land on Fill & review. Build L3a.
     */
    public function launch(Lease $lease, RentalLeaseTemplate $agreement, User $user): Flow
    {
        throw new \LogicException('LeaseSigningLauncher::launch() is built in Build L3a (leases.md §15.21).');
    }
}
