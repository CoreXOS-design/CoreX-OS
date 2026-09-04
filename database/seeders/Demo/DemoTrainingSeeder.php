<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — orphan screen (cc3's audit). training.index is
 * confirmed real, working code with zero demo rows.
 *
 * IMPORTANT finding, reported separately as a product gap: migration
 * 2026_03_27_500000_create_training_tables.php already contains three
 * genuinely good, real courses (FICA Compliance, RMCP Overview, CoreX OS
 * System Training — real PPRA/FICA content) gated behind
 * `if (DB::table('agencies')->where('id',1)->exists())` CHECKED AT
 * MIGRATE TIME. On any environment where agency_id=1 is created by a
 * seeder that runs after migrations (the normal order), that condition is
 * false, the migration is marked "ran," and these courses silently never
 * appear — permanently, with no re-run path. Same defect class as the
 * hfc-coastal calendar-mapping seeder found earlier tonight.
 *
 * This seeder reuses that exact real content (not new placeholder text)
 * rather than writing something worse from scratch — with one fix: the
 * "Getting started" lesson hardcoded corex.hfcoastal.co.za, reworded to a
 * generic instruction (nothing HFC-branded may appear on demo).
 *
 * Idempotent: matched on course title.
 */
final class DemoTrainingSeeder
{
    public function run(int $agencyId): array
    {
        $created = 0;
        $now = now();

        foreach ($this->courses() as $course) {
            $exists = DB::table('training_courses')
                ->where('agency_id', $agencyId)
                ->where('title', $course['title'])
                ->exists();
            if ($exists) {
                continue;
            }

            $courseId = DB::table('training_courses')->insertGetId([
                'agency_id' => $agencyId,
                'title' => $course['title'],
                'description' => $course['description'],
                'category' => $course['category'],
                'is_required' => $course['is_required'],
                'is_required_for_activation' => $course['is_required_for_activation'],
                'sort_order' => $course['sort_order'],
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($course['lessons'] as $lesson) {
                DB::table('training_lessons')->insert([
                    'course_id' => $courseId,
                    'title' => $lesson['title'],
                    'content' => $lesson['content'],
                    'content_type' => 'text',
                    'duration_minutes' => $lesson['dur'],
                    'sort_order' => $lesson['sort'],
                    'is_published' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $created++;
        }

        return ['courses' => $created];
    }

    private function courses(): array
    {
        return [
            [
                'title' => 'FICA Compliance Training',
                'description' => 'Mandatory training on the Financial Intelligence Centre Act and your obligations as a property practitioner.',
                'category' => 'compliance', 'is_required' => true, 'is_required_for_activation' => true, 'sort_order' => 1,
                'lessons' => [
                    ['title' => 'What is FICA?', 'content' => "The Financial Intelligence Centre Act (FICA) 38 of 2001 is South Africa's primary anti-money laundering legislation. As a property practitioner, you are classified as an accountable institution and must comply with all FICA requirements.\n\nFICA requires you to:\n- Identify and verify the identity of all clients\n- Keep records of all transactions\n- Report suspicious and unusual transactions\n- Develop and maintain a Risk Management and Compliance Programme (RMCP)", 'sort' => 1, 'dur' => 10],
                    ['title' => 'Your obligations as a property practitioner', 'content' => "As a property practitioner registered with the PPRA, you have specific FICA obligations:\n\n1. **Customer Due Diligence (CDD)** — Verify the identity of every client before entering into a transaction\n2. **Record Keeping** — Maintain records for at least 5 years\n3. **Reporting** — Report suspicious transactions to the FIC\n4. **Training** — Complete annual FICA training\n5. **RMCP** — Follow the agency's Risk Management and Compliance Programme", 'sort' => 2, 'dur' => 15],
                    ['title' => 'Customer Due Diligence (CDD)', 'content' => "CDD is the process of verifying who your client is and understanding the nature of the business relationship.\n\n**Required documents:**\n- Valid South African ID or passport\n- Proof of residential address (not older than 3 months)\n- Source of funds declaration for high-value transactions\n\n**Enhanced Due Diligence** is required for:\n- Foreign nationals\n- Politically Exposed Persons (PEPs)\n- High-risk clients\n- Transactions over R100,000 in cash", 'sort' => 3, 'dur' => 15],
                    ['title' => 'Identifying suspicious transactions', 'content' => "A suspicious transaction is any transaction that gives you reasonable grounds to suspect that it may involve money laundering or terrorism financing.\n\n**Red flags include:**\n- Client reluctant to provide identification\n- Transactions that don't match the client's profile\n- Unusually large cash payments\n- Requests to structure transactions to avoid reporting\n- Third-party payments with no clear relationship\n- Properties purchased well above or below market value", 'sort' => 4, 'dur' => 10],
                    ['title' => 'Reporting to the FIC', 'content' => "You must report the following to the Financial Intelligence Centre:\n\n1. **Suspicious Transaction Reports (STR)** — Within 15 days of forming the suspicion\n2. **Cash Threshold Reports (CTR)** — Cash transactions of R24,999.99 or more\n3. **Terrorist Property Reports (TPR)** — Immediately if you suspect terrorism financing\n\nReports are submitted via the FIC's goAML system. Your compliance officer can assist with the reporting process.", 'sort' => 5, 'dur' => 10],
                    ['title' => 'Penalties for non-compliance', 'content' => "Non-compliance with FICA can result in severe penalties:\n\n- **Administrative sanctions** — Fines up to R50 million\n- **Criminal prosecution** — Up to 15 years imprisonment\n- **Reputational damage** — Publication of sanctions\n- **Loss of FFC** — PPRA may revoke your Fidelity Fund Certificate\n\nThe FIC conducts regular inspections of accountable institutions. Ensure your records are always up to date.", 'sort' => 6, 'dur' => 10],
                ],
            ],
            [
                'title' => 'RMCP Overview',
                'description' => 'Understanding the agency Risk Management and Compliance Programme.',
                'category' => 'compliance', 'is_required' => true, 'is_required_for_activation' => true, 'sort_order' => 2,
                'lessons' => [
                    ['title' => 'What is the RMCP?', 'content' => "The Risk Management and Compliance Programme (RMCP) is a mandatory document required by FICA for all accountable institutions.\n\nIt outlines:\n- How the agency identifies and manages money laundering and terrorism financing risks\n- The procedures for Customer Due Diligence\n- Record-keeping requirements\n- Reporting procedures\n- Training requirements\n\nThe RMCP is available in CoreX OS under Compliance > RMCP.", 'sort' => 1, 'dur' => 10],
                    ['title' => 'Risk ratings explained', 'content' => "The RMCP uses a risk-based approach to classify clients and transactions:\n\n**Low Risk:** Standard residential transactions with verified SA citizens\n**Medium Risk:** Commercial transactions, high-value residential, non-resident buyers\n**High Risk:** Cash transactions, PEPs, complex ownership structures, foreign buyers\n\nEach risk level requires different levels of due diligence. Higher risk = more verification.", 'sort' => 2, 'dur' => 10],
                    ['title' => 'Your role in compliance', 'content' => "Every agent is responsible for:\n\n1. Collecting and verifying client documents at the start of every transaction\n2. Completing the FICA checklist in CoreX for every deal\n3. Flagging any suspicious behaviour to the compliance officer\n4. Keeping your training up to date (renewed annually)\n5. Never proceeding with a transaction if you cannot verify the client's identity\n\nCompliance is not optional — it protects you, the agency, and the public.", 'sort' => 3, 'dur' => 10],
                ],
            ],
            [
                'title' => 'CoreX OS System Training',
                'description' => 'Learn how to use CoreX OS — the agency operating system.',
                'category' => 'systems', 'is_required' => false, 'is_required_for_activation' => true, 'sort_order' => 3,
                'lessons' => [
                    // Fixed: originally hardcoded corex.hfcoastal.co.za — reworded, nothing HFC on demo.
                    ['title' => 'Getting started with CoreX', 'content' => "CoreX OS is your agency operating system. Everything you need — from deals to documents to compliance — lives here.\n\n**First steps:**\n1. Log in with the credentials your administrator provided\n2. Set up your profile (photo, contact details, FFC number)\n3. Explore the Dashboard — your daily activity summary\n4. Check My Earnings — track your commission and cap progress\n5. Review your Training — complete all required courses", 'sort' => 1, 'dur' => 10],
                    ['title' => 'Creating evaluations', 'content' => "Market evaluations are created through the Evaluation module.\n\n1. Navigate to Evaluations in the sidebar\n2. Click 'New Evaluation'\n3. Enter the property address and details\n4. The system will pull comparable sales and market data\n5. Generate a professional PDF to share with the seller\n\nEvaluations link to Properties and Contacts in the system.", 'sort' => 2, 'dur' => 10],
                    ['title' => 'Document management', 'content' => "DocuPerfect is the document management system within CoreX.\n\n**Key features:**\n- Create documents from templates\n- Auto-fill from property and contact data\n- E-Sign workflow for digital signatures\n- PDF generation and storage\n- Version tracking\n\nAll documents are linked to the relevant property, contact, and deal.", 'sort' => 3, 'dur' => 15],
                    ['title' => 'E-Sign workflow', 'content' => "The E-Sign system allows digital signing of all documents.\n\n**Process:**\n1. Create or select a document\n2. Add signers (contacts or agents)\n3. Set signing order if sequential\n4. Send for signature\n5. Signers receive an email/link to sign\n6. Once all signed, the document is finalized\n\nSigned documents are legally binding under ECTA.", 'sort' => 4, 'dur' => 10],
                    ['title' => 'Contact and property management', 'content' => "Contacts and Properties are two of the four pillars of CoreX.\n\n**Contacts:**\n- Buyers, sellers, landlords, tenants\n- Contact details, FICA status, linked documents\n- Activity history and communication log\n\n**Properties:**\n- Physical address and details\n- Linked listings, valuations, documents\n- Ownership and transaction history\n\nEvery action in CoreX connects back to these pillars.", 'sort' => 5, 'dur' => 10],
                ],
            ],
        ];
    }
}
