<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * .ai/specs/leases.md §15.8 / §15.9 (Build L3c) — the one fixture the change-check and confirm tests share: an agency
 * that is NOT HFC (a Cape Town agency with its own lease agreement), a rental property with a landlord, a tenant, an
 * agent, and a draft lease whose agreement is out for signing — its e-sign document printing the values the launcher
 * would have written (rent, dates, both parties, the agreement details), read back through the agency's own field map.
 *
 * `$this->printed` is what the document prints, by the template's OWN field names; a test changes one of them to
 * simulate an edit made in e-sign by whichever route it wants.
 */
trait BuildsLeaseAgreementFixture
{
    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;

    /** registry key => the agency's own field name in its lease agreement */
    private array $map = [
        'rent' => 'rent_f', 'start_date' => 'start_f', 'end_date' => 'end_f',
        'tenant_name' => 'tenant_f', 'landlord_name' => 'landlord_f',
        'adults' => 'adults_f', 'pets' => 'pets_f', 'escalation_percent' => 'esc_f', 'escalation_month' => 'escm_f',
    ];

    /** field name => what the document prints, as the launcher wrote it */
    private array $printed = [
        'rent_f' => '8500.00', 'start_f' => '2026-11-01', 'end_f' => '2027-10-31',
        'tenant_f' => 'Thandi Nkosi', 'landlord_f' => 'Pieter Botha',
        'adults_f' => '2', 'pets_f' => 'One cat', 'esc_f' => '7.5', 'escm_f' => 'November',
    ];

    protected function setUpAgreementFixture(): void
    {
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->tenant = $this->contact('Thandi', 'Nkosi', '8002025009081', '4 Beach Road, Umhlanga');
        $this->landlord = $this->contact('Pieter', 'Botha', '7001015009087', '9 Hill Street, Durban');
        ContactProperty::create(['contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
    }

    private function contact(string $first, string $last, ?string $idNumber, ?string $address = null): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first) . uniqid() . '@example.test', 'id_number' => $idNumber, 'address' => $address,
        ]);
    }

    /**
     * A draft lease with its agreement out (or waiting for the agent's approval), linked to its envelope, document and
     * the agency's field map; one tenant; its agreement details on file.
     *
     * @param array<string,mixed> $leaseOverrides
     * @param array<string,mixed>|null $map  replaces the default map
     * @return array{0: Lease, 1: SignatureTemplate, 2: Document, 3: RentalLeaseTemplate}
     */
    private function agreementOut(array $leaseOverrides = [], string $envelopeStatus = SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, ?array $map = null, ?string $signingStatus = null): array
    {
        $document = Document::create([
            'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'web_template_data' => ['canonical_html' => $this->html($this->printed)],
        ]);
        $envelope = SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => $envelopeStatus, 'created_by' => $this->agent->id,
        ]);

        $template = new Template(['name' => 'Cape residential lease', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $row = RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Cape residential lease', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true, 'field_map' => $map ?? $this->map,
        ]);

        $lease = Lease::create($leaseOverrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'signing_status' => $signingStatus ?? ($envelopeStatus === SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL ? Lease::SIGNING_AWAITING_AGENT_REVIEW : Lease::SIGNING_OUT_FOR_SIGNING),
            'signature_template_id' => $envelope->id, 'agreement_document_id' => $document->id, 'agreement_template_id' => $template->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->forceFill(['adults' => 2, 'pets' => 'One cat', 'escalation_percent' => 7.5, 'escalation_month' => 11])->save();

        return [$lease, $envelope, $document, $row];
    }

    /** Rewrite what the document prints, the way an edit in e-sign would (by `data-field` span in the stored HTML). */
    private function print(Document $document, array $changes): void
    {
        $this->printed = $changes + $this->printed;
        $data = (array) $document->web_template_data;
        $data['canonical_html'] = $this->html($this->printed);
        $document->update(['web_template_data' => $data]);
    }

    /** @param array<string,string> $printed */
    private function html(array $printed): string
    {
        $out = '<div class="lease">';
        foreach ($printed as $field => $text) {
            $out .= '<p><span data-field="' . $field . '">' . htmlspecialchars((string) $text) . '</span></p>';
        }

        return $out . '</div>';
    }
}
