# Contact Communication Status — 3-State Marketing/Transaction Gating (AT-50)

> Status: Approved for build 2026-06-16 (Johan, AT-50). Pillars: Contact (spine) + Deal (live-transaction signal) + Property (named sale).
> Builds on AT-49 (`MarketingConsentService`, opt-out/opt-in links, `/unsubscribe`). Investigation: Jira AT-50.

## Purpose

A contact's communication state is one of three, **derived** (never a stored column):

| State | Meaning | Condition |
|---|---|---|
| `opted_in` | Receives marketing + transactional | `messaging_opt_out_at` IS NULL |
| `marketing_opted_out` | No marketing; no live transaction either | `messaging_opt_out_at` set AND NOT in a live transaction |
| `transaction_only` | Marketing off, but business comms continue because a live sale is in progress | `messaging_opt_out_at` set AND in a live transaction |

**Rule:** marketing can ALWAYS be switched off. Transactional/business comms CANNOT be switched off while the contact is an active party in a live SALE. When the sale concludes, the contact falls back to `marketing_opted_out`. **Rentals are out of scope** for this build.

## Live-transaction signal (from AT-50 investigation — do not use `properties.status`)

A contact is in a live transaction (per agency) if EITHER:
1. They are a party on a `deals_v2` row with `status` ∈ live-statuses AND `actual_registration IS NULL`, linked via `deal_v2_contacts.role` ∈ `{seller, co_seller, buyer, co_buyer}`; OR
2. They have an active `property_seller_links` row (`revoked_at IS NULL`) — secondary seller signal.

**Live-statuses are agency-configurable** (`agencies.outreach_live_deal_statuses` JSON; default `['active']` from `config/corex-outreach.php`). Never hardcoded.

## Components

- `config/corex-outreach.php` — `live_deal_statuses` (default `['active']`), `transaction_party_roles`.
- `agencies.outreach_live_deal_statuses` (JSON, nullable) + `Agency::liveDealStatuses()` (override ?? config default).
- `App\Services\SellerOutreach\TransactionStateService`:
  - `isInLiveTransaction(int $agencyId, Contact $contact): bool`
  - `liveTransactions(int $agencyId, Contact $contact): array` — descriptors `{type, label, property}` to NAME the sale on the lock screen.
- `Contact::communicationStatus(): string` + `communicationStatusMeta(): array{key,label,class}`.
- Public opt-out screen (`/outreach/opt-out/{token}`) → two switches: **A Marketing** (always toggleable: off→`optOutContact`, on→`optInContact`); **B Transaction** (LOCKED + named-sale explanation when in a live sale; else "stop all messages" = full opt-out).
- `/unsubscribe/{agency}` → same gate: marketing always suppressed; if the resolved contact is in a live sale, the page explains transactional comms continue.
- 3-state badge on the contact show page + outreach timeline panel.

## Robustness (BUILD_STANDARD)

- All writes go through AT-49 `MarketingConsentService` (idempotent). GET is preview-safe (no write); POST acts.
- Public routes are unauthenticated → suppression/transaction queries use explicit `agency_id` (AT-49 precedent).
- Deleted/archived related deal or property renders gracefully (descriptor falls back to a generic "an active sale").
- Switch B can never turn transactional off during a live sale — enforced server-side (action ignored + re-explained), not just hidden in the UI (No Silent Locks).

## Acceptance

(a) active deals_v2 seller → live → transaction switch LOCKED, named sale shown, marketing opt-out still works & blocks marketing; (b) active-deal buyer → also locked; (c) no active deal → transaction switch enabled → full opt-out works; (d) deal registered → lock lifts; (e) 3-state badge correct per case on contact page; (f) `/unsubscribe` respects the same gate.
