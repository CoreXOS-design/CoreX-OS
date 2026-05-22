<?php

declare(strict_types=1);

namespace Database\Seeders\Compliance;

use App\Models\Compliance\Rcr\RcrAnswer;
use App\Models\Compliance\Rcr\RcrQuestion;
use App\Models\Compliance\Rcr\RcrQuestionnaire;
use App\Models\Compliance\Rcr\RcrQuestionnaireSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9d.1 C — FIC Directive 11 of 2026 verbatim questionnaire.
 *
 * REPLACES the Phase 9d invented thematic groupings (A–N composite + 1–6
 * estate-agents) with FIC's actual Part 1 / Part 2 / Part 3 / Part 8
 * structure and verbatim question wording.
 *
 * Idempotent: re-running produces the same end state. Sections+questions
 * scoped to the two FIC questionnaires are truncated first (cascade deletes
 * answers — acceptable per Phase 9d.1 investigation A.9 because no
 * production submissions exist; the 9d "19 passing tests" were ad-hoc).
 *
 * Each question entry: [code, parent_code|null, text, footnote|null,
 *   answer_type, options|null, evidence_sources[], auto_populate_hint|null]
 */
final class FicDirective11Seeder extends Seeder
{
    public function run(): void
    {
        $composite = $this->upsertQuestionnaire([
            'key'                   => 'fic_2026_composite',
            'title'                 => 'FIC 2026 RCR Composite Questionnaire',
            'description'           => 'Risk and Compliance Return covering AML / CTF / PF programme, governance, CDD, monitoring, and reporting. Reporting period 1 April 2023 to 31 March 2026; submission deadline 31 July 2026. Three reporting periods: P1 (2023-07 → 2024-03), P2 (2024-04 → 2025-03), P3 (2025-04 → 2026-03).',
            'directive_reference'   => 'Directive 11 of 2026',
            'reporting_period_from' => '2023-04-01',
            'reporting_period_to'   => '2026-03-31',
            'submission_deadline'   => '2026-07-31',
            'submission_platform'   => 'FIC goAML',
            'sort_order'            => 1,
        ]);

        $sector = $this->upsertQuestionnaire([
            'key'                   => 'fic_2026_estate_agents',
            'title'                 => 'FIC 2026 RCR Estate Agents Sector-Specific (Part 8)',
            'description'           => 'Sector-specific addendum for property practitioners under PPRA. Same reporting period and deadline as the Composite questionnaire.',
            'directive_reference'   => 'Directive 11 of 2026',
            'reporting_period_from' => '2023-04-01',
            'reporting_period_to'   => '2026-03-31',
            'submission_deadline'   => '2026-07-31',
            'submission_platform'   => 'FIC goAML',
            'sort_order'            => 2,
        ]);

        // Truncate old questions+sections scoped to these two questionnaires.
        // Need to delete answers first since rcr_answers.question_id has a
        // RESTRICT FK. See investigation audit A.9 — any pre-9d.1 answer rows
        // are ad-hoc test data and acceptable to drop.
        $oldIds = collect([$composite->id, $sector->id]);
        $oldQuestionIds = DB::table('rcr_questions')->whereIn('questionnaire_id', $oldIds)->pluck('id');
        if ($oldQuestionIds->isNotEmpty()) {
            DB::table('rcr_answer_evidence')
                ->whereIn('answer_id', DB::table('rcr_answers')->whereIn('question_id', $oldQuestionIds)->pluck('id'))
                ->delete();
            DB::table('rcr_answers')->whereIn('question_id', $oldQuestionIds)->delete();
        }
        DB::table('rcr_questions')->whereIn('questionnaire_id', $oldIds)->delete();
        DB::table('rcr_questionnaire_sections')->whereIn('questionnaire_id', $oldIds)->delete();

        $this->seedCompositeSections($composite);
        $this->seedEstateAgentsSections($sector);
    }

    private function upsertQuestionnaire(array $attrs): RcrQuestionnaire
    {
        return RcrQuestionnaire::updateOrCreate(
            ['key' => $attrs['key']],
            array_merge($attrs, ['is_active' => true, 'issued_by' => 'FIC']),
        );
    }

    private function seedCompositeSections(RcrQuestionnaire $q): void
    {
        $sections = [
            ['institution_details',  'Details of Institution',          false, null],
            ['about_institution',    'About the Institution',           false, null],
            ['part_1_general_risk',  'Part 1 — General Risk Questions', true,  null],
            ['part_2_pf',            'Part 2 — Proliferation Financing', true, null],
            ['part_3_tf',            'Part 3 — Terrorist Financing',    true,  null],
            ['declaration',          'Declaration',                     false, null],
        ];
        $sectionRows = [];
        foreach ($sections as $i => [$code, $title, $hasPeriods, $applies]) {
            $sectionRows[$code] = RcrQuestionnaireSection::updateOrCreate(
                ['questionnaire_id' => $q->id, 'section_code' => $code],
                [
                    'title'              => $title,
                    'sort_order'         => $i + 1,
                    'has_period_columns' => $hasPeriods,
                    'applies_when_json'  => $applies,
                ],
            );
        }

        $order = 1;
        foreach ($this->institutionDetails()           as $row) $this->upsertQuestion($q, $sectionRows['institution_details'], $row, $order++);
        foreach ($this->aboutInstitution()             as $row) $this->upsertQuestion($q, $sectionRows['about_institution'], $row, $order++);
        foreach ($this->part1GeneralRisk()             as $row) $this->upsertQuestion($q, $sectionRows['part_1_general_risk'], $row, $order++);
        foreach ($this->part2ProliferationFinancing()  as $row) $this->upsertQuestion($q, $sectionRows['part_2_pf'], $row, $order++);
        foreach ($this->part3TerroristFinancing()      as $row) $this->upsertQuestion($q, $sectionRows['part_3_tf'], $row, $order++);
        foreach ($this->declaration()                  as $row) $this->upsertQuestion($q, $sectionRows['declaration'], $row, $order++);
    }

    private function seedEstateAgentsSections(RcrQuestionnaire $q): void
    {
        $section = RcrQuestionnaireSection::updateOrCreate(
            ['questionnaire_id' => $q->id, 'section_code' => 'part_8_estate_agents'],
            [
                'title'              => 'Part 8 — Estate Agents',
                'sort_order'         => 1,
                'has_period_columns' => false,
                'applies_when_json'  => ['sector' => 'estate_agent'],
            ],
        );

        $order = 1;
        foreach ($this->part8EstateAgents() as $row) $this->upsertQuestion($q, $section, $row, $order++);
    }

    private function upsertQuestion(RcrQuestionnaire $q, RcrQuestionnaireSection $section, array $row, int $order): void
    {
        [$code, $parentCode, $text, $footnote, $answerType, $options, $sources, $autoHint] = $row;

        $evidenceArray = is_array($sources) && count($sources) > 0 ? array_values($sources) : null;
        $legacyPrimary = $evidenceArray ? $evidenceArray[0] : null;

        RcrQuestion::updateOrCreate(
            ['questionnaire_id' => $q->id, 'question_code' => $code],
            [
                'section_id'                 => $section->id,
                'parent_code'                => $parentCode,
                'question_text'              => $text,
                'footnote'                   => $footnote,
                'answer_type'                => $answerType,
                'answer_options_json'        => $options,
                'is_required'                => true,
                'auto_population_source'     => $legacyPrimary,
                'evidence_source_codes_json' => $evidenceArray,
                'auto_populate_hint'         => $autoHint,
                'sort_order'                 => $order,
            ],
        );
    }

    // ── Institution details ──────────────────────────────────────────────

    private function institutionDetails(): array
    {
        return [
            ['inst.legal_name',        null, 'Full registered or legal name',                    null, 'free_text', null, ['agency.profile'], 'Pulls agency.name.'],
            ['inst.business_address',  null, 'Business address',                                 null, 'free_text', null, ['agency.profile'], 'Pulls agency.address.'],
            ['inst.contact_number',    null, 'Contact number',                                   null, 'free_text', null, ['agency.profile'], 'Pulls agency.phone.'],
            ['inst.email',             null, 'E-mail address',                                   null, 'free_text', null, ['agency.profile'], 'Pulls agency.email.'],
            ['inst.representatives',   null, "Institution's representative(s)",                  null, 'free_text', null, ['agency.fica_officer.primary', 'agency.fica_officer.mlro'], 'Primary CO + MLRO names.'],
            ['inst.org_id',            null, 'ORG ID number (FIC accountable institution registration)', null, 'free_text', null, [], 'Manual entry — capture from FIC goAML profile.'],
            ['inst.sector',            null, 'Sector',                                           null, 'free_text', null, [], 'Static value: "Estate Agents — Item 3, FIC Act Schedule 1"'],
            ['inst.institution_type',  null, 'Institution type',                                 null, 'free_text', null, [], 'Manual entry.'],
            ['inst.location',          null, 'Location',                                         null, 'free_text', null, ['agency.profile'], 'Suburb / town from agency profile.'],
        ];
    }

    // ── About the Institution ────────────────────────────────────────────

    private function aboutInstitution(): array
    {
        return [
            ['about.structure',          null, 'Structure of the institution', null, 'single_select',
                ['Public company','Private company','Sole proprietor','Partnership','Close corporation','Other'],
                [], 'Derive from agency entity_type if present.'],
            ['about.products_services',  null, "List the institution's products or services", null, 'free_text', null, [], null],
            ['about.annual_turnover',    null, 'Annual turnover for the last financial year', null, 'number', null, [], 'Manual — Johan / Sage.'],
            ['about.criminal_charges',   null, 'Does any senior manager or beneficial owner of the institution have criminal charges?', null, 'yes_no', RcrAnswer::OPTIONS_YES_NO, [], null],
            ['about.employee_count',     null, 'Number of employees', null, 'number', null, [], null],
        ];
    }

    // ── Part 1 — General Risk (verbatim FIC) ─────────────────────────────

    private function part1GeneralRisk(): array
    {
        $yn = RcrAnswer::OPTIONS_YES_NO;
        return [
            ['1.1',  null, 'What percentage of your clients are natural persons?',                              null, 'percentage', null, [], null],
            ['1.2',  null, 'What percentage of your natural person clients are foreign nationals?',             null, 'percentage', null, [], null],
            ['1.3',  null, 'What percentage of your clients are foreign legal persons?',                        null, 'percentage', null, [], null],
            ['1.4',  null, 'What percentage of your clients are trusts?',                                       null, 'percentage', null, [], null],
            ['1.5',  null, 'Does your institution conduct business with any domestic politically exposed persons (PEPs) or their close family members or close associates?', null, 'yes_no', $yn, ['edd.pep_screenings'], 'Auto-populated from contact PEP flags when present; manual otherwise.'],
            ['1.6',  null, 'What percentage of your clients have been identified as high-risk domestic PEPs?',  null, 'percentage', null, [], null],
            ['1.7',  null, 'What percentage of your clients have been identified as high-risk Domestic Prominent Influential Persons?', null, 'percentage', null, [], null],
            ['1.8',  null, 'Does your institution conduct business with any foreign PEPs?',                     null, 'yes_no', $yn, ['edd.pep_screenings'], null],
            ['1.9',  null, 'What percentage of your clients have been identified as foreign PEPs?',             null, 'percentage', null, [], null],
            ['1.10', null, 'Does the institution onboard clients on a non-face-to-face basis (i.e. social media platforms, electronic platforms or agents?)', null, 'yes_no', $yn, [], null],
            ['1.11', null, 'Does your institution accept cash in the conclusion of any transactions (not applicable to electronic funds transfers)?', null, 'yes_no', $yn, [], null],
            ['1.12', null, 'Do you allow third parties to transact on behalf of your clients?',                 null, 'yes_no', $yn, [], null],
            ['1.13', null, 'Has your institution refunded or reversed client monies paid into your accounts where a client had not provided KYC documents?', null, 'yes_no', $yn, [], null],
            ['1.14', null, 'Does your institution conduct cross-border transactions?',                          null, 'yes_no', $yn, [], null],
            ['1.15', null, 'Does your institution screen employees against the TF list?',                       null, 'yes_no', $yn, [], null],
            ['1.16', null, "Do you 'risk rate' your products and services for ML/TF/PF when introducing new products and services?", null, 'yes_no', $yn, [], null],
            ['1.17', null, 'Is your institution situated within 100km of an international border?',             null, 'yes_no', $yn, [], null],
            ['1.18', null, 'Does your institution conduct business with clients from countries regarded as high-risk for ML/TF/PF purposes?', null, 'yes_no', $yn, [], null],
            ['1.19', null, 'Does your institution conduct business with clients from countries regarded as tax havens or high-secrecy jurisdictions?', null, 'yes_no', $yn, [], null],
            ['1.20', null, 'Does your institution conduct business with clients from countries on the Financial Action Task Force blacklist?',
                'Democratic Republic of North Korea, Islamic Republic of Iran, and Republic of Myanmar', 'yes_no', $yn, [], null],
            ['1.21', null, 'Does your institution screen potential clients against the TFS list before onboarding?', null, 'yes_no', $yn, ['edd.sanctions_screenings'], null],
            ['1.22', null, 'Does your institution screen its clients against the TFS list when the list is updated?', null, 'yes_no', $yn, ['edd.sanctions_screenings'], null],
            ['1.23', null, 'Are you aware of the appropriate compliance obligations that arise when conducting business with individuals and institutions on such lists?', null, 'yes_no', $yn, [], null],
            ['1.24', null, 'Does your institution have a risk management and compliance programme (RMCP) implemented?', null, 'yes_no', $yn, ['rmcp.exists', 'rmcp.sections_count'], 'Positive section count = Yes.'],
            ['1.25', null, 'Does your institution have branches and/or subsidiaries?',                          null, 'yes_no', $yn, [], null],
            ['1.26', null, 'Does your institution have a compliance function?',                                 null, 'yes_no', $yn, ['agency.fica_officer.primary'], 'Active FICA officer = Yes.'],
            ['1.27', null, 'Does your institution provide ongoing training to its employees to enable them to comply with the FIC Act and the RMCP?', null, 'yes_no', $yn, ['training.completed_pct', 'rmcp.acknowledgements_complete_pct'], null],
            ['1.28', null, 'Does your institution have processes and procedures in place for the identification and verification of clients?', null, 'yes_no', $yn, ['cdd.completed_in_period'], null],
            ['1.29',   null,  'Does your institution have processes and procedures in place to establish whether a prospective client or beneficial owner of the prospective client in a business relationship is a:', null, 'yes_no', $yn, [], null],
            ['1.29.1', '1.29', 'Foreign politically exposed person?',                                           null, 'yes_no', $yn, [], null],
            ['1.30',   null,  'Family member or known close associate of the foreign politically exposed person?', null, 'yes_no', $yn, [], null],
            ['1.31',   null,  'Domestic politically exposed person',                                            null, 'yes_no', $yn, [], null],
            ['1.32',   null,  'Family member or known close associate of the domestic politically exposed person', null, 'yes_no', $yn, [], null],
            ['1.33',   null,  'Does your institution establish the:',                                           null, 'yes_no', $yn, [], null],
            ['1.33.1', '1.33', 'Nature of the business relationship?',                                          null, 'yes_no', $yn, [], null],
            ['1.34',   null,  'Intended purpose of the business relationship?',                                 null, 'yes_no', $yn, [], null],
            ['1.35',   null,  'Source of funds?',                                                               null, 'yes_no', $yn, [], null],
            ['1.36',   null,  'When the client is a legal or natural person who is acting on behalf of a partnership, trust, or similar arrangements between natural persons, does the institution establish the:', null, 'yes_no', $yn, [], null],
            ['1.36.1', '1.36', 'Ownership and control structure of the client?',                                null, 'yes_no', $yn, [], null],
            ['1.37',   null,  'Does your institution establish the identity of the ultimate beneficial owner/s of the client?', null, 'yes_no', $yn, [], null],
            ['1.38',   null,  'Does your institution establish the identity of the natural person who exercises effective control of the legal person client?', null, 'yes_no', $yn, [], null],
            ['1.39',   null,  'Does your institution establish the identity of each founder and trustee and beneficiary of the trust (where the client is a trust)?', null, 'yes_no', $yn, [], null],
            ['1.40',   null,  'Does your institution establish the identity of the founder / trustee / beneficiary of the trust if the founder / trustee / beneficiary is a legal person? (where the client is a trust)', null, 'yes_no', $yn, [], null],
            ['1.41',   null,  'Does your institution monitor transactions undertaken throughout the course of the business relationship, including:', null, 'yes_no', $yn, [], null],
            ['1.41.1', '1.41', 'Ascertaining the source of funds',                                              null, 'yes_no', $yn, [], null],
            ['1.42',   null,  'Background and purpose of complex, unusual large transactions',                  null, 'yes_no', $yn, [], null],
            ['1.43',   null,  'Unusual patterns of transactions which have no apparent business or lawful purpose', null, 'yes_no', $yn, [], null],
            ['1.44',   null,  'Does your institution keep the customer identification and verification documents up to date?', null, 'yes_no', $yn, [], null],
            ['1.45',   null,  'Does your institution take additional steps when it has doubts about the veracity or adequacy of previously obtained information?', null, 'yes_no', $yn, [], null],
            ['1.46',   null,  'Does your institution file regulatory reports with the FIC under section 29 of the FIC Act if unable to conduct customer due diligence?', null, 'yes_no', $yn, [], null],
            ['1.47',   null,  'Does your institution keep records on clients?',                                 null, 'yes_no', $yn, [], null],
            ['1.48',   null,  'Does your institution keep records of client information?',                      null, 'yes_no', $yn, [], null],
            ['1.49',   null,  'Does your institution keep records of transactions?',                            null, 'yes_no', $yn, [], null],
            ['1.50',   null,  'Does your institution keep client records for five years after the termination of a business relationship?', null, 'yes_no', $yn, [], null],
            ['1.51',   null,  'Does your institution use a third party to keep client records?',                null, 'yes_no', $yn, [], null],
            ['1.51.1', '1.51', 'If yes to the previous question, does your institution have sufficient information about the third party and does the institution:', null, 'yes_no', $yn, [], null],
            ['1.52',   null,  'Have free and unencumbered access to the relevant records?',                     null, 'yes_no', $yn, [], null],
            ['1.53',   null,  'Are the records kept by the third party readily accessible to the FIC and/or the relevant supervisory body when required?', null, 'yes_no', $yn, [], null],
            ['1.54',   null,  'Are the records capable of being reproduced in a legible format?',               null, 'yes_no', $yn, [], null],
            ['1.55',   null,  'Have the full name and contact particulars of the individual who exercises control over access to those records?', null, 'yes_no', $yn, [], null],
            ['1.56',   null,  'Have the address where the records are kept?',                                   null, 'yes_no', $yn, [], null],
            ['1.57',   null,  'Did your institution file section 28 reports (cash threshold reports) for the reporting period?', null, 'yes_no', $yn, [], null],
            ['1.58',   null,  'Has your institution filed reports under section 28A i.e., terrorist property reports, during the reporting year?', null, 'yes_no', $yn, [], null],
            ['1.59',   null,  'Does your institution have a process in place for the identification and reporting of suspicious and unusual transactions S29 reporting?', null, 'yes_no', $yn, ['str.filed_count'], null],
            ['1.60',   null,  "Is your institution's staff aware that they may not disclose information about the contents of section 29 reports?", null, 'yes_no', $yn, [], null],
            ['1.61',   null,  'Was your institution served with any subpoenas in terms of section 205 Criminal Procedures Act, 1977 (Act 51 of 1977), received any enquiries or requests for information from the FIC, investigative authorities or other regulatory bodies in respect of any transaction concluded with a client?', null, 'yes_no', $yn, [], null],
            ['1.62',   null,  'Have any clients of your institution enquired as to whether your institution had reported them to the FIC?', null, 'yes_no', $yn, [], null],
            ['1.63',   null,  'Does your institution have processes and procedures in place to deal with a section 32 request from the FIC?', null, 'yes_no', $yn, [], null],
        ];
    }

    private function part2ProliferationFinancing(): array
    {
        $yn = RcrAnswer::OPTIONS_YES_NO;
        return [
            ['2.1',  null, 'Do you have any dealings/interactions with clients that provide products and services to the three countries that are on the FATF Blacklist (Democratic Republic of North Korea, Islamic Republic of Iran, and Republic of Myanmar), considered high risk for PF purposes.', null, 'yes_no', $yn, [], null],
            ['2.2',  null, 'Do you have any dealings/interactions with the three countries listed on the FATF Blacklist [See 2.1 above]?', null, 'yes_no', $yn, [], null],
            ['2.3',  null, 'Do you produce or deal in products that may be used in the proliferation of weapons of mass destruction?', null, 'yes_no', $yn, [], null],
            ['2.4',  null, 'Do you assess PF risks related to dual-use goods?',
                "For guidance on dual-use goods or 'controlled goods and activities' – Refer to Public compliance communication 44A.", 'yes_no', $yn, [], null],
            ['2.5',  null, 'Do you screen for PF red flags from FATF Guidance 2024?',                          null, 'yes_no', $yn, [], null],
            ['2.6',  null, 'Do you have PF risk factors unique to identify possible instances of PF when dealing with clients?', null, 'yes_no', $yn, [], null],
            ['2.7',  null, 'Do you have clients that are nationals of countries that are FATF black-listed? Which percentage?', null, 'percentage', null, [], null],
            ['2.8',  null, 'Do you have clients that process funds to or from countries that are on the FATF blacklist?', null, 'yes_no', $yn, [], null],
            ['2.9',  null, 'Do you follow a process to screen all existing client information against updates to the TFS list without delay?', null, 'yes_no', $yn, [], null],
            ['2.10', null, 'Do you have clients that deal with controlled good or dual use goods? Refer to PCC 44A on targeted financial sanctions.', null, 'yes_no', $yn, [], null],
            ['2.11', null, 'Do you follow a process to freeze without delay funds that are linked to a designated person?', null, 'yes_no', $yn, [], null],
            ['2.12', null, 'Do you follow a process to report without delay funds or business relations that are linked to a designated person?', null, 'yes_no', $yn, [], null],
            ['2.13', null, 'Do you identify beneficial owners of clients that are foreign legal companies or arrangements?', null, 'yes_no', $yn, [], null],
            ['2.14', null, 'Do you provide training to staff on aspects of proliferation financing?',          null, 'yes_no', $yn, [], null],
        ];
    }

    private function part3TerroristFinancing(): array
    {
        $yn = RcrAnswer::OPTIONS_YES_NO;
        return [
            ['3.1',  null, 'Does your institution operate in any areas that are considered a high risk from a TF perspective? Consider media reports and crime statistics.', null, 'yes_no', $yn, [], null],
            ['3.2',  null, 'Do you have customers that are citizens from countries that are subject to UNSC sanctions measures?', null, 'yes_no', $yn, [], null],
            ['3.3',  null, 'Do you conclude transactions to or from the geographic locations that pose a heightened risk of terrorist financing?', null, 'yes_no', $yn, [], null],
            ['3.4',  null, 'Do you transact with CASPs or accept crypto assets as payment from countries that pose a high risk for terrorist financing?', null, 'yes_no', $yn, [], null],
            ['3.5',  null, 'Do you have clients that are complex legal structures, legal persons or trusts that are from countries that are highlighted in the SA 2024 TF NRA?',
                'Refer to the South African National Terrorism Financing Risk Assessment dated 24 June 2024.', 'yes_no', $yn, [], null],
            ['3.6',  null, 'Do you accept payments of prepaid cards, Money Value Transfer Services (mobile money) or Alternative remittance services?', null, 'yes_no', $yn, [], null],
            ['3.7',  null, 'Do you accept third parties to collect cash on behalf of money remitters?',        null, 'yes_no', $yn, [], null],
            ['3.8',  null, 'Do you send funds to countries in Africa that are known for terrorist activity?',  null, 'yes_no', $yn, [], null],
            ['3.9',  null, 'Do you conduct transactions with clients that facilitate transfers to high-risk countries from a terrorist financing perspective?', null, 'yes_no', $yn, [], null],
            ['3.10', null, 'Do you conduct transactions with hawala and remittance networks?',                 null, 'yes_no', $yn, [], null],
            ['3.11', null, 'Do you facilitate travel to and from conflict areas?',                             null, 'yes_no', $yn, [], null],
            ['3.12', null, 'Do you conduct transactions in crypto assets or trade in commodities (oil, diamonds, or gold)?', null, 'yes_no', $yn, [], null],
            ['3.13', null, 'Do you establish business relationships with clients using passports, asylum seeker/refugee permits as proof of identity?', null, 'yes_no', $yn, [], null],
            ['3.14', null, 'Do you conduct transactions of Krugerrand gold / silver coins and / or foreign currency?', null, 'yes_no', $yn, [], null],
            ['3.15', null, 'Does your institution conduct business with dealers of precious metals or stones (DPMS) that are in proximity (less than 100 kms) to conflict areas?', null, 'yes_no', $yn, [], null],
            ['3.16', null, 'Do you conduct transactions with non-profit organisations and/or non-governmental institutions?', null, 'yes_no', $yn, [], null],
            ['3.17', null, 'Do you conduct business with unregistered or voluntary NPOs?',                     null, 'yes_no', $yn, [], null],
            ['3.18', null, 'Do you conduct business with charities and organisations involved in health, faith based, humanitarian and educational work operating in terror/conflict areas?', null, 'yes_no', $yn, [], null],
            ['3.19', null, 'If answered yes above, do you ensure that the use of the funds and/or properties involved are used in accordance with the stated objectives of these organisations?', null, 'yes_no', $yn, [], null],
            ['3.20', null, 'Have you submitted an STR/SAR relating to TF/PF in the last year?',                null, 'yes_no', $yn, ['str.filed_count'], null],
            ['3.21', null, 'Does your institution conduct business with clients in the arms/national defence industry?', null, 'yes_no', $yn, [], null],
            ['3.22', null, 'Does your institution conclude transactions with customers from countries that are involved in armed conflict or terrorist activity?', null, 'yes_no', $yn, [], null],
            ['3.23', null, 'Does your institution conduct business with customers that have a significant social media presence, and allege to be raising funds for charitable organisations in conflict areas?', null, 'yes_no', $yn, [], null],
            ['3.24', null, 'Do you monitor TF crowdfunding typologies?',                                       null, 'yes_no', $yn, [], null],
            ['3.25', null, 'Do you track clients linked to the Islamic State of Iraq and the Levant (ISIS/ISIL) returnees?', null, 'yes_no', $yn, [], null],
            ['3.26',   null,  'Where you suspect that a potential client is a sanctioned person or entity or is linked to a sanctioned person or entity?', null, 'yes_no', $yn, [], null],
            ['3.26.1', '3.26', 'Do you have processes to further investigate the validity of this suspicion (e.g. do you scrutinise their information against the TFS list, do you conduct an adverse media search)?', null, 'yes_no', $yn, [], null],
            ['3.27',   null,  'Do you have a process in place to report without delay to the FIC where a designated person or entity tries to transact with you?', null, 'yes_no', $yn, [], null],
            ['3.28',   null,  'Do you follow a process to scrutinize client information against the TFS lists without delay from date of updates to the TFS list?', null, 'yes_no', $yn, [], null],
            ['3.29',   null,  'Within which period do you screen your client data against the TFS list?', null, 'single_select', RcrAnswer::OPTIONS_FREQUENCY_BAND, [], null],
            ['3.30',   null,  'Do you monitor developments on domestic terrorist activity?',                   null, 'yes_no', $yn, [], null],
            ['3.31',   null,  'Do you screen your client information without delay against the section 23 POCDATARA court orders as published from time to time?', null, 'yes_no', $yn, [], null],
        ];
    }

    private function declaration(): array
    {
        return [
            ['decl.signatory_name', null, 'Name of person making declaration', null, 'free_text', null, ['agency.fica_officer.primary'], 'Defaults to the primary FICA compliance officer.'],
            ['decl.signature_date', null, 'Signature date',                    null, 'free_text', null, [], 'Set on lock.'],
        ];
    }

    private function part8EstateAgents(): array
    {
        $yn = RcrAnswer::OPTIONS_YES_NO;
        $freq = RcrAnswer::OPTIONS_FREQUENCY_BAND;
        return [
            ['8.1',   null,  'What is the nature of your business?', null, 'multi_select',
                ["Seller's agent/listing agent", 'Renting agent', 'Estate agent or attorney employee'], [], null],
            ['8.2',   null,  'Which properties do you provide sales or rentals in respect of?', null, 'multi_select',
                ['Residential', 'Undeveloped land', 'Agricultural', 'Industrial/commercial'], [], null],
            ['8.3',   null,  'Do you offer rental services?',                                                       null, 'yes_no', $yn, [], null],
            ['8.3.1', '8.3', 'Do you enquire about the purpose for which the property is required?',                null, 'yes_no', $yn, [], null],
            ['8.3.2', '8.3', 'Do you assess whether the rate at which the property is rented out, is market-related?', null, 'yes_no', $yn, [], null],
            ['8.3.3', '8.3', 'Are you made aware of whether there are requests for cancellation of transactions and refunds of monies already paid?', null, 'yes_no', $yn, [], null],
            ['8.3.4', '8.3', 'In the previous financial year, what is the total number of leasing contracts executed by your entity?', null, 'number', null, [], null],
            ['8.4',   null,  'What is the total number of property sales or purchases that were facilitated by your establishment in the reporting period?', null, 'number', null, ['transactions.total_count'], 'Auto-populates from registered deals in window.'],
            ['8.5',   null,  'Do you conduct business in areas that have been highlighted as facing a heightened risk of money laundering?', null, 'yes_no', $yn, [], null],
            ['8.6',   null,  "Do you assess whether the client's income is consistent with the property they seek to purchase or rent?", null, 'yes_no', $yn, [], null],
            ['8.7',   null,  'How often do clients request to discontinue the business relationship upon a request for customer due diligence information?', null, 'single_select', $freq, [], null],
            ['8.8a',  null,  'What percentage of your clients purchase or rent property through the use of a third party?', null, 'percentage', null, [], null],
            ['8.8b',  null,  'What percentage of your transactions are conducted in cash?',                          null, 'percentage', null, [], 'PDF duplicates 8.8 — disambiguated as 8.8a / 8.8b.'],
            ['8.9',   null,  'What percentage of your listings are between the value of R5 million and under R10 million?', null, 'percentage', null, ['transactions.high_value_count'], null],
            ['8.10',  null,  'What percentage of your listings are equal to or over the value of R10 million?',      null, 'percentage', null, [], null],
            ['8.11',  null,  'How frequently do your clients make multiple cash payments in quick succession?',      null, 'single_select', $freq, [], null],
            ['8.12',  null,  'How frequently do the parties involved in the transaction request the payments to be divided into several smaller payments made at shorter intervals?', null, 'single_select', $freq, [], null],
            ['8.13',  null,  'When conducting transactions through a third party, do you identify and verify the third party?', null, 'yes_no', $yn, [], null],
            ['8.14',  null,  'What percentage of your sales were of clients purchasing on behalf of another person?', null, 'percentage', null, [], null],
            ['8.15',   null,   'Has a client or a potential client:',                                                 null, 'yes_no', $yn, [], null],
            ['8.15.1', '8.15', 'concluded a transaction where the payment or part thereof was made by a third party.', null, 'yes_no', $yn, [], null],
            ['8.15.2', '8.15', 'refused to sign required documentation.',                                             null, 'yes_no', $yn, [], null],
            ['8.15.3', '8.15', 'wanted to cease a transaction following the request of CDD documents.',               null, 'yes_no', $yn, [], null],
            ['8.16',  null,  'If answered yes, did you submit a Section 29 report to the FIC?',                      null, 'yes_no', $yn, [], null],
            ['8.17',  null,  'Have any of your clients ever purchased property without inspection or viewing?',      null, 'yes_no', $yn, [], null],
            ['8.19',  null,  'How frequently do potential clients list properties below its market value?',          'PDF skips 8.18 — gap preserved.', 'single_select', $freq, [], null],
            ['8.20',  null,  'What percentage of your transactions are clients purchasing property in the name of another person, for example a spouse / child?', null, 'percentage', null, [], null],
            ['8.21',  null,  'In the previous financial year, has a client purchased multiple properties from your institution?', null, 'yes_no', $yn, [], null],
            ['8.22',  null,  'If yes, did your entity establish the intended use of these properties?',              null, 'yes_no', $yn, [], null],
            ['8.23',  null,  'To your knowledge, has a client ever listed a property shortly after purchasing it from your agency?', null, 'yes_no', $yn, [], null],
            ['8.24',  null,  'Do you apply additional verification measures for higher value property?',             null, 'yes_no', $yn, [], null],
            ['8.25',  null,  'Do you assess any adverse media about clients or potential clients?',                  null, 'yes_no', $yn, [], null],
            ['8.26',  null,  'How frequently does the profile of the client fit with the transaction with regard to the property value?', null, 'single_select', $freq, [], null],
            ['8.27',  null,  'How often have you ceased a transaction due to a client or transaction being regarded as suspicious or unusual?', null, 'single_select', $freq, [], null],
            ['8.28',  null,  'How often do your clients make changes in financing arrangements?',                    null, 'single_select', $freq, [], null],
            ['8.29',  null,  'Do you conduct transactions through the use of crypto assets or other forms or virtual currency?', null, 'yes_no', $yn, [], null],
            ['8.30',  null,  'Do you identify ultimate seller and purchaser using beneficial ownership databases?',  null, 'yes_no', $yn, [], null],
            ['8.31',  null,  'Do you assess non-face-to-face property buyers from high-risk jurisdictions?',         null, 'yes_no', $yn, [], null],
        ];
    }
}
