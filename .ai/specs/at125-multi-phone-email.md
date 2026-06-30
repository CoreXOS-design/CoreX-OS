# CoreX OS — AT-125: Multiple Phones + Emails per Contact

**Spec ID:** AT-125
**Status:** Draft — ready for build (decisions locked)
**Author:** Johan (product architect) + Claude (senior engineer)
**Date:** 2026-06-29
**Audit basis:** `.ai/audits/2026-06-29-at125-multi-phone-email-consumer-audit.md`
**Depends on / relates:** AT-122 (ingestion match-only — this WIDENS its matching to all identifiers);
MarketingConsentService / opt-out engine (suppression semantics preserved); AT-118 (comms gate, later).

---

## 1. Purpose

A contact must hold MULTIPLE phone numbers and MULTIPLE email addresses (arbitrary count — not 1, not
2), each with one primary per kind. Today the contact model holds a single `contacts.phone` (NOT NULL)
and `contacts.email` (nullable). This single-column limit is the root cause of the AT-122 ingestion
gap (messages to/from a contact's secondary identifier are discarded) and a real CRM shortfall (the
code already carries dead `cell_number`/`mobile` references and the importer already crams a secondary
number into one column — latent demand).

Once contacts hold multiple identifiers, ingestion (AT-122) matches an incoming email/phone against
ALL of a contact's identifiers, not just the primary.

---

## 2. Locked Decisions

1. **Model = Option A (child tables + synced-primary mirror).** New `contact_phones` + `contact_emails`
   tables (many per contact). KEEP `contacts.phone` / `contacts.email` as synced-primary MIRRORS so the
   ~77 existing readers keep working unchanged on day one. Full column deprecation is a LATER cleanup,
   not this build.
2. **Suppression = contact-level (preserved).** Opt-out blocks the WHOLE contact across all identifiers.
   `optOutContact()` keeps suppressing every identifier + setting the contact flag. A second unsuppressed
   identifier can NEVER make an opted-out person reachable. (Per-identifier opt-out = separate future
   ticket; the store already keys per-identifier, only the application stays contact-level.)
3. **Email-only contacts allowed.** A contact is NOT forced to have a phone. Relax the mirror so the
   phone mirror can be null. A contact must have AT LEAST ONE identifier of some kind (phone OR email),
   but not necessarily a phone.
4. **Primary-change UX:** mark-primary control (radio/select); first-added identifier auto-primary;
   changing primary re-syncs the mirror column. Exactly one primary per kind at all times (if any of
   that kind exist).
5. **Soft-unique:** index on the normalised match key; dedupe WITHIN a contact (can't add the same
   number/email twice to one contact). Cross-contact remains detection-only (no new hard uniqueness —
   matches current behaviour).

---

## 3. Data Model

### `contact_phones`
- `id`, `agency_id` (BelongsToAgency)
- `contact_id` → contacts (indexed)
- `phone` (raw as entered)
- `phone_normalised` (the match key — last-9 / 0→27 per ContactDuplicateService::normalizePhone),
  indexed
- `label` (nullable — e.g. mobile/home/work; optional, free or enum)
- `is_primary` (boolean; exactly one true per contact if any rows exist)
- timestamps + softDeletes
- Index: (contact_id, phone_normalised) soft-unique within contact (dedupe).

### `contact_emails`
- `id`, `agency_id` (BelongsToAgency)
- `contact_id` → contacts (indexed)
- `email` (raw as entered)
- `email_normalised` (lower(trim()) — the match key), indexed
- `label` (nullable)
- `is_primary` (boolean; exactly one true per contact if any rows exist)
- timestamps + softDeletes
- Index: (contact_id, email_normalised) soft-unique within contact.

### `contacts` (mirror columns retained)
- `contacts.phone` — becomes a SYNCED MIRROR of the primary phone. **Relax NOT-NULL → nullable**
  (email-only contacts). Kept so existing readers work unchanged.
- `contacts.email` — synced mirror of the primary email (already nullable).
- Mirrors are written by the model whenever the primary changes (an observer / service method keeps
  them in sync). Readers that haven't migrated yet read the mirror transparently.

---

## 4. Migration (lossless)

1. Create `contact_phones` + `contact_emails`.
2. Backfill: every existing contact's `contacts.phone` → one `contact_phones` row, `is_primary=true`,
   normalised. Same for `contacts.email` → `contact_emails` (skip if null).
3. Relax `contacts.phone` NOT-NULL → nullable (after backfill, so no row is left without its mirror).
4. The mirror columns now reflect the primary child row (they already hold the same value post-backfill).
5. No data loss; reversible (down: drop child tables, restore NOT-NULL only if safe — flag if any
   contact ended email-only).

---

## 5. The Canonical Resolvers (the leverage point)

The audit found two canonical resolvers + ~20 ad-hoc single-column lookups. Strategy: **point the two
canonical resolvers at the child tables FIRST**; the ad-hoc sites mostly collapse once routed through
them, and this is what enables AT-122 to match all identifiers.

- `ContactDuplicateService` (dedup / match-or-create): match an incoming phone/email against
  `contact_phones.phone_normalised` / `contact_emails.email_normalised` across ALL rows — match on ANY.
- `ContactIdentifierResolver` (the AT-122 ingestion gate): resolve an incoming email/phone against ALL
  of a contact's identifiers, not just the primary. THIS is the AT-122 widening — once it reads the
  child tables, ingestion matches secondary identifiers automatically.
- Ad-hoc `where('phone'|'email')` / LIKE sites: route through the canonical resolvers where practical;
  for read-only display they can keep reading the mirror for now (staged migration).

---

## 6. Writers — Contact Form + Importers

- **Contact create/edit form** (ContactController, PropertyContactController, MobileContactController):
  add/remove multiple phones + emails, mark primary (Q4 UX). First-added auto-primary. Saving syncs
  the mirror.
- **Importers** (CSV, P24, teaser-lead, entry-point, PP webhook, triage, e-sign signer): where they
  currently write a single phone/email (and the CSV importer that already merges cell+phone_secondary),
  write the identifiers as child rows — the dead `cell_number`/`mobile`/`phone_secondary` demand becomes
  real secondary rows instead of being merged/lost.
- **Mobile API**: same multi-identifier write path.
- No outreach writer (outreach reads then snapshots to the send — unaffected at write; see §7).

---

## 7. Readers — Staged, Mirror-Backed

The ~77 readers keep working via the mirror on day one. Migrate the high-value ones to multi-identifier
awareness deliberately:
- **Outreach send/merge/queue:** sends to the PRIMARY identifier (the mirror) — unchanged default.
  (Future: let the agent pick which identifier a pitch uses — out of scope here, note it.)
- **Display surfaces (~30):** the contact show/edit eventually lists ALL identifiers; other display
  sites read the primary mirror until migrated. The contact detail view is the priority display
  upgrade (show all numbers/emails).
- **FICA / e-sign / documents / exports:** primary mirror for now; migrate if a real need for
  multi-identifier surfaces.
- **Consent/suppression:** see §8 — must read ALL identifiers.

---

## 8. Consent / Suppression (LOAD-BEARING — preserve exactly)

The audit confirmed: suppression is STORED per-identifier (`marketing_suppressions` keyed by normalised
email/last-9 phone) but APPLIED contact-level — `optOutContact()` suppresses every identifier + sets
`messaging_opt_out_at`; the gate blocks if ANY identifier is suppressed OR the contact flag is set.

**AT-125 MUST preserve contact-level opt-out:**
- `optOutContact()` suppresses ALL N of the contact's identifiers (now read from the child tables, so
  if a contact has 3 emails + 2 phones, all 5 get suppressed) + sets the contact flag.
- The marketability gate (`canMarketTo` / `isContactSuppressed`) checks ALL identifiers — if ANY is
  suppressed, or the flag is set, the contact is blocked.
- A newly-added identifier on an already-opted-out contact must NOT be marketable (the contact flag
  blocks regardless; and any new identifier should be suppressed too if the contact is opted out).
- **NET:** adding multiple identifiers can NEVER create a path to reach an opted-out person. This is
  the privacy-correct invariant and it is non-negotiable.

---

## 9. Ingestion Link (AT-122 widening)

Once `ContactIdentifierResolver` reads the child tables (§5), AT-122's match-only ingestion
automatically matches an incoming email/phone against ALL of a contact's identifiers — a message
to/from a secondary email/number now MATCHES (and is imported + owner-stamped) instead of being
discarded. No ingestor change needed beyond the resolver pointing at the child tables.

---

## 10. Robustness & Hard Rules

- No hard deletes (identifiers soft-delete/archive). Removing an identifier never orphans the mirror —
  if the primary is removed, another becomes primary (and the mirror re-syncs); a contact can't end up
  with a stale mirror pointing at a deleted identifier.
- AgencyScope on the child tables.
- Exactly one primary per kind invariant enforced (setting a new primary clears the old).
- A contact must retain AT LEAST ONE identifier (can't delete the last one if it leaves the contact
  with zero of both kinds — or allow it per business rule; default: a contact needs at least one
  contactable identifier). CONFIRM at build.
- Migration preserves all existing data; mirror stays correct throughout.
- Soft-unique within contact prevents duplicate identifiers on one contact.

---

## 11. Build Order

1. Migration: `contact_phones` + `contact_emails`, backfill existing primary, relax `contacts.phone`
   NOT-NULL. Models (BelongsToAgency, SoftDeletes, is_primary invariant, normalised key, mirror-sync).
2. Mirror-sync mechanism (observer/service): primary change → mirror column updates. Prove the mirror
   always reflects the primary.
3. Canonical resolvers (`ContactDuplicateService`, `ContactIdentifierResolver`) read the child tables →
   match against ALL identifiers. THIS widens AT-122 ingestion. Verify ingestion now matches a secondary
   identifier.
4. Suppression: `optOutContact()` + the gate read ALL identifiers (§8). Verify an opted-out contact
   stays blocked across every identifier, and a newly-added identifier can't reach them.
5. Contact form: multi add/remove + mark-primary UX (+ importers write child rows).
6. Contact detail display: show all identifiers. Other readers stay mirror-backed (staged).
7. Robustness pass + nav/UX consistency.

---

## 12. Out of Scope (this build)

- Per-identifier opt-out (suppress one email but not another) — separate future ticket; the store
  supports it, the application stays contact-level here.
- Full deprecation of `contacts.phone`/`email` columns — later cleanup; mirrors stay for now.
- Letting outreach pick a non-primary identifier to send to — note as future; default sends to primary.
- Migrating all ~77 readers off the mirror — staged over time; day one they read the mirror.
