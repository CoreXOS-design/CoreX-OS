# Amendment Review V2 — in-document tracked-changes review + initial-only re-sign

> AT-302. Johan's ruling (2026-07-19): "the current Amendment Review screen (bare
> 'CONCERN FLAGGED — comm should be 5%' + 3 buttons) means nothing without the document."
> Single source of truth for the flag → agent-amend → re-send → recipient-initials → complete loop.
> Extends `claude_esignature_v2_spec.md` §17–§19 and `esign-ceremony-v3.md` §5.

## 1. Why

A recipient flags a clause during signing (AT-291 ⑤ freezes the ceremony; AT-299 notifies the agent and
surfaces the doc as FLAGGED). Today the agent lands on a bare concern + 3 buttons with no document
context — they cannot see the clause, cannot edit it, and the recipient must full-re-sign on any change.
V2 makes review happen **in the document**, tracked-changes style, and lets the ceremony **continue on
an initial**, not a full re-sign.

## 2. Actors & entry

- Agent opens **Review Flag** from the FLAGGED list (AT-300: `docuperfect.amendments.review/{amendment}`).
- Governing records already exist: `DocumentAmendment` (`TYPE_FLAG_RAISED`, `flag_clause_ref`,
  `flag_reason` = recipient's note, `original_text`, `new_text` = recipient's suggestion), template
  `STATUS_AMENDMENT_REVIEW`, `web_template_data.clause_flags[party_role][]`.

## 3. Review screen (agent) — renders THE DOCUMENT

`AmendmentController::review` renders the **full document** (the same `merged_html` render pipeline the
ceremony uses — `SignatureSurfaceNormalizer → LetterheadRefresher → InsertableBlockRenderer →
RoleBlockExpansionService`), read-only except the flagged clause, with:

1. The flagged clause **highlighted in place** (locate by `flag_clause_ref` = the `.corex-clause`
   `.corex-clause-number` text; wrap it `.amendment-flagged`).
2. The recipient's **note attached at the clause** (an inline callout: signer name + `flag_reason`).
3. An **amend control** on that clause: the agent proposes amended text. Rendered tracked-changes:
   the **original struck through** (`<del class="amendment-original">`) with a clause reference, and the
   **replacement directly below** (`<ins class="amendment-new">`). The agent MAY edit the actual clause
   text inline (contenteditable on the clause body) — this is the point of review.

Every edit is **audit-logged**: `SignatureAuditLog` action `amendment_text_edited` with metadata
`{amendment_id, clause_ref, original, new, actor_id, at}`. The amendment's `new_text` is updated to the
agent's final text; `original_text` is preserved.

## 4. Agent actions

- **Apply amendment** — persist the agent's final `new_text`; write the tracked-change (strikeout+ins)
  into `merged_html` at the clause; bump `document_version`; snapshot (see §6); mark the amendment
  `STATUS_ACCEPTED`; enter the **initialing cascade** (§5) via `requeueAllPartiesForInitialing`.
- **Reject change** — keep the original clause; amendment `STATUS_REJECTED`; note back to the recipient
  (existing `notifyRecipientOfResolution('rejected_change')`); template returns to `STATUS_SIGNING` and
  the flagged party resumes (existing `rejectAmendmentChange`).
- **Reject document** — terminal (existing `rejectAmendmentDocument`).

## 5. Send-back — initial-only continuation (NOT a full re-sign)

On **Apply**, the ceremony re-opens in **initialing mode** (existing `STATUS_AMENDMENT_INITIALING` +
`showInitialingView`, extended):

1. The recipient's (and every already-signed party's) ceremony renders the amended clause **highlighted**
   — `<del>` original + `<ins>` new — with an **INITIAL box next to the change**.
2. Each party **initials the amendment only** — no full re-sign. Their prior signature stays valid
   (`SectionAcceptance` per party per amendment; existing acceptance machinery).
3. When all required parties have initialed the amendment (`checkInitialingCascadeComplete` /
   `checkAmendmentResolution`), the template leaves `AMENDMENT_INITIALING` and the **signing process
   continues** from where it was (the freeze lifts; AT-291 ⑤ gate + AT-300 derive off the amendment
   status, so both release together).
4. A party who had **not yet signed** simply continues normal signing (the amended clause is now the
   document); a party who **had signed** is asked to initial the amendment only.

## 6. Versioning + audit (mandatory)

- Each applied amendment bumps `SignatureTemplate.document_version` and writes a **snapshot** of the
  pre-amendment `merged_html` (append to `web_template_data.amendment_snapshots[]` =
  `{version, at, amendment_id, merged_html_before}`) so the full change history is auditable.
- `SignatureAuditLog` records: `clause_flagged_by_recipient` (exists), `amendment_text_edited` (new),
  `amendment_applied` / `amendment_change_rejected` / `amendment_document_rejected`,
  `amendment_initialed` per party. Each carries actor + timestamp + original/new where relevant.
- The initialed amendment (strikeout+ins + each party's initial + timestamp) is baked into the final
  signed PDF (the audit certificate lists the amendment + who initialed it).

## 7. Data / files

- `app/Http/Controllers/Docuperfect/AmendmentController.php` — `review` renders the document (not the bare
  screen); new `applyAmendment` (agent's edited text + tracked-change write + snapshot + cascade).
- `resources/views/docuperfect/amendments/review.blade.php` — full-document render + flagged-clause
  highlight + note callout + inline amend/edit control + tracked-changes preview + 3 actions.
- `app/Services/Docuperfect/AmendmentApplicationService.php` (new) — locate clause by ref, write
  `<del>/<ins>` into `merged_html`, snapshot, version bump, audit.
- `resources/views/docuperfect/signatures/external/sign.blade.php` — initialing mode renders the amended
  clause highlighted + an INITIAL box at the change; initial-only completion.
- Reuse: `DocumentAmendment`, `SectionAcceptance`, `SignatureAuditLog`, `requeueAllPartiesForInitialing`,
  `checkInitialingCascadeComplete`, `showInitialingView`, AT-291 ⑤ freeze, AT-299 notify.

## 8. Acceptance (on-site proof of the FULL loop, deployed qa1)

flag (recipient) → agent opens Review Flag → **sees the document** with the clause highlighted + note →
edits/proposes amended text (tracked-changes, audit-logged) → Apply → re-send → recipient ceremony shows
the amendment highlighted with an INITIAL box → recipient initials → **signing continues + completes**
(no full re-sign); already-signed parties initial the amendment only; every step versioned + audit-logged.
Prove by driving it on the deployed composed page (existing flagged doc + a fresh send).

## 9. Deliberately phased

Given the size, land in this order (each its own commit, READY-FOR-QA1): (1) review screen renders the
document + highlighted clause + note (the "means nothing without the document" fix Johan hit first);
(2) in-doc amend/edit + tracked-changes + audit + Apply; (3) initial-only send-back + ceremony initial
box + continue. Each verified on the deployed composed page before the next.
