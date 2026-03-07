# CoreX OS — Flows & Wizard Architecture

> Every task is a flow. Every document is a flow. Templates define flows.
> Ellie launches them. Users never think about screens — they think about tasks.

## The Principle

Flows = Document Types. Agent clicks "Offer to Purchase" → wizard launches.
Agent clicks "Marketing Permission" → wizard launches. The template defines
what the wizard asks, who signs, and in what order.

## Flow Dashboard

```
┌─────────────────────────────────────────────┐
│  Start a Flow                                │
│                                             │
│  RENTAL                                     │
│  📄 Marketing Permission (Mandate)          │
│  📄 Mandatory Disclosure                    │
│  📄 Lease Agreement                         │
│  📄 Addendum: Furniture                     │
│  📄 Addendum: Pets                          │
│  📦 Full Rental Pack (all above)            │
│                                             │
│  SALES                                      │
│  📄 Sole Mandate (EATS)                     │
│  📄 Open Mandate (OATS)                     │
│  📄 Offer to Purchase (OTP)                 │
│  📄 Deed of Sale                            │
│  📄 Addendum: Fixtures                      │
│                                             │
│  COMPLIANCE                                 │
│  📄 FICA Verification                       │
│  📄 Property Condition Report               │
│                                             │
│  OTHER                                      │
│  📝 Seller Presentation                     │
│  📋 Daily Activity                          │
│  📋 New Deal                                │
│  📋 Filing Register Entry                   │
└─────────────────────────────────────────────┘
```

## Every Flow is a Wizard

Agent clicks a flow → wizard launches with steps defined by the template:

### Example: OTP Flow
```
Step 1: Property    → select or add
Step 2: Seller      → select or add contact
Step 3: Buyer       → select or add contact
Step 4: Deal Terms  → price, deposit, conditions, dates
Step 5: Fill & Review → agent fields pre-filled, remaining shown
Step 6: Sign & Send → signing chain, agent signs, sends to parties
```

### Example: Marketing Permission Flow
```
Step 1: Property    → select or add
Step 2: Landlord    → select or add contact
Step 3: Rental Info → price, deposit, commission, dates
Step 4: Fill & Review → pre-filled from above steps
Step 5: Sign & Send → agent signs, sends to landlord
```

### Example: Presentation Flow
```
Step 1: Property    → select or add
Step 2: Seller      → select or add contact
Step 3: P24 Links   → paste comparable URLs
Step 4: Upload Docs → CMA, sales reports
Step 5: Articles    → add market articles
Step 6: Review      → preview presentation
Step 7: Compile     → generate PDF
```

## Template Defines the Flow

Each template has wizard metadata:

```json
{
    "flow_type": "rental",
    "wizard_steps": [
        { "key": "property", "label": "Property", "type": "property_selector", "required": true },
        { "key": "landlord", "label": "Landlord", "type": "contact_selector", "party": "landlord", "required": true },
        { "key": "rental_details", "label": "Rental Details", "type": "field_group", "fields": [
            "rental_price", "deposit", "lease_start", "lease_end", "commission"
        ]},
        { "key": "fill_review", "label": "Review & Fill", "type": "field_entry" },
        { "key": "sign_send", "label": "Sign & Send", "type": "signing" }
    ],
    "signing_parties": ["agent", "landlord"],
    "default_signing_order": ["agent", "landlord"]
}
```

New template = new flow. Admin sets up wizard steps, parties, order.
No developer needed. Flow appears on dashboard automatically.

## Pack Flows (Multi-Document)

Agent clicks "Full Rental Pack" → runs multiple wizards chained:

1. Property + contacts entered ONCE
2. Rental details entered ONCE
3. Doc 1: fill agent fields → sign → next
4. Doc 2: carry forward → fill → sign → next
5. All docs signed, sent, linked to property

Data carries forward. Enter once, appears everywhere.

## Flow UI Pattern

```
┌─────────────────────────────────────────────────────────┐
│ Flow: Marketing Permission                  Step 3 of 5 │
│ ■■■□□                                                   │
├──────────────────────┬──────────────────────────────────┤
│                      │                                  │
│   STEP PANEL         │   PREVIEW / CONTEXT              │
│   (form inputs)      │   (document preview,             │
│                      │    property card, map)            │
│                      │                                  │
├──────────────────────┴──────────────────────────────────┤
│ [← Back]                                    [Next →]    │
└─────────────────────────────────────────────────────────┘
```

- Progress bar at top
- Left panel: current step form
- Right panel: document preview / contextual info
- Data persists across steps
- Can pause and resume later (saved as draft)

## Signing Chain

Unlimited signers, user-defined order:
```
Signer 1: Agent           → signs now
Signer 2: Landlord        → sent after agent completes
Signer 3: Landlord spouse → sent after landlord completes
Signer 4: Tenant          → sign later (deferred)
```

Deferred = parked on property record. Resume when tenant found.

## Section-by-Section Signing

Documents divided into sections (defined at template level).
Each section: read → accept → initial. Then final signature.

```
┌──────────────────────┬──────────────────────────────────┐
│ SIGNING PANEL        │  DOCUMENT                        │
│                      │                                  │
│ YOUR FIELDS          │  Doc scrolls to current section  │
│ Bank Name:   [____]  │                                  │
│ Account Nr:  [____]  │                                  │
│                      │                                  │
│ Section 1 of 4       │                                  │
│ "Parties & Property" │                                  │
│ ☑ I accept           │                                  │
│ Initial: [  MV  ]    │                                  │
│ [Accept & Next →]    │                                  │
│                      │                                  │
│ Progress: ■■■□□ 3/5  │                                  │
└──────────────────────┴──────────────────────────────────┘
```

## Deferred Signing (Sign Later)

For documents where a party isn't known yet (e.g. tenant on mandate):

1. Agent marks signer as "Sign later" during setup
2. Known parties sign the document
3. Document parked on property record with status "partial"
4. When tenant found: agent clicks "Resume signing"
5. Enters tenant details, system sends to tenant
6. Tenant signs, document completes

Property dashboard:
```
Property: 11/6877 Lot 14 Marburg Settlement
├── Marketing Permission    Agent ✅  Landlord ✅  Complete
├── Mandatory Disclosure    Agent ✅  Landlord ✅  Tenant ⏸ Deferred
└── Lease Agreement         Agent ✅  Landlord ✅  Tenant ⏸ Deferred

[Resume Signing → Enter tenant details]
```

## Ellie as Flow Launcher

| User says | Ellie does |
|-----------|-----------|
| "New mandate" | Launches mandate wizard |
| "Create OTP for Marine Drive" | Launches OTP, pre-fills property |
| "Run rental pack for Hartley" | Launches pack flow, pre-fills contact |
| "Send lease to tenant Sarah" | Resumes deferred signing |
| "Change deal 1578 to granted" | Updates deal directly |

## Flow State Persistence

```sql
CREATE TABLE flows (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    template_id BIGINT NULL,
    user_id BIGINT NOT NULL,
    property_id BIGINT NULL,
    contact_id BIGINT NULL,
    current_step INT DEFAULT 1,
    step_data JSON,
    status ENUM('active','completed','abandoned','draft') DEFAULT 'active',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    completed_at TIMESTAMP NULL
);
```

Dashboard shows: "Continue: Marketing Permission for Hartley (step 3/5)"

## Reusable Components

Built once, used across all flows:
- PropertySelector: search + add new inline
- ContactSelector: search + add new inline
- DocumentPreview: pages with field highlights
- FieldEntryPanel: left panel grouped fields
- SigningChain: drag-to-reorder signer list
- SectionSigner: accept/initial/sign per section
- ProgressBar: click-to-jump step indicator
- FlowLayout: standard 2-panel layout

## Build Priority

1. Rental Mandate Wizard (this week)
2. Signing chain with unlimited signers
3. Section-by-section signing
4. External signer new UI
5. Deferred signing + property linking
6. Pack chaining
7. Template wizard config UI
8. Sales document flows
9. Presentation flow
10. Ellie flow launcher