# A seller removed from a property keeps a working, unauthenticated live link to it

**The link is still valid.** Confirmed by an unauthenticated `curl` fetch after the
seller was removed: `HTTP 200`, and the page genuinely renders the property (title
tag, address, "Live Marketing Update"). No login, no session, no token check beyond
the 64-char URL itself.

**Date:** 2026-09-13. **Found by:** the conductor, live on QA1, reading raw HTML rather
than trusting the visible page after closing the Marketing Readiness walk (cc4's item
2 — confirmed passed, separately). **Status:** confirmed, read-only, not fixed —
QA1 is frozen tonight.

## What this is and is not

**Not a regression from tonight's work.** `property_seller_links` is a completely
separate table from `contact_property` — untouched by cc3's write-side conversion or
cc4's read-side conversion, and it would have behaved identically yesterday, last
week, or on day one of this feature.

**It is the same class of problem the rest of tonight was spent on, running the
opposite direction.** Tonight's other findings were about a system destroying
history it should have kept (the `contact_property` hard delete) or naming a person
who should no longer be named (DR2's owner-gate message, before the fix). This one is
a system **keeping access it should have withdrawn**: the relationship
(`contact_property`) that originally justified issuing the link is gone, but the link
itself — and the access it grants — persists with no connection back to that
relationship at all.

## Reproduction (2026-09-13, live on QA1, property 21037)

1. Property 21037 had one seller, contact 19051 (ZZDISPOSABLE FicaSeller). A "Seller
   Live Links" row existed for that pair: `property_seller_links.id = 972`, token
   `bcae76cd...0813db6`, `revoked_at` null, `access_count = 0`.
2. The seller was removed from the property via the property's own Contacts panel
   Unlink control (a real, correct soft-delete on `contact_property` — confirmed
   separately tonight, working as designed).
3. Reloaded the property's Drive tab. The FICA compliance action correctly updated
   ("Link a seller" — that part of tonight's fix is confirmed working).
4. The "Seller Live Links" section, lower on the same page, still listed the removed
   seller's live link — name, the live URL, "Viewed 0x", and working **Copy**,
   **Email**, and **Revoke** buttons beside it.
5. Fetched that URL directly, unauthenticated (`curl`, no cookies): **`HTTP 200`**,
   the property's live marketing page rendered in full.

## Where this renders and why

`resources/views/corex/properties/show.blade.php:5868-5913` — the "Seller Live Links"
section on the property's own Drive tab:

```blade
$sellers = $property->contacts()->wherePivotIn('role', [...])->get();
foreach ($sellers as $seller) {
    \App\Models\PropertySellerLink::ensureExists($property->id, $seller->id);
}
$sellerLinks = \App\Models\PropertySellerLink::where('property_id', $property->id)
    ->whereNull('revoked_at')
    ->get();
```

`$sellers` (the auto-create loop) correctly reads `Property::contacts()`, which is
already `deleted_at`-filtered — so a removed seller never gets a *new* link created
for them after removal. That part is fine. But `$sellerLinks` — what actually renders
— is a completely independent query against `property_seller_links` by `property_id`
alone, with no join back to the current seller list at all. It shows **every
non-revoked link this property has ever issued**, regardless of whether the person it
was issued to is still linked.

## The backing table and its lifecycle

`property_seller_links` (model `App\Models\PropertySellerLink`,
`app/Models/PropertySellerLink.php`). Columns include `token`, `contact_id`,
`revoked_at`, `revoked_by_user_id`, `access_count`, `last_accessed_at`.

- **Created by:** `PropertySellerLink::ensureExists()` — called from six link-creation
  call sites across `PropertyContactController.php` and `ContactPropertyController.php`
  (every path that links a seller-side contact to a property), plus the auto-create
  loop above. Idempotent, correctly scoped, no issue there.
- **Revoked by:** nothing, except a human manually clicking the **Revoke** button
  (`POST corex.properties.seller-links.revoke`), which sets `revoked_at`. I grepped
  every reference to `PropertySellerLink` in the codebase — every call site is
  `ensureExists()` (create-on-link). **There is no revoke-on-unlink call anywhere.**
  `unlink()` in both `PropertyContactController` and `ContactPropertyController` (the
  exact actions that soft-delete the `contact_property` row) do not reference
  `PropertySellerLink` at all.
- **Result:** a link, once issued, outlives the relationship that justified it,
  indefinitely, unless an agent happens to notice and manually revoke it — and
  nothing on screen prompts them to. The agent who unlinked the seller has every
  reason to believe that action was complete.

## The other two occurrences of the name (ruled out — correct, expected)

The conductor found the removed seller's name in two other places in the raw HTML
and asked me to account for both before writing this up. Both are legitimate,
correctly-working audit trail entries, not part of this finding:

- The property's **Activity feed** (a `text-xs font-medium` div): *"ZZDISPOSABLE
  FicaSeller unlinked (was seller)"*, attributed "by Johan Reichel", timestamped.
- The property's **History tab** (a `text-xs font-medium` span, tagged
  `Contact_property`): the same event, full audit-log row, with an "Export CSV" and
  "Include system trail" control alongside it.

Both are `PropertyAuditService` log entries — the correct, working audit trail for
the unlink action itself. Naming the removed person in a historical record of "this
happened" is exactly right; it's only the *live, actionable* link (Seller Live Links)
that's the problem.

## Scope of a fix (not attempted — named so it doesn't need re-deriving)

The `$sellerLinks` query needs to stop being independent of current seller state —
either join against `contact_property.deleted_at IS NULL` for that `contact_id`, or
revoke a contact's `property_seller_links` rows explicitly at the same point
`contact_property` is soft-deleted (mirroring how the unlink action already writes
its own audit entry — the same call site, one more line). The second is probably the
more honest fix: it makes "removed" actually mean removed, with its own audited
`revoked_at`/`revoked_by_user_id`, rather than silently hiding a still-active grant
behind a query filter. Not evaluated further — this is a report, not a design.
