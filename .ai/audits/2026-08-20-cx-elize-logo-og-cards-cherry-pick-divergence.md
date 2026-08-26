# Elize logo / OG-card fix — live/Staging divergence by design, recorded

**Date:** 2026-08-20

## What happened

cc6 built and verified (on qa1) the fix for Elize's WhatsApp link-preview
showing the CoreX logo instead of Home Finders Coastal's — Open Graph tags
added to the seller-outreach landing page (`/m/{shortcode}`) and the
buyer-portal wishlist share link (`/buyer/portal/{token}`), sourced from
`Agency::publicBrandingFor()`, `og:image` omitted entirely (never defaulted
to CoreX) when an agency has no logo. cc6 was mid-build on the buyers report
rebuild and could not run it through the hops, so cc3 took it from qa1
through Staging then live, same shape both times: cherry-pick the single
commit only (never merge qa1's or Staging's full branch — both carry other,
unrelated, not-yet-authorised history), reload the target's own FPM pool
(dynamically resolved from its own vhost, never hardcoded), `view:clear`
(Blade-only change — a stale compiled view would look like nothing
happened), then verify against real data: real head markup, `og:image`
present, and the logo URL fetched directly to confirm it returns an actual
image rather than a 404.

Files touched (exactly, in all three commits): `app/Http/Controllers/
SellerOutreach/PublicLandingController.php`, `resources/views/buyer-portal/
show.blade.php`, `resources/views/seller-outreach/landing.blade.php`.

## The three commits — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| qa1 (`qa1-og-cards`) | `31938a29d2be88766fc5fde6e574696b150fcf09` | `c30a7df5faf5894e08fdd0658bbf268a55221570` |
| Staging | `75b6645417ba9d4a1493e2973905505bebe4d280` | `c30a7df5faf5894e08fdd0658bbf268a55221570` |
| Live (`main`) | `f28a44f9afa6a2ed03ee6949f2e9e55515e363ba` | `c30a7df5faf5894e08fdd0658bbf268a55221570` |

Confirmed identical patch-ids (`git show <sha> | git patch-id --stable`) —
same tree change, same 3 files, different commit hash at each hop because
each was cherry-picked onto a different parent history (qa1's `qa1-og-cards`
branch history, `Staging`'s tip, `main`'s tip), not because the content
diverged.

Staging ref moved `52bd5bcbc` → `75b664541`. Live ref moved `39b026955` →
`f28a44f9a`. Both pushed to their respective `origin` refs.

## Verification performed at each hop

**Staging** (`staging.corexos.co.za`, DB `hfc_staging`): fetched `/m/WAuxBy`
(a real HFC/agency-1 send, id 176) — `og:image` present, pointing at
`https://staging.corexos.co.za/storage/agencies/1/logo.jpg`; fetched that
URL directly — HTTP 200, `image/jpeg`, 1,247,370 bytes. No active
`buyer_portal_links` rows existed on staging, so a temporary row was
inserted (contact 16424, agency 1), the portal page fetched and confirmed
(`og:image` present, same logo URL), then the temporary row deleted —
matching cc6's own qa1 verification method for the identical no-data gap.

**Live** (`corexos.co.za`, DB `nexus_os`): fetched `/m/KfD6Ke` (a real
HFC/agency-1 send from today, id 1169) — `og:image` present, pointing at
`https://corexos.co.za/storage/agencies/1/logo.jpg`; fetched that URL
directly — HTTP 200, `image/jpeg`, 1,247,370 bytes (same file, byte-identical
size to staging's copy). A real, already-existing active `buyer_portal_links`
row (id 4, agency 1) was used directly — fetched, `og:image` present,
same logo URL.

## The one thing this note exists to prevent

**When `Staging` is eventually merged into live/`main`** — whatever else is
riding along on `Staging` at that point — **`75b664541`'s content will
already be present on live** under hash `f28a44f9a`. Git will not recognise
this as "already applied" by hash; it recognises it by tree/patch content.
A plain `merge` handles this fine on its own (no conflict on these 3 files,
since both sides already have equivalent content) — but if anyone reaches
for a manual cherry-pick or a conflict-resolution pass on that future merge
and sees `75b664541` "missing" from live's history by hash, **do not
re-apply it and do not panic at what looks like a conflict.** Check
patch-id first:

```
git show 75b6645417ba9d4a1493e2973905505bebe4d280 | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep c30a7df5faf5894e08fdd0658bbf268a55221570
```

If it matches `f28a44f9a` (or whatever live's cherry-picked hash is by
then), the content is already live. The divergence is expected and
accounted for here — it is not a lost commit and not a merge conflict to
fight.
