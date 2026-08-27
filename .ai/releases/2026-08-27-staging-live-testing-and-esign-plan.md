# Release plan — Staging → live-testing, and e-sign QA1 → Staging

Investigation date: 2026-08-27. Author: cc3 (lane-3). Investigation and planning only —
nothing in this document has been executed. No push, merge, cherry-pick, deploy, or
migration was run against any real environment. All cherry-pick/merge testing was done
in a disposable scratch worktree that has since been removed.

---

## URGENT — read this before anything else in this document

**`live-testing.corexos.co.za` is not a separate environment. It is the live production
server, under a second name.**

Verified directly:
- The domain resolves to `91.99.130.85` — the production server itself.
- Its nginx site serves from `/corex/public` — the exact same codebase directory as
  `corexos.co.za`.
- It runs through the exact same PHP-FPM pool/socket as production.
- Its `.env` (there is only one — `/corex/.env`) reads `APP_ENV=production`,
  `DB_DATABASE=nexus_os` — the real production database.
- There is no hostname-based switching anywhere in the app that would make it behave
  differently depending on which domain name was used to reach it.

**Consequence: as things stand today, "push Staging to live-testing" and "push Staging to
live" are the same action.** There is no intermediate, safe environment to test in before
the real thing. If the intent is genuinely a three-step Staging → live-testing → live
process with a real regression pass in between, **a real separate live-testing environment
has to be built first** — its own checkout directory, its own database (a copy of
production data, or a fresh test set — Johan's call), and its own vhost pointed at that
checkout, not at `/corex`. Until that exists, everything below that talks about "the
live-testing push" is describing a production deploy, not a safe rehearsal.

This does not block writing the rest of this plan — the commit range, migrations, and
config differences below are exactly what either a real live-testing environment OR a
live deploy would need — but the order-of-operations and risk sections have been written
honestly around the fact that, right now, this step IS the live deploy.

---

## PART 1 — Staging → live-testing (currently: Staging → live)

### What is on Staging that isn't on live-testing/live

Commit range: `origin/main..Staging` (31 commits, of which 3 are merge commits — 28 real
changes). `origin/main` is what live-testing currently serves, confirmed identical to
`origin/Prod`.

In plain terms, what changes for a user:
- **Map screen**: no more duplicate pins for a property that's both a portal listing and
  our own tracked property; a search that doesn't match anything in view now pans/zooms to
  find it instead of silently doing nothing; the layer toggle row no longer wraps
  awkwardly; badge counts on the map now match what's actually drawn.
- **MIC / My Claims**: a claim someone removed and quickly re-added no longer silently
  reverts back to "removed"; "Not Selling" now properly closes the claim instead of leaving
  it lingering as company stock; a claim's address shows correctly instead of the blank
  portal field; search terms survive toggling "Show in-stock too"; claims tied to your own
  branch stop disappearing under a different scope filter.
- **Deeds capture**: a re-scraped sectional-title unit (a flat/unit in a complex) is now
  correctly recognised as the same one already on file instead of creating a duplicate;
  owners already known as a contact are now flagged so an agent can see that at a glance.
- **Suppliers**: a firm's business address can now be captured and edited (built last
  night) — see the entanglement note in Part 2, this is one of the files that will need
  hand-combining once e-sign's own supplier work is decided.
- **A stray double-quote bug** that broke the entire seller-outreach compose screen (turned
  the whole page into a wall of visible raw code) is fixed.

Full commit list is in the appendix at the end of this document.

### Migrations

Exactly two new migrations in this range, both simple, additive, and fully reversible —
no destructive changes, no data transformation, no backfill script:

1. `2026_08_30_000004_add_matched_contact_at_to_tracked_property_owners_table.php` — adds
   one nullable timestamp column. `down()` drops it cleanly.
2. `2026_08_30_000005_add_mic_counts_cache_window_to_suggested_action_thresholds.php` —
   adds two nullable-with-default small integers. `down()` drops them cleanly.

**Neither is destructive or irreversible.** If either fails halfway through a deploy, the
fix is: run its own `down()` migration to unwind it, or if that's not needed, just re-run
`migrate` — `ADD COLUMN` migrations don't leave partial damage the way a data-rewriting
migration can.

### Config / environment differences that could bite

Since live-testing currently = production, these are the real differences a tester would
hit that Staging testing never surfaces:

- **Mail is real on live/live-testing.** Staging captures all outgoing mail locally and
  never actually sends it. Live sends real email through the real mail server. Any of
  these 28 commits — or anything already on main waiting behind them — that triggers an
  email will send a real one to a real address the first time it's exercised here.
- **WhatsApp (WAHA) is a real, remote session on live/live-testing** (a real phone number's
  session), where Staging points at a local, disconnected instance. Several of tonight's
  changes touch the seller-outreach/prospecting screens, which are WhatsApp-adjacent. Test
  those flows carefully — they are capable of sending a real WhatsApp message to a real
  contact on this environment, unlike on Staging.
- **`WHISTLEBLOW_PPRA_LIVE_SEND=false`** exists only in production's `.env` (Staging has no
  such key at all, because Staging doesn't send real regulator reports). It's correctly set
  to `false` today. One of tonight's commits (`2fd1f039e`) touches the whistleblower report
  modal — worth a specific, deliberate check that this flag is still `false` after deploy
  and that the fix didn't touch the send gate.
- **Session lifetime is much shorter here** (2 hours vs Staging's 8) — testers will be
  logged out sooner than they're used to on Staging. Not a bug, just a surprise to expect.
- **Geocoding and image-service caps/URLs differ** (expected, cost-related) — no action
  needed, just don't be alarmed if usage counters look different.

### What must NOT travel

Scanned the full diff for debug output, TODOs, and hardcoded test values — **found none.**
No `dd()`/`dump()`/`console.log`/`TODO`/`FIXME` left in any of the 28 commits. Nothing
found sitting in code today that shouldn't ship.

The one thing to flag: the current working folder on Staging has a small pile of untracked
scratch files (verification scripts, a not-yet-authorised document-recovery script) sitting
alongside the real code. None of them are wired into the app or would be picked up by a
deploy that pulls from git — they're harmless — but worth a `git status` glance before the
push just to confirm nothing new has landed there since.

### Order of operations

1. Build (or confirm Johan wants to skip building) a genuinely separate live-testing
   environment before doing anything else — see the urgent note above. If he wants to
   proceed treating this as the live deploy directly, that's his call to make explicitly,
   not an assumption.
2. Run the two migrations.
3. Regenerate the schema snapshot if a fresh environment is being stood up
   (`php artisan schema:dump`, then strip DEFINER clauses per the project's own standing
   rule).
4. Clear view/route/config caches, reload PHP-FPM, restart the queue worker (any of tonight's
   28 commits that touch a queued job).
5. Deploy the code.

### Verification checklist — what to actually click

- Open the map, search for an address far outside the current view — it should pan to it,
  or show "no results" if genuinely not found. No duplicate pins on any known dual-listed
  property.
- Open My Claims — remove a contact, immediately re-add them, confirm they stay. Mark a
  claim "Not Selling" and confirm it actually closes.
- Open a deeds-capture record for a sectional-title unit already captured once — re-capture
  it and confirm it does NOT create a second row.
- Open Supplier Directory → Add provider — confirm the address field is there, save, reload
  cold, confirm it held.
- Open the seller-outreach compose screen from a tracked property or a listing — confirm
  the page renders normally (this is the one that was a wall of broken text before the fix).
- Confirm the whistleblower report modal still opens and its send gate is still off.

### Honest risk list

1. **Someone treats this as a safe rehearsal when it's actually live.** Likelihood: high,
   given the domain name implies otherwise. Impact: real data, real customers. Mitigation:
   this document's urgent note — make sure whoever executes this knows it before they start.
2. **A real email or WhatsApp message goes out during "testing."** Likelihood: moderate,
   given multiple prospecting/outreach commits in this batch. Mitigation: test those flows
   last, with a real staff account and full awareness, not as an incidental click.
3. **The schema-dump DEFINER footgun** (documented separately in the project's own
   standing rules) resurfaces if anyone re-dumps the schema without stripping it. Low
   likelihood if the existing checklist is followed, high impact if missed (breaks every
   restricted DB user's test bootstrap).
4. **A tester gets logged out mid-test** on the shorter session lifetime and mistakes it
   for a bug. Low impact, just a "know before you go" item.

---

## PART 2 — e-sign only, QA1 → Staging

### The real e-sign commit set

Went through every commit on QA1 not on Staging (144 total, not the 118 from two nights
ago — QA1 has kept moving) and classified each one properly rather than trusting a tag.
**110 commits are genuinely e-sign** (`fix(esign)` × 79, `feat(esign)` × 21,
`fix(esign-harness)` × 3, `feat(esign-harness)` × 1, `fix(recipient-templates)` × 1 — this
one's about the Late Estate wording, confirmed e-sign despite the different tag, plus
`feat(deal-register,esign)` × 1 — the split of Company Registration Number /
Representative ID, which is dual-tagged because it exists FOR e-sign).

Confirmed present in that set, exactly as named:
- `6d6f369ba` — the canonical-bake signature fix (agent's own signature wasn't being baked
  into the document, so it showed blank to every recipient).
- `d796a5b65` — the late-estate document skipping the final agent-approval gate. This is
  currently QA1's exact branch tip.
- **cc5's domicilium proxy-ordering fix is NOT yet a commit.** It's sitting uncommitted
  right now in `app/Services/Docuperfect/CanonicalDocumentRenderer.php` on the shared QA1
  checkout — live-in-progress work. I did not touch it. It needs to be committed before it
  can travel anywhere, and since it's someone else's work-in-progress, that's cc5's call
  to make when ready, not something to pull mid-edit.

### One thing found that matters directly: QA1 has stopped being e-sign-only again

Since the last audit (two nights ago, tip was `b6c7a1713`), QA1 has picked up **34 more
commits that are NOT e-sign** — interleaved with the e-sign work: 8 more map commits, my
own supplier commit from last night, a whistleblower fix (also independently on Staging,
harmless — same fix, not a real collision), and others. **This means the "QA1 = e-sign
only" assumption from two nights ago is already out of date** — anyone planning this move
needs to re-classify commit by commit at the time they actually do it, not rely on an
old list, mine included.

### Does e-sign cherry-pick cleanly onto Staging?

Tested properly in a disposable scratch worktree (created off Staging, removed afterward —
never touched `/corex-staging` or `/corex-qa1` directly).

**A commit-by-commit cherry-pick of the filtered e-sign list hit conflicts almost
immediately** — but tracing them down, they were an artifact of cherry-picking a filtered
subset out of its natural sequence, not real disagreements with Staging. Proof: I then ran
a genuine full merge test (QA1 into a scratch copy of Staging) and checked file-by-file —
**not one of the true e-sign files showed a real conflict.** Staging has never touched
`ESignWizardController.php`, `SignatureService.php`, `SignatureRequest.php`, or any of the
other core e-sign files since the fork — there's nothing there to collide with.

**Conclusion: the practical way to do this move is a proper merge (or an ordered rebase)
of the real e-sign commit chain, not a manual pick of individual commits out of sequence.**
Cherry-picking them one at a time, in isolation, is more likely to produce false alarms
than the real thing would.

### The real conflicts — and where e-sign genuinely entangles with non-e-sign work

The full merge test surfaced 16 conflicting files. Every one of them traces to either an
already-known duplicate-feature collision, or one specific, important new finding:

**Already known, "Staging wins" already resolves these — no new decision needed:**
- `SuburbReportDataService.php`, `resources/views/corex/market-intelligence/suburb-report.blade.php`,
  `app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php` — the
  suburb-report double-build, already flagged.
- `SellerLinkController.php`, `PropertyIntelligenceService.php`,
  `resources/views/seller-link/live.blade.php`,
  `resources/views/corex/properties/live-preview.blade.php` — the seller-link double-build,
  already flagged.
- `FicaCompletionReportService.php`, `resources/views/compliance/fica/completion-report.blade.php`
  — a THIRD double-build I found tonight, same kind as the two above: FICA's Completion
  Report was independently built on both branches. Same resolution: Staging's stands.
- `PublicLinkUnavailableResponder.php` — a fourth, smaller case of the same thing (a
  dead-end/unavailable page built independently on both sides). Same resolution.
- `database/schema/mysql-schema.sql` — mechanical, expected whenever both branches add
  migrations; fixed by regenerating the snapshot after the real migrations are decided, not
  by hand-merging it.
- `routes/web.php` — only 3 small conflict spots, both sides just adding new routes near
  each other. A five-minute manual pass, not a real design decision.
- `resources/views/deals-v2/suppliers/index.blade.php` — the exact 3-way supplier-file
  entanglement flagged two nights ago (Staging's address, QA1's registration/ID fields,
  QA1's create-time-capture tidy-up). Still open, still Johan's call, unchanged by anything
  found tonight.

**New finding — name this one specifically, because it's not a duplicate feature, it's
e-sign's OWN foundation colliding with Staging's own work:**
- `app/Http/Controllers/CoreX/ContactRepresentativeController.php`, `app/Models/Contact.php`,
  `app/Models/ContactRepresentative.php`, `resources/views/corex/contacts/show.blade.php`.
  The QA1 commit `618cab0a6` — `feat(entity-rep foundation): capacity + proxy on
  contact_representatives + proxy-aware signing/email API` — is genuinely part of e-sign
  (it's what lets a company recipient sign via a nominated proxy representative), even
  though it isn't tagged `esign`. It adds two new columns (`capacity`, `proxy`) to the
  `contact_representatives` table. **Staging's own commit `308314dc0`
  (the representative sort-order arrows, built the same night) added a THIRD new column
  (`sort_order`) to the exact same table, and touches the exact same controller, model, and
  screen.** Neither commit is wrong — they're just two different, unrelated features that
  happened to land on the same small set of files at the same time. This needs the same
  kind of hand-combining as the supplier files, not a pick-one-side resolution, because
  both pieces (the sort order AND the proxy capability) are wanted.

### Options for handling the entanglement, with a recommendation

**Option A — merge the real e-sign branch history, hand-resolve the ~7 known conflict
spots.** Lowest risk of silently losing anything, because git shows every point where both
sides touched something and forces a decision there. Cost: someone spends real time on the
representative-foundation conflict (and the suppliers one, which already needs this
regardless). **This is the recommendation** — the conflict list is short (7 substantive
spots, not the whole codebase) and every one of them is already understood.

**Option B — cherry-pick the e-sign commits as an ordered set, accepting that some of the
mechanical "conflicts" seen during testing will reappear and need trivial resolution.**
Slower and noisier than Option A for no real safety benefit, since the full-merge test
already showed the real content is clean; not recommended.

**Option C — take QA1's version of the shared foundation files wholesale (`Contact.php`,
`ContactRepresentative.php`, etc.) instead of merging, then re-apply Staging's sort-order
feature on top by hand.** Only worth it if the merge conflict in Option A turns out to be
messier once someone is actually inside it; treat as a fallback, not the first plan.

### Migrations for the e-sign move

Have not enumerated every e-sign migration individually (there are many, across 110
commits) — that's a task for whoever executes Option A, at which point `php artisan
migrate --pretend` against a real copy of Staging's schema will show exactly what's new.
What I can say from the file-level conflict check: no e-sign migration collides with a
Staging migration on the same table in a way that would corrupt data — the only
schema-level collision found (`contact_representatives` gaining `sort_order` on one side
and `capacity`/`proxy` on the other) is an **additive, same-table, different-column**
situation on both sides — safe to reconcile, not a destructive collision.

### Verification checklist

- Full e-sign flow end to end, including a natural-person late-estate case specifically
  (that's exactly what `d796a5b65`, `b6c7a1713`, and the recipient-template wording fix
  were all fixing).
- A company/entity signer using a proxy representative — confirm both the proxy capability
  (from e-sign's foundation commit) and the sort-order arrows (from Staging's commit) work
  together once the `contact_representatives` conflict is resolved.
- The supplier form, once its own decision is made — all three pieces present together.
- An executor pulled from the supplier directory, carrying a registration number, onto a
  real document — this is the actual end-to-end case Johan hit the address gap on.

### Risks specific to this move

1. **The representative-foundation conflict gets resolved by picking one side**, and either
   the proxy-signing capability or the sort-order arrows quietly disappears. Mitigation:
   whoever resolves it tests both features afterward, not just confirms the file compiles.
2. **cc5's in-progress domicilium fix gets swept up or lost** if this move happens before
   it's committed. Mitigation: confirm with cc5 it's committed before starting.
3. **The QA1 commit list is stale by the time this actually runs** — it was already stale
   between two nights ago and tonight. Mitigation: re-run the classification at execution
   time, don't reuse tonight's list verbatim.

---

## Appendix — full 28-commit list, Staging not yet on live-testing/live

```
7e88f934a  fix(map): portal stock already matched to our own listing no longer draws a second pin
a806ebc02  fix(mic): remove-then-re-add a contact could silently revert — poll/mutation race
0b5bf3bda  feat(deeds): flag owners already matched to a contact by ID, enrich phone/email
8bb5b678e  perf(map): cache OnMarketStockService::identitySets() across requests
1407ef455  feat(suppliers): add firm-level business address, capturable + editable
1fb4e3cf1  docs(map): make identity-cache TTL an explicit backstop, not a mechanism
7f31e3197  fix(map): wrap the layer toggle row so all 8 buttons stay reachable
c8dd0385a  fix(prospecting): sectional-title re-capture with no erf number created a duplicate property
53fdd6d7d  feat(map): Pitch Now directly from a sectional scheme unit
a5d7411fe  fix(seller-outreach): stray double-quote inside x-data broke the whole compose screen
69ab59a20  fix(map): fold a tracked-property pin into its own agency-stock pin
2fd1f039e  fix(properties): whistleblower report modal referenced wbReportOpen outside its x-data scope
5ebdc9487  fix(prospecting): stop stamping Pitched before the pitch is complete; deed link updates address on every fresh visit
f296e3150  fix(prospecting): Continue no longer blocks on a deed-supplied address
1cbf78de8  fix(map): badge counts diverged from drawn pins for the M layer
e1be0a345  fix(map): a tracked property with a live Portal Stock listing drew a duplicate T-pin
49e554c55  fix(mic): Work/My Claims list shows the linked property's address, not the blank portal field
3a91afd75  fix(map): Portal-vs-Tracked fold + badge merge, corrected guard
f0f835150  fix(mic): decouple the address-display backfill from the shared listing object
1beadf292  fix(map): layer row still wrapped to 2 lines after the Tracked badge was removed; LAYER_NAMES still said "Tracked"
de21d9969  fix(map): layer row gap was a 7px (3%) knife-edge fit, not genuinely robust -- widen the margin
6d2925070  fix(mic): My Claims stops badging an incomplete pitch's own property as company stock
2c6e79a29  fix(mic,properties): Not Selling closes the claim; stop badging never-real stock
1f90974be  fix(mic): "Show in-stock too" no longer discards the active search
d2808580a  fix(mic): My Claims stops hiding claims the agent already holds by branch scope
cf5ed4e1d  fix(mic): claim-centric presets survive lapsed listings; tile counts invalidate on claim change
```
(3 merge commits omitted — no independent content.)
