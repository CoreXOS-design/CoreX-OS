<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicySection;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;

/**
 * Seeds ONE realistic, ACTIVE agency policy for the demo webinar (Johan,
 * 2026-09-03) — the Compliance → Policy Manager screen currently shows the
 * "No policies yet" onboarding prompt because agency_policies has zero rows.
 *
 * Generic content only — a Code of Conduct & Professional Ethics Policy,
 * the canonical first example the framework's own spec names. Nothing
 * branded to any real agency beyond the demo agency's own (fictional) name.
 *
 * IDEMPOTENT: keyed on (agency_id, policy_key='code_of_conduct'). If it
 * already exists, this is a no-op — re-running the seeder never duplicates
 * or overwrites an edited policy.
 */
final class DemoPolicyDocumentSeeder
{
    private const POLICY_KEY = 'code_of_conduct';

    public function run(int $agencyId): array
    {
        $existing = AgencyPolicy::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('policy_key', self::POLICY_KEY)
            ->first();

        if ($existing) {
            return ['created' => false, 'note' => "SKIPPED (already seeded): policy '" . self::POLICY_KEY . "' id={$existing->id}"];
        }

        $agencyName = \App\Models\Agency::withoutGlobalScopes()->find($agencyId)?->name ?? 'the Agency';
        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first()
            ?? User::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        $policy = AgencyPolicy::withoutGlobalScopes()->create([
            'agency_id'   => $agencyId,
            'policy_key'  => self::POLICY_KEY,
            'name'        => 'Code of Conduct & Professional Ethics Policy',
            'description' => 'Governs how ' . $agencyName . ' staff and practitioners deal with clients, colleagues, and the public — professional standards, confidentiality, fair dealing, and the disciplinary process.',
            'is_active'   => true,
        ]);

        $effectiveFrom = now()->subMonths(4)->startOfMonth();
        $approvedAt = $effectiveFrom->copy()->subDays(5);

        $version = PolicyVersion::withoutGlobalScopes()->create([
            'agency_id'        => $agencyId,
            'policy_id'        => $policy->id,
            'version_number'   => 1,
            'title'            => 'Code of Conduct & Professional Ethics Policy v1',
            'status'           => 'active',
            'approved_by'      => $admin?->id,
            'approved_at'      => $approvedAt,
            'approver_title'   => 'Managing Director',
            'approval_notes'   => 'Adopted by management ahead of the ' . $effectiveFrom->format('Y') . ' compliance review cycle.',
            'effective_from'   => $effectiveFrom,
            'next_review_due'  => $effectiveFrom->copy()->addMonths(12),
            'change_notes'     => 'Initial version.',
            'created_by'       => $admin?->id,
            'created_at'       => $approvedAt,
            'updated_at'       => $approvedAt,
        ]);

        $sections = [
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '1',
                'title' => 'Purpose & Scope',
                'body' => "<p>This policy sets out the standards of professional conduct expected of every director, branch manager, agent, and support staff member of {$agencyName} (\"the Agency\") in their dealings with clients, colleagues, competitors, and the public.</p>"
                    . "<p>It applies to all property practitioners registered or employed under the Agency's Fidelity Fund Certificate, and to all administrative staff who handle client information, funds, or documentation in the course of their duties. Compliance with this policy is a condition of continued engagement with the Agency, and breaches are dealt with under the disciplinary process set out in Section 8.</p>",
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '2',
                'title' => 'Professional Standards & PPRA Compliance',
                'body' => '<p>All practitioners must hold a valid Fidelity Fund Certificate (FFC) issued by the Property Practitioners Regulatory Authority (PPRA) before conducting any mandated activity, and must display their FFC number on all marketing material and online listings as required by the Property Practitioners Act 22 of 2019.</p>'
                    . '<p>No practitioner may accept a mandate, advertise a property, or represent a transaction to a client without first confirming that a signed, valid mandate is on file. Practitioners must complete mandatory PPRA continuing professional development (CPD) hours annually and keep the Compliance Officer informed of any change to their FFC status.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '3',
                'title' => 'Client Care & Conflicts of Interest',
                'body' => "<p>Every client — buyer, seller, landlord, or tenant — is entitled to honest, competent, and timeous service. Practitioners must disclose all material facts about a property known to them, and must never mislead a client about price, condition, or competing offers.</p>"
                    . '<p>A practitioner who has a personal or financial interest in a property or a party to a transaction (including a purchase by themselves, a family member, or a business associate) must disclose that interest in writing to the Agency and to the affected client before proceeding. Failure to disclose a conflict of interest is treated as a serious breach of this policy.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '4',
                'title' => 'Confidentiality & Protection of Personal Information',
                'body' => '<p>Client and staff personal information is processed strictly in accordance with the Protection of Personal Information Act (POPIA). Practitioners may only collect, use, or share personal information (ID numbers, financial details, contact information) for the purpose for which it was provided, and must never disclose it to a third party without the data subject\'s consent or a lawful basis.</p>'
                    . '<p>All FICA verification documents, bank confirmation letters, and signed mandates must be stored in the Agency\'s document management system with restricted access, never emailed unencrypted to unauthorised recipients, and retained only for as long as legally required.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '5',
                'title' => 'Fair Dealing & Anti-Discrimination',
                'body' => '<p>The Agency does not tolerate discrimination on the grounds of race, gender, religion, disability, sexual orientation, or any other ground prohibited by the Constitution and the Promotion of Equality and Prevention of Unfair Discrimination Act. All clients and prospective clients are treated equally in the marketing, showing, and sale or letting of property.</p>'
                    . '<p>Practitioners must never accept instructions from a mandator to unfairly exclude, deter, or discriminate against a class of prospective buyer or tenant, and must report any such instruction to the Compliance Officer immediately.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '6',
                'title' => 'Marketing & Advertising Standards',
                'body' => '<p>All property marketing — portal listings, social media, print, and signage — must be truthful, must not misrepresent the property, and must comply with the Consumer Protection Act and applicable advertising codes. A property may only be advertised once a valid mandate is on file, and the advertisement must reflect the current, correct mandate type (sole, open, or dual).</p>'
                    . '<p>Photographs and floor plans must be a fair and current representation of the property. Practitioners must not use another agency\'s copyrighted photography or misrepresent a property as available when it is already under offer, without clearly marking its status.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '7',
                'title' => 'Handling of Client & Trust Funds',
                'body' => '<p>Deposits, rental payments, and any other client funds received by the Agency are held in the Agency\'s trust account and are never mixed with the Agency\'s operating funds. No practitioner may instruct a client to pay deposits or fees directly into a personal or non-trust account under any circumstances.</p>'
                    . '<p>All trust account transactions are reconciled monthly and are subject to audit in accordance with PPRA requirements. Any suspected irregularity in the handling of client funds must be reported to the Compliance Officer without delay, and may be escalated to the PPRA where required by law.</p>',
            ],
            [
                'type' => PolicySection::TYPE_SECTION,
                'number' => '8',
                'title' => 'Disciplinary Process',
                'body' => '<p>A breach of this policy is investigated by the Compliance Officer, who may recommend a verbal or written warning, mandatory retraining, suspension of mandating authority, or termination of the practitioner\'s engagement with the Agency, depending on the severity and repetition of the breach.</p>'
                    . '<p>Serious breaches — including misrepresentation to a client, unauthorised handling of trust funds, or operating without a valid FFC — are reported to the PPRA as required by law, independently of any internal disciplinary outcome. A practitioner subject to disciplinary action retains the right to respond in writing before a final decision is made.</p>',
            ],
            [
                'type' => PolicySection::TYPE_ACKNOWLEDGEMENT,
                'number' => '9',
                'title' => 'Acknowledgement',
                'body' => '<p>By acknowledging this policy, I confirm that I have read, understood, and agree to comply with the Code of Conduct & Professional Ethics Policy in the course of my duties on behalf of ' . $agencyName . '.</p>',
                'requires_ack' => true,
                'ack_prompt' => 'I have read and agree to comply with this Code of Conduct.',
            ],
        ];

        foreach ($sections as $i => $s) {
            PolicySection::withoutGlobalScopes()->create([
                'agency_id'                => $agencyId,
                'policy_version_id'        => $version->id,
                'section_type'             => $s['type'],
                'display_order'            => $i + 1,
                'section_number'           => $s['number'],
                'title'                    => $s['title'],
                'body_html'                => $s['body'],
                'requires_acknowledgement' => $s['requires_ack'] ?? false,
                'acknowledgement_prompt'   => $s['ack_prompt'] ?? null,
                'created_at'               => $approvedAt,
                'updated_at'               => $approvedAt,
            ]);
        }

        return ['created' => true, 'note' => "CREATED: policy '" . self::POLICY_KEY . "' id={$policy->id}, version id={$version->id} (active), " . count($sections) . ' sections'];
    }
}
