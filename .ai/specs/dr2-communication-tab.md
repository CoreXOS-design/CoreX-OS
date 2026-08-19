# DR2 — Deal Communication Tab (Investigation → Draft Spec)

> **Status:** INVESTIGATION COMPLETE, NOT SIGNED OFF. Nothing built. This document is the read-only
> investigation Johan asked for, written up as a spec draft so it can be reviewed and scoped before any
> code is written. Do not start Phase 1 without Johan's explicit go-ahead on the open questions in §6.
> **Ticket:** none yet assigned. Sibling/prerequisite: AT-231 (`.ai/specs/at231-inbound-attorney-comms-filing.md`).
> **Author:** investigation by cc5 lane, 2026-07-28/29, at Johan's request.
> **Pillars:** Deal (primary — DR2 twin), Contact, Property. Reads the existing comms archive; the new
> surface is read-only (plus the attach/remove control described in §5, which is itself unbuilt today).

---

## 1. What this does and why (business requirement)

Johan wants a new **Communication** tab on the DR2 deal right panel, alongside Deal Structure / Supplier
Work Orders / Documents / Email Parties / Proforma Invoice / Comments, showing the communications already
being ingested from a deal's linked parties — suppliers, bond originators, transferring attorneys, buyers,
sellers — grouped by party.

Johan also described the surrounding design he expects this to sit inside:

- Ingested emails (e.g. from transferring attorneys) that cannot be auto-linked to a deal sit in a
  **suspense** screen (an unlinked/unmatched queue).
- When an agent manually **links** an email to a deal, the system should remember that email's
  references/parties (addresses/subjects) to **auto-link** future emails from them going forward.
- The tab shows linked emails/chats for the deal, grouped by party (attorney / bond originator /
  supplier / seller / buyer).
- Chats (WhatsApp) to/from sellers and buyers are also ingested/linked.
- Agents can **attach** or **remove** communications (emails/chats) to/from a deal.

This document reports what already exists in the codebase against that design, what's real vs.
provisional in the data, and a graded build order. It does not scope a build.

---

## 2. Investigation baseline (what exists — the extend-don't-greenfield map)

| Piece | State | file:line anchor |
|---|---|---|
| Comms archive (`communications` table, channel-agnostic email/WhatsApp) | Built | `app/Models/Communications/Communication.php` |
| Polymorphic link table (`communication_links`, links a comm to Contact/DealV2/Property/provider) | Built | `app/Models/Communications/CommunicationLink.php` |
| Default ingestion → links every comm to a `Contact` only, never a deal | Built, is the dominant path (803/841 links) | `EmailArchiveIngestor.php:71-90`, `WaArchiveIngestor.php:299-304` |
| Attorney-correspondence suspense queue (park → match → file → learn) — **email route** | **Built, migrated, permission-gated, nav-wired.** Zero real data on QA1 — never exercised | `CorrespondenceFilingService.php`, `CorrespondenceMatchService.php`, `CommunicationFilingSuspense` model + `communication_filing_suspense` table (migration `2026_07_27_000002`, batch 190, HAS run), `CommsSuspenseController.php`, `resources/views/corex/communications/comms-suspense.blade.php`, routes `routes/web.php:3052-3061`, perms `deal_comms_suspense.view`/`.resolve` (`config/corex-permissions.php:459-460`), nav badges in Deals + Comms sidebars (`resources/views/layouts/corex-sidebar.blade.php:814-818,936-954`) |
| Learn-a-reference on manual verify, silent auto-file next time | **Built, attorney-email route only** | `CorrespondenceFilingService::learn()` (`:316-341`), `CommunicationLearnedRef` model/table, `CorrespondenceMatchService::matchLearned()` (`:135-170`) |
| WhatsApp → provider-contact resolution + ad-hoc manual WA→deal link (AT-231 P4) | **Speced, never built.** No `wa_allowed`/`wa_number` columns anywhere | AT-231 spec §4.1–4.3 |
| Generic "attach/remove any comm to/from a deal" UI (any channel, any party) | **Does not exist anywhere in the app** | see §5 below |
| Outbound "Send documents to a party" (Email Parties tab, AT-228) → writes one `DealV2`-typed link, always provisional, no sibling party link | Built, narrow | `OutboundProvisionalLogger::logDistribution()` (`:118-183`) |
| Party roster (seller/buyer/transfer_attorney/bond_originator) resolution, reusable | Built — this is what the new tab should reuse for grouping | `Dr2DistributionComposer::parties()` (`app/Services/DealV2/Dr2DistributionComposer.php:37-65,68-77,135-159`) |
| Precedent for deal-scoped comms query (Pipeline Dashboard activity lane) | Built, deal-scoped only, no per-party grouping | `App\Services\Deal\Pipeline\CommunicationEventSource` |

---

## 3. Real-data findings

**Deal 183** (agency 1, `deal_v2_id=20`, property "Unit 22, Kubu Bali", Shelly Beach) — real, unseeded
deal with recorded seller, buyer, attorney and bond-originator parties.

- Comms linked directly to its `DealV2` twin: **0**.
- Comms linked to its seller/buyer `Contact` ids: **3**, all against the seller (Martin Rossouw,
  contact 12802) — 1 outbound, 1 inbound, 1 outbound reply, all real email subjects/timestamps.
- Comms linked to its attorney or bond-originator provider-contacts: **0** — consistent with the
  attorney-comms path never having fired in this database (see §2).
- **The seller (contact 12802) is also a party on deal 181, same property, same role.** A tab built as
  "join the deal's party contact_ids to `communication_links`" would show deal 181's correspondence on
  deal 183's tab and vice versa, with nothing in the data to tell them apart. Three contacts in this
  database are party to more than one deal — a real, if currently small, collision class.

**Deal 156** — the one deal with real `DealV2`-linked comms (8, all outbound document-send rows, all
still `provisional_at` non-null, none carrying a sibling Contact link that would identify which party
they went to).

**Buyer/seller coverage, broader check:** of 15 distinct buyer/seller contact ids across sampled deals,
7 have at least one linked communication (mostly WhatsApp, some email) — e.g. contact 10298 is buyer on
deals 155 and 163 **and** seller on deal 168, the same cross-deal/cross-role ambiguity as above.

**Conclusion:** unlike attorneys/bond originators (nothing ingested at all today), buyer/seller
email+WhatsApp genuinely is flowing into the archive and correctly linked to the right `Contact`. The
gap for buyer/seller is that the link is to the *Contact*, not the *deal* — so a contact who is party to
more than one deal (or more than one role) cannot be attributed to a specific deal from data alone.

---

## 4. Suspense queue + auto-link learning — detailed status

### 4.1 Suspense queue (AT-231 P1–P3, email/attorney route)

Fully built and wired: table exists and is migrated, model, service, controller, view, routes,
permissions, sidebar nav with a live pending-count badge. It has **zero rows** on QA1 because no inbound
email from a known attorney-firm sender has ever actually been ingested/parked here — this is an
unexercised feature, not a missing one. See AT-231 spec for full mechanism (ref-stamping → park →
first-verify-then-trust → learned refs).

### 4.2 Auto-link-by-reference learning (AT-231 P2a/P3)

Real and working, for the attorney-email route only. On manual verify
(`CommsSuspenseController::verify()` → `CorrespondenceFilingService::verify()` →
`learn()`), a `CommunicationLearnedRef` row is written (signal type/value → deal_id, verified). The next
ingestion run checks `CorrespondenceMatchService::matchLearned()` and auto-files silently on a hit. This
is wired only into the known-attorney branch of `EmailArchiveIngestor.php:73-90` — the default
Contact-matched path (which is what buyer/seller/supplier comms go through) never consults it.

### 4.3 WhatsApp route + ad-hoc manual link (AT-231 P4)

Speced (`at231-inbound-attorney-comms-filing.md` §4.1–4.3) but never built. §4.3 in particular describes
exactly a generic "agent manually attaches a WA exchange to a deal" affordance — the closest existing
design to Johan's attach/remove ask, but it doesn't exist in code.

---

## 5. Attach/remove capability — what exists today

No generic "attach this communication to a deal" or "unlink" control exists anywhere in the app, for any
entity. Closest precedents, nearest to furthest:

1. `CommsSuspenseController::verify()`/`reassign()`/`dismiss()` — effectively attach / detach+reattach /
   remove, but **only operate on rows already sitting in the attorney suspense queue**. Nothing reaches
   this from an ordinary Contact-linked comm, a WhatsApp thread, or a comm already showing on a deal.
2. AT-231 §4.3's ad-hoc WA→deal link — speced, not built (§4.3 above).
3. Unrelated features that surfaced on a broad grep for "attach"/"unlink" near comms:
   `WhatsAppLinkController::unlink` (unlinks an agent's own WA device session, AT-156, not a
   message-to-deal link), `DealLinkReviewController::unlink` (property/deal reconciliation, unrelated),
   `SupplierDirectoryController::attach` (attaches a directory provider firm to a deal, not a
   communication).
4. No controller anywhere calls `CommunicationLink::create()`/`updateOrCreate()` from a user-facing
   manual action outside the ingestion services and `CorrespondenceFilingService`.

---

## 6. Open questions for Johan (before any build is scoped)

1. **Cross-deal/cross-role contact ambiguity.** A contact who is party to more than one deal (proven,
   not hypothetical — see §3) cannot have their Contact-linked comms attributed to a specific deal from
   data alone. Does the tab accept this risk initially (show all of a party's comms, unfiltered by
   deal), or is deal-level attribution a hard requirement before this ships?
2. **Attorney/bond-originator/supplier comms aren't ingested-and-linked at all today**, despite the code
   existing for attorneys (§4.1). Those tab buckets would be empty on day one unless the suspense queue
   is proven with real data first and/or generalised beyond attorneys.
3. **Should the tab show provisional (unreconciled) `DealV2`-linked comms?** All 23 real rows of this
   type in the database are provisional; none carry a party attribution.
4. **Scope of the auto-link-learning extension.** Johan's design implies "any manual link should teach
   the system." Today that only happens for the attorney-email route. Extending it to buyer/seller/
   supplier/WhatsApp is new integration work, not a wiring change.

---

## 7. Graded build order

Each item marked **[NEW]** (build from scratch), **[FINISH]** (resume/complete a partially-started
piece), or **[EXISTS]** (already built, needs wiring/proving only).

1. **[EXISTS, dormant]** Prove the attorney-email suspense queue end-to-end with a real inbound email on
   QA1. Nothing to build — validates the pattern the rest of this depends on.
2. **[NEW]** Generalise the suspense/"unlinked" concept beyond attorneys — supplier, bond-originator,
   and (per §3) potentially buyer/seller comms that can't be deal-attributed.
3. **[NEW]** Extend the learned-reference auto-link mechanism (§4.2) to non-attorney channels — the
   default Contact-matched ingestion path does not currently consult `CorrespondenceMatchService` at all.
4. **[FINISH]** AT-231 P4 — WhatsApp provider-contact resolution + the ad-hoc manual link affordance
   (§4.3) is the closest existing design to a generic attach/remove control; finishing/generalising it
   is more direct than inventing a new mechanism.
5. **[NEW]** Generic attach/remove control living directly on the Communication tab, any channel, any
   party — §4.3 as speced is WhatsApp-only and provider-only; Johan's ask is broader.
6. **[NEW, depends on 1–5]** The Communication tab itself: read confirmed/verified links from whichever
   suspense/learned-ref state exists per party, surface pending/unlinked items as an actionable
   affordance (not just a passive list), group by party role (reusing `Dr2DistributionComposer::parties()`),
   and provide the attach/remove control from item 5 inline per message. Read-only display of message
   fields (`channel`, `direction`, `getFromDisplayAttribute()`, `subject`/preview,
   `getDisplayBodyAttribute()`, `occurred_at`, attachment count, provisional/not-delivered badges), all
   gated through `Communication::scopeVisibleTo()` and standard `AgencyScope`.

**Sequencing dependency:** items 3 and 5 both need item 2 (a generalised, non-attorney-only "unlinked"
concept) to be coherent; item 6 depends on all of 1–5. Item 1 is the cheapest first step and proves the
existing pattern before extending it.

---

## 8. Acceptance criteria

Not yet defined — this document is investigation, not an approved build spec. Acceptance criteria should
be written once Johan has ruled on §6 and selected which of §7's items are in scope for the next phase.

---

## 9. Files (investigated, not modified)

Read-only investigation touched no files. Key files referenced throughout this document:

- `app/Models/Communications/Communication.php`, `CommunicationLink.php`, `CommunicationFilingSuspense.php`, `CommunicationLearnedRef.php`
- `app/Services/Communications/EmailArchiveIngestor.php`, `WaArchiveIngestor.php`, `CorrespondenceFilingService.php`, `CorrespondenceMatchService.php`, `OutboundProvisionalLogger.php`
- `app/Services/DealV2/Dr2DistributionComposer.php`
- `app/Services/Deal/Pipeline/CommunicationEventSource.php`
- `app/Http/Controllers/Communications/CommsSuspenseController.php`
- `resources/views/dr2/_email-parties.blade.php`, `resources/views/dr2/_pipeline-context-tabs.blade.php`
- `resources/views/corex/communications/comms-suspense.blade.php`
- `.ai/specs/at231-inbound-attorney-comms-filing.md` (sibling spec, WhatsApp/P4 detail)
