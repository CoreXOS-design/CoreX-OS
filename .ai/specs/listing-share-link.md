# Listing Share Link (view-only)

**Status:** Phase 1 built (2026-06-19) — see build note in §5. Phase 2 still deferred.
**Date:** 2026-06-19
**Author:** Claude + Andre
**Origin:** Testing feedback — "a way to share other agents' listing." Decision (2026-06-19): the intent is a **view-only share link**, not co-listing access and not blanket colleague visibility.

---

## 1. Goal

From any listing an agent can reach, produce a clean, public, **view-only** link to that listing and share it (copy / WhatsApp / email) — including listings belonging to another agent in the same agency. The recipient sees the property, never CoreX internals. No login, no edit, no agent PII beyond what the public listing already exposes.

## 2. Pillar connection

- **Property** — the shared asset. The link renders a read-only view of the `Property`.
- **Agent** — the share is attributed to the sharing agent (audit + optional lead attribution).

## 3. Key finding: a public view already exists — reuse it, don't rebuild

CoreX already serves public, view-only listing pages:
- `PublicAgencyPropertiesController@show` → `GET /{agencySlug}/properties/{property}` (named `public.agency.properties.show`)
- `Property::public_url` accessor builds that URL.

So the **core deliverable is the share affordance**, not a new public renderer. Building a second public-view system would duplicate the moat — forbidden. The base build wires share actions to the existing public URL.

A tokenized, trackable, revocable link (mirroring `PresentationSnapshotLink`) is a **Phase 2** option, only if engagement tracking / expiry / revocation is wanted. It is NOT required for the stated request and is deferred behind an explicit decision.

## 4. The visibility dependency (must be resolved before build)

To share *another agent's* listing from inside CoreX, the sharing agent must first be able to open that listing. Today, non-admin agents are scoped to their own listings (the "All Agents" filter is admin/BM only). Two clean paths:

- **(A) Share from the public website index** — the public agency site already lists *all* active listings. The agent opens any listing's public page and shares it. **Zero new CoreX permission.** Recommended default.
- **(B) In-CoreX colleague visibility** — a separate, separately-approved feature (the "colleague visibility" option from the 2026-06-19 decision) that lets agents *view* colleagues' listings inside CoreX, where the Share button then lives.

This spec delivers the share mechanism. It does **not** silently widen in-CoreX visibility — that stays a separate decision so we don't leak listing internals by accident.

## 5. Base build (Phase 1 — no new model) — BUILT

**Build note (2026-06-19):** shipped as `resources/views/corex/properties/partials/share-actions.blade.php`, mounted in the property show-page header action row, gated by `properties.share` + a publicly-shareable status whitelist. The share target is `Property::public_url` (the canonical public listing URL, which points at the marketing website and is id-resolved). The in-repo `public.agency.properties.show` page is the external marketing site's CoreX-side equivalent; the polished customer-facing destination is the marketing site, so `public_url` is the correct link to share. Agents who can open a colleague's listing (managers/BM via the "All Agents" filter, branch-scope agents) can share it; broadening own-scope agents' visibility remains the separate decision in §4.

A reusable **Share** action component:
- Buttons: **Copy link**, **WhatsApp**, **Email** — all targeting `Property::public_url`.
- Placement: property show page (for listings the agent can open) and the public website listing page (path A above).
- WhatsApp: `https://wa.me/?text=<prefilled message + public_url>`. Email: `mailto:` with subject/body. Copy: clipboard write + toast.
- Only offered for listings whose status is publicly visible (e.g. `active`) — never for drafts/withdrawn.
- Permission: a new key `properties.share` in `config/corex-permissions.php`, gated in the UI and (for the in-CoreX placement) on the route/controller. Sharing a public link is low-risk but still permissioned per non-negotiable #5.

## 6. Phase 2 (optional, behind a separate decision) — tokenized share link

Only if tracking/expiry/revocation is wanted. Mirrors `PresentationSnapshotLink`:
- Model `PropertyShareLink` (uses `BelongsToAgency`, `SoftDeletes`): `token`, `property_id`, `created_by_user_id`, `expires_at?`, `revoked_at?`, `view_count`, `last_viewed_at`.
- Public route `GET /share/listing/{token}` → resolves token → renders the existing public listing view, increments view count, honours expiry/revocation (soft, never hard-deleted per non-negotiable #1).
- Engagement could emit a domain event (per `corex-domain-events-spec.md`) for lead attribution — to be specced if Phase 2 is approved.

## 7. Permissions

- New key `properties.share` in `config/corex-permissions.php`, added to default agent role.
- Phase 2 adds nothing further unless revocation management needs an admin gate.

## 8. Acceptance criteria (Phase 1)

1. A Share control appears on the property show page for any active listing the agent can open, and on the public website listing page.
2. Copy / WhatsApp / Email all carry the correct `public_url` for that listing.
3. The control is hidden for non-public statuses (draft/withdrawn) and for agents lacking `properties.share`.
4. No new public renderer is introduced — links resolve to the existing `public.agency.properties.show` route.
5. Multi-tenancy: the public URL only ever exposes the listing's own agency-public data; no cross-agency leakage.
6. `scripts/dev-check.ps1` passes with 0 new failures.

## 9. Files to create / modify (Phase 1)

- **Modify:** `config/corex-permissions.php` (add `properties.share`)
- **Create:** `resources/views/corex/properties/partials/share-actions.blade.php` (reusable share component)
- **Modify:** `resources/views/corex/properties/show.blade.php` (mount share component, permission-gated)
- **Modify:** the public website listing view (mount share component)
- **Create:** `tests/Feature/Properties/ListingShareTest.php` (public_url correctness, status gating, permission gating)

## 10. Open questions

1. Confirm path A (share from public site, no new CoreX permission breadth) vs path B (in-CoreX colleague visibility) — A is the recommended default.
2. Is Phase 2 (tokenized, trackable, expiring links) wanted now, or deferred?
3. WhatsApp/email prefilled copy — agency-branded template or plain "Check out this listing: <url>"?
