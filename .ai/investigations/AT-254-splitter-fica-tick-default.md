# PDF Splitter — "Start wet-ink FICA verification" tick: default + downstream

_INVESTIGATION only, no code changed. 2026-07-14. Requested by Johan (urgent). Reporter: m3._
_Johan decides the wanted default AFTER this report._

## TL;DR
The tick has **no fixed default and no agency setting**. Its starting state is a **hardcoded
smart heuristic**: it starts **TICKED when the pack contains at least one FICA/ID/POR page
assigned to a contact who is not already FICA-complete**, and **UNTICKED (and disabled)** when no
such page is assigned. Once the agent clicks it, their choice overrides the heuristic. Ticking it
starts **one wet-ink FICA verification per distinct assigned contact** on submit.

---

## 1. Exact component + files/lines

**UI — `resources/views/tools/pdf_splitter_review.blade.php`**
- **Visible checkbox card** — lines **215–235** (`@if(!empty($canFica))` … card `data-tour="spr-fica"`).
  - The `<input type="checkbox">` itself: **line 219** — `:checked="ficaChecked"`, `@change="ficaOverride = $event.target.checked"`, `:disabled="!ficaHasTargets"`.
  - Label text: **line 222** — "Start wet-ink FICA verification(s) from this pack".
- **The value that actually posts** — line **256**: `<input type="hidden" name="trigger_fica" :value="ficaChecked ? '1' : '0'">` (rendered only when `$canFica`).
- **Alpine state driving it**:
  - `ficaOverride: null` — initial state, **line 396** (null = "agent hasn't touched it").
  - `ficaTargetIds()` — **lines 498–504** (contacts on any page whose type has `fica_slot != none`).
  - `get ficaHasTargets` — **line 505** (property set AND ≥1 target id).
  - `get ficaTargetCount` — **line 506**.
  - `get ficaNeedsVerify` — **lines 507–509** (any target contact with `fica_status !== 'complete'`).
  - `get ficaChecked` — **lines 511–514** (the default logic — see §2).

**Controller — `app/Http/Controllers/Tools/PdfSplitterController.php`**
- `$canFica = (bool) auth()->user()?->hasPermission('access_compliance')` — **line 320**; passed to the view at **line 346**.
- On submit (`link()`): `if ($request->boolean('trigger_fica'))` → `kickoffMultiFica(...)` — **lines 603–608**.
- `kickoffMultiFica(...)` — **lines 834–898** (the actual FICA kickoff).
- Dedupe helper `existingActiveFica()` — **lines 909–916**.
- Slot map `FICA_SLOT_TO_DOC_TYPE` (fica_slot → fica_form/id_copy/proof_of_address) — used at **line 849**.

---

## 2. Default state (ticked or unticked) + where it's set

**Not a constant — computed.** `ficaOverride` starts `null` (line 396). While `null` (untouched),
`ficaChecked` (lines 511–514) resolves to:

```js
get ficaChecked() {
    if (!this.ficaHasTargets) return false;                 // no FICA/ID/POR page → OFF (+ disabled)
    return this.ficaOverride === null ? this.ficaNeedsVerify // untouched → smart default
                                      : this.ficaOverride;    // agent clicked → their choice wins
}
```

So the **default (agent hasn't clicked)** is:
- **TICKED** ⟺ `ficaHasTargets` (≥1 FICA/ID/POR page assigned to a contact) **AND** `ficaNeedsVerify`
  (at least one of those target contacts has `fica_status !== 'complete'`).
- **UNTICKED** if either: no FICA/ID/POR page is assigned to any contact (then it's also **disabled**
  with the hint "Assign a FICA / ID / Proof-of-Residence page to a contact to enable this"), **or**
  every target contact is already FICA-complete.

**Where set:** the `ficaChecked` getter (Blade lines 511–514) + `ficaOverride: null` (line 396).
It is dynamic per pack — it re-evaluates live as the agent assigns pages to contacts.

---

## 3. What ticking actually triggers downstream

On submit, `trigger_fica=1` → `kickoffMultiFica()` (lines 834–898):
1. **Re-gates** on `access_compliance` (lines 836–839) and a valid agency (840–843) — no-op with a
   user note otherwise.
2. Walks the page groups; keeps only pages whose doc-type `fica_slot != none` (agency routing), maps
   the slot to a FICA doc type (`fica_form` / `id_copy` / `proof_of_address`), and buckets them
   **by each assigned contact** (lines 845–856).
3. For **each distinct contact**:
   - **Dedupe** — if the contact already has an in-flight `FicaSubmission` (`existingActiveFica`:
     status ∈ draft/submitted/under_review/agent_approved/corrections_requested) it **reuses** that
     one; no duplicate is created (lines 869–872).
   - Else **creates a new wet-ink verification**:
     `FicaWetInkService::create($contact, $agencyId, ['source' => 'pdf_splitter'])`, attaches the
     contact's assigned pages to their slots via `addStoredDocument(...)`, then `fireSubmitted(...)`
     (emits the submitted event → the normal FICA notification/queue chain) — lines 873–884.
4. Returns one banner row per contact (name, link to `compliance.fica.show`, reused flag, slot count).

Net effect: **one wet-ink FICA process per distinct assigned party**, pre-filled with that party's
own ID/POR/FICA-form pages; each party FICAs individually; already-open verifications are reused.

---

## 4. Hardcoded or an agency setting?

- **The tick's default = HARDCODED heuristic.** `ficaNeedsVerify` (Blade) is the only thing deciding
  the starting state. **There is no agency setting** for "should the FICA tick start on/off", and no
  Setup-Wizard control for it.
- **Whether the control appears at all = a PERMISSION**, not a setting: `access_compliance`
  (`$canFica`, controller line 320 + re-gated in `kickoffMultiFica`).
- **What IS agency-configurable** is the *routing that feeds the heuristic*: each document type's
  `fica_slot` (catalogue default + per-agency override via
  `AgencyComplianceDocTypeService::routingMapBySlugFor`). Change which types carry a `fica_slot` and
  you change which pages count as FICA targets — but not the on/off default logic itself.

**Bottom line for the decision:** if Johan wants a fixed default (always-on, or always-off, or a
per-agency toggle), that is a *new* behaviour — today it's a hardcoded smart-default in the Blade
`ficaChecked` getter, gated only by the `access_compliance` permission. No setting exists to flip.
