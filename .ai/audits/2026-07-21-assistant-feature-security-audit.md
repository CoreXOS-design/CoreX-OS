# Assistant Feature (AT-267) — Security Audit & Remediation

**Date:** 2026-07-21 · **Branch:** QA2 · **Scope:** the whole AT-267 assistant feature.

A 7-lane adversarial audit (permissions, cross-agent read, cross-agent write, routes/middleware,
locks/toggles, lifecycle/control-page, non-web surfaces) plus a per-finding verification pass. The
**assistant permission core is sound** (resolver, view-vs-mutate split, ContactScope, fail-closed
lifecycle, control-page save, image-delete block). The gaps were **coverage holes** — the safe
patterns were wired into a hand-picked set of surfaces; everything off that list was unguarded.
Several holes were **systemic** (cross-agency, any user) that the assistant lens exposed.

## Remediation — all fixed, each with a passing test, on QA2

| Sev | Finding | Commit fix |
|-----|---------|-----------|
| CRITICAL | Cross-tenant lease terminate/renew (no auth, no agency scope on LeaseRecord) | Per-record guard via LeaseRecord::visibleTo + agency-bounded 'all' scope |
| CRITICAL | Assistant creates agency stock via multiple paths (legacy mobile/API, promote×2); no model backstop | `Property::creating` abort_if(is_assistant) + deny_assistant_property_write on the ungated routes |
| CRITICAL | Deal settlement/edit with no per-record guard (Dr2 + DealV2) | AuthorizesDealAccess on Dr2; new AuthorizesDealV2Access on DealV2 |
| HIGH | Command Center tasks/calendar unguarded (task hijack via assigned_to) | Per-record guard (API + web), clamp assistant to 'own' |
| HIGH | Assistant self-reassignment escalation (routes ungated + `assistants` seeded on) | deny_assistant on admin/assistants routes + `assistants` in admin_default_off_sections |
| HIGH | Property/deal ownership-column injection (agent_id reassign, no agency scope) | Assistant can't reassign; cross-agency target rejected |
| HIGH | restore endpoints key-only (Property on the allow-list, DocumentFiling, Presentation) | Per-record guard on each |
| HIGH | DocuPerfect doc mutators key-OR-self / unscoped (DocumentController, Sales, Amendment) | New AuthorizesDocumentAccess trait applied |
| HIGH | E-sign signing pipeline (authorizeDocument) view-scoped, not dataIdentityIds | mutation-scope + dataIdentityIds; SigningView pipeline test |
| HIGH | is_assistant not pinned to role=assistant (~20 sites read role/is_admin directly) | User::saving pins role='assistant', is_admin=0 |
| HIGH | Download toggle uncovered surfaces (presentation pack, viewing-pack, comms attachment) | deny_assistant_download added |
| HIGH/POPIA | Property Drive files on public disk, world-readable, toggle unenforceable | Move to local + gated download route; backfill command `corex:move-property-files-to-local` |
| MED | Duplicate-cluster dismiss cross-agency (raw DB, no scope) | Agency-scoped |
| MED | ContactMatch page inert for assistants | whereIn(dataIdentityIds) |
| MED | Ownership attribution — assistant work filed under assistant not agent | ownershipUserId() at task/document/presentation stamping sites |
| LOW | E1 agent-deactivation freeze not persisted | User::updated freezes/thaws assignments |

## Verified NOT bugs (checked, no change)
- Contacts export `agent_id` — bounded by the global ContactScope.
- FICA agency-level guard — intentional shared review pool.
- Ellie (AiConversation private-to-self), background jobs, broadcast channels, raw-flag consistency.

## Operational follow-up for Johan
- **Run the backfill once** and verify a Drive download after:
  `php artisan corex:move-property-files-to-local --dry-run` then without the flag. It moves existing
  world-readable property files to the private disk (idempotent).
