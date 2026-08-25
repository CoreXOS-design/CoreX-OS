<?php

namespace App\Services\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\FicaSubmission;

/**
 * The FICA Completion Report — Johan's spec, verbatim: what the recipient
 * actually selected and answered, their signature, the agent with their
 * ticks, and the RO/CO approver with their approval. Deliberately NOT the
 * audit trail (FicaStatusHistory) — no timestamps-as-narrative, no actor-id
 * lists, no event sequence. This is the completed form as a human artefact.
 *
 * Question wording is pulled from the two real screens that ask these
 * questions — resources/views/fica/form.blade.php (recipient) and
 * resources/views/compliance/fica/show.blade.php (agent checklist) /
 * compliance-review.blade.php (CO checklist) — never re-worded here, so the
 * report can never drift from what the person actually saw on screen.
 */
class FicaCompletionReportService
{
    /**
     * @return array{
     *   submission: FicaSubmission,
     *   sections: array<int, array{title: string, rows: array<int, array{label: string, value: string}>}>,
     *   signature: ?string,
     *   agent: ?array{name: string, verified_at: ?string, ticks: array, notes: ?string},
     *   co: ?array{name: string, titles: array<int, string>, verified_at: ?string, ticks: array, notes: ?string, signature: ?string},
     * }
     */
    public function build(FicaSubmission $submission): array
    {
        $submission->loadMissing(['contact', 'agency', 'requestedBy', 'agentVerifiedBy', 'coVerifiedBy']);

        $data = $submission->form_data ?? [];

        return [
            'submission' => $submission,
            'sections'   => $this->recipientSections($submission->entity_type, $data),
            'signature'  => $submission->signature_data ?: ($data['signature_data'] ?? null),
            'agent'      => $this->agentBlock($submission),
            'co'         => $this->coBlock($submission),
        ];
    }

    /**
     * The recipient's own answers, section by section, in the same order
     * and the same words as fica/form.blade.php. Only sections/fields the
     * form actually asked for this entity_type are shown — a field the
     * recipient never saw is never printed as a blank row.
     */
    private function recipientSections(string $entityType, array $data): array
    {
        $sections = [];

        $sections[] = [
            'title' => 'Entity Type',
            'rows'  => [
                ['label' => 'Entity Type', 'value' => $this->entityTypeLabel($entityType)],
            ],
        ];

        $personal = $data['personal'] ?? [];
        if (! empty($personal)) {
            $sections[] = [
                'title' => 'Person Completing This Form',
                'rows'  => array_filter([
                    $this->row('Full Name', $personal['full_name'] ?? null),
                    $this->row('SA Identity Number / Foreign Passport Number', $personal['id_number'] ?? null),
                    $this->row('Are you a South African citizen / permanent resident?', $this->yesNo($personal['sa_citizen'] ?? null)),
                    $this->row('Residential Address', $personal['residential_address'] ?? null),
                    $this->row('Telephone Number', $personal['phone'] ?? null),
                    $this->row('Email Address', $personal['email'] ?? null),
                    $this->row('SA Income Tax Number', $personal['tax_number'] ?? null),
                ]),
            ];
        }

        $entity = $data['entity'] ?? [];
        if ($entityType === 'company' && ! empty($entity)) {
            $sections[] = [
                'title' => 'Company / Close Corporation Details',
                'rows'  => array_filter([
                    $this->row('Company / CC Name', $entity['company_name'] ?? null),
                    $this->row('Registration Number', $entity['company_reg_number'] ?? null),
                    $this->row('Does the company have a presence in South Africa? If yes, provide details', $entity['company_sa_presence'] ?? null),
                    $this->row('Is the company listed on a stock exchange? If so, which?', $entity['company_stock_exchange'] ?? null),
                    $this->row('SARS Income Tax Number', $entity['company_tax_number'] ?? null),
                    $this->row('VAT Registration Number', $entity['company_vat_number'] ?? null),
                    $this->row('Registered Address', $entity['company_registered_address'] ?? null),
                    $this->row('Source of authority to act on behalf of the company', $entity['company_authority_source'] ?? null),
                    $this->row("Describe the company's business — industry, products/services", $entity['company_business_description'] ?? null),
                    $this->row('Describe the ownership and control structure', $entity['company_ownership_structure'] ?? null),
                ]),
            ];
            $this->appendRepeaterSection($sections, 'Beneficial Owners', $entity['beneficial_owners'] ?? []);
        }

        if ($entityType === 'trust' && ! empty($entity)) {
            $sections[] = [
                'title' => 'Trust Details',
                'rows'  => array_filter([
                    $this->row('Trust Name', $entity['trust_name'] ?? null),
                    $this->row("Master's Reference Number", $entity['trust_master_ref'] ?? null),
                    $this->row('Does the trust have a presence in South Africa? If yes, provide details', $entity['trust_sa_presence'] ?? null),
                    $this->row('Which Master of the High Court administers the trust?', $entity['trust_master_office'] ?? null),
                    $this->row('SARS Income Tax Number (if registered)', $entity['trust_tax_number'] ?? null),
                    $this->row('VAT Number (if registered)', $entity['trust_vat_number'] ?? null),
                    $this->row('Source of authority to act on behalf of the trust', $entity['trust_authority_source'] ?? null),
                    $this->row("Describe the trust's purpose or business", $entity['trust_business_description'] ?? null),
                ]),
            ];
            $this->appendRepeaterSection($sections, 'Donor (Person who created the Trust)', array_filter([$entity['donor'] ?? null]));
            $this->appendRepeaterSection($sections, 'Trustees', $entity['trustees'] ?? []);
            if (($entity['has_named_beneficiaries'] ?? null) !== null) {
                $rows = [$this->row('Are there named beneficiaries?', $this->yesNo($entity['has_named_beneficiaries'] ?? null))];
                if (($entity['has_named_beneficiaries'] ?? '') === 'no') {
                    $rows[] = $this->row('How are the beneficiaries determined?', $entity['beneficiaries_determination'] ?? null);
                }
                $sections[] = ['title' => 'Beneficiaries', 'rows' => array_filter($rows)];
                $this->appendRepeaterSection($sections, 'Named Beneficiaries', $entity['beneficiaries'] ?? []);
            }
        }

        if ($entityType === 'partnership' && ! empty($entity)) {
            $sections[] = [
                'title' => 'Partnership Details',
                'rows'  => array_filter([
                    $this->row('Partnership Identifying Name / Trading Name', $entity['partnership_name'] ?? null),
                    $this->row('Does the partnership have a presence in South Africa? If yes, provide details', $entity['partnership_sa_presence'] ?? null),
                    $this->row('Source of authority to act on behalf of the partnership', $entity['partnership_authority_source'] ?? null),
                    $this->row("Describe the partnership's business — industry, products/services", $entity['partnership_business_description'] ?? null),
                    $this->row('Is this a professional partnership?', $this->yesNo($entity['is_professional_partnership'] ?? null)),
                    $this->row('Who are the executive partners controlling day-to-day operations?', $entity['partnership_executive_partners'] ?? null),
                    $this->row('Ownership and control structure', $entity['partnership_ownership_structure'] ?? null),
                    $this->row('SARS Income Tax Number (if registered)', $entity['partnership_tax_number'] ?? null),
                    $this->row('VAT Number (if registered)', $entity['partnership_vat_number'] ?? null),
                ]),
            ];
            $this->appendRepeaterSection($sections, 'Partners', $entity['partners'] ?? []);
        }

        $principal = $data['principal'] ?? [];
        if (($principal['acting_on_behalf'] ?? null) !== null) {
            $rows = [$this->row('Are you dealing with us on behalf of another person?', $this->yesNo($principal['acting_on_behalf']))];
            if (($principal['acting_on_behalf'] ?? '') === 'yes') {
                $rows = array_merge($rows, [
                    $this->row("Principal's Full Name", $principal['full_name'] ?? null),
                    $this->row("Principal's SA ID / Passport Number", $principal['id_number'] ?? null),
                    $this->row('Is the Principal a South African citizen / permanent resident?', $this->yesNo($principal['sa_citizen'] ?? null)),
                    $this->row("Principal's Residential Address", $principal['residential_address'] ?? null),
                    $this->row("Principal's Telephone", $principal['phone'] ?? null),
                    $this->row("Principal's Email", $principal['email'] ?? null),
                    $this->row("Principal's SA Income Tax Number", $principal['tax_number'] ?? null),
                    $this->row('Source of your authority to act on their behalf', $principal['authority_source'] ?? null),
                ]);
            }
            $sections[] = ['title' => 'Principal', 'rows' => array_filter($rows)];
        }

        $representative = $data['representative'] ?? [];
        if (($representative['has_representative'] ?? null) !== null) {
            $rows = [$this->row('Will someone else deal with us on your behalf going forward?', $this->yesNo($representative['has_representative']))];
            if (($representative['has_representative'] ?? '') === 'yes') {
                $rows = array_merge($rows, [
                    $this->row("Representative's Full Name", $representative['full_name'] ?? null),
                    $this->row("Representative's SA ID / Passport Number", $representative['id_number'] ?? null),
                    $this->row("Source of representative's authority", $representative['authority_source'] ?? null),
                ]);
            }
            $sections[] = ['title' => 'Representative', 'rows' => array_filter($rows)];
        }

        $service = $data['service'] ?? [];
        if (! empty($service)) {
            $sections[] = [
                'title' => 'Service & Payment',
                'rows'  => array_filter([
                    $this->row('Purpose of Transaction', $this->transactionPurposeLabel($service['transaction_purpose'] ?? null)),
                    $service['transaction_purpose'] === 'other' ? $this->row('Other', $service['purpose_other'] ?? null) : null,
                    $this->row('How will payments be financed?', $service['payment_method'] ?? null),
                    $this->row('Will any payment involve R50,000 or more in cash?', $this->yesNo($service['cash_over_50k'] ?? null)),
                ]),
            ];
        }

        $pep = $data['pep'] ?? [];
        if (! empty($pep)) {
            $rows = [
                $this->row('Do you now occupy, or have you in the past 12 months occupied, any prominent public position in a country OTHER than South Africa?', $this->yesNo($pep['is_foreign_pep'] ?? null)),
            ];
            if (! empty($pep['foreign_pep'])) {
                $rows[] = $this->row('Foreign PEP position(s)', $this->pepChecklist($pep['foreign_pep']));
            }
            $rows[] = $this->row('Do you now occupy, or have you in the past 12 months occupied, any prominent public position in South Africa?', $this->yesNo($pep['is_domestic_pep'] ?? null));
            if (! empty($pep['domestic_pep'])) {
                $rows[] = $this->row('Domestic PEP position(s)', $this->pepChecklist($pep['domestic_pep']));
            }
            $rows[] = $this->row('Are you a family member or close associate of any person described above?', $this->yesNo($pep['is_family_associate'] ?? null));
            if (($pep['is_family_associate'] ?? '') === 'yes') {
                $rows[] = $this->row('Name the person and indicate their position', $pep['family_associate_details'] ?? null);
            }
            $rows[] = $this->row('Please indicate your source of wealth', $pep['source_of_wealth'] ?? null);
            $sections[] = ['title' => 'Politically Exposed Person (PEP)', 'rows' => array_filter($rows)];
        }

        $declaration = $data['declaration'] ?? [];
        if (! empty($declaration['signed_at_location'])) {
            $sections[] = [
                'title' => 'Declaration',
                'rows'  => [$this->row('Signed at (location)', $declaration['signed_at_location'])],
            ];
        }

        return array_values(array_filter($sections, fn ($s) => ! empty($s['rows'])));
    }

    private function appendRepeaterSection(array &$sections, string $title, array $items): void
    {
        $items = array_values(array_filter($items));
        if (empty($items)) {
            return;
        }
        foreach ($items as $i => $person) {
            $sections[] = [
                'title' => count($items) > 1 ? $title . ' — ' . ($i + 1) : $title,
                'rows'  => array_filter([
                    $this->row('Full Name', $person['full_name'] ?? null),
                    $this->row('SA ID / Passport', $person['id_number'] ?? null),
                    $this->row('Residential Address', $person['residential_address'] ?? null),
                    $this->row('Telephone', $person['phone'] ?? null),
                    $this->row('Email', $person['email'] ?? null),
                ]),
            ];
        }
    }

    private function agentBlock(FicaSubmission $submission): ?array
    {
        // Johan, 2026-08-25: a compliance document must never print "Unknown"
        // for a human being. If agent_verified_by is set but the referenced
        // user record can't be resolved, that's the same as having nothing
        // reliable to show — treat it exactly like no agent at all rather
        // than rendering a half-populated approval box.
        if (! $submission->agent_verified_by || ! $submission->agentVerifiedBy) {
            return null;
        }

        $ticks = $submission->agent_verification_data ?? [];

        return [
            'name'        => $submission->agentVerifiedBy->name,
            'verified_at' => $submission->agent_verified_at?->format('d M Y'),
            'ticks'       => array_filter([
                $this->row('Identity document(s) proving IDENTITY provided?', $this->yna($ticks['identity_docs'] ?? null)),
                $this->row('Document(s) proving ADDRESS provided?', $this->yna($ticks['address_docs'] ?? null)),
                $this->row('Document proving AUTHORITY provided?', $this->yna($ticks['authority_docs'] ?? null)),
                $this->row('Is the client a VIP / PEP?', $this->yna($ticks['is_vip'] ?? null)),
                $this->row('Anything suspicious or unusual?', $this->yna($ticks['suspicious'] ?? null)),
                ($ticks['suspicious'] ?? null) === 'yes' ? $this->row('Suspicious activity — details', $ticks['suspicious_details'] ?? null) : null,
                $this->row('Transaction consistent with knowledge of client?', $this->yna($ticks['consistent'] ?? null)),
            ]),
            'notes'       => $submission->agent_notes,
        ];
    }

    private function coBlock(FicaSubmission $submission): ?array
    {
        // Same rule as agentBlock() — an unresolvable co_verified_by is
        // treated as no approver, never printed as "Unknown".
        if (! $submission->co_verified_by || ! $submission->coVerifiedBy) {
            return null;
        }

        $ticks = $submission->co_verification_data ?? [];

        return [
            'name'        => $submission->coVerifiedBy->name,
            'titles'      => $this->officerTitlesAsAt((int) $submission->co_verified_by, $submission->co_verified_at?->toDateString()),
            'verified_at' => $submission->co_verified_at?->format('d M Y'),
            'ticks'       => array_filter([
                $this->row('Identity document verified?', $this->yna($ticks['identity_docs'] ?? null)),
                $this->row('Address proof verified (< 2 months)?', $this->yna($ticks['address_docs'] ?? null)),
                $this->row('Authority document verified?', $this->yna($ticks['authority_docs'] ?? null)),
                $this->row('Delegating authority verified?', $this->yna($ticks['delegating_docs'] ?? null)),
                $this->row('Client is VIP/PEP?', $this->yna($ticks['is_vip'] ?? null)),
                $this->row('Suspicious or unusual activity?', $this->yna($ticks['suspicious'] ?? null)),
                ($ticks['suspicious'] ?? null) === 'yes' ? $this->row('Suspicious activity — details', $ticks['suspicious_details'] ?? null) : null,
                $this->row('Transaction consistent with knowledge of client?', $this->yna($ticks['consistent'] ?? null)),
                $this->row('TFS screening completed?', $this->yna($ticks['tfs_screening'] ?? null)),
            ]),
            'notes'       => $submission->co_notes,
            'signature'   => $submission->co_signature_data,
        ];
    }

    /**
     * Johan, 2026-08-25: the approval columns record ONE approver but never
     * which capacity they held — a user can hold both mlro and
     * primary_compliance_officer at once (confirmed real case: Elize
     * Reichel). Look up every appointment active ON THE APPROVAL DATE and
     * print all of them. Never picks one, never invents a "primary" the
     * data doesn't support.
     */
    private function officerTitlesAsAt(int $userId, ?string $onDate): array
    {
        if (! $onDate) {
            return [];
        }

        return FicaOfficerAppointment::where('user_id', $userId)
            ->where('appointed_on', '<=', $onDate)
            ->where(function ($q) use ($onDate) {
                $q->whereNull('ended_on')->orWhere('ended_on', '>=', $onDate);
            })
            ->get()
            ->map(fn (FicaOfficerAppointment $a) => $a->title ?: $a->role)
            ->unique()
            ->values()
            ->all();
    }

    private function row(string $label, $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        return ['label' => $label, 'value' => is_array($value) ? implode(', ', $value) : (string) $value];
    }

    private function yesNo($v): ?string
    {
        if ($v === null || $v === '') return null;
        return ucfirst((string) $v);
    }

    private function yna($v): ?string
    {
        if ($v === null || $v === '') return null;
        return $v === 'na' ? 'N/A' : ucfirst((string) $v);
    }

    private function entityTypeLabel(string $type): string
    {
        return match ($type) {
            'natural'     => 'Natural Person (Individual)',
            'company'     => 'Company / Close Corporation',
            'trust'       => 'Trust',
            'partnership' => 'Partnership',
            default       => ucfirst($type),
        };
    }

    private function transactionPurposeLabel(?string $v): ?string
    {
        return match ($v) {
            'sell'     => 'I/We wish to sell a property',
            'purchase' => 'I/We wish to purchase a property',
            'let_out'  => 'I/We wish to let out a property',
            'rent'     => 'I/We wish to rent a property',
            'other'    => 'Other',
            default    => $v,
        };
    }

    /** The PEP checkboxes are stored as raw value keys — map back to the label the recipient actually ticked. */
    private function pepChecklist(array $values): string
    {
        $labels = [
            'head_of_state' => 'Head of state', 'royal_family' => 'Member of the royal family',
            'cabinet_member' => 'Cabinet member', 'political_party' => 'Senior member of a political party',
            'judicial_officer' => 'Senior judicial officer', 'soe_executive' => 'Senior executive of a state-owned entity',
            'military' => 'High rank in the military',
            'president' => 'President or Deputy President of South Africa', 'cabinet_minister' => 'Cabinet Minister or Deputy Minister',
            'premier' => 'Premier of a province', 'mec' => 'MEC of a province', 'mayor' => 'Mayor of a municipality',
            'political_leader' => 'Leader of a political party', 'traditional_leader' => 'Senior traditional leader',
            'dept_head' => 'Head, accounting officer or CFO of a national or provincial department',
            'municipal_manager' => 'Manager or CFO of a municipality',
            'public_entity' => 'Chairperson, CEO, accounting authority, CFO or chief investment officer of a public entity',
            'judge' => 'Judge', 'ambassador' => 'Ambassador, high commissioner or other senior representative of a foreign country based in SA',
            'govt_business' => 'Chairperson of board, chairperson of audit committee, executive officer or CFO of a company doing significant business with government',
        ];

        return implode(', ', array_map(fn ($v) => $labels[$v] ?? $v, $values));
    }
}
