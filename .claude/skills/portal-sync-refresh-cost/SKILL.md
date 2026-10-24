---
name: portal-sync-refresh-cost
description: The portal sync refresh-cost contract - an unchanged Property24 listing refresh must cost exactly one portal call. Use when touching Property24 syndication, the submit path, photo/agent pushes, or any portal sync file.
---
# Portal sync - refresh cost contract

**A Refresh of a listing where nothing changed costs exactly ONE portal call: the listing POST.** Never a photo re-upload, agent profile push, agent photo upload, or a portal-side agent-list scan. Breaking it is invisible (no assertion, no red pipeline) and has happened twice - both times the only alarm was an agent saying "Refresh feels slow": (1) every refresh re-uploaded the whole gallery (fixed by `properties.p24_image_signature`: unchanged gallery sends `photos: null`); (2) an unconditional agent profile + photo push per agent put P24's 15-120 s `GET /agencies/{id}/agents` on the critical path (fixed by `users.p24_profile_signature` / `users.p24_photo_signature` and resolving the agent id from `users.p24_agent_id`).

**Rule: never send a portal bytes it already holds.** Anything a refresh pushes is gated on a signature of what the portal currently has. If you add a call to the submit path, fingerprint what it sends and skip it when unchanged.
Enforcement (none optional): runtime `Property24SyndicationService::auditRefreshCost()` logs `P24 REFRESH COST REGRESSION` (WARNING, `property24` channel) when an unchanged refresh exceeds budget - fix the caller, never raise the budget; build `tests/Feature/Syndication/Property24RefreshCostTest.php` asserts the one-call budget; any change to the portal sync files must land with a test diff in `tests/Feature/Syndication/`.
