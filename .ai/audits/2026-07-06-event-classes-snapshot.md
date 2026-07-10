# Event Classes — LIVE snapshot (restoration baseline)

> AT-197 Part A. Captured 2026-07-06 18:14 SAST, read-only, LIVE calendar_event_class_settings.
> Agency 1 (HFC) has 0 override rows — inherits 49 GLOBAL (agency_id=NULL) classes. Active: 48, inactive: 1.
> RESTORE: delete the agency-1 overrides created by the turn-off (or reset is_active per the JSON). Globals untouched.

| event_class | label | type | active | grn/amb/red | show | occ | nature | digest |
|---|---|---|:--:|---|--:|:--:|---|:--:|
| `agent_birthday` | Agent Birthday | — | ON | 7/3/0 | 14 | n | informational | n |
| `agent_document_expiry` | Agent Document Expiry | — | ON | 60/30/14 | 90 | n | actionable | Y |
| `leave_annual` | Annual Leave | — | ON | 14/3/0 | 60 | n | informational | Y |
| `salary_review` | Annual Salary Review | — | ON | 30/14/7 | 60 | n | actionable | n |
| `commercial_lease_expiry` | Commercial Lease Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `compliance_override_expiry` | Compliance Override Expiry | — | ON | 14/7/3 | 30 | n | actionable | n |
| `compliance_provision_expiry` | Compliance Provision Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `contact_birthday` | Contact Birthday | — | ON | 7/3/0 | 14 | n | informational | n |
| `deal_step_deadline` | Deal Pipeline Step Due | — | ON | 14/7/3 | 21 | n | actionable | Y |
| `employee_termination` | Employee Last Day | — | ON | 14/7/3 | 30 | n | actionable | Y |
| `screening_due` | Employee Screening Due | — | ON | 60/30/14 | 90 | n | actionable | Y |
| `employment_anniversary` | Employment Anniversary | — | ON | 7/3/0 | 14 | n | actionable | n |
| `ffc_expiry` | FFC Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `fica_renewal_due` | FICA Renewal Due | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `filed_document_expiry` | Filed Document Expiry | — | ON | 30/14/7 | 60 | n | actionable | n |
| `irp5_deadline` | IRP5 Issue Deadline | — | ON | 30/14/7 | 60 | n | actionable | Y |
| `lease_expiry` | Lease Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `leave_cycle_end` | Leave Cycle End | — | ON | 30/14/7 | 60 | n | actionable | n |
| `listing_presentation` | Listing presentation | — | ON | 14/5/1 | 21 | Y | actionable | Y |
| `mandate_expiry` | Mandate Expiry | — | ON | 30/14/7 | 90 | n | actionable | Y |
| `manual` | Manual | — | ON | 7/3/0 | — | Y | actionable | n |
| `manual` | Manual | — | ON | 7/3/0 | — | Y | actionable | n |
| `meeting` | Meeting | — | ON | 7/2/0 | 14 | Y | informational | Y |
| `office_closure` | Office Closure | — | off | 14/7/0 | 30 | n | informational | n |
| `other` | Other | — | ON | 7/2/0 | 14 | Y | informational | n |
| `payroll_run` | Payroll Run | — | ON | 7/3/1 | 30 | n | actionable | Y |
| `pi_insurance_expiry` | PI Insurance Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `portal_listing_expiry` | Portal Listing Expiry | — | ON | 14/5/2 | 30 | n | actionable | n |
| `ppra_trust_audit` | PPRA Trust Audit Report | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `private` | Private | — | ON | 7/2/0 | — | Y | informational | n |
| `property_evaluation` | Property evaluation | — | ON | 14/5/1 | 21 | Y | actionable | Y |
| `viewing` | Property viewing | — | ON | 7/2/0 | 14 | Y | actionable | Y |
| `rent_due` | Rent Due Date | — | ON | 3/1/0 | 7 | n | actionable | n |
| `rent_escalation` | Rent Escalation Effective | — | ON | 14/7/3 | 30 | n | actionable | n |
| `rmcp_ack_expiry` | RMCP Acknowledgement Expiry | — | ON | 30/14/7 | 60 | n | actionable | Y |
| `rmcp_review_due` | RMCP Review Due | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `sales_doc_expiry` | Sales Document Expiry | — | ON | 5/2/1 | 14 | n | actionable | n |
| `sars_emp201` | SARS EMP201 Due | — | ON | 5/3/1 | 14 | n | actionable | Y |
| `sars_emp501` | SARS EMP501 Reconciliation | — | ON | 30/14/7 | 60 | n | actionable | Y |
| `sdl_submission` | SDL Submission Due | — | ON | 5/3/1 | 14 | n | actionable | Y |
| `property_showday` | Show Day / Open House | — | ON | 3/1/0 | 30 | n | actionable | n |
| `leave_sick` | Sick Leave | — | ON | 14/3/0 | 60 | n | informational | Y |
| `signature_expiry` | Signature Request Expiry | — | ON | 5/2/1 | 14 | n | actionable | n |
| `deal_registration_target` | Target Registration Date | — | ON | 21/10/5 | 90 | n | actionable | Y |
| `task` | Task / To-do | — | ON | 7/2/0 | 14 | n | actionable | n |
| `tax_clearance_expiry` | Tax Clearance Expiry | — | ON | 60/30/14 | 120 | n | actionable | Y |
| `tax_year_end` | Tax Year End | — | ON | 30/14/7 | 60 | n | actionable | Y |
| `training_expiry` | Training Certification Expiry | — | ON | 30/14/7 | 60 | n | actionable | Y |
| `uif_declaration` | UIF Declaration Due | — | ON | 5/3/1 | 14 | n | actionable | Y |

## Full restoration JSON (all 49 global rows)

```json
[
    {
        "id": 35,
        "agency_id": null,
        "event_class": "agent_birthday",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"bm\"]",
        "amber_visibility": "[\"bm\"]",
        "red_visibility": "[\"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"bm\": [\"in_app\"]}",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Agent Birthday",
        "description": "Annual recurring. BM sees team birthdays.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 20,
        "agency_id": null,
        "event_class": "agent_document_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 90,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"], \"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Agent Document Expiry",
        "description": "Generic document renewal. Honours agency_document_type_configs.renewal_days.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 47,
        "agency_id": null,
        "event_class": "leave_annual",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 14,
        "amber_days": 3,
        "red_days": 0,
        "show_days": 60,
        "green_visibility": "[\"bm\", \"admin\"]",
        "amber_visibility": "[\"bm\", \"admin\"]",
        "red_visibility": "[\"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "[]",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Annual Leave",
        "description": "Approved annual leave. Agents see own via creator bypass; BM+admin see all.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 38,
        "agency_id": null,
        "event_class": "salary_review",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"hr\"]",
        "amber_visibility": "[\"hr\", \"admin\"]",
        "red_visibility": "[\"hr\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"hr\": [\"in_app\"]}",
        "red_notifications": "{\"hr\": [\"in_app\", \"email\"], \"admin\": [\"in_app\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Annual Salary Review",
        "description": "Internal HR planning. Retention and budgeting.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 27,
        "agency_id": null,
        "event_class": "commercial_lease_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Commercial Lease Expiry",
        "description": "Higher revenue impact than residential. Commercial vacancy forecasting.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 19,
        "agency_id": null,
        "event_class": "compliance_override_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 7,
        "red_days": 3,
        "show_days": 30,
        "green_visibility": "[\"compliance_officer\"]",
        "amber_visibility": "[\"compliance_officer\"]",
        "red_visibility": "[\"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Compliance Override Expiry",
        "description": "Underlying requirement re-activates when override expires.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 18,
        "agency_id": null,
        "event_class": "compliance_provision_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"compliance_officer\"]",
        "amber_visibility": "[\"compliance_officer\", \"admin\"]",
        "red_visibility": "[\"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Compliance Provision Expiry",
        "description": "Agency-level regulatory provision. Compliance gap when expired.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 36,
        "agency_id": null,
        "event_class": "contact_birthday",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Contact Birthday",
        "description": "Annual recurring. Personal relationship building.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 8,
        "agency_id": null,
        "event_class": "deal_step_deadline",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 7,
        "red_days": 3,
        "show_days": 21,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Deal Pipeline Step Due",
        "description": "Bond/transfer/compliance deadlines. Defaults overridden by step rag_*_days.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 29,
        "agency_id": null,
        "event_class": "employee_termination",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 7,
        "red_days": 3,
        "show_days": 30,
        "green_visibility": "[\"hr\"]",
        "amber_visibility": "[\"hr\", \"payroll\", \"admin\"]",
        "red_visibility": "[\"hr\", \"payroll\", \"admin\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"hr\": [\"in_app\"], \"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"hr\": [\"in_app\", \"email\"], \"admin\": [\"in_app\"], \"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"hr\", \"payroll\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Employee Last Day",
        "description": "Final payroll, leave payout, system access revocation, equipment return.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 15,
        "agency_id": null,
        "event_class": "screening_due",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 90,
        "green_visibility": "[\"compliance_officer\"]",
        "amber_visibility": "[\"compliance_officer\", \"hr\"]",
        "red_visibility": "[\"compliance_officer\", \"hr\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"hr\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\", \"hr\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Employee Screening Due",
        "description": "Periodic background screening. Frequency by risk: high=1y, med=3y, low=5y.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 34,
        "agency_id": null,
        "event_class": "employment_anniversary",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\", \"bm\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"bm\": [\"in_app\"]}",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Employment Anniversary",
        "description": "Annual recurring. Culture/retention milestone.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 5,
        "agency_id": null,
        "event_class": "ffc_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"bm\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "{\"agent\": [\"in_app\"]}",
        "amber_notifications": "{\"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\", \"email\"], \"admin\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "FFC Expiry",
        "description": "CRITICAL: Agent cannot legally transact without valid FFC.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 10,
        "agency_id": null,
        "event_class": "fica_renewal_due",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"], \"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "FICA Renewal Due",
        "description": "Source: fica_submissions.fica_expires_at (24-month PPRA validity).",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 39,
        "agency_id": null,
        "event_class": "filed_document_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Filed Document Expiry",
        "description": "Generic filing register expiry. Mandate docs excluded (use mandate_expiry).",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 33,
        "agency_id": null,
        "event_class": "irp5_deadline",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\"]",
        "red_visibility": "[\"payroll\", \"admin\", \"accountant\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "IRP5 Issue Deadline",
        "description": "Computed: ~60 days after tax year end. SARS requirement.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 4,
        "agency_id": null,
        "event_class": "lease_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\", \"email\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "Lease Expiry",
        "description": "Tenant lease expiring. Source: lease_records.lease_end_date.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 28,
        "agency_id": null,
        "event_class": "leave_cycle_end",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Leave Cycle End",
        "description": "Employee may forfeit accrued leave unknowingly.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 43,
        "agency_id": null,
        "event_class": "listing_presentation",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 5,
        "red_days": 1,
        "show_days": 21,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"agent\", \"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "seller_action",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "require_feedback",
        "feedback_mode": "per_contact",
        "label": "Listing presentation",
        "description": "Agent presenting CMA/market analysis to potential seller. Longer planning cycle.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 3,
        "agency_id": null,
        "event_class": "mandate_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 90,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\", \"email\"], \"admin\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "Mandate Expiry",
        "description": "Sole/open/dual mandate expiring. Risk: lose listing to competitor.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 2,
        "agency_id": null,
        "event_class": "manual",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 0,
        "show_days": null,
        "green_visibility": "[\"all\"]",
        "amber_visibility": "[\"all\"]",
        "red_visibility": "[\"all\"]",
        "green_notifications": "{}",
        "amber_notifications": "{}",
        "red_notifications": "{}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "agent",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Manual",
        "description": "Manually-created calendar events",
        "created_at": "2026-05-29 11:50:54",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 1,
        "agency_id": null,
        "event_class": "manual",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 0,
        "show_days": null,
        "green_visibility": "[\"all\"]",
        "amber_visibility": "[\"all\"]",
        "red_visibility": "[\"all\"]",
        "green_notifications": "{}",
        "amber_notifications": "{}",
        "red_notifications": "{}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "agent",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Manual",
        "description": "Manually-created calendar events",
        "created_at": "2026-05-29 11:50:45",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 44,
        "agency_id": null,
        "event_class": "meeting",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 7,
        "amber_days": 2,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"agent\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "both",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Meeting",
        "description": "General meeting \u2014 team, client, or external. Manual-creatable.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 40,
        "agency_id": null,
        "event_class": "office_closure",
        "is_active": 0,
        "event_nature": "informational",
        "green_days": 14,
        "amber_days": 7,
        "red_days": 0,
        "show_days": 30,
        "green_visibility": "[\"all\"]",
        "amber_visibility": "[\"all\"]",
        "red_visibility": "[\"all\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Office Closure",
        "description": "SYSTEM-level. Everyone sees. No notifications (informational).",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 46,
        "agency_id": null,
        "event_class": "other",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 7,
        "amber_days": 2,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "both",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Other",
        "description": "Catch-all for events that do not fit other classes. Manual-creatable.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 11,
        "agency_id": null,
        "event_class": "payroll_run",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 3,
        "red_days": 1,
        "show_days": 30,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\"]",
        "red_visibility": "[\"payroll\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Payroll Run",
        "description": "Pay date for payroll runs in draft/processing status.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 6,
        "agency_id": null,
        "event_class": "pi_insurance_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"], \"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "PI Insurance Expiry",
        "description": "CRITICAL: Agent operates without PI cover.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 24,
        "agency_id": null,
        "event_class": "portal_listing_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 5,
        "red_days": 2,
        "show_days": 30,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "Portal Listing Expiry",
        "description": "P24/PP listing expiry. Buyer exposure lost when expired.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 16,
        "agency_id": null,
        "event_class": "ppra_trust_audit",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"admin\"]",
        "amber_visibility": "[\"admin\"]",
        "red_visibility": "[\"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"admin\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "PPRA Trust Audit Report",
        "description": "Annual trust account audit. PPRA regulatory action if missed.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 49,
        "agency_id": null,
        "event_class": "private",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 7,
        "amber_days": 2,
        "red_days": 0,
        "show_days": null,
        "green_visibility": "[\"all\"]",
        "amber_visibility": "[\"all\"]",
        "red_visibility": "[\"all\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "[]",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "both",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Private",
        "description": "Personal time block. Only the creator sees the details; everyone else sees a \"Private\" busy slot so they know the time is taken.",
        "created_at": "2026-07-03 07:57:24",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 42,
        "agency_id": null,
        "event_class": "property_evaluation",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 5,
        "red_days": 1,
        "show_days": 21,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"agent\", \"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "seller_action",
        "autofill_buyers": 0,
        "occupies_time": 1,
        "completion_behaviour": "require_feedback",
        "feedback_mode": "per_contact",
        "label": "Property evaluation",
        "description": "Agent evaluating property for potential seller. Longer planning cycle, booked days/weeks ahead.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 41,
        "agency_id": null,
        "event_class": "viewing",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 2,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"agent\", \"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 1,
        "buyer_facing": 0,
        "actor_role": "buyer_action",
        "autofill_buyers": 1,
        "occupies_time": 1,
        "completion_behaviour": "require_feedback",
        "feedback_mode": "per_contact",
        "label": "Property viewing",
        "description": "Buyer viewing a property. Short cycle, same-day actionable. Red on event day = capture feedback after.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 26,
        "agency_id": null,
        "event_class": "rent_due",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 3,
        "amber_days": 1,
        "red_days": 0,
        "show_days": 7,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Rent Due Date",
        "description": "Computed: 1st of each month. Auto-purges after payment.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 25,
        "agency_id": null,
        "event_class": "rent_escalation",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 14,
        "amber_days": 7,
        "red_days": 3,
        "show_days": 30,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Rent Escalation Effective",
        "description": "Tenant billed wrong amount if escalation not applied. Revenue leakage.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 37,
        "agency_id": null,
        "event_class": "rmcp_ack_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "RMCP Acknowledgement Expiry",
        "description": "Agent must re-acknowledge RMCP. 12-month cycle.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 14,
        "agency_id": null,
        "event_class": "rmcp_review_due",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"compliance_officer\"]",
        "amber_visibility": "[\"compliance_officer\", \"admin\"]",
        "red_visibility": "[\"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "RMCP Review Due",
        "description": "PPRA compliance breach if missed.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 23,
        "agency_id": null,
        "event_class": "sales_doc_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 5,
        "amber_days": 2,
        "red_days": 1,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Sales Document Expiry",
        "description": "sales_document_recipients.token_expires_at.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 12,
        "agency_id": null,
        "event_class": "sars_emp201",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 5,
        "amber_days": 3,
        "red_days": 1,
        "show_days": 14,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\"]",
        "red_visibility": "[\"payroll\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\"], \"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\", \"admin\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "SARS EMP201 Due",
        "description": "Computed: 7th of each month. SARS penalties and interest if missed.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 13,
        "agency_id": null,
        "event_class": "sars_emp501",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\", \"accountant\"]",
        "red_visibility": "[\"payroll\", \"admin\", \"accountant\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"admin\": [\"in_app\"], \"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"payroll\": [\"in_app\", \"email\"], \"accountant\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\", \"admin\", \"accountant\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "SARS EMP501 Reconciliation",
        "description": "Computed: 31 May + 31 Oct biannual. Reconciliation penalties if missed.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 32,
        "agency_id": null,
        "event_class": "sdl_submission",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 5,
        "amber_days": 3,
        "red_days": 1,
        "show_days": 14,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\"]",
        "red_visibility": "[\"payroll\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "SDL Submission Due",
        "description": "Computed: 7th of each month. Skills Development Levy.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 21,
        "agency_id": null,
        "event_class": "property_showday",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 3,
        "amber_days": 1,
        "red_days": 0,
        "show_days": 30,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Show Day / Open House",
        "description": "Tactical event. Missed open house = missed buyer leads.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 48,
        "agency_id": null,
        "event_class": "leave_sick",
        "is_active": 1,
        "event_nature": "informational",
        "green_days": 14,
        "amber_days": 3,
        "red_days": 0,
        "show_days": 60,
        "green_visibility": "[\"bm\", \"admin\"]",
        "amber_visibility": "[\"bm\", \"admin\"]",
        "red_visibility": "[\"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "[]",
        "red_notifications": "[]",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Sick Leave",
        "description": "Approved sick leave. Agents see own via creator bypass; BM+admin see all.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 22,
        "agency_id": null,
        "event_class": "signature_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 5,
        "amber_days": 2,
        "red_days": 1,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\", \"bm\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "Signature Request Expiry",
        "description": "Active signature_requests.token_expires_at.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 9,
        "agency_id": null,
        "event_class": "deal_registration_target",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 21,
        "amber_days": 10,
        "red_days": 5,
        "show_days": 90,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\", \"email\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Target Registration Date",
        "description": "Expected deeds registration. Source: deals_v2.expected_registration.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 45,
        "agency_id": null,
        "event_class": "task",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 7,
        "amber_days": 2,
        "red_days": 0,
        "show_days": 14,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\"]",
        "red_visibility": "[\"agent\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\"]}",
        "daily_digest_enabled": 0,
        "daily_digest_roles": null,
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Task / To-do",
        "description": "Personal task with a deadline. Manual-creatable.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 7,
        "agency_id": null,
        "event_class": "tax_clearance_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 60,
        "amber_days": 30,
        "red_days": 14,
        "show_days": 120,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"compliance_officer\"]",
        "red_visibility": "[\"agent\", \"compliance_officer\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"], \"compliance_officer\": [\"in_app\"]}",
        "red_notifications": "{\"agent\": [\"in_app\", \"email\"], \"compliance_officer\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "require_reason",
        "feedback_mode": "per_contact",
        "label": "Tax Clearance Expiry",
        "description": "Cannot prove tax compliance. SARS issues.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 30,
        "agency_id": null,
        "event_class": "tax_year_end",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\", \"accountant\"]",
        "red_visibility": "[\"payroll\", \"admin\", \"accountant\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"admin\": [\"in_app\"], \"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"admin\": [\"in_app\", \"email\"], \"payroll\": [\"in_app\", \"email\"], \"accountant\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\", \"admin\", \"accountant\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Tax Year End",
        "description": "Computed: 28 Feb annual. Triggers IRP5, EMP501, annual reconciliation.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 17,
        "agency_id": null,
        "event_class": "training_expiry",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 30,
        "amber_days": 14,
        "red_days": 7,
        "show_days": 60,
        "green_visibility": "[\"agent\"]",
        "amber_visibility": "[\"agent\", \"bm\"]",
        "red_visibility": "[\"agent\", \"bm\", \"compliance_officer\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"agent\": [\"in_app\"]}",
        "red_notifications": "{\"bm\": [\"in_app\"], \"agent\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"bm\", \"compliance_officer\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "Training Certification Expiry",
        "description": "CPD non-compliance. PPRA audit finding.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    },
    {
        "id": 31,
        "agency_id": null,
        "event_class": "uif_declaration",
        "is_active": 1,
        "event_nature": "actionable",
        "green_days": 5,
        "amber_days": 3,
        "red_days": 1,
        "show_days": 14,
        "green_visibility": "[\"payroll\"]",
        "amber_visibility": "[\"payroll\", \"admin\"]",
        "red_visibility": "[\"payroll\", \"admin\"]",
        "green_notifications": "[]",
        "amber_notifications": "{\"payroll\": [\"in_app\"]}",
        "red_notifications": "{\"payroll\": [\"in_app\", \"email\"]}",
        "daily_digest_enabled": 1,
        "daily_digest_roles": "[\"payroll\"]",
        "default_reminder_offsets": null,
        "default_reminder_channels": null,
        "allow_multiple_properties": 0,
        "buyer_facing": 0,
        "actor_role": "neither",
        "autofill_buyers": 0,
        "occupies_time": 0,
        "completion_behaviour": "freeform",
        "feedback_mode": "per_contact",
        "label": "UIF Declaration Due",
        "description": "Computed: 7th of each month. Department of Employment and Labour.",
        "created_at": "2026-06-03 13:11:12",
        "updated_at": "2026-07-06 09:32:55"
    }
]
```

---

## Part A — TURN-OFF EXECUTED (LIVE, agency 1) — 2026-07-06

Turned **all 49 classes OFF for agency 1 (HFC)** via the settings screen's own mechanism:
an agency-1 override row per class (`updateOrCreate` on `agency_id`+`event_class`), a
faithful copy of the global with `is_active=false`. Result: **48 override rows** (the
duplicate `manual` global collapses to one), effective `is_active` active-count = **0/49**.
Globals were NOT touched. The settings screen renders (200) all-off. Live workers restarted
to clear the per-process `forAgencyAndClass` resolve-cache.

**Restoration (for the midweek setup session):** delete the agency-1 override rows
(`CalendarEventClassSetting::withoutGlobalScopes()->where('agency_id',1)->delete()`, or the
screen's per-class "reset to default") → agency 1 falls back to inheriting the globals in
the JSON above. No global was modified, so the pre-state is fully recoverable.

**Gotcha logged:** the first turn-off pass copied `getAttributes()` (raw JSON *strings*) into
the array-cast columns (`green_visibility` etc.), which 500'd the screen (`in_array(): arg 2
must be array`). Fixed by re-creating from the model's cast accessors (`$g->{col}` → decoded
arrays). When cloning a row with JSON-cast columns, read via the model, never `getAttributes()`.

## Part A — IMPACT STATEMENT (what goes quiet vs what stays live)

**Goes QUIET for HFC until the setup session** — everything that resolves the class per-agency
via `CalendarEventClassSetting::forAgencyAndClass()` + `is_active`:
- **The system deadline feed's intelligence** — mandate_expiry, portal_listing_expiry, FICA
  renewal, FFC/PI/tax-clearance expiry, rent_due/escalation, lease_expiry, deal_step_deadline,
  deal_registration_target, signature/sales-doc expiry, all the payroll/HR/compliance dates:
  they stop getting a RAG colour, stop escalating, and stop driving notifications
  (`CalendarThresholdResolver` + `CalendarNotificationDispatcher` both early-return on
  `!is_active`). The rows may still be materialised by the reconcile, but they're inert.
- **Per-class notification routing** (in-app/email to the configured roles) — off.
- **The daily calendar digest** (`SendCalendarDigests`, per-class `daily_digest_enabled`) — off.
- **Class-default reminders** newly seeded onto events of these classes — not applied.

**STAYS LIVE (independent of this system):**
- **Manual event creation** — creating viewings, meetings, listing presentations, evaluations,
  tasks, private/other events. The manual-creatable class list reads the GLOBAL `is_active`
  (`CalendarController` :458-460, `whereNull('agency_id')`), which was NOT changed. Agents keep
  working the calendar normally.
- **AT-178 event reminders** — per-event popup/email reminders an agent set on a specific event.
  `CalendarReminderService` has no class-`is_active` gate → they still fire.
- **AT-181 mailbox-health admin alerts** (today's work) — driven by `MailboxHealthRecorder`, not
  the calendar-class system → they still fire.
- **All non-calendar notifications** (WA capture, outreach, deal/DR1 flows outside the class feed,
  comms) — unaffected.
- **DR2 advisories** — would be class-driven, but DR2 is dormant (0 `deals_v2` rows) → nil impact.


---

# PART C — EXISTENCE AUDIT (setup-session dossier)

# AT-197 — Event-Class Emitter Reconciliation Dossier

**Read-only audit.** Code truth from `app/Services/CommandCenter/Calendar/Sources/`, the manual-create paths, and `LeaveCalendarService`. Routing from the seed defaults in `database/seeders/CalendarEventClassSeeder.php`. Row counts are **LIVE** (`/corex`, `calendar_events` grouped by `category`, all rows incl. soft-deleted; "active" excludes `deleted_at`).

---

## 0. Global-row inventory (the "49")

- `calendar_event_class_settings` has **49 global (agency_id NULL) rows**.
- **48 distinct `event_class` keys** — `manual` is duplicated: **id=1 and id=2, both label "Manual", both active** → the DUPLICATE.
- The seeder (`CalendarEventClassSeeder`) defines **47** classes (#1–#46 plus #44b `private`). It does **NOT** seed `manual` — the two `manual` rows are legacy/un-seeded. `manual` is the runtime **default category** written when a manual event is created with no explicit category (`CalendarEventService.php:44`, `CalendarEventCreator.php`).
- One data anomaly: **1 live event with `category = NULL`** (source_type unknown, created 2026-04-23) — pre-dates the "category MUST be set" guard.

---

## 8 registered reconcile sources (AppServiceProvider.php:184–191)

ComplianceCalendarSource, DealCalendarSource, PropertyCalendarSource, RentalCalendarSource, PayrollCalendarSource, DocumentCalendarSource, PeopleCalendarSource, RecurringCalendarSource — all driven nightly by `corex:calendar:reconcile` (`ReconcileCalendarEvents.php`), upserted keyed by `(source_type, source_id, category)`. Leave events are **not** a reconcile source — they are written on leave-approval by `LeaveCalendarService`. Manual events are written by `CalendarEventService` / `CalendarEventCreator`.

---

## (i) Full reconciliation table

| # | Class | Label | Emitter (file:line) | Trigger (code truth) | Routing (seed vis / owner) | Rows live (active/total, max event) | Verdict |
|---|-------|-------|---------------------|----------------------|----------------------------|--------------------------------------|---------|
| 1 | `mandate_expiry` | Mandate Expiry | PropertyCalendarSource.php:49 | `properties.expiry_date` set & status in active/for_sale/draft/to_let | agent → +bm → +admin; digest bm/admin | 651/651, 2027-07-01 | **LIVE** |
| 2 | `lease_expiry` | Lease Expiry | RentalCalendarSource.php:45 & :83 (canonical `lease_records`/`rentals`) + PropertyCalendarSource.php:76 (fallback) | lease_end_date on lease_records / active rentals / property w/o lease_record | agent → +bm → +admin; digest bm | 52/52, 2029-08-14 | **LIVE** |
| 3 | `ffc_expiry` | FFC Expiry | ComplianceCalendarSource.php:44 | `users.ffc_expiry_date` not null | agent → +bm+compliance → +admin; digest compliance/admin | 12/12, 2029-01-01 | **LIVE** |
| 4 | `pi_insurance_expiry` | PI Insurance Expiry | ComplianceCalendarSource.php:64 | `users.pi_insurance_expiry` not null | agent → +compliance → +admin; digest compliance | 0/0 | **DORMANT** |
| 5 | `tax_clearance_expiry` | Tax Clearance Expiry | ComplianceCalendarSource.php:84 | `users.tax_clearance_expiry` not null | agent → +compliance → +admin | 0/0 | **DORMANT** |
| 6 | `deal_step_deadline` | Deal Pipeline Step Due | DealCalendarSource.php:40 | `deal_step_instances.due_date` on active/not_started step of live V2 deal | agent → +bm → +admin; digest bm | 0/0 | **DORMANT** |
| 7 | `deal_registration_target` | Target Registration Date | DealCalendarSource.php:84 | `deals_v2.expected_registration` on live deal | agent → +bm → +admin; digest bm | 0/0 | **DORMANT** |
| 8 | `fica_renewal_due` | FICA Renewal Due | ComplianceCalendarSource.php:106 | `fica_submissions.fica_expires_at` where status=approved | agent → +compliance → +admin; digest compliance | 98/98, 2028-07-03 | **LIVE** |
| 9 | `payroll_run` | Payroll Run | PayrollCalendarSource.php:41 | `payroll_runs.pay_date` where status draft/processing/pending/open | payroll → +admin; digest payroll/admin | 0/0 | **DORMANT** |
| 10 | `sars_emp201` | SARS EMP201 Due | PayrollCalendarSource.php:64 (computed) | 7th of each month, +3 lookahead | payroll → +admin; digest payroll/admin | 5/5, 2026-10-07 | **LIVE** |
| 11 | `sars_emp501` | SARS EMP501 Reconciliation | PayrollCalendarSource.php:81 (computed) | 31 May + 31 Oct biannual | payroll → +admin+accountant; digest same | 3/3, 2027-05-31 | **LIVE** |
| 12 | `rmcp_review_due` | RMCP Review Due | ComplianceCalendarSource.php:138 | `rmcp_versions.next_review_due` not null | compliance → +admin; digest compliance/admin | 1/1, 2027-04-21 | **LIVE** |
| 13 | `screening_due` | Employee Screening Due | ComplianceCalendarSource.php:160 | `employee_screenings.next_due_on` not null | compliance → +hr → +admin; digest compliance/hr | 0/0 | **DORMANT** |
| 14 | `ppra_trust_audit` | PPRA Trust Audit Report | RecurringCalendarSource.php:37 (computed) | annual 30 June | admin only; digest admin | 2/2, 2027-06-30 | **LIVE** |
| 15 | `training_expiry` | Training Certification Expiry | ComplianceCalendarSource.php:192 | `training_completions.expires_at` not null | agent → +bm → +compliance; digest bm/compliance | 0/0 | **DORMANT** |
| 16 | `compliance_provision_expiry` | Compliance Provision Expiry | ComplianceCalendarSource.php:221 | `agency_compliance_provisions.effective_until` not null | compliance → +admin; digest compliance/admin | 0/0 | **DORMANT** |
| 17 | `compliance_override_expiry` | Compliance Override Expiry | ComplianceCalendarSource.php:250 | `user_compliance_overrides.expires_at` not null | compliance → +admin | 0/0 | **DORMANT** |
| 18 | `agent_document_expiry` | Agent Document Expiry | ComplianceCalendarSource.php:280 | `user_documents.expiry_date` set (type has_expiry or no config) | agent → +compliance → +admin; digest compliance | 16/16, 2029-01-01 | **LIVE** |
| 19 | `property_showday` | Show Day / Open House | PropertyCalendarSource.php:103 | `property_showdays.start_date` ≥ now−2d | agent → +bm; no digest | 0/0 | **DORMANT** |
| 20 | `signature_expiry` | Signature Request Expiry | DocumentCalendarSource.php:42 | `signature_requests.token_expires_at`, status waiting/pending/viewed | agent → +bm; no digest | 26/26, 2026-06-11 | **LIVE** |
| 21 | `sales_doc_expiry` | Sales Document Expiry | DocumentCalendarSource.php:71 | `sales_document_recipients.token_expires_at`, status sent | agent → +bm; no digest | 0/0 | **DORMANT** |
| 22 | `portal_listing_expiry` | Portal Listing Expiry | PropertyCalendarSource.php:140 | `listing_stocks.expires_at` not null | agent → +bm; no digest | 205/210, 2027-02-02 | **LIVE** |
| 23 | `rent_escalation` | Rent Escalation Effective | RentalCalendarSource.php:118 | `rental_amount_versions.effective_from` within next 30d | agent → +bm; no digest | 0/0 | **DORMANT** |
| 24 | `rent_due` | Rent Due Date | RentalCalendarSource.php:157 (computed) | next 1st-of-month per active rental w/ future lease_end | agent → +bm; no digest | 47/47, 2026-08-01 | **LIVE** |
| 25 | `commercial_lease_expiry` | Commercial Lease Expiry | RentalCalendarSource.php:193 | `commercial_evaluation_units.lease_end` not null | agent → +bm → +admin; digest bm | 0/0 | **DORMANT** |
| 26 | `leave_cycle_end` | Leave Cycle End | PeopleCalendarSource.php:160 | `leave_entitlements.cycle_end_date` ≥ now−30d | agent → +bm; no digest | 0/0 | **DORMANT** |
| 27 | `employee_termination` | Employee Last Day | PeopleCalendarSource.php:131 | `payroll_employees.termination_date` ≥ now−30d | hr → +payroll+admin → +bm; digest hr/payroll | 0/0 | **DORMANT** |
| 28 | `tax_year_end` | Tax Year End | PayrollCalendarSource.php:101 (computed) | annual 28 Feb | payroll → +admin+accountant; digest same | 1/1, 2027-02-28 | **LIVE** |
| 29 | `uif_declaration` | UIF Declaration Due | PayrollCalendarSource.php:69 (computed) | 7th of each month | payroll → +admin; digest payroll | 5/5, 2026-10-07 | **LIVE** |
| 30 | `sdl_submission` | SDL Submission Due | PayrollCalendarSource.php:74 (computed) | 7th of each month | payroll → +admin; digest payroll | 5/5, 2026-10-07 | **LIVE** |
| 31 | `irp5_deadline` | IRP5 Issue Deadline | PayrollCalendarSource.php:119 (computed) | annual 31 May | payroll → +admin → +accountant; digest payroll/admin | 2/2, 2027-05-31 | **LIVE** |
| 32 | `employment_anniversary` | Employment Anniversary | PeopleCalendarSource.php:96 (computed) | annual from `users.employment_date` (skips year 1) | agent+bm; no digest | 0/0 | **DORMANT** |
| 33 | `agent_birthday` | Agent Birthday | PeopleCalendarSource.php:44 (computed) | annual from `users.date_of_birth`, active users | bm only; no digest | 0/0 | **DORMANT** |
| 34 | `contact_birthday` | Contact Birthday | PeopleCalendarSource.php:67 (computed) | annual from `contacts.birthday` **where `birthday_reminder=true`** (opt-in) | agent only; no digest | 3417/3421, 2027-06-07 | **LIVE** |
| 35 | `rmcp_ack_expiry` | RMCP Acknowledgement Expiry | PeopleCalendarSource.php:189 | `rmcp_acknowledgements.valid_until` ≥ now−30d | agent → +compliance → +admin; digest compliance | 18/20, 2027-06-01 | **LIVE** |
| 36 | `salary_review` | Annual Salary Review | RecurringCalendarSource.php:42 (computed) | annual 1 March | hr → +admin; no digest | 1/1, 2027-03-01 | **LIVE** |
| 37 | `filed_document_expiry` | Filed Document Expiry | PropertyCalendarSource.php:175 | `document_filing_register.expiry_date`, excl. types EA/OA | agent → +bm; no digest | 0/0 | **DORMANT** |
| 38 | `office_closure` | Office Closure | **none** (deferred — RecurringCalendarSource.php:14 note) | n/a — `is_active=false`, Tier-2 leave module deferred | all; no notifications | 0/0 | **ORPHAN (deferred, inactive)** |
| 39 | `viewing` | Property viewing | CalendarController.php:24 + CalendarEventService.php:26 (manual) | agent creates a buyer-viewing appointment (multi-property allowed) | agent → +bm → +admin; digest agent/bm | 32/33, 2026-07-14 | **LIVE** |
| 40 | `property_evaluation` | Property evaluation | manual (creatable set) | agent books a property evaluation | agent → +bm → +admin; digest agent/bm | 4/4, 2026-07-07 | **LIVE** |
| 41 | `listing_presentation` | Listing presentation | manual (creatable set) | agent books a CMA/listing presentation to a seller | agent → +bm → +admin; digest agent/bm | 11/11, 2026-07-10 | **LIVE** |
| 42 | `meeting` | Meeting | manual (creatable set) | agent creates a general meeting | agent → +bm; digest agent | 13/13, 2026-07-08 | **LIVE** |
| 43 | `task` | Task / To-do | manual (creatable set) | agent creates a personal task with a deadline | agent only; no digest | 24/24, 2027-01-12 | **LIVE** |
| 44 | `other` | Other | manual (creatable set) | catch-all manual event | agent only; no digest | 32/37, 2026-07-25 | **LIVE** |
| 45 | `private` | Private | CalendarController.php:24 & :728 (manual) | agent creates a personal busy block (content redacted to others) | all see busy slot, creator sees detail; no digest | 13/14, 2026-07-31 | **LIVE** |
| 46 | `leave_annual` | Annual Leave | LeaveCalendarService.php:55–72 | on leave **approval**, `category='leave_'+leaveType.category`=annual | bm+admin (agent via creator bypass); digest bm | 0/0 | **DORMANT** |
| 47 | `leave_sick` | Sick Leave | LeaveCalendarService.php:55–72 | on leave approval, category=sick | bm+admin (agent via creator bypass); digest bm | 0/0 | **DORMANT** |
| 48 | `manual` (id=1) | Manual | CalendarEventService.php:44 default / CalendarEventCreator.php | **default category** when a manual event is created without an explicit class | creator (always visible to creator, CalendarController:444) | 23/25, 2026-07-25 | **LIVE (legacy, un-seeded)** |
| — | `manual` (id=2) | Manual | — (no distinct emitter; second global row) | duplicate of id=1 | same | (shares the `manual` count) | **DUPLICATE** |
| — | `(null)` | — | none (pre-guard anomaly) | 1 orphaned event with NULL category | — | 1/1, 2026-04-23 | **DATA ANOMALY** |

**Tally:** LIVE = 24 · DORMANT = 20 · ORPHAN = 1 (`office_closure`) · DUPLICATE = 1 (`manual` id=2) · plus 1 NULL-category data anomaly. (48 distinct keys; `manual` counted once as class + once as duplicate.)

---

## (ii) Plain-language descriptions (Johan format) — from code truth

**mandate_expiry** — The countdown to a listing mandate lapsing. Fires when a stock property's `expiry_date` enters the 90-day show window and it's still on-market. Routes to the listing agent, escalating to BM then admin as it reddens. *e.g. Sole mandate expiring in 14 days on 12 Beach Rd → agent + BM.*

**lease_expiry** — A tenant's lease reaching its end date. Fires from a signed `lease_record` (canonical), an active rental, or a property carrying a `lease_end_date` with no lease record (fallback). Routes to the managing agent then BM/admin. *e.g. Lease ends in 30 days at 4 Marine Dr → agent + BM.*

**ffc_expiry** — An agent's Fidelity Fund Certificate running out; they cannot legally transact without it. Fires from `users.ffc_expiry_date`. Routes agent → BM + compliance → admin. *e.g. FFC expires in 14 days — J. Smith → agent + compliance officer + BM.*

**pi_insurance_expiry** — Professional Indemnity cover lapsing. Fires from `users.pi_insurance_expiry`. Routes agent → compliance → admin. *e.g. PI insurance expires in 30 days — J. Smith → agent + compliance officer.*

**tax_clearance_expiry** — An agent's SARS tax-clearance validity ending. Fires from `users.tax_clearance_expiry`. Routes agent → compliance → admin. *e.g. Tax clearance expires in 14 days — J. Smith → agent + compliance officer.*

**deal_step_deadline** — A due date on a live V2 deal-pipeline step (bond, transfer, compliance). Fires from `deal_step_instances.due_date` on active/not-started steps. Routes to the deal's agent → BM → admin. *e.g. "Bond approval Due" in 3 days — DEAL-1042, 12 Beach Rd → agent + BM.*

**deal_registration_target** — The expected deeds-registration date for a live deal. Fires from `deals_v2.expected_registration`. Routes agent → BM → admin. *e.g. Registration expected in 5 days — DEAL-1042 → agent + BM.*

**fica_renewal_due** — A contact's approved FICA verification approaching its 24-month PPRA expiry. Fires from `fica_submissions.fica_expires_at` (approved only). Routes agent → compliance → admin. *e.g. FICA renewal due in 30 days — A. Buyer → agent + compliance officer.*

**payroll_run** — A scheduled pay date for a payroll run still in draft/processing. Fires from `payroll_runs.pay_date`. Routes payroll → admin. *e.g. Payroll run #7 pay date in 3 days → payroll + admin.*

**sars_emp201** — The monthly PAYE/UIF/SDL declaration to SARS, due the 7th. Computed monthly (3 months ahead). Routes payroll → admin. *e.g. EMP201 due 7 Oct → payroll + admin.*

**sars_emp501** — The biannual employer reconciliation (31 May / 31 Oct). Computed. Routes payroll → admin + accountant. *e.g. EMP501 reconciliation due 31 May → payroll + admin + accountant.*

**rmcp_review_due** — The agency's Risk Management & Compliance Programme reaching its scheduled review. Fires from `rmcp_versions.next_review_due`. Routes compliance → admin. *e.g. RMCP review due in 30 days → compliance officer + admin.*

**screening_due** — A staff member's periodic background screening coming due (risk-based cadence). Fires from `employee_screenings.next_due_on`. Routes compliance → HR → admin. *e.g. Background screening due in 30 days — R. Clerk → compliance officer + HR.*

**ppra_trust_audit** — The annual PPRA trust-account audit report (30 June). Computed. Routes admin only. *e.g. PPRA trust audit report due 30 Jun → admin.*

**training_expiry** — A CPD/training certification lapsing. Fires from `training_completions.expires_at`. Routes agent → BM → compliance. *e.g. Training expires in 14 days — J. Smith → agent + BM.*

**compliance_provision_expiry** — An agency-level regulatory provision reaching the end of its validity. Fires from `agency_compliance_provisions.effective_until`. Routes compliance → admin. *e.g. Compliance provision expires in 30 days → compliance officer + admin.*

**compliance_override_expiry** — A temporary waiver on a user's compliance requirement expiring (the requirement re-activates). Fires from `user_compliance_overrides.expires_at`. Routes compliance → admin. *e.g. Compliance override expires in 7 days — J. Smith → compliance officer.*

**agent_document_expiry** — A generic agent document (ID copy, cert, etc.) with an expiry reaching renewal. Fires from `user_documents.expiry_date`, honouring the doc-type's renewal window. Routes agent → compliance → admin. *e.g. ID copy expires in 30 days — J. Smith → agent + compliance officer.*

**property_showday** — A scheduled show day / open house. Fires from `property_showdays.start_date` (from 2 days ago onward). Routes agent → BM. *e.g. Show day tomorrow — 12 Beach Rd → agent + BM.*

**signature_expiry** — An e-sign signing link approaching its token expiry while still unsigned. Fires from `signature_requests.token_expires_at` (waiting/pending/viewed). Routes agent → BM. *e.g. Signature link expires in 2 days — A. Buyer → agent.*

**sales_doc_expiry** — A sent sales-document recipient link nearing token expiry. Fires from `sales_document_recipients.token_expires_at` (status sent). Routes agent → BM. *e.g. Sales document expires in 2 days — A. Buyer → agent.*

**portal_listing_expiry** — A P24/PP portal listing about to expire and drop off the market. Fires from `listing_stocks.expires_at`. Routes agent → BM. *e.g. Portal listing expires in 5 days — 12 Beach Rd → agent + BM.*

**rent_escalation** — A scheduled rent-escalation effective date approaching (bill the new amount). Fires from `rental_amount_versions.effective_from` within 30 days. Routes agent → BM. *e.g. Rent escalation effective in 7 days — 4 Marine Dr → agent + BM.*

**rent_due** — The monthly rent-due marker (1st of month) for each active rental. Computed, rolls forward nightly. Routes agent → BM. *e.g. Rent due 1 Aug — 4 Marine Dr → agent.*

**commercial_lease_expiry** — A commercial tenancy unit's lease ending (higher revenue impact). Fires from `commercial_evaluation_units.lease_end`. Routes agent → BM → admin. *e.g. Commercial lease expires in 30 days — Unit 3, Main St → agent + BM.*

**leave_cycle_end** — An employee's leave-accrual cycle ending (use-or-lose warning). Fires from `leave_entitlements.cycle_end_date`. Routes agent → BM. *e.g. Leave cycle ends in 14 days — R. Clerk → agent + BM.*

**employee_termination** — A staff member's last working day (triggers final payroll, access revocation, equipment return). Fires from `payroll_employees.termination_date`. Routes HR → payroll + admin → BM. *e.g. Last working day in 7 days — R. Clerk → HR + payroll.*

**tax_year_end** — The SA tax year end (28 Feb) that triggers IRP5/EMP501/annual recon. Computed. Routes payroll → admin + accountant. *e.g. Tax year end 28 Feb → payroll + admin + accountant.*

**uif_declaration** — The monthly UIF declaration to Dept. of Employment & Labour, due the 7th. Computed. Routes payroll → admin. *e.g. UIF declaration due 7 Oct → payroll.*

**sdl_submission** — The monthly Skills Development Levy submission, due the 7th. Computed. Routes payroll → admin. *e.g. SDL submission due 7 Oct → payroll.*

**irp5_deadline** — The deadline to issue IRP5 certificates (~31 May). Computed. Routes payroll → admin → accountant. *e.g. IRP5 issue deadline 31 May → payroll + admin.*

**employment_anniversary** — A staff work anniversary (retention milestone). Computed annually from `users.employment_date`, skipping the first year. Routes agent + BM. *e.g. 3-year anniversary in 3 days — J. Smith → BM.*

**agent_birthday** — A team member's birthday, so the BM can acknowledge it. Computed annually from `users.date_of_birth` (active users). Routes BM. *e.g. Birthday in 3 days — J. Smith → BM.*

**contact_birthday** — A contact's birthday, surfaced only when the agent opted that contact in (`birthday_reminder=true`). Computed annually from `contacts.birthday`. Routes the owning agent. *e.g. Birthday in 3 days — A. Buyer → agent.*

**rmcp_ack_expiry** — An agent's RMCP acknowledgement reaching its 12-month re-sign point. Fires from `rmcp_acknowledgements.valid_until`. Routes agent → compliance → admin. *e.g. RMCP acknowledgement expires in 14 days — J. Smith → agent + compliance officer.*

**salary_review** — The annual salary-review planning marker (1 March). Computed. Routes HR → admin. *e.g. Annual salary review 1 Mar → HR + admin.*

**filed_document_expiry** — A document in the filing register expiring (non-mandate types; EA/OA excluded to avoid duplicating mandate_expiry). Fires from `document_filing_register.expiry_date`. Routes agent → BM. *e.g. COC expires in 14 days — 12 Beach Rd → agent.*

**office_closure** — An agency-wide closure day everyone should see (public holiday, shutdown). **No emitter — deferred to the Tier-2 leave module; row is inactive.** Would route to all. *e.g. Office closed 16 Dec → everyone.*

**viewing** — An agent-booked buyer viewing appointment; the one multi-property class (a viewing trip can span several homes). Created manually. Same-day actionable; asks for feedback after. Routes agent → BM → admin. *e.g. Viewing tomorrow, 3 properties for A. Buyer → agent + BM.*

**property_evaluation** — An agent's booked visit to evaluate a property for a potential seller (longer planning cycle). Created manually. Routes agent → BM → admin. *e.g. Evaluation in 5 days — 12 Beach Rd → agent + BM.*

**listing_presentation** — An agent presenting a CMA/market analysis to win a seller's mandate. Created manually. Routes agent → BM → admin. *e.g. Listing presentation in 5 days — Seller J → agent + BM.*

**meeting** — A general meeting (team/client/external). Created manually; informational, never overdue. Routes agent → BM. *e.g. Team meeting tomorrow → agent + BM.*

**task** — A personal to-do with a deadline. Created manually. Routes the agent only. *e.g. Task due 12 Jan — follow up bank → agent.*

**other** — Catch-all for manual events that fit no other class. Informational. Routes the agent only. *e.g. Misc appointment 25 Jul → agent.*

**private** — A personal busy block: everyone in scope sees the slot is taken, but only the creator sees the detail (role-blind redaction). Created manually. *e.g. "Private" busy block Fri 14:00 → creator sees detail, others see busy.*

**leave_annual** — Approved annual leave placed on the calendar. Written on leave **approval** by `LeaveCalendarService` (`category = leave_annual`). Agents see their own via creator bypass; BM + admin see all. *e.g. Annual leave 12–16 Aug — R. Clerk → BM + admin.*

**leave_sick** — Approved sick leave on the calendar. Written on approval (`category = leave_sick`). Same visibility as annual. *e.g. Sick leave 3 Aug — R. Clerk → BM + admin.*

**manual** — The runtime **default** class for a manually created event when no explicit class is chosen. Not seeded; two legacy global rows exist. Visible to the creator. *e.g. Ad-hoc event with no class → falls to `manual`, creator only.*

---

## (iii) Orphans / anomalies

1. **`office_closure` — ORPHAN (deferred).** Seeded row exists but `is_active=false` and **no source emits it**. RecurringCalendarSource.php:14 explicitly defers it to the Tier-2 leave module. Either build the emitter (stored office-closure dates) or leave it flagged as not-yet-wired in the AT-197 overhaul.
2. **`manual` (id=2) — DUPLICATE global row.** Two identical `agency_id=NULL, event_class='manual'` rows (ids 1 & 2). Neither is seeded (the seeder never emits `manual`). One should be soft-deleted; `manual` should arguably be formalised in the seeder (it IS the live default category with 23 active events) or replaced by making manual creation always pick a real class.
3. **1 event with `category = NULL` — DATA ANOMALY.** A single pre-guard row (created 2026-04-23). `ReconcileCalendarEvents`/GET filters `whereIn('category', …)` so it is effectively invisible. Candidate for a one-time backfill to `other`/`manual`.
4. **20 DORMANT classes (emitter exists, zero live rows).** Not orphans — the code path is wired but no live data has triggered them yet: `pi_insurance_expiry, tax_clearance_expiry, deal_step_deadline, deal_registration_target, payroll_run, screening_due, training_expiry, compliance_provision_expiry, compliance_override_expiry, property_showday, sales_doc_expiry, rent_escalation, commercial_lease_expiry, leave_cycle_end, employee_termination, employment_anniversary, agent_birthday, filed_document_expiry, leave_annual, leave_sick`. These reflect data gaps on live (e.g. no `users.date_of_birth` → agent_birthday empty though contact_birthday has 3,417), **not** missing emitters — worth noting for the descriptions so Johan doesn't mistake "0 rows" for "broken".

---

## (iv) MISSING-class CANDIDATES (reverse sweep — notification-worthy flows with NO calendar class)

Grounded in code that already fires but emits no calendar event:

1. **Mailbox health failing** — `app/Services/Communications/MailboxHealthRecorder.php` (AT-181) computes Inactive/Pending/Healthy/**Failing** and raises an episode-once admin alert (live revealed `johan@` = auth_failed). No calendar class. **Candidate `mailbox_health_alert`** → admin/BM. Rationale: a dead mailbox silently stops comms capture — deserves a red calendar surface until fixed.

2. **FICA lifecycle beyond renewal** — `app/Events/Fica/{FicaSubmitted,FicaApproved,FicaRejected,FicaExpired}` all fire. Only the *upcoming expiry* (`fica_renewal_due`) has a class. **Candidates `fica_review_due`** (submitted, awaiting compliance action) and **`fica_rejected`** (must re-submit) → compliance officer. Rationale: an unactioned or rejected FICA is a live compliance gap with no time-bound surface.

3. **Commission / payout milestones** — `app/Events/Deal/{DealClosed,DealCommissionFinalised,DealMoneyLineChanged}` fire. `deal_step_deadline` + `deal_registration_target` cover deadlines but nothing marks *commission due / payout expected*. **Candidate `commission_payout_due`** → agent + BM + finance. Rationale: agents chase commission dates manually today.

4. **Viewing-pack follow-up** — `app/Services/ViewingPack/*` builds/sends buyer packs (redaction, buyer PDF) with no calendar follow-up class. **Candidate `viewing_pack_followup`** → listing agent. Rationale: a pack sent to a buyer with no logged follow-up is a lead leaking; a dated nudge closes the loop (parallels the existing missed-feedback auto-task).

5. **Mandate signed — key dates** — `app/Events/Mandate/MandateSigned` fires (drives Tracked→Stock promotion). Only expiry has a class. **Candidate `mandate_signed`** (or a sole-mandate exclusivity/cooling-off marker) → listing agent + BM. Rationale: the start of a mandate has its own actionable dates, not just the end. *(Lower priority than 1–4.)*

6. **E-sign completion** — `app/Events/Document/DocumentSigned` + `app/Events/Esign/TemplatePublished` fire. `signature_expiry` covers the *unsigned link expiring* but there is no positive "document fully signed → file / next step" calendar surface. **Candidate `signing_completed`** → sending agent. Rationale: marginal (it's an activity, not a deadline) — note but likely fold into deal/document activity rather than a calendar class.

7. **DR2 escalation-rung visibility** — `app/Services/DealV2/NotificationService::escalateOverdueStep()` fires BM (+N days) and admin (+M days) rungs off overdue steps. These are notifications only; the underlying `deal_step_deadline` class already surfaces the step, so **no new class needed** — flagged only so the overhaul doesn't double-model it.

8. **Consent / opt-out** — `app/Events/Contact/ContactConsentChanged` + `app/Events/SellerOutreach/OptOutRecorded` (AT-183 POPIA) fire. Weak calendar fit (point-in-time compliance events, not future deadlines). **No class recommended** unless a "re-consent due" cadence is introduced.

**Strong candidates to spec in AT-197:** `mailbox_health_alert`, `fica_review_due` / `fica_rejected`, `commission_payout_due`, `viewing_pack_followup`.
**Weaker / note-only:** `mandate_signed`, `signing_completed`, DR2 rungs, consent events.
