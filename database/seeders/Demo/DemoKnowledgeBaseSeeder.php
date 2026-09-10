<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — orphan screen (cc3's audit). admin.knowledge.
 * index confirmed real, working, actively-maintained code (upload, real
 * file processing, category management) with zero demo rows. Same models
 * (KnowledgeDocument/KnowledgeChunk) Ellie's local knowledge search
 * already reads — this content is the highest-value of tonight's orphan
 * screens: a prospective agency principal immediately understands what
 * it's for, and it's the corpus Ellie's answers would eventually draw on.
 *
 * knowledge_categories already has 10 real rows on demo (seeded
 * separately) — this seeder only adds documents + chunks against them,
 * matching Johan's specific list: FICA procedure, mandate handling, OTP
 * basics, trust account rules, POPIA, and internal how-tos.
 *
 * Embeddings are NOT required for the admin screen or the fallback LIKE-
 * search to work (confirmed by investigation before building) — has_
 * embedding stays false, matching the demo environment's real state.
 *
 * Idempotent: matched on (agency_id, title).
 */
final class DemoKnowledgeBaseSeeder
{
    public function run(int $agencyId): array
    {
        $uploaderId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->value('id');
        $categoryIds = DB::table('knowledge_categories')->pluck('id', 'name');

        if (! $uploaderId || $categoryIds->isEmpty()) {
            return ['created' => 0, 'note' => "Skipped — agency {$agencyId} lacks an admin user or knowledge categories."];
        }

        $created = 0;
        foreach ($this->documents() as $doc) {
            $exists = DB::table('knowledge_documents')
                ->where('agency_id', $agencyId)
                ->where('title', $doc['title'])
                ->exists();
            if ($exists) {
                continue;
            }

            $categoryId = $categoryIds[$doc['category']] ?? null;
            if (! $categoryId) {
                continue;
            }

            $documentId = DB::table('knowledge_documents')->insertGetId([
                'agency_id' => $agencyId,
                'category_id' => $categoryId,
                'uploaded_by' => $uploaderId,
                'title' => $doc['title'],
                'description' => $doc['description'],
                'file_path' => 'knowledge/demo/' . \Illuminate\Support\Str::slug($doc['title']) . '.md',
                'file_name' => \Illuminate\Support\Str::slug($doc['title']) . '.md',
                'file_type' => 'text/markdown',
                'file_size' => array_sum(array_map('strlen', array_column($doc['chunks'], 'content'))),
                'chunk_count' => count($doc['chunks']),
                'page_count' => 1,
                'status' => 'ready',
                'is_active' => true,
                'is_ellie_enabled' => true,
                'version' => 1,
                'effective_date' => now()->subDays(random_int(10, 90)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($doc['chunks'] as $i => $chunk) {
                DB::table('knowledge_chunks')->insert([
                    'document_id' => $documentId,
                    'chunk_index' => $i,
                    'content' => $chunk['content'],
                    'section_title' => $chunk['section'],
                    'page_number' => 1,
                    'char_count' => strlen($chunk['content']),
                    'word_count' => str_word_count($chunk['content']),
                    'has_embedding' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $created++;
        }

        return ['created' => $created];
    }

    private function documents(): array
    {
        return [
            [
                'title' => 'FICA Procedure — Client Verification',
                'category' => 'FICA & Compliance',
                'description' => 'Step-by-step client identification and verification procedure for every new mandate or offer.',
                'chunks' => [
                    ['section' => 'Overview', 'content' => "Every accountable institution under FICA — which includes this agency — must verify a client's identity before concluding a transaction. This applies to sellers, buyers, landlords and tenants alike, not only the party paying.\n\nVerification must happen BEFORE an Offer to Purchase or lease agreement is signed, not after."],
                    ['section' => 'Required documents', 'content' => "For a natural person: a valid green-barcoded ID book, smart ID card, or passport, plus proof of residential address dated within the last 3 months (utility bill, bank statement, or municipal account).\n\nFor a company, close corporation or trust: registration documents, a resolution authorising the signatory, and ID + proof of address for every director/member/trustee with authority to act."],
                    ['section' => 'Enhanced due diligence', 'content' => "Apply enhanced due diligence — extra verification steps and a second sign-off from the compliance officer — for: foreign nationals, politically exposed persons (PEPs), cash transactions over R100,000, and any client who is reluctant to provide documentation."],
                    ['section' => 'Where to capture it', 'content' => "Upload verified documents against the contact record in CoreX under FICA. The system tracks verification status per contact and will not allow a deal to move to Granted status until both parties' FICA is marked complete."],
                ],
            ],
            [
                'title' => 'Mandate Handling — Sole, Open & Dual Mandates',
                'category' => 'Company Policies & Procedures',
                'description' => 'How to correctly capture and manage the three mandate types the agency works with.',
                'chunks' => [
                    ['section' => 'Sole mandate', 'content' => "A sole mandate gives this agency the exclusive right to market and sell the property for the mandate period. The seller may not appoint another agency during that period, though many sole mandates still allow the seller to sell privately without paying commission — check the specific clause on each mandate."],
                    ['section' => 'Open mandate', 'content' => "An open mandate is non-exclusive — the seller may appoint multiple agencies simultaneously, and only the agency that successfully introduces the eventual buyer earns commission. Always confirm in writing that a mandate is open before investing significant marketing spend on a listing."],
                    ['section' => 'Dual mandate (sale + rental)', 'content' => "A dual mandate authorises the agency to market a property for both sale and rental concurrently — common with investment properties where the owner wants whichever happens first. Capture both listing types in CoreX against the same property; the system keeps sale and rental status independent."],
                    ['section' => 'Mandate renewal', 'content' => "Mandates typically run 90 days from signature. CoreX flags a mandate approaching expiry 14 days out — renew via the Property record or let it lapse if the seller is no longer proceeding. Never continue marketing a property on an expired mandate."],
                ],
            ],
            [
                'title' => 'Offer to Purchase (OTP) Basics',
                'category' => 'OTP & Contract Templates',
                'description' => 'What every agent needs to know before drafting or presenting an Offer to Purchase.',
                'chunks' => [
                    ['section' => 'What makes an OTP binding', 'content' => "Once both the buyer and seller have signed the Offer to Purchase and any suspensive conditions are met, it becomes a legally binding Deed of Sale. Never let a client sign without reading every clause — verbal promises made outside the document do not apply."],
                    ['section' => 'Common suspensive conditions', 'content' => "The most common suspensive conditions are: bond approval (usually 21 days), sale of the buyer's existing property, and a satisfactory inspection (electrical/beetle/gas compliance certificates). If a condition is not met by its deadline, the OTP lapses unless the parties agree in writing to extend it."],
                    ['section' => 'Deposit handling', 'content' => "Any deposit paid by the buyer must go into the conveyancing attorney's trust account, never the agency's own account. The agency itself never physically holds a buyer's deposit."],
                    ['section' => 'Capturing the OTP in CoreX', 'content' => "Once signed, capture the deal in DR2 (Deal Register) with the accepted OTP attached as a document. The deal automatically enters the pipeline at the 'Offer Accepted' stage and the system starts tracking suspensive condition deadlines."],
                ],
            ],
            [
                'title' => 'Trust Account Rules — What Agents Need to Know',
                'category' => 'Legal Documents & Acts',
                'description' => "Trust account handling obligations under the Property Practitioners Act — what agents may and may not do.",
                'chunks' => [
                    ['section' => 'Agents never hold trust money directly', 'content' => "Under the Property Practitioners Act, any money an agency holds on behalf of a client (deposits, rental income, etc.) must be kept in a dedicated trust account, completely separate from the agency's own operating funds. Individual agents never handle or hold this money personally — it goes through the agency's designated trust account process."],
                    ['section' => 'Interest on trust funds', 'content' => "Interest earned on trust account balances belongs to the Property Practitioners Fidelity Fund unless the client has specifically agreed otherwise in writing — an agent may never simply retain or redirect trust account interest."],
                    ['section' => 'Rental trust accounting', 'content' => "For managed rentals, rent collected on behalf of a landlord must be paid out (less agreed commission) within the timeframe set out in the mandate — typically within 7 days of receipt. Late payment to a landlord is a serious compliance matter, not just a service issue."],
                ],
            ],
            [
                'title' => 'POPIA — Protecting Client Information',
                'category' => 'Legal Documents & Acts',
                'description' => 'Practical guidance on the Protection of Personal Information Act for day-to-day agency work.',
                'chunks' => [
                    ['section' => 'What POPIA requires', 'content' => "The Protection of Personal Information Act (POPIA) requires the agency to only collect client information for a specific, lawful purpose, keep it secure, and never share it with a third party without the client's consent — this includes ID copies, bank statements, and contact details collected for FICA."],
                    ['section' => 'Marketing consent', 'content' => "A client's contact details may only be used for marketing (newsletters, market updates, buyer-demand outreach) if they have opted in. CoreX tracks consent per contact and per channel (email/WhatsApp/SMS) — never message a contact who has opted out, even with good intentions."],
                    ['section' => 'Data breach reporting', 'content' => "If client information is lost, leaked, or accessed without authorisation (a lost laptop, a misdirected email with attachments), report it to the compliance officer immediately — POPIA requires notification to the Information Regulator and affected data subjects without undue delay."],
                ],
            ],
            [
                'title' => 'How-To: Filing a Document Against a Deal',
                'category' => 'Training Materials',
                'description' => 'Internal how-to guide for correctly filing documents so they attach to the right deal and property.',
                'chunks' => [
                    ['section' => 'Steps', 'content' => "1. Open the property or deal record in CoreX.\n2. Go to the Documents tab.\n3. Upload the file and select the correct document type (Mandate, Disclosure, Addendum, etc.) — the type determines who can see it and where it appears.\n4. Confirm the correct property/contact links before saving.\n\nA document filed under the wrong type will not show up where agents expect it — for example, only 'disclosure' type documents are eligible to appear in a buyer's Viewing Pack."],
                ],
            ],
            [
                'title' => 'How-To: Building a Viewing Pack for a Buyer',
                'category' => 'Training Materials',
                'description' => 'Internal how-to guide for the buyer-pipeline "Build Viewing Pack" flow.',
                'chunks' => [
                    ['section' => 'Steps', 'content' => "1. From the buyer's pipeline card, open their profile and click 'Build Viewing Pack'.\n2. Search for and add each property you're taking the buyer to see.\n3. For each property, tick which attached documents to include — only buyer-appropriate document types (disclosure, rates & taxes, condition reports) will be offered; seller-confidential documents like the mandate never appear here.\n4. Share or print the pack before the viewing.\n\nThe pack automatically links back to the buyer's pipeline record, so their agent can see exactly what was shown and when."],
                ],
            ],
        ];
    }
}
