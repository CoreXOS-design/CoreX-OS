<?php

/**
 * Gradual feature rollout — the default FULL explanation of each feature (AT-478, spec
 * agency-feature-rollout.md §19.3–19.4). Shown in the "New in CoreX" pop-up and the admin's access pop-up.
 *
 * These are only the defaults: a dev edits the wording (and adds a video link and other links) in
 * Dev Settings → Feature rollout → Feature wording & videos, which wins over this file. The one-line
 * summary defaults to the feature's `explain` in config/corex-features.php.
 *
 * Platform-wide product copy: the same for every agency, so no agency names, places or branding (NN #9).
 * Blank lines separate paragraphs.
 */
return [
    'descriptions' => [

        // ── Deals & commission ────────────────────────────────────────────────
        'deals' => "The Deal Register is where every sale and rental transaction lives, from the moment an offer is accepted until the deal is settled and the commission is paid.\n\nEach deal links the property, the buyer or tenant, the seller or landlord and the agents involved, and moves through a pipeline of stages so everyone can see what is outstanding. Deal documents, conditions, important dates and the settlement figures are all kept on the deal.\n\nBecause the deal is one of CoreX's four pillars, the rest of the system reads from it: your performance figures, commission, reports and the property's own history all update as a deal moves forward.",
        'agency-tracker' => "Agency Tracker is the performance spine of your office. It brings together worksheets, targets, daily activity, listing stock and the deal register into the numbers that show how each agent and the agency as a whole is doing.\n\nAgents capture their daily activity and see it measured against their targets; managers see the same picture across their team. The figures come from the work already recorded in CoreX, so there is no separate spreadsheet to keep up to date.",
        'commission-management' => "Commission Management shows the commission side of your deals: what each person has earned, what is still pending and what has been paid, worked out from the deals in the Deal Register.\n\nIt sits on top of the deal register, so as deals are captured and settled the commission figures follow automatically, using the commission settings your agency has chosen.",
        'proforma-invoices' => "Proforma Invoices lets you produce a proforma invoice for a deal's commission and fees, ready to send to the attorney or the paying party.\n\nThe figures come from the deal itself, so the invoice matches what was agreed, and every invoice issued is kept on record against its deal.",

        // ── Documents & e-sign ───────────────────────────────────────────────
        'docuperfect' => "DocuPerfect is the document suite. Create mandates, offers to purchase, leases and other agency documents from your templates, with the property and contact details filled in for you.\n\nManage your clauses and document packs, then send documents for electronic signature. Each signer gets their own secure link, and the signed document is filed automatically against the right property, contact and deal.\n\nBefore it switches on, your agency needs its logo and physical address, because they appear on every document you send.",
        'document-library' => "The Document Library is a searchable home for your agency's documents. Everything filed in CoreX can be found again in one place, by name, type, property or contact.\n\nIt saves hunting through email and shared folders: if it was filed in CoreX, it is in the library.",
        'filing-register' => "The Filing Register records where every filed document lives, so any document can be traced and retrieved for an audit or a query.\n\nIt is the index behind your filing: what was filed, against what, and when.",
        'shared-drive' => "The Shared Drive is a place for the agency's general files that do not belong to a particular deal, property or contact: templates, forms, marketing material and office documents.\n\nEveryone with access works from the same files instead of copies emailed around the office.",
        'title-deeds' => "Title Deeds keeps a register of the title deeds attorneys send to the agency: who was told the deed had arrived, and exactly when, and by whom each one was collected.\n\nIt gives you a clear record if a deed is ever queried.",

        // ── Listings & marketing ────────────────────────────────────────────
        'ad-manager' => "Ad Manager is where you build and track adverts for your listings across the channels you use.\n\nSee which adverts are running for each property and keep your advertising organised in one place.",
        'auctions' => "Auctions adds a third way to sell a property, alongside private-treaty sales and rentals.\n\nSchedule an auction, catalogue the lots, and record the result against each property. An agency that does not auction never sees any of it.",
        'imported-stock' => "Imported Stock lists properties brought in from other agencies' portal listings, kept separate from your own stock.\n\nIt helps your agents know the market and spot opportunities, without mixing other agencies' listings into your own.",
        'marketing' => "Marketing gives your agents tools to market each listing: social posts, brochures and campaign tracking, all attached to the property.\n\nThe tools appear right on the listing, so marketing a property starts from the property itself.",
        'marketing-suppressions' => "Marketing Suppressions keeps the list of contacts who have asked not to receive marketing.\n\nCoreX honours the list automatically wherever it sends marketing, which helps your agency stay within the privacy rules.",
        'public-website' => "Your agency's public website shows your agents, your listings and your branches to the public, kept up to date straight from CoreX.\n\nBecause it is seen by people outside your agency, it never goes live by itself: you switch it on when you are ready.",

        // ── Buyers & matching ───────────────────────────────────────────────
        'core-matches' => "Core Matches checks every new listing against your buyers' wishlists in the background.\n\nWhen a property that fits a buyer comes in, the right agent is told who to call, so a good match is never missed because nobody remembered that buyer.",
        'buyer-pipeline' => "Buyer Pipeline is a board for working your buyers. Move each buyer through your stages, record why a buyer was lost, and open a buyer's full details from the board.\n\nIt gives every agent a clear picture of where each buyer stands and who needs a call next.",

        // ── Presentations & evaluations ─────────────────────────────────────
        'presentations' => "Presentations & CMA builds the evidence you put in front of a seller: a seller presentation and a Comparative Market Analysis based on comparable sales.\n\nPresentations can be shared as a link, and you can see when a seller has opened them. Published presentations are kept with the property.",
        'commercial-evaluations' => "Commercial Evaluations is a tool for valuing commercial and income-producing property, built around the income a property produces.\n\nSave each evaluation against the property so the reasoning behind a price is always on record.",
        'viewing-packs' => "Viewing Packs lets you put together a set of properties to take a buyer on a viewing day.\n\nPlan the day, share the pack with the buyer, and keep their feedback on each property.",

        // ── Prospecting & outreach ──────────────────────────────────────────
        'prospecting' => "Market Intelligence and Prospecting turn the property information CoreX gathers into leads.\n\nThe Market Intelligence Centre and tracked properties show what is happening in your areas, and canvassing intelligence points your agents at the properties most likely to come to market.",
        'address-review' => "Address Review lists the property and prospect addresses CoreX could not read with confidence.\n\nAn administrator corrects or dismisses each one, which keeps your property information clean and your matching accurate.",
        'deeds-capture' => "Deeds Capture is where you review property and owner details captured from the Deeds Office and add them to your tracked properties.\n\nIt turns deeds information into prospecting data your agents can work with.",
        'outreach' => "Seller Outreach and Canvassing organises the work of finding new stock: canvassing, the outreach queue, a WhatsApp outreach summary and standalone seller info packs.\n\nBecause it reaches people outside your agency, it never switches on by itself: you switch it on when you are ready.",
        'portal-leads' => "Portal Leads is the inbox for buyer enquiries that arrive from the property portals your agency is connected to.\n\nEach enquiry can be actioned and assigned to the right agent, so no lead sits unanswered.",
        'road-to-sold' => "Road to Sold gives each agent a daily list of properties to phone to win new stock, measured against a target for each weekday that your agency sets per agent.\n\nManagers see the calls made and their outcomes, so the daily work of finding stock becomes a habit.",

        // ── Reports & performance ───────────────────────────────────────────
        'buyers-report' => "The Buyers Report shows buyer demand and activity across the agency, with drill-downs by branch and agent.\n\nPrint it or save it as a PDF for meetings.",
        'lead-response-report' => "The Lead Response Report shows how quickly new enquiries from the portals, your website and shared links get a first reply, by agent and by source.\n\nFast replies win buyers; this report shows where enquiries are waiting too long. Print it or save it as a PDF.",
        'performance-roi-report' => "The Performance & ROI Report shows how the agency performs and what return it gets, with drill-downs by branch and agent and a print version.\n\nIt is built from the work already recorded in CoreX, so it is always current.",
        'performance-dashboards' => "Performance Dashboards adds the performance pages to the Dashboard: My Performance, the Branch and Agency reports, Lost Deals, Oversight and Performance.\n\nAgents see how they are doing and managers see their whole team, from the same live figures.",

        // ── Communications ──────────────────────────────────────────────────
        'communications' => "Communications captures WhatsApp and email conversations with your clients into a message archive that cannot be changed, with tools to sort, triage and flag messages.\n\nIt is your evidence trail for compliance: when you need to show what was said and when, it is all on record against the right contact.",

        // ── Compliance ──────────────────────────────────────────────────────
        'compliance' => "Compliance brings together the checks a property practice has to keep up with: client verification (FICA), your Risk Management and Compliance Programme, privacy policies, staff screening, document verification and whistleblower reporting.\n\nCoreX runs the checklists for you and keeps the records ready for an inspection.\n\nBefore it switches on, your agency needs its Fidelity Fund Certificate number and PPRA reference number, because the compliance records are built around them.",
        'ppra-inspection-pack' => "The PPRA Inspection Pack builds the set of registers, samples and evidence you hand to an inspector, from the records already in CoreX.\n\nInstead of days of gathering paperwork, the pack is put together for you.",

        // ── Company & admin ─────────────────────────────────────────────────
        'assistants' => "Assistants lets an agent work with a personal assistant who acts on their behalf.\n\nThe assistant starts with a copy of the agent's permissions, and the agent switches off anything they do not want to hand over.",
        'billing' => "Billing shows what your agency pays for CoreX each month: the plan, the per-user charges and the amount due.\n\nIt is for the people who look after the agency's accounts.",
        'contact-governance' => "Contact Governance sets the agency-wide rules for contacts: who can see whose contacts, how duplicates are handled, how fresh a contact must be kept and how long records are retained.\n\nThe rules apply to every contact automatically once they are set.",
        'finance-engine' => "The Finance Engine holds the formulas CoreX uses to work out commission and fees, and the audit tools that recalculate and check a deal's figures.\n\nIt is for the people responsible for the agency's numbers, to confirm every figure is right.",
        'misfiled-documents' => "Misfiled Documents finds contact documents such as IDs, FICA documents and proofs of residence that were filed against a property or left without a contact.\n\nRe-file each one to the right person, so client records are complete.",
        'soft-deletes' => "Nothing in CoreX is ever truly deleted; it is archived. Soft Deletes is the recovery screen that lists everything archived.\n\nAn administrator can restore anything archived by mistake.",

        // ── People & payroll ────────────────────────────────────────────────
        'agent-onboarding' => "Agent Onboarding manages applications from agents who want to join the agency, and the steps to bring a new agent on board.\n\nEvery application and its progress is kept in one place.",
        'leave' => "Leave Management handles leave applications, balances, leave types and public holidays, with leave reports for managers.\n\nStaff apply for leave in CoreX and managers approve it there.",
        'multi-branch' => "Multi-branch lets your agency run as more than one branch, each with its own agents and its own properties, contacts and deals.\n\nAn agent in one branch does not see another branch's information, and commission is credited to the right branch.",
        'ppra-employment-letters' => "PPRA Employment Letters handles the employment letter agents need for their Fidelity Fund Certificate.\n\nAgents request their letter in My Portal, and the office registers, signs and files it.",
        'staff-take-on' => "Staff Take-On is the checklist for taking on a new staff member, so every step from paperwork to system access is done and recorded.",

        // ── Learning & AI ───────────────────────────────────────────────────
        'ellie' => "Ellie is CoreX's AI assistant. Ask a question by voice or chat and Ellie helps you find information and get work done.\n\nEllie is there to save you time; the decisions stay yours.",
        'knowledge-base' => "The Knowledge Base is a searchable collection of your agency's articles, procedures and reference material.\n\nWhen someone asks how something is done, the answer can live here.",
        'training' => "Training is the learning centre: courses, help guides and the tools to manage them.\n\nNew and existing staff learn at their own pace, and progress is tracked.",
        'guided-tours' => "Guided Tours are short, interactive walkthroughs that show you how to use each part of CoreX, step by step on the real screen.\n\nWhenever something new switches on, its tour is the quickest way to learn it.",

        // ── Tools & calculators ─────────────────────────────────────────────
        'calculators' => "The Calculators are quick tools for everyday sums: commission, deposit interest and related figures.\n\nThey give fast, consistent answers without a spreadsheet.",
        'image-converter' => "The Image Converter changes images from one format to another for listings and documents.\n\nIt is useful when a photo will not upload because it is in the wrong format.",
        'pdf-suite' => "The PDF Suite lets you merge, split, redact, convert and enhance PDF documents without leaving CoreX.",
        'trust-interest' => "The Trust Interest Register records the interest earned on deposits held in trust, for compliance and reconciliation.",
        'tv-display' => "TV Display puts office messages and leaderboards on a wall-mounted screen.\n\nCelebrate wins and share news with the whole office.",

        // ── Rentals ─────────────────────────────────────────────────────────
        'rentals' => "Rentals is the whole rental side of CoreX: leases, applications, inspections, maintenance, and the rental views of your contacts, properties and pipeline.\n\nIt is the main switch for the rental modules. CoreX also keeps track of upcoming renewals for you.",
        'rental-command-centre' => "The Rental Command Centre is a single-screen overview of every rental property, showing what is due, overdue or needs attention across leases, inspections, faults and maintenance.\n\nIt is the first place to look each morning.",
        'rental-applications' => "Rental Applications handles tenant applications from start to finish. Send the application link, collect the documents, assess affordability, and have an authoriser approve or decline.\n\nEvery application is kept on record against the contact.",
        'rental-leases' => "Leases is where you create and manage leases. Capture a lease, activate it, renew or cancel it, send notices and follow the tenancy through to the end.\n\nThe lease links the property, tenant and landlord, so everything about a tenancy is in one place.",
        'rental-inspections' => "Rental Inspections covers move-in, periodic and move-out inspections and inventories: room-by-room condition, photos and signatures.\n\nThe move-out inspection is compared with the move-in, which makes deposit discussions straightforward.",
        'rental-faults' => "Rental Fault Reports records maintenance problems raised against a rental property, using the fault types your agency offers.\n\nEach report can be followed through to a work order and a job card.",
        'rental-work-orders' => "Rental Work Orders manages maintenance work: quotes, landlord approval, supplier assignment, changes to the work, and completion.\n\nEveryone can see where each job stands.",
        'rental-job-cards' => "Rental Job Cards record the maintenance work itself: which crew did it, the parts and labour used, the price, the tenant's confirmation and the sign-off.",
        'rental-crews' => "Rental Crews lists your in-house maintenance crews and their members.\n\nCrews are assigned to job cards and get a secure link to see their jobs.",
        'rental-catalogue' => "The Parts & Labour Catalogue is your price list of parts and labour items, used to build job-card lines quickly and consistently.\n\nItems can be imported in bulk.",
        'rental-notices' => "Rental Notices produces formal notices to tenants and landlords from templates, sends them, and files them against the lease.",
        'rental-reports' => "Rental Reports gives you property history, tenancy and portfolio reports, each with a print version.",
        'rental-take-on-import' => "Rental Take-On Import brings an existing rental portfolio into CoreX from a spreadsheet: properties, tenants and leases in one go.\n\nIt is used when you first move your rentals onto CoreX.",
    ],
];
