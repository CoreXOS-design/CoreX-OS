# CoreX OS — E-Signature V2 Specification

> This replaces the current multi-screen signature flow with a single
> wizard-based flow. Documents are created, filled, and signed in one session.

## Current Problems (V1)

1. Too many steps across too many screens (11 steps, 4-5 screens)
2. Fields placed on template don't carry through to signing view
3. Duplicate field placement — template setup AND signature setup
4. No section-by-section acceptance (legal risk)
5. Overlapping fields when multiple signers have fields at same position
6. No deferred signing (can't park a doc for future tenant)
7. Hardcoded parties (agent/landlord/tenant) — not flexible
8. Font size mismatch between creation view and tenant view
9. Double rendering on signature review (flattened image + HTML overlay)

## V2 Architecture

### Two Document Channels

**Channel 1: Create Document (existing, unchanged)**
Agent creates, fills everything, downloads/prints. No signing flow.

**Channel 2: Create E-Signature Document (NEW)**
Wizard-based flow. One session from creation to signing.

### The E-Signature Wizard

Each e-signature document is a FLOW (see CLAUDE_FLOWS_SPEC.md).
The flow steps are defined by the template's wizard configuration.

### Signing Chain (Unlimited, User-Defined Order)

Not hardcoded roles. Agent adds signers in any order:

```
Signer 1: Maggie Venter (Agent)     → signs now
Signer 2: Mrs Hartley (Landlord)    → signs after agent
Signer 3: Mr Hartley (Spouse)       → signs after Mrs Hartley
Signer 4: Tenant (unknown)          → sign later (deferred)
```

- 1 signer or 7 signers — system doesn't care
- Each signer only receives doc after previous completes
- Agent defines the chain during wizard step 6

Data model:
```
signing_chain: [
    { order: 1, role: "agent", name: "Maggie", email: "...", status: "completed" },
    { order: 2, role: "landlord", name: "Mrs Hartley", email: "...", status: "pending" },
    { order: 3, role: "landlord_spouse", name: "Mr Hartley", email: "...", status: "waiting" },
    { order: 4, role: "tenant", name: null, email: null, status: "deferred" }
]
```

Status values:
- `completed` — signed
- `pending` — link sent, waiting for signature
- `waiting` — in queue, will be sent after previous completes
- `deferred` — parked, no details yet, resume later

### Field Assignment

Every field has `assignedTo` property:
- `"creator"` — agent fills during document creation
- `"agent"` — agent fills during signing
- `"landlord"` / `"lessor"` — landlord fills during signing
- `"tenant"` / `"lessee"` — tenant fills during signing
- `"buyer"` / `"seller"` — for sales documents
- Any custom signer role from the signing chain

Role aliases (interchangeable):
- lessor = landlord
- lessee = tenant

### Section-by-Section Signing

Instead of "scroll through and sign at bottom":

1. Document divided into SECTIONS (defined at template level)
2. Each section gets an acceptance step
3. Signer sees: section content → "I accept this section" → initial
4. Progress through all sections
5. Final signature at the end
6. Option to reject a section with reason

Section definition in template:
```json
{
    "sections": [
        { "label": "Parties & Property", "startPage": 1, "startY": 0, "endPage": 1, "endY": 50 },
        { "label": "Terms & Conditions", "startPage": 1, "startY": 50, "endPage": 2, "endY": 80 },
        { "label": "Special Conditions", "startPage": 2, "startY": 80, "endPage": 3, "endY": 40 },
        { "label": "Signatures", "startPage": 3, "startY": 40, "endPage": 3, "endY": 100 }
    ]
}
```

### Signing UI — Left Panel Flow

```
┌──────────────────────┬──────────────────────────────────┐
│ SIGNING PANEL        │  DOCUMENT                        │
│                      │                                  │
│ YOUR FIELDS          │  Doc scrolls to show fields      │
│ ─────────────        │  as you complete them             │
│ Bank Name:   [____]  │                                  │
│ Account Nr:  [____]  │                                  │
│                      │                                  │
│ SIGN & ACCEPT        │                                  │
│ ─────────────        │                                  │
│ Section 1 of 4       │  Doc scrolls to section          │
│ "Parties & Property" │                                  │
│                      │                                  │
│ ☑ I accept this      │                                  │
│   section            │                                  │
│                      │                                  │
│ Initial: [  MV  ]    │                                  │
│                      │                                  │
│ [Accept & Next →]    │                                  │
│ [Reject Section]     │                                  │
│                      │                                  │
│ Progress: ■■■□□ 3/5  │                                  │
└──────────────────────┴──────────────────────────────────┘
```

- Left panel shows fields grouped by this signer
- Document preview on right scrolls to match current field/section
- Fields entered in panel appear live on document
- After fields, section-by-section acceptance
- Progress bar shows completion
- Final signature at end

### Deferred Signing (Sign Later)

For documents where a party isn't known yet (e.g. tenant on mandate):

1. During signing chain setup, agent marks signer as "Sign later"
2. Document is signed by known parties
3. Status: "partial" — linked to property record
4. Document appears on property dashboard as "Awaiting tenant"
5. When tenant found: agent clicks "Resume signing" from property
6. Agent enters tenant name/email/cell
7. System picks up where it left off — sends to tenant
8. Tenant signs, doc becomes complete

Property dashboard:
```
Property: 11/6877 Lot 14 Marburg Settlement
├── Marketing Permission    Agent ✅  Landlord ✅  Complete
├── Mandatory Disclosure    Agent ✅  Landlord ✅  Tenant ⏸ Deferred
└── Lease Agreement         Agent ✅  Landlord ✅  Tenant ⏸ Deferred

⏸ = Deferred — [Resume → Enter tenant details, send]
```

### Template Wizard Configuration

Each template defines its wizard steps:

```json
{
    "wizard_steps": [
        { "key": "property", "label": "Property", "type": "property_selector", "required": true },
        { "key": "landlord", "label": "Landlord", "type": "contact_selector", "party": "landlord", "required": true },
        { "key": "rental_details", "label": "Rental Details", "type": "field_group", "fields": [
            "rental_price", "deposit", "lease_start", "lease_end", "commission"
        ]},
        { "key": "fill_review", "label": "Review & Fill", "type": "field_entry", "required": true },
        { "key": "sign_send", "label": "Sign & Send", "type": "signing", "required": true }
    ],
    "signing_parties": ["agent", "landlord"],
    "default_signing_order": ["agent", "landlord"],
    "sections": [
        { "label": "Parties", "startPage": 1, "startY": 0, "endPage": 1, "endY": 40 },
        { "label": "Terms", "startPage": 1, "startY": 40, "endPage": 2, "endY": 100 }
    ]
}
```

### Pack Flow (Multi-Document)

When launched from a pack, the wizard chains documents:

1. Property + contacts entered ONCE (step 1-2)
2. Rental details entered ONCE (step 3)
3. First doc: fill agent fields → sign → next doc
4. Second doc: carry forward, fill remaining → sign → next doc
5. All docs: signed, sent, linked to property

"Next: Mandatory Disclosure →" instead of "Done"

### Ellie Integration

```
"New mandate for Hartley at Marburg"
→ Launches mandate wizard, pre-fills property + contact

"Send the lease to the new tenant Sarah Jones"
→ Finds deferred docs on the property, resumes with tenant details

"Run the rental pack for unit 14"
→ Launches full pack flow, property pre-filled
```

## Build Phases

### Phase 1: E-Signature Wizard (3-4 days)
- New route: /docuperfect/esign/create
- Wizard layout with progress bar
- Steps: template pick → property → contacts → details → fill → sign chain → agent signs
- Left panel field entry with document preview
- Basic signing chain (unlimited signers, sequential)

### Phase 2: Section-by-Section Signing (2-3 days)
- Template section definitions
- Accept/initial per section flow
- Progress through sections
- Reject section with reason

### Phase 3: External Signer Flow (2-3 days)
- New signing experience for tenants/landlords
- Left panel with their fields
- Section acceptance
- Same UI as agent signing

### Phase 4: Deferred Signing (1-2 days)
- "Sign later" option in chain
- Property document dashboard
- Resume signing flow

### Phase 5: Pack Chaining (1-2 days)
- Multi-doc flow from packs
- Carry forward property/contact/rental data
- One session for all docs

### Phase 6: Template Wizard Config (1-2 days)
- New tab on template editor: "Wizard Setup"
- Define steps, parties, sections
- Any template becomes a flow automatically

## Files Involved

### New files to create:
- `app/Http/Controllers/Docuperfect/ESignWizardController.php`
- `resources/views/docuperfect/esign/wizard.blade.php`
- `resources/views/docuperfect/esign/components/property-step.blade.php`
- `resources/views/docuperfect/esign/components/contact-step.blade.php`
- `resources/views/docuperfect/esign/components/details-step.blade.php`
- `resources/views/docuperfect/esign/components/fill-step.blade.php`
- `resources/views/docuperfect/esign/components/signing-step.blade.php`
- `resources/views/docuperfect/esign/components/agent-sign-step.blade.php`
- `database/migrations/xxxx_create_flows_table.php`
- `database/migrations/xxxx_add_wizard_config_to_templates.php`

### Existing files to modify:
- `resources/views/docuperfect/dashboard.blade.php` — add "Create E-Sign Doc" button
- `resources/views/layouts/corex-sidebar.blade.php` — add menu item
- `routes/web.php` — add wizard routes
- `app/Models/Docuperfect/Template.php` — wizard_config accessor
- `app/Http/Controllers/Docuperfect/SigningController.php` — signing chain support
- `resources/views/docuperfect/signatures/external/sign.blade.php` — new signing UI