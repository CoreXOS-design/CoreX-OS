<?php

namespace Database\Seeders;

use App\Models\CommandCenter\CalendarEventClassSetting;
use Illuminate\Database\Seeder;

class CalendarEventClassSeeder extends Seeder
{
    /**
     * AT-197 — plain-language, agency-agnostic descriptions shown on the event-class
     * settings screen. Format: what it is · what triggers it (the emitting feature) ·
     * who it routes to · one concrete example. Sourced from the code truth of each
     * emitter (the Calendar Feed sources + the manual-create + leave paths), not guessed.
     */
    public const CLASS_DESCRIPTIONS = [
        'mandate_expiry'              => "The countdown to a listing mandate lapsing. Fires when a stock property's expiry date enters the show window and it's still on the market. Routes to the listing agent, escalating to the BM then admin as it reddens. e.g. Sole mandate expiring in 14 days on 12 Beach Rd → agent + BM.",
        'lease_expiry'                => "A tenant's lease reaching its end date. Fires from a signed lease record (or an active rental, or a property carrying a lease-end date with no record). Routes to the managing agent, then BM/admin. e.g. Lease ends in 30 days at 4 Marine Dr → agent + BM.",
        'ffc_expiry'                  => "An agent's Fidelity Fund Certificate running out — they cannot legally transact without it. Fires from the agent's FFC expiry date. Routes agent → BM + compliance officer → admin. e.g. FFC expires in 14 days — J. Smith → agent + compliance officer + BM.",
        'pi_insurance_expiry'        => "Professional Indemnity cover lapsing. Fires from the agent's PI-insurance expiry date. Routes agent → compliance officer → admin. e.g. PI insurance expires in 30 days — J. Smith → agent + compliance officer.",
        'tax_clearance_expiry'       => "An agent's SARS tax-clearance validity ending. Fires from the agent's tax-clearance expiry date. Routes agent → compliance officer → admin. e.g. Tax clearance expires in 14 days — J. Smith → agent + compliance officer.",
        'deal_step_deadline'         => "A due date on a live deal-pipeline step (bond, transfer, compliance). Fires from a deal step's due date on active steps. Routes to the deal's agent → BM → admin. e.g. 'Bond approval' due in 3 days — 12 Beach Rd → agent + BM.",
        'deal_registration_target'   => "The expected deeds-registration date for a live deal. Fires from the deal's expected-registration date. Routes agent → BM → admin. e.g. Registration expected in 5 days — 12 Beach Rd → agent + BM.",
        'fica_renewal_due'           => "A contact's approved FICA verification approaching its PPRA expiry. Fires from an approved FICA submission's expiry date. Routes agent → compliance officer → admin. e.g. FICA renewal due in 30 days — A. Buyer → agent + compliance officer.",
        'payroll_run'                => "A scheduled pay date for a payroll run still being processed. Fires from a payroll run's pay date. Routes payroll → admin. e.g. Payroll run pay date in 3 days → payroll + admin.",
        'sars_emp201'                => "The monthly PAYE/UIF/SDL declaration to SARS, due the 7th. Computed monthly (a few months ahead). Routes payroll → admin. e.g. EMP201 due 7 Oct → payroll + admin.",
        'sars_emp501'                => "The biannual employer reconciliation to SARS (31 May / 31 Oct). Computed. Routes payroll → admin + accountant. e.g. EMP501 reconciliation due 31 May → payroll + admin + accountant.",
        'rmcp_review_due'            => "The agency's Risk Management & Compliance Programme reaching its scheduled review. Fires from the RMCP's next-review date. Routes compliance officer → admin. e.g. RMCP review due in 30 days → compliance officer + admin.",
        'screening_due'              => "A staff member's periodic background screening coming due. Fires from an employee screening's next-due date. Routes compliance officer → HR → admin. e.g. Background screening due in 30 days — R. Clerk → compliance officer + HR.",
        'ppra_trust_audit'           => "The annual PPRA trust-account audit report (30 June). Computed each year. Routes admin only. e.g. PPRA trust audit report due 30 Jun → admin.",
        'training_expiry'            => "A CPD / training certification lapsing. Fires from a training completion's expiry date. Routes agent → BM → compliance officer. e.g. Training expires in 14 days — J. Smith → agent + BM.",
        'compliance_provision_expiry'=> "An agency-level regulatory provision reaching the end of its validity. Fires from the provision's effective-until date. Routes compliance officer → admin. e.g. Compliance provision expires in 30 days → compliance officer + admin.",
        'compliance_override_expiry' => "A temporary waiver on a user's compliance requirement expiring (the requirement re-activates). Fires from the override's expiry date. Routes compliance officer → admin. e.g. Compliance override expires in 7 days — J. Smith → compliance officer.",
        'agent_document_expiry'      => "A general agent document (ID copy, certificate, etc.) reaching renewal. Fires from a user document's expiry date, honouring the document type's renewal window. Routes agent → compliance officer → admin. e.g. ID copy expires in 30 days — J. Smith → agent + compliance officer.",
        'property_showday'           => "A scheduled show day / open house. Fires from a property show-day's start date. Routes agent → BM. e.g. Show day tomorrow — 12 Beach Rd → agent + BM.",
        'signature_expiry'           => "An e-sign signing link approaching its expiry while still unsigned. Fires from a signature request's token-expiry (waiting/viewed). Routes agent → BM. e.g. Signature link expires in 2 days — A. Buyer → agent.",
        'sales_doc_expiry'           => "A sent sales-document link nearing its token expiry. Fires from a sales-document recipient's token-expiry (status sent). Routes agent → BM. e.g. Sales document expires in 2 days — A. Buyer → agent.",
        'portal_listing_expiry'      => "A Property24 / Private Property portal listing about to expire and drop off the market. Fires from the listing's portal expiry date. Routes agent → BM. e.g. Portal listing expires in 5 days — 12 Beach Rd → agent + BM.",
        'rent_escalation'            => "A scheduled rent-escalation effective date approaching (bill the new amount). Fires from a rent-version's effective-from date within the next month. Routes agent → BM. e.g. Rent escalation effective in 7 days — 4 Marine Dr → agent + BM.",
        'rent_due'                   => "The monthly rent-due marker (1st of the month) for each active rental. Computed, rolls forward nightly. Routes agent → BM. e.g. Rent due 1 Aug — 4 Marine Dr → agent.",
        'commercial_lease_expiry'    => "A commercial tenancy unit's lease ending (higher revenue impact than residential). Fires from a commercial unit's lease-end date. Routes agent → BM → admin. e.g. Commercial lease expires in 30 days — Unit 3, Main St → agent + BM.",
        'leave_cycle_end'            => "An employee's leave-accrual cycle ending (a use-or-lose warning). Fires from a leave entitlement's cycle-end date. Routes agent → BM. e.g. Leave cycle ends in 14 days — R. Clerk → agent + BM.",
        'employee_termination'       => "A staff member's last working day (triggers final payroll, access revocation, equipment return). Fires from an employee's termination date. Routes HR → payroll + admin → BM. e.g. Last working day in 7 days — R. Clerk → HR + payroll.",
        'tax_year_end'               => "The SA tax year end (28 Feb) that triggers IRP5 / EMP501 / annual recon. Computed. Routes payroll → admin + accountant. e.g. Tax year end 28 Feb → payroll + admin + accountant.",
        'uif_declaration'            => "The monthly UIF declaration to Employment & Labour, due the 7th. Computed. Routes payroll → admin. e.g. UIF declaration due 7 Oct → payroll.",
        'sdl_submission'             => "The monthly Skills Development Levy submission, due the 7th. Computed. Routes payroll → admin. e.g. SDL submission due 7 Oct → payroll.",
        'irp5_deadline'              => "The deadline to issue IRP5 certificates (around 31 May). Computed. Routes payroll → admin + accountant. e.g. IRP5 issue deadline 31 May → payroll + admin.",
        'employment_anniversary'     => "A staff work anniversary (a retention milestone). Computed annually from the employment date, skipping the first year. Routes agent + BM. e.g. 3-year anniversary in 3 days — J. Smith → BM.",
        'agent_birthday'             => "A team member's birthday, so the BM can acknowledge it. Computed annually from the user's date of birth (active users). Routes BM. e.g. Birthday in 3 days — J. Smith → BM. (Empty on live until staff birth dates are captured.)",
        'contact_birthday'           => "A contact's birthday, surfaced only when the agent opted that contact in. Computed annually from the contact's birthday. Routes the owning agent. e.g. Birthday in 3 days — A. Buyer → agent.",
        'rmcp_ack_expiry'            => "An agent's RMCP acknowledgement reaching its re-sign point. Fires from the acknowledgement's valid-until date. Routes agent → compliance officer → admin. e.g. RMCP acknowledgement expires in 14 days — J. Smith → agent + compliance officer.",
        'salary_review'              => "The annual salary-review planning marker (1 March). Computed. Routes HR → admin. e.g. Annual salary review 1 Mar → HR + admin.",
        'filed_document_expiry'      => "A document in the filing register expiring (non-mandate types; EA/OA excluded to avoid duplicating Mandate Expiry). Fires from a filed document's expiry date. Routes agent → BM. e.g. COC expires in 14 days — 12 Beach Rd → agent.",
        'office_closure'             => "An agency-wide closure day everyone should see (public holiday, shutdown). NOTE: no emitter yet — deferred to the leave module; currently inactive. Would route to everyone. e.g. Office closed 16 Dec → everyone.",
        'viewing'                    => "An agent-booked buyer viewing appointment — the one class that can span several homes in one trip. Created manually. Same-day actionable; asks for feedback after. Routes agent → BM → admin. e.g. Viewing tomorrow, 3 properties for A. Buyer → agent + BM.",
        'property_evaluation'        => "An agent's booked visit to evaluate a property for a potential seller. Created manually. Routes agent → BM → admin. e.g. Evaluation in 5 days — 12 Beach Rd → agent + BM.",
        'listing_presentation'       => "An agent presenting a CMA / market analysis to win a seller's mandate. Created manually. Routes agent → BM → admin. e.g. Listing presentation in 5 days — Seller J → agent + BM.",
        'meeting'                    => "A general meeting (team / client / external). Created manually; informational, never overdue. Routes agent → BM. e.g. Team meeting tomorrow → agent + BM.",
        'task'                       => "A personal to-do with a deadline. Created manually. Routes the agent only. e.g. Task due 12 Jan — follow up bank → agent.",
        'other'                      => "Catch-all for a manual event that fits no other class. Informational. Routes the agent only. e.g. Misc appointment 25 Jul → agent.",
        'private'                    => "A personal busy block: everyone in scope sees the slot is taken, but only the creator sees the detail. Created manually. e.g. 'Private' busy block Fri 14:00 → creator sees detail, others see busy.",
        'leave_annual'               => "Approved annual leave placed on the calendar. Written when leave is approved. Agents see their own; BM + admin see all. e.g. Annual leave 12–16 Aug — R. Clerk → BM + admin.",
        'leave_sick'                 => "Approved sick leave placed on the calendar. Written when leave is approved. Same visibility as annual leave. e.g. Sick leave 3 Aug — R. Clerk → BM + admin.",
        'manual'                     => "The default class for a manually created event when no specific class is chosen. Visible to its creator. e.g. Ad-hoc event with no class → falls to Manual, creator only.",
    ];

    public function run(): void
    {
        foreach ($this->classes() as $class) {
            CalendarEventClassSetting::withoutGlobalScopes()
                ->updateOrCreate(
                    ['agency_id' => null, 'event_class' => $class['event_class']],
                    $class
                );
        }

        // Reassert actor_role + completion_behaviour from the authoritative
        // map declared in migration 2026_05_06_000001. That migration runs
        // BEFORE this seeder creates the class rows, so its per-class
        // UPDATE matches zero rows on a fresh migrate — leaving every
        // class on the column defaults ('neither' / 'freeform'). With
        // 'viewing' stuck on 'freeform' the calendar event-detail panel
        // never offered "Capture Feedback to Complete" (blade gate at
        // resources/views/command-center/calendar/index.blade.php:1383
        // requires completion_behaviour === 'require_feedback'). Applying
        // the map here — at the canonical creation point — fixes ALL
        // event classes coherently on every fresh demo:seed. Idempotent.
        $behaviourMap = [
            // event_nature = the DEFAULT "requires feedback" (actionable) vs "no
            // feedback needed" (informational). Agency-overridable via settings;
            // the create/edit form pre-selects it and the user can override per event.
            // Appointments (viewing / evaluation / listing presentation) = actionable
            // → can go overdue/red + ask for feedback. Time-blocks (meeting / other /
            // private) = informational → never overdue/red, no feedback prompt.
            'viewing'              => ['actor_role' => 'buyer_action',  'completion_behaviour' => 'require_feedback', 'event_nature' => 'actionable'],
            'listing_presentation' => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback', 'event_nature' => 'actionable'],
            'property_evaluation'  => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback', 'event_nature' => 'actionable'],
            'meeting'              => ['actor_role' => 'both',          'completion_behaviour' => 'freeform',         'event_nature' => 'informational'],
            'other'                => ['actor_role' => 'both',          'completion_behaviour' => 'freeform',         'event_nature' => 'informational'],
            // ITEM 4 — 'both' (NOT 'neither') so a private block counts as a real
            // appointment for conflict detection (its whole purpose is busy time).
            'private'              => ['actor_role' => 'both',          'completion_behaviour' => 'freeform',         'event_nature' => 'informational'],
            'task'                 => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'leave_annual'         => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'leave_sick'           => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'agent_birthday'       => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'contact_birthday'     => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'leave_cycle_end'      => ['actor_role' => 'neither',       'completion_behaviour' => 'freeform'],
            'ffc_expiry'           => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'mandate_expiry'       => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'portal_listing_expiry'=> ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'signature_expiry'     => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'lease_expiry'         => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'tax_clearance_expiry' => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
            'pi_insurance_expiry'  => ['actor_role' => 'neither',       'completion_behaviour' => 'require_reason'],
        ];
        foreach ($behaviourMap as $eventClass => $values) {
            CalendarEventClassSetting::withoutGlobalScopes()
                ->where('event_class', $eventClass)
                ->whereNull('agency_id')
                ->update($values);
        }

        // occupies_time — explicit appointment flag, decoupled from actor_role
        // (which is now ONLY the buyer/seller feedback field). Seed it to match
        // TODAY's behaviour EXACTLY: occupies_time = (actor_role != 'neither'),
        // mirroring migration 2026_07_02_000001's backfill. Doing it by actor_role
        // (not a hardcoded class list) keeps EVERY row behaviour-identical —
        // including legacy/non-standard rows (e.g. an old actor_role='agent') that
        // a fixed appointment list would silently flip to a marker. Runs AFTER the
        // behaviourMap above so actor_role is set. Global rows only (agency rows
        // carry it via SettingsController + the migration's one-time all-row backfill).
        // For the seeded classes this equals {viewing, property_evaluation,
        // listing_presentation, meeting, other, private} = appointments; everything
        // else (markers/reminders) = false.
        CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')->where('actor_role', 'neither')
            ->update(['occupies_time' => false]);
        CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->where(function ($q) { $q->where('actor_role', '<>', 'neither')->orWhereNull('actor_role'); })
            ->update(['occupies_time' => true]);

        // AT-154 — autofill_buyers: buyers auto-fill ONLY for buyer-driven
        // appointment classes (viewing). Sellers auto-fill for every property
        // appointment (enforced at owner-fetch time); buyers are gated here so
        // listing_presentation / property_evaluation / meeting / other never
        // pull the linked property's buyer. By actor_role (not a hardcoded
        // class list) so it stays correct for any buyer-driven class; global
        // rows only (agency rows override via SettingsController). Agency-configurable.
        CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->update(['autofill_buyers' => false]);
        CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')->where('actor_role', 'buyer_action')
            ->update(['autofill_buyers' => true]);

        // AT-197 — plain-language, Johan-facing descriptions for the settings screen,
        // written from the CODE TRUTH of each emitter (what it is · trigger · routing ·
        // one concrete example). Applied to ALL rows for a class (globals AND agency
        // overrides) so the screen shows the current text regardless of an override.
        foreach (self::CLASS_DESCRIPTIONS as $eventClass => $desc) {
            CalendarEventClassSetting::withoutGlobalScopes()
                ->where('event_class', $eventClass)
                ->update(['description' => $desc]);
        }

        $this->command->info('Seeded ' . count($this->classes()) . ' calendar event class settings + AT-197 descriptions.');
    }

    /**
     * 38 event class configurations from
     * SPEC_calendar_event_classes.md — Default Event Class Configurations.
     * Channel keys: 'in_app', 'email'.
     */
    private function classes(): array
    {
        return [
            // ========== GROUP A — Critical Operational (18) ==========

            // #1 mandate_expiry
            [
                'event_class'         => 'mandate_expiry',
                'label'               => 'Mandate Expiry',
                'description'         => 'Sole/open/dual mandate expiring. Risk: lose listing to competitor.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 90,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm', 'admin'],
            ],

            // #2 lease_expiry
            [
                'event_class'         => 'lease_expiry',
                'label'               => 'Lease Expiry',
                'description'         => 'Tenant lease expiring. Source: lease_records.lease_end_date.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],

            // #3 ffc_expiry
            [
                'event_class'         => 'ffc_expiry',
                'label'               => 'FFC Expiry',
                'description'         => 'CRITICAL: Agent cannot legally transact without valid FFC.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm', 'compliance_officer'],
                'red_visibility'      => ['agent', 'bm', 'compliance_officer', 'admin'],
                'green_notifications' => ['agent' => ['in_app']],
                'amber_notifications' => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app', 'email'], 'compliance_officer' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer', 'admin'],
            ],

            // #4 pi_insurance_expiry
            [
                'event_class'         => 'pi_insurance_expiry',
                'label'               => 'PI Insurance Expiry',
                'description'         => 'CRITICAL: Agent operates without PI cover.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'compliance_officer'],
                'red_visibility'      => ['agent', 'compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app'], 'compliance_officer' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer'],
            ],

            // #5 tax_clearance_expiry
            [
                'event_class'         => 'tax_clearance_expiry',
                'label'               => 'Tax Clearance Expiry',
                'description'         => 'Cannot prove tax compliance. SARS issues.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'compliance_officer'],
                'red_visibility'      => ['agent', 'compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app'], 'compliance_officer' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer'],
            ],

            // #6 deal_step_deadline
            [
                'event_class'         => 'deal_step_deadline',
                'label'               => 'Deal Pipeline Step Due',
                'description'         => 'Bond/transfer/compliance deadlines. Defaults overridden by step rag_*_days.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 7,
                'red_days'            => 3,
                'show_days'           => 21,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],

            // #7 deal_registration_target
            [
                'event_class'         => 'deal_registration_target',
                'label'               => 'Target Registration Date',
                'description'         => 'Expected deeds registration. Source: deals_v2.expected_registration.',
                'is_active'           => true,
                'green_days'          => 21,
                'amber_days'          => 10,
                'red_days'            => 5,
                'show_days'           => 90,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],

            // #8 fica_renewal_due
            [
                'event_class'         => 'fica_renewal_due',
                'label'               => 'FICA Renewal Due',
                'description'         => 'Source: fica_submissions.fica_expires_at (24-month PPRA validity).',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'compliance_officer'],
                'red_visibility'      => ['agent', 'compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app'], 'compliance_officer' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer'],
            ],

            // #9 payroll_run
            [
                'event_class'         => 'payroll_run',
                'label'               => 'Payroll Run',
                'description'         => 'Pay date for payroll runs in draft/processing status.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 3,
                'red_days'            => 1,
                'show_days'           => 30,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin'],
                'red_visibility'      => ['payroll', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email'], 'admin' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll', 'admin'],
            ],

            // #10 sars_emp201
            [
                'event_class'         => 'sars_emp201',
                'label'               => 'SARS EMP201 Due',
                'description'         => 'Computed: 7th of each month. SARS penalties and interest if missed.',
                'is_active'           => true,
                'green_days'          => 5,
                'amber_days'          => 3,
                'red_days'            => 1,
                'show_days'           => 14,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin'],
                'red_visibility'      => ['payroll', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll', 'admin'],
            ],

            // #11 sars_emp501
            [
                'event_class'         => 'sars_emp501',
                'label'               => 'SARS EMP501 Reconciliation',
                'description'         => 'Computed: 31 May + 31 Oct biannual. Reconciliation penalties if missed.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin', 'accountant'],
                'red_visibility'      => ['payroll', 'admin', 'accountant'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app'], 'admin' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email'], 'admin' => ['in_app', 'email'], 'accountant' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll', 'admin', 'accountant'],
            ],

            // #12 rmcp_review_due
            [
                'event_class'         => 'rmcp_review_due',
                'label'               => 'RMCP Review Due',
                'description'         => 'PPRA compliance breach if missed.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['compliance_officer'],
                'amber_visibility'    => ['compliance_officer', 'admin'],
                'red_visibility'      => ['compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['compliance_officer' => ['in_app']],
                'red_notifications'   => ['compliance_officer' => ['in_app', 'email'], 'admin' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer', 'admin'],
            ],

            // #13 screening_due
            [
                'event_class'         => 'screening_due',
                'label'               => 'Employee Screening Due',
                'description'         => 'Periodic background screening. Frequency by risk: high=1y, med=3y, low=5y.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 90,
                'green_visibility'    => ['compliance_officer'],
                'amber_visibility'    => ['compliance_officer', 'hr'],
                'red_visibility'      => ['compliance_officer', 'hr', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['compliance_officer' => ['in_app']],
                'red_notifications'   => ['compliance_officer' => ['in_app', 'email'], 'hr' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer', 'hr'],
            ],

            // #14 ppra_trust_audit
            [
                'event_class'         => 'ppra_trust_audit',
                'label'               => 'PPRA Trust Audit Report',
                'description'         => 'Annual trust account audit. PPRA regulatory action if missed.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['admin'],
                'amber_visibility'    => ['admin'],
                'red_visibility'      => ['admin'],
                'green_notifications' => [],
                'amber_notifications' => ['admin' => ['in_app']],
                'red_notifications'   => ['admin' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['admin'],
            ],

            // #15 training_expiry
            [
                'event_class'         => 'training_expiry',
                'label'               => 'Training Certification Expiry',
                'description'         => 'CPD non-compliance. PPRA audit finding.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'compliance_officer'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm', 'compliance_officer'],
            ],

            // #16 compliance_provision_expiry
            [
                'event_class'         => 'compliance_provision_expiry',
                'label'               => 'Compliance Provision Expiry',
                'description'         => 'Agency-level regulatory provision. Compliance gap when expired.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['compliance_officer'],
                'amber_visibility'    => ['compliance_officer', 'admin'],
                'red_visibility'      => ['compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['compliance_officer' => ['in_app']],
                'red_notifications'   => ['compliance_officer' => ['in_app', 'email'], 'admin' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer', 'admin'],
            ],

            // #17 compliance_override_expiry
            [
                'event_class'         => 'compliance_override_expiry',
                'label'               => 'Compliance Override Expiry',
                'description'         => 'Underlying requirement re-activates when override expires.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 7,
                'red_days'            => 3,
                'show_days'           => 30,
                'green_visibility'    => ['compliance_officer'],
                'amber_visibility'    => ['compliance_officer'],
                'red_visibility'      => ['compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['compliance_officer' => ['in_app']],
                'red_notifications'   => ['compliance_officer' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #18 agent_document_expiry
            [
                'event_class'         => 'agent_document_expiry',
                'label'               => 'Agent Document Expiry',
                'description'         => 'Generic document renewal. Honours agency_document_type_configs.renewal_days.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 90,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'compliance_officer'],
                'red_visibility'      => ['agent', 'compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app'], 'compliance_officer' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer'],
            ],

            // ========== GROUP B — Important Workflow (13) ==========

            // #19 property_showday
            [
                'event_class'         => 'property_showday',
                'label'               => 'Show Day / Open House',
                'description'         => 'Tactical event. Missed open house = missed buyer leads.',
                'is_active'           => true,
                'green_days'          => 3,
                'amber_days'          => 1,
                'red_days'            => 0,
                'show_days'           => 30,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #20 signature_expiry
            [
                'event_class'         => 'signature_expiry',
                'label'               => 'Signature Request Expiry',
                'description'         => 'Active signature_requests.token_expires_at.',
                'is_active'           => true,
                'green_days'          => 5,
                'amber_days'          => 2,
                'red_days'            => 1,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #21 sales_doc_expiry
            [
                'event_class'         => 'sales_doc_expiry',
                'label'               => 'Sales Document Expiry',
                'description'         => 'sales_document_recipients.token_expires_at.',
                'is_active'           => true,
                'green_days'          => 5,
                'amber_days'          => 2,
                'red_days'            => 1,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #22 portal_listing_expiry
            [
                'event_class'         => 'portal_listing_expiry',
                'label'               => 'Portal Listing Expiry',
                'description'         => 'P24/PP listing expiry. Buyer exposure lost when expired.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 5,
                'red_days'            => 2,
                'show_days'           => 30,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #23 rent_escalation
            [
                'event_class'         => 'rent_escalation',
                'label'               => 'Rent Escalation Effective',
                'description'         => 'Tenant billed wrong amount if escalation not applied. Revenue leakage.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 7,
                'red_days'            => 3,
                'show_days'           => 30,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #24 rent_due
            [
                'event_class'         => 'rent_due',
                'label'               => 'Rent Due Date',
                'description'         => 'Computed: 1st of each month. Auto-purges after payment.',
                'is_active'           => true,
                'green_days'          => 3,
                'amber_days'          => 1,
                'red_days'            => 0,
                'show_days'           => 7,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => ['agent' => ['in_app'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #25 commercial_lease_expiry
            [
                'event_class'         => 'commercial_lease_expiry',
                'label'               => 'Commercial Lease Expiry',
                'description'         => 'Higher revenue impact than residential. Commercial vacancy forecasting.',
                'is_active'           => true,
                'green_days'          => 60,
                'amber_days'          => 30,
                'red_days'            => 14,
                'show_days'           => 120,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],

            // #26 leave_cycle_end
            [
                'event_class'         => 'leave_cycle_end',
                'label'               => 'Leave Cycle End',
                'description'         => 'Employee may forfeit accrued leave unknowingly.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #27 employee_termination
            [
                'event_class'         => 'employee_termination',
                'label'               => 'Employee Last Day',
                'description'         => 'Final payroll, leave payout, system access revocation, equipment return.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 7,
                'red_days'            => 3,
                'show_days'           => 30,
                'green_visibility'    => ['hr'],
                'amber_visibility'    => ['hr', 'payroll', 'admin'],
                'red_visibility'      => ['hr', 'payroll', 'admin', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['hr' => ['in_app'], 'payroll' => ['in_app']],
                'red_notifications'   => ['hr' => ['in_app', 'email'], 'payroll' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['hr', 'payroll'],
            ],

            // #28 tax_year_end
            [
                'event_class'         => 'tax_year_end',
                'label'               => 'Tax Year End',
                'description'         => 'Computed: 28 Feb annual. Triggers IRP5, EMP501, annual reconciliation.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin', 'accountant'],
                'red_visibility'      => ['payroll', 'admin', 'accountant'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app'], 'admin' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email'], 'admin' => ['in_app', 'email'], 'accountant' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll', 'admin', 'accountant'],
            ],

            // #29 uif_declaration
            [
                'event_class'         => 'uif_declaration',
                'label'               => 'UIF Declaration Due',
                'description'         => 'Computed: 7th of each month. Department of Employment and Labour.',
                'is_active'           => true,
                'green_days'          => 5,
                'amber_days'          => 3,
                'red_days'            => 1,
                'show_days'           => 14,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin'],
                'red_visibility'      => ['payroll', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll'],
            ],

            // #30 sdl_submission
            [
                'event_class'         => 'sdl_submission',
                'label'               => 'SDL Submission Due',
                'description'         => 'Computed: 7th of each month. Skills Development Levy.',
                'is_active'           => true,
                'green_days'          => 5,
                'amber_days'          => 3,
                'red_days'            => 1,
                'show_days'           => 14,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin'],
                'red_visibility'      => ['payroll', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll'],
            ],

            // #31 irp5_deadline
            [
                'event_class'         => 'irp5_deadline',
                'label'               => 'IRP5 Issue Deadline',
                'description'         => 'Computed: ~60 days after tax year end. SARS requirement.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['payroll'],
                'amber_visibility'    => ['payroll', 'admin'],
                'red_visibility'      => ['payroll', 'admin', 'accountant'],
                'green_notifications' => [],
                'amber_notifications' => ['payroll' => ['in_app']],
                'red_notifications'   => ['payroll' => ['in_app', 'email'], 'admin' => ['in_app', 'email']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['payroll', 'admin'],
            ],

            // ========== GROUP C — Nice to Have (7) ==========

            // #32 employment_anniversary
            [
                'event_class'         => 'employment_anniversary',
                'label'               => 'Employment Anniversary',
                'description'         => 'Annual recurring. Culture/retention milestone.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 3,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent', 'bm'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['bm' => ['in_app']],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #33 agent_birthday
            [
                'event_class'         => 'agent_birthday',
                'event_nature'        => 'informational',
                'label'               => 'Agent Birthday',
                'description'         => 'Annual recurring. BM sees team birthdays.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 3,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['bm'],
                'amber_visibility'    => ['bm'],
                'red_visibility'      => ['bm'],
                'green_notifications' => [],
                'amber_notifications' => ['bm' => ['in_app']],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #34 contact_birthday
            [
                'event_class'         => 'contact_birthday',
                'event_nature'        => 'informational',
                'label'               => 'Contact Birthday',
                'description'         => 'Annual recurring. Personal relationship building.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 3,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #35 rmcp_ack_expiry
            [
                'event_class'         => 'rmcp_ack_expiry',
                'label'               => 'RMCP Acknowledgement Expiry',
                'description'         => 'Agent must re-acknowledge RMCP. 12-month cycle.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'compliance_officer'],
                'red_visibility'      => ['agent', 'compliance_officer', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email'], 'compliance_officer' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['compliance_officer'],
            ],

            // #36 salary_review
            [
                'event_class'         => 'salary_review',
                'label'               => 'Annual Salary Review',
                'description'         => 'Internal HR planning. Retention and budgeting.',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['hr'],
                'amber_visibility'    => ['hr', 'admin'],
                'red_visibility'      => ['hr', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['hr' => ['in_app']],
                'red_notifications'   => ['hr' => ['in_app', 'email'], 'admin' => ['in_app']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #37 filed_document_expiry
            [
                'event_class'         => 'filed_document_expiry',
                'label'               => 'Filed Document Expiry',
                'description'         => 'Generic filing register expiry. Mandate docs excluded (use mandate_expiry).',
                'is_active'           => true,
                'green_days'          => 30,
                'amber_days'          => 14,
                'red_days'            => 7,
                'show_days'           => 60,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app', 'email']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #38 office_closure
            [
                'event_class'         => 'office_closure',
                'event_nature'        => 'informational',
                'label'               => 'Office Closure',
                'description'         => 'SYSTEM-level. Everyone sees. No notifications (informational).',
                'is_active'           => false,
                'green_days'          => 14,
                'amber_days'          => 7,
                'red_days'            => 0,
                'show_days'           => 30,
                'green_visibility'    => ['all'],
                'amber_visibility'    => ['all'],
                'red_visibility'      => ['all'],
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // ========== GROUP D — Manual Activity Events (3) ==========

            // #39 viewing
            [
                'event_class'         => 'viewing',
                'label'               => 'Property viewing',
                'description'         => 'Buyer viewing a property. Short cycle, same-day actionable. Red on event day = capture feedback after.',
                'is_active'           => true,
                // A buyer viewing trip covers several properties in one
                // outing — viewing is the multi-property class (migration
                // 2026_05_05_000019 intended this; it was lost because the
                // migration ran before the seeder created the row + the
                // column was not fillable). All other classes stay single.
                'allow_multiple_properties' => true,
                'green_days'          => 7,
                'amber_days'          => 2,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['agent', 'bm'],
            ],

            // #40 property_evaluation
            [
                'event_class'         => 'property_evaluation',
                'label'               => 'Property evaluation',
                'description'         => 'Agent evaluating property for potential seller. Longer planning cycle, booked days/weeks ahead.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 5,
                'red_days'            => 1,
                'show_days'           => 21,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['agent', 'bm'],
            ],

            // #41 listing_presentation
            [
                'event_class'         => 'listing_presentation',
                'label'               => 'Listing presentation',
                'description'         => 'Agent presenting CMA/market analysis to potential seller. Longer planning cycle.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 5,
                'red_days'            => 1,
                'show_days'           => 21,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app'], 'bm' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['agent', 'bm'],
            ],

            // #42 meeting
            [
                'event_class'         => 'meeting',
                'label'               => 'Meeting',
                'description'         => 'General meeting — team, client, or external. Manual-creatable.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 2,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent', 'bm'],
                'red_visibility'      => ['agent', 'bm'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app']],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['agent'],
            ],

            // #43 task
            [
                'event_class'         => 'task',
                'label'               => 'Task / To-do',
                'description'         => 'Personal task with a deadline. Manual-creatable.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 2,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent'],
                'green_notifications' => [],
                'amber_notifications' => ['agent' => ['in_app']],
                'red_notifications'   => ['agent' => ['in_app']],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #44 other
            [
                'event_class'         => 'other',
                'label'               => 'Other',
                'description'         => 'Catch-all for events that do not fit other classes. Manual-creatable.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 2,
                'red_days'            => 0,
                'show_days'           => 14,
                'green_visibility'    => ['agent'],
                'amber_visibility'    => ['agent'],
                'red_visibility'      => ['agent'],
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // #44b private (ITEM 4 — personal time-block)
            [
                'event_class'         => 'private',
                'label'               => 'Private',
                'description'         => 'Personal time block. Only the creator sees the details; everyone else sees a "Private" busy slot so they know the time is taken.',
                'is_active'           => true,
                'green_days'          => 7,
                'amber_days'          => 2,
                'red_days'            => 0,
                // null = always show — a personal block can be booked any distance out.
                'show_days'           => null,
                // Everyone in scope sees the BUSY block; CalendarController redacts
                // the content (title/detail) for anyone but the creator (role-blind).
                'green_visibility'    => ['all'],
                'amber_visibility'    => ['all'],
                'red_visibility'      => ['all'],
                // No notifications — a private block is personal, not an alert.
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => [],
                'daily_digest_enabled'=> false,
                'daily_digest_roles'  => null,
            ],

            // ========== GROUP E — Leave Events (2) ==========

            // #45 leave_annual
            // NOTE: Leave visibility is interim. Module 3 (Contact Governance) will
            // introduce agency_leave_settings for proper per-role configuration.
            // Agents see only their own leave via creator bypass (user_id match in canSee).
            // BM + admin see all leave in agency (branch filter deferred to Module 3).
            [
                'event_class'         => 'leave_annual',
                'event_nature'        => 'informational',
                'label'               => 'Annual Leave',
                'description'         => 'Approved annual leave. Agents see own via creator bypass; BM+admin see all.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 3,
                'red_days'            => 0,
                'show_days'           => 60,
                'green_visibility'    => ['bm', 'admin'],
                'amber_visibility'    => ['bm', 'admin'],
                'red_visibility'      => ['bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => [],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],

            // #46 leave_sick
            [
                'event_class'         => 'leave_sick',
                'event_nature'        => 'informational',
                'label'               => 'Sick Leave',
                'description'         => 'Approved sick leave. Agents see own via creator bypass; BM+admin see all.',
                'is_active'           => true,
                'green_days'          => 14,
                'amber_days'          => 3,
                'red_days'            => 0,
                'show_days'           => 60,
                'green_visibility'    => ['bm', 'admin'],
                'amber_visibility'    => ['bm', 'admin'],
                'red_visibility'      => ['bm', 'admin'],
                'green_notifications' => [],
                'amber_notifications' => [],
                'red_notifications'   => [],
                'daily_digest_enabled'=> true,
                'daily_digest_roles'  => ['bm'],
            ],
        ];
    }
}
