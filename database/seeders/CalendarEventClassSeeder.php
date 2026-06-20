<?php

namespace Database\Seeders;

use App\Models\CommandCenter\CalendarEventClassSetting;
use Illuminate\Database\Seeder;

class CalendarEventClassSeeder extends Seeder
{
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
            'viewing'              => ['actor_role' => 'buyer_action',  'completion_behaviour' => 'require_feedback'],
            'listing_presentation' => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback'],
            'property_evaluation'  => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback'],
            'meeting'              => ['actor_role' => 'both',          'completion_behaviour' => 'freeform'],
            'other'                => ['actor_role' => 'both',          'completion_behaviour' => 'freeform'],
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

        $this->command->info('Seeded ' . count($this->classes()) . ' calendar event class settings.');
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
