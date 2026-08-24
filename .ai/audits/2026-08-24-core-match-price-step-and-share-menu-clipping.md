# Core Match: price step-validation block + clipped share menu — both fixed, staging

Both real, both blocking Johan's testing. Both fixed with real before/after proof.

## The actual "clicking Update and it do not persist" bug

**This is the one Retha and Johan reported this morning.** The array-clearing fix from
earlier today (`2026-08-24-core-match-clear-criteria-bug-fix.md`) is real and stays — it was a
second, genuinely different bug found on the way, not this one.

`resources/views/corex/contacts/_match-form.blade.php:186,191` — both price inputs carried
`step="50000"`. HTML5 step validation rejects any value not a multiple of the step relative to
`min`, **for every field on the form, whether the agent touches it or not** — a stale pre-filled
value alone blocks the whole submission.

**Root cause of the non-round values:** `app/Services/Buyers/BuyerLeadCascadeService.php:163-164`
derives `price_min`/`price_max` as `floor/ceil(listing_price * band_fraction)` for
enquiry-sourced wishlists — a real listing price times a configured percentage, essentially
never a round 50000. Johan's example: 719,100 / 878,901. Match 238: 1,255,500 / 1,534,501. The
system generates data its own form then refuses to submit.

The browser's native validation bubble appears at the price field near the top of a long form;
Update sits at the bottom. An agent clicks Update, sees nothing happen, no error, no idea why.
This is precisely why match 238's `updated_at` sat unchanged since 2026-07-16 — Johan clicked
Update every time; the browser silently refused to let the click do anything.

**Fix:** removed `step="50000"` rather than rounding the generated values (Johan's explicit
call — price is a free amount, not a stepped quantity, and rounding a derived search band
changes what an agent is searching for without telling them). Confirmed safe first: neither
`ContactMatchController::validatePayload()` nor `BuyerDetailController::validateWishlistPayload()`
has ever required roundness server-side (`nullable|integer|min:0` only) — removing the client
constraint doesn't just move the failure server-side.

**Proof:** set a match to 719,100/878,901 (Johan's exact figures). Real browser, price fields
untouched, edited `beds_min` only, clicked Update — row changed (`beds_min: 3→4`), price
preserved. A round-value edit (500,000→600,000) still saves normally. The array-clearing fix
still holds on top of this (features survive a price-only edit; a genuine clear still clears).

**Blast radius: 359 of 471 live wishlists with any price set (76%) currently carry a non-round
min or max and are unsaveable through this form today.**

## Share menu clipped, reachable only to the first option

Johan: both Core Matches and Buyers Pipeline per-property share menus were cut off — could only
test "My details," not "Listing agent's details." Real bug on **both** screens (an earlier
message guessed it was Buyers-Pipeline-only; that guess was wrong and corrected before
building the fix).

**Root cause:** `resources/views/components/match-card.blade.php:74` — the shared property
card component both screens use — has `overflow-hidden` on its own root div (for the rounded
photo thumbnail corners). A `position: absolute` dropdown inside that card is clipped by the
card's own overflow regardless of z-index. Not a page-level container issue on either screen.

**Fix:** `resources/views/corex/properties/partials/share-actions.blade.php` — the dropdown now
renders via `x-teleport="body"` (already an established pattern elsewhere in this codebase —
docuperfect esign wizard, dr2 pipeline tiles) with `position: fixed` coordinates computed from
the trigger button's own `getBoundingClientRect()` on open, since a teleported node loses its
positioned ancestor. Guarded `@click.outside` against the button's own ref to avoid the standard
teleport gotcha where the button's own toggle-open and the now-detached menu's outside-click
handler fire on the same click and fight each other.

**Proof, real clicks, both options, both screens, real URLs:**
- Core Matches results (contact 16416, match 94): "My details" → correct `?agent=22` wa.me/mailto links, menu fully within viewport. "Listing agent's details" → correct `?agent=listing`.
- Buyers Pipeline, Johan's exact URL (`/corex/command-center/buyers/8918`): same two checks, same result. Screenshot confirms both options fully visible and reachable — not cut off.
- The whole-wishlist "Client Page" button — checked, not assumed: it's a plain `<a target="_blank">`, no dropdown, no `x-show`, nothing to clip. Structurally cannot exhibit this issue.

Nothing deployed to live. All four fixes today (array-clearing, price-step, share-menu, plus
the earlier validator gap) are on `origin/Staging`.
