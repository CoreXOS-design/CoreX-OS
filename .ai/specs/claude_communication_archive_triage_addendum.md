# CoreX — Communication Archive: Pending Triage & Classification Addendum (SSOT)

**File:** `.ai/specs/claude_communication_archive_triage_addendum.md`
**Status:** Draft for build. Extends `claude_communication_archive_spec.md` (§7.5 pending buffer).
**Owner:** Johan (domain/BA) · Build: Johan + Andre
**One-line purpose:** Decide what unknown-contact messages are worth keeping, let staff triage them, and turn every discard into an attributable, auditable decision — so an agent can't bury a real-estate contact by calling it personal.

---

## 1. Where this sits

The archive already gates ingestion to known contacts (Gate 1, `ContactIdentifierResolver`). Unknown-contact inbound currently lands wholesale in `communication_pending`. This addendum adds:

- **Gate 2 — relevance classification:** only messages that *read as real-estate-related* reach the pending screen; obvious-personal is discarded, never surfaced (POPIA minimisation).
- **A triage screen:** staff resolve pending items — add the contact (archive) or flag not-real-estate (discard, attributably).
- **Multi-agent contradiction detection:** because contacts are not exclusive to one agent, a discard by one agent is per-agent only, and any later contradiction (another agent, or the AI) raises a BM alert. This is the private-deal-loop tightening.

---

## 2. The locked rules

1. **Gate 1 (exists):** known contact → archive. Unknown → Gate 2.
2. **Gate 2 (new):** classify the unknown-contact message. Personal → discard silently (not retained, not surfaced). Real-estate-related (or uncertain) → pending screen.
3. **Flags are per-agent, per-identifier — never global.** A contact chats to multiple agents. One agent's "not real estate" suppresses it *for that agent only*; it does not bind any other agent or block the same identifier from another agent's capture.
4. **Every discard is attributable:** identifier + flagging user + timestamp recorded. Content is discarded; the *decision* is retained. A wrongly-discarded business message is a disciplinable, named act.
5. **Contradiction = signal.** Same identifier classified real-estate by anyone (another agent, or the AI) after an agent flagged it personal → BM alert for review.

---

## 3. Gate 2 — relevance classification

Two-stage, cheap-first:

- **Stage A — keyword pre-filter (free, no call):** an obvious-personal heuristic discards the clear cases without spending an AI call (e.g. no property/price/viewing/mandate/suburb signal AND short personal-chat shape). Conservative — only discards the *obvious*; anything ambiguous passes to Stage B.
- **Stage B — AI classifier (Ellie):** for everything not cleared by Stage A, an Ellie classify-pass returns `{ is_real_estate: bool, confidence: float }`. Uses existing Ellie/embedding infrastructure — a contained call, not new infra.
- **Decision:** `is_real_estate && confidence >= threshold` → pending screen, stamped with `ai_classification` + `ai_confidence`. Below threshold / not real-estate → discard (but see §6: if an agent later interacts, the AI verdict is the neutral third opinion).
- **Threshold is an agency-configurable setting** (per CoreX "every threshold is a setting" rule), with a sensible default.

The AI verdict is **stored on the pending/discard record even when it agrees to discard**, because it is the neutral reference for later contradiction detection.

---

## 4. Data model

### 4.1 Extend `communication_pending`
Add: `classification` enum(`real_estate`,`personal`,`uncertain`), `ai_is_real_estate` bool null, `ai_confidence` decimal null, `classified_at`, `classifier` enum(`keyword`,`ai`,`manual`). Existing grace-window fields (§7.5) stay.

### 4.2 `communication_flags` (NEW — the per-agent decision register)
The attributable record of every discard / classification decision. One row per (identifier, user) decision.
| Column | Notes |
|---|---|
| id | |
| agency_id | BelongsToAgency |
| identifier | normalised number/email (via `normalizePhone`/lowercased email) |
| identifier_name | name if one was present on the message; else null |
| user_id | the agent who flagged (attribution) |
| flag | enum(`not_real_estate`,`real_estate`) |
| ai_is_real_estate / ai_confidence | the AI verdict at flag time (neutral reference) |
| message_external_id | the message that triggered the flag (reference only; body not retained) |
| flagged_at | audited timestamp |
| contradicted_at / contradicted_by_user_id | set when a later opposing classification appears |
| review_status | enum(`open`,`reviewed`,`actioned`) for BM workflow |
| softDeletes |

**No message body, subject, or headings are stored here.** Only the identifier, optional name, attribution, AI verdict, and timestamps — exactly the BM register fields (§5). This is the POPIA-correct shape: retain the decision and its accountability, not the discarded personal content.

### 4.3 `communication_flag_alerts` (NEW — BM contradiction queue)
`id, agency_id, identifier, original_flag_id, contradicting_flag_id (nullable — null when the contradiction source is the AI), alert_type enum(agent_vs_agent, agent_vs_ai), created_at, status enum(open,reviewed,dismissed,actioned), reviewed_by, reviewed_at`. Routed to **both** the flagging agent's branch BM **and** agency compliance (Elize).

---

## 5. The BM register (triage audit view)

Spreadsheet-style, read-only, gated under an appropriate compliance/communication permission (BM + admin + compliance). Columns: row #, identifier captured, name (if present), flagging agent, flag type, AI verdict + confidence, flagged_at, contradiction status. **No message content anywhere on this screen.** This is the breach-enforcement evidence: who discarded what, when, and whether the machine or another agent disagreed.

---

## 6. The triage screen (staff-facing)

Lists this agent's pending items (classified real-estate/uncertain, within grace window). Per item, two actions:

- **Positive → "Add contact":** opens the **existing MIC prospect-now add-contact modal** (reuse, do not rebuild). On create → the pending message *and any others from that identifier* auto-link into the archive (the retroactive-attach the pruner already does, triggered manually here). Writes a `communication_flags` row `flag=real_estate`.
- **Negative → "Not real estate related":** discards the message; writes a `communication_flags` row `flag=not_real_estate` (per-agent). **If the stored AI verdict was `is_real_estate=true` at/above threshold → immediately raise an `agent_vs_ai` alert** (§4.3). The agent's call stands — they are not blocked mid-work — but the disagreement is logged and surfaced to the BM. Discard is reversible via the register; blocking the agent is not worth it.

---

## 7. Contradiction detection (the private-deal-loop tightening)

On any new `real_estate` classification for an identifier that has an existing `not_real_estate` flag from a *different* user:
- Stamp `contradicted_at` / `contradicted_by_user_id` on the original flag.
- Raise an `agent_vs_agent` alert to the original flagger's branch BM **and** compliance.
- The archive proceeds (Agent 2's legitimate classification wins — messages archive); the alert is about Agent 1's earlier judgement, for investigation.

Two independent breach triggers result:
1. **agent_vs_agent** — one agent buried what another worked legitimately.
2. **agent_vs_ai** — an agent's "personal" call contradicts the AI's high-confidence "real-estate" verdict, even with no second agent involved.

Both make private-deal evasion expensive: discarding a real contact leaves a named, timestamped, machine-witnessed trail.

---

## 8. Build order

- **Phase A — Flags + register (no AI yet):** `communication_flags` + `communication_flag_alerts`, the triage screen (reusing the MIC add-contact modal), the BM register, per-agent flag logic, agent_vs_agent contradiction + alert. Operates on `communication_pending` as it fills today. *(~5–7 prompts)*
- **Phase B — Classification (Gate 2):** keyword pre-filter + Ellie classify-pass, threshold setting, `communication_pending` classification columns, agent_vs_ai alert. Slots in front of the screen to reduce noise and add the neutral third opinion. *(~4–6 prompts)*

Phase A is the part you described and is independently shippable; Phase B is the classifier that needs Ellie wiring. They're separable — A can ship first and triage everything in pending; B reduces what reaches A and adds the AI contradiction trigger.

---

## 9. Done-criteria (every build prompt)

`php -l` · `php artisan migrate` + `schema:dump` · `view:clear` · documented test command, full-suite failures stay at the 220 baseline (no new) · explicit short FK names · BelongsToAgency + SoftDeletes on new models · permissions added + granted · nav present (triage screen + BM register) · feature tests: per-agent flag isolation (Agent 1's discard doesn't bind Agent 2), agent_vs_agent alert fires on contradiction, agent_vs_ai alert fires when discard contradicts the stored AI verdict, register shows no message content. Report results, files, line counts. Update Jira.
