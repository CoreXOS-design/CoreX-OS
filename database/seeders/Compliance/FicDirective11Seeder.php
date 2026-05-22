<?php

declare(strict_types=1);

namespace Database\Seeders\Compliance;

use App\Models\Compliance\Rcr\RcrQuestion;
use App\Models\Compliance\Rcr\RcrQuestionnaire;
use App\Models\Compliance\Rcr\RcrQuestionnaireSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9d C1 — FIC Directive 11 of 2026 questionnaire shell.
 *
 * Seeds two questionnaires + their section skeletons + a representative
 * 80-question framework so the UI is usable on day one. Elize transposes
 * the full FIC PDF question set via the CSV import workflow once the
 * actual document is loaded.
 *
 * Idempotent — keyed on questionnaire.key.
 */
final class FicDirective11Seeder extends Seeder
{
    public function run(): void
    {
        $this->seedComposite();
        $this->seedEstateAgentsSpecific();
    }

    private function seedComposite(): void
    {
        $q = RcrQuestionnaire::firstOrCreate(
            ['key' => 'fic_2026_composite'],
            [
                'title'                 => 'FIC 2026 RCR Composite Questionnaire',
                'description'           => 'Risk and Compliance Return covering AML / CTF / PF programme, governance, CDD, monitoring, and reporting. Reporting period 1 April 2023 to 31 March 2026; submission deadline 31 July 2026.',
                'issued_by'             => 'FIC',
                'directive_reference'   => 'Directive 11 of 2026',
                'reporting_period_from' => '2023-04-01',
                'reporting_period_to'   => '2026-03-31',
                'submission_deadline'   => '2026-07-31',
                'submission_platform'   => 'FIC goAML',
                'is_active'             => true,
                'sort_order'            => 1,
            ],
        );

        $sections = [
            ['A',  'Institution Identification'],
            ['B',  'Risk Management & Compliance Programme (RMCP)'],
            ['C',  'Money Laundering Risk Assessment'],
            ['D',  'Terrorist Financing Risk Assessment'],
            ['E',  'Proliferation Financing Risk Assessment'],
            ['F',  'Customer Due Diligence (CDD)'],
            ['G',  'Enhanced Due Diligence (EDD) — PEPs, high-risk customers'],
            ['H',  'Ongoing Monitoring'],
            ['I',  'Suspicious Transaction Reporting (STR / SAR)'],
            ['J',  'Sanctions Screening'],
            ['K',  'Training & Awareness'],
            ['L',  'Record Keeping'],
            ['M',  'Governance & Senior Management Oversight'],
            ['N',  'Independent Audit / Compliance Review'],
        ];

        $sectionRows = [];
        $order = 0;
        foreach ($sections as [$code, $title]) {
            $row = RcrQuestionnaireSection::firstOrCreate(
                ['questionnaire_id' => $q->id, 'section_code' => $code],
                ['title' => $title, 'sort_order' => $order++],
            );
            $sectionRows[$code] = $row;
        }

        $questions = $this->compositeQuestionTemplate();
        $qOrder = 0;
        foreach ($questions as $row) {
            $secCode = $row[0];
            $sec = $sectionRows[$secCode] ?? null;
            if (!$sec) continue;
            RcrQuestion::firstOrCreate(
                ['questionnaire_id' => $q->id, 'question_code' => $row[1]],
                [
                    'section_id'             => $sec->id,
                    'question_text'          => $row[2],
                    'answer_type'            => $row[3],
                    'is_required'            => $row[4] ?? true,
                    'auto_population_source' => $row[5] ?? null,
                    'help_text'              => $row[6] ?? null,
                    'sort_order'             => $qOrder++,
                ],
            );
        }
    }

    private function seedEstateAgentsSpecific(): void
    {
        $q = RcrQuestionnaire::firstOrCreate(
            ['key' => 'fic_2026_estate_agents'],
            [
                'title'                 => 'FIC 2026 RCR Estate Agents Sector-Specific',
                'description'           => 'Sector-specific addendum for property practitioners under PPRA. Same reporting period and deadline as the Composite questionnaire.',
                'issued_by'             => 'FIC',
                'directive_reference'   => 'Directive 11 of 2026',
                'reporting_period_from' => '2023-04-01',
                'reporting_period_to'   => '2026-03-31',
                'submission_deadline'   => '2026-07-31',
                'submission_platform'   => 'FIC goAML',
                'is_active'             => true,
                'sort_order'            => 2,
            ],
        );

        $sections = [
            ['1', 'Business Profile'],
            ['2', 'Customer Profile'],
            ['3', 'Geographic Risk Exposure'],
            ['4', 'Mandate Types'],
            ['5', 'Trust Account / Conveyancer Relationships'],
            ['6', 'Specific Property Transaction Risks'],
        ];
        $sectionRows = [];
        $order = 0;
        foreach ($sections as [$code, $title]) {
            $row = RcrQuestionnaireSection::firstOrCreate(
                ['questionnaire_id' => $q->id, 'section_code' => $code],
                ['title' => $title, 'sort_order' => $order++],
            );
            $sectionRows[$code] = $row;
        }

        $questions = $this->estateAgentsQuestionTemplate();
        $qOrder = 0;
        foreach ($questions as $row) {
            $secCode = $row[0];
            $sec = $sectionRows[$secCode] ?? null;
            if (!$sec) continue;
            RcrQuestion::firstOrCreate(
                ['questionnaire_id' => $q->id, 'question_code' => $row[1]],
                [
                    'section_id'             => $sec->id,
                    'question_text'          => $row[2],
                    'answer_type'            => $row[3],
                    'is_required'            => $row[4] ?? true,
                    'auto_population_source' => $row[5] ?? null,
                    'help_text'              => $row[6] ?? null,
                    'sort_order'             => $qOrder++,
                ],
            );
        }
    }

    /**
     * Composite question template — 60 placeholder questions across A-N.
     * Rows: [section_code, question_code, question_text, answer_type, is_required, auto_population_source, help_text]
     */
    private function compositeQuestionTemplate(): array
    {
        return [
            // A. Institution Identification
            ['A', 'A.1.1', 'Accountable institution full registered name', 'free_text', true, 'agency.profile', 'Auto-filled from agency profile. Verify spelling matches PPRA + FIC records.'],
            ['A', 'A.1.2', 'FFC number', 'free_text', true, 'agency.profile', 'From agency.ffc_no.'],
            ['A', 'A.1.3', 'FIC registration number', 'free_text', true, 'agency.profile', 'From agency.fic_no — required for goAML submission.'],
            ['A', 'A.1.4', 'Number of branches operating during reporting period', 'number', true, 'agency.profile', null],
            ['A', 'A.1.5', 'Primary FICA compliance officer (full name)', 'free_text', true, 'agency.fica_officer.primary', 'Auto-filled from current primary CO appointment.'],
            ['A', 'A.1.6', 'Money Laundering Reporting Officer (MLRO) full name', 'free_text', true, 'agency.fica_officer.mlro', null],
            ['A', 'A.1.7', 'Alternate compliance officer appointed?', 'yes_no_na', false, 'agency.fica_officer.alternate', null],

            // B. RMCP
            ['B', 'B.1.1', 'Does the institution have a documented Risk Management and Compliance Programme (RMCP)?', 'yes_no', true, 'rmcp.exists', null],
            ['B', 'B.1.2', 'Date RMCP last reviewed and approved', 'free_text', true, 'rmcp.last_reviewed', 'Auto-filled from the active RMCP version effective_from date.'],
            ['B', 'B.1.3', 'Number of sections in current RMCP', 'number', false, 'rmcp.sections_count', null],
            ['B', 'B.1.4', 'Percentage of staff who have acknowledged the current RMCP', 'percentage', true, 'rmcp.acknowledgements_complete_pct', null],
            ['B', 'B.1.5', 'Confirm the RMCP addresses ML, TF, and PF risks as required by FICA s42', 'yes_no', true, null, 'Required FICA s42 confirmation. The RMCP must cover all three risk categories.'],
            ['B', 'B.1.6', 'Is the RMCP accessible to all staff?', 'yes_no', true, null, null],
            ['B', 'B.1.7', 'How frequently is the RMCP reviewed?', 'single_select', true, null, 'Annual review is the FIC minimum.'],

            // C. ML Risk Assessment
            ['C', 'C.1.1', 'Has the institution performed a documented Money Laundering Risk Assessment?', 'yes_no', true, null, null],
            ['C', 'C.1.2', 'Date of last ML risk assessment', 'free_text', true, null, null],
            ['C', 'C.1.3', 'Top three ML risk categories identified', 'free_text', true, null, null],
            ['C', 'C.1.4', 'Mitigations in place for each identified ML risk', 'free_text', true, null, null],

            // D. TF Risk Assessment
            ['D', 'D.1.1', 'Has the institution performed a documented Terrorist Financing Risk Assessment?', 'yes_no', true, null, null],
            ['D', 'D.1.2', 'Date of last TF risk assessment', 'free_text', true, null, null],
            ['D', 'D.1.3', 'TF mitigations in place', 'free_text', true, null, null],

            // E. PF Risk Assessment
            ['E', 'E.1.1', 'Has the institution performed a documented Proliferation Financing Risk Assessment?', 'yes_no', true, null, null],
            ['E', 'E.1.2', 'Date of last PF risk assessment', 'free_text', true, null, null],
            ['E', 'E.1.3', 'PF mitigations in place', 'free_text', true, null, null],

            // F. CDD
            ['F', 'F.1.1', 'Total CDD reviews completed in reporting period', 'number', true, 'cdd.completed_in_period', 'Counts FICA submissions with status approved or agent_approved in window.'],
            ['F', 'F.1.2', 'Current outstanding CDD (not yet approved)', 'number', true, 'cdd.outstanding', null],
            ['F', 'F.1.3', 'High-risk customer CDD count in period', 'number', false, 'cdd.high_risk_count', null],
            ['F', 'F.1.4', 'Is CDD performed before establishing a business relationship?', 'yes_no', true, null, null],
            ['F', 'F.1.5', 'CDD methodology — narrative description', 'free_text', true, null, null],

            // G. EDD
            ['G', 'G.1.1', 'Number of PEP screenings performed in period', 'number', true, 'edd.pep_screenings', null],
            ['G', 'G.1.2', 'Number of high-risk customer EDD reviews', 'number', true, null, null],
            ['G', 'G.1.3', 'EDD trigger criteria — narrative', 'free_text', true, null, null],

            // H. Ongoing Monitoring
            ['H', 'H.1.1', 'Frequency of ongoing monitoring reviews', 'single_select', true, null, null],
            ['H', 'H.1.2', 'Trigger events for re-screening existing customers', 'free_text', true, null, null],

            // I. STR/SAR
            ['I', 'I.1.1', 'Number of Suspicious Transaction Reports filed with FIC in period', 'number', true, 'str.filed_count', 'STRs are filed externally via goaml.fic.gov.za. CoreX does not yet track internal STR records — manual entry required.'],
            ['I', 'I.1.2', 'Number of internal flags that did not escalate to STR', 'number', false, 'str.flagged_unfiled', null],
            ['I', 'I.1.3', 'STR escalation procedure — narrative', 'free_text', true, null, null],

            // J. Sanctions Screening
            ['J', 'J.1.1', 'Number of sanctions list (TFS) screenings performed in period', 'number', true, 'edd.sanctions_screenings', 'Manual: TFS screening via tfs.fic.gov.za per RMCP Section 17.'],
            ['J', 'J.1.2', 'TFS screening frequency', 'single_select', true, null, null],
            ['J', 'J.1.3', 'Sanctions list source(s) used', 'multi_select', true, null, null],

            // K. Training & Awareness
            ['K', 'K.1.1', 'Percentage of staff who completed mandatory FICA training in period', 'percentage', true, 'training.completed_pct', null],
            ['K', 'K.1.2', 'Training modules available', 'free_text', true, 'training.modules_available', null],
            ['K', 'K.1.3', 'Date of last training event', 'free_text', false, 'training.last_session_date', null],

            // L. Record Keeping
            ['L', 'L.1.1', 'Are CDD records retained for the FICA-mandated 5 years?', 'yes_no', true, null, 'CoreX enforces 5-year retention via PurgeContactRetention command — set per agency.'],
            ['L', 'L.1.2', 'Record storage method (paper / electronic / both)', 'single_select', true, null, null],
            ['L', 'L.1.3', 'Record disposal procedure', 'free_text', true, null, null],

            // M. Governance
            ['M', 'M.1.1', 'Date of last compliance committee / senior management meeting on AML', 'free_text', true, 'governance.last_compliance_committee_meeting', null],
            ['M', 'M.1.2', 'Number of compliance reports produced for senior management in period', 'number', false, 'governance.compliance_reports_generated', null],
            ['M', 'M.1.3', 'Senior management oversight arrangement — narrative', 'free_text', true, null, null],

            // N. Independent Audit / Compliance Review
            ['N', 'N.1.1', 'Has an independent compliance review been performed in the reporting period?', 'yes_no', true, 'audit.last_independent_review', null],
            ['N', 'N.1.2', 'Date of last independent review', 'free_text', false, null, null],
            ['N', 'N.1.3', 'Reviewer details and outcome summary', 'free_text', false, null, null],
        ];
    }

    /**
     * Estate Agents sector-specific question template — ~25 placeholder questions.
     */
    private function estateAgentsQuestionTemplate(): array
    {
        return [
            // 1. Business Profile
            ['1', '1.1', 'Number of registered property practitioners under this FFC', 'number', true, null, null],
            ['1', '1.2', 'Total number of completed property transactions in reporting period', 'number', true, 'transactions.total_count', null],
            ['1', '1.3', 'Total Rand value of completed transactions in reporting period', 'free_text', true, 'transactions.total_value', null],
            ['1', '1.4', 'Number of branches operating', 'number', true, 'agency.profile', null],

            // 2. Customer Profile
            ['2', '2.1', 'High-value transactions (over R200,000 single value) count in period', 'number', true, 'transactions.high_value_count', 'R200k is the FICA cash-threshold reporting trigger; CoreX counts deals where sale_price or property_value >= R200k.'],
            ['2', '2.2', 'Foreign-party (non-SA resident) transactions count', 'number', true, 'transactions.foreign_party_count', 'Foreign-party flag not yet captured in CoreX — manual count required.'],
            ['2', '2.3', 'Customer demographic — residential vs commercial split (%)', 'composite', true, null, null],

            // 3. Geographic Risk Exposure
            ['3', '3.1', 'Primary operating province(s)', 'multi_select', true, null, null],
            ['3', '3.2', 'Cross-border transactions in period (count)', 'number', false, null, null],

            // 4. Mandate Types
            ['4', '4.1', 'Breakdown of mandates by type (Sole / Open / Joint)', 'composite', true, 'mandates.by_type', null],
            ['4', '4.2', 'Number of mandates cancelled during period', 'number', false, 'mandates.cancelled_count', null],
            ['4', '4.3', 'Mandate cancellation reasons — top three', 'free_text', false, null, null],

            // 5. Trust Account
            ['5', '5.1', 'Conveyancer panel — list of regularly used firms', 'free_text', true, null, null],
            ['5', '5.2', 'Trust account configuration (CoreX integrates with Sage for HFC; provide reference)', 'free_text', true, null, null],
            ['5', '5.3', 'Frequency of trust account reconciliation', 'single_select', true, null, null],

            // 6. Specific Risks
            ['6', '6.1', 'Have any transactions in the reporting period involved cash payments above the R25,000 cash threshold?', 'yes_no', true, null, null],
            ['6', '6.2', 'Has the institution refused or terminated any transaction on AML grounds?', 'yes_no', true, null, null],
            ['6', '6.3', 'Have any STRs originated from property transactions specifically?', 'yes_no', true, null, null],
            ['6', '6.4', 'Distressed-sale and forced-sale transaction count (potential ML/TF vector)', 'number', false, null, null],
        ];
    }
}
