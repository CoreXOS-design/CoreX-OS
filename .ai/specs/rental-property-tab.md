# Rental Property Tab — Advertising Text-Block (PARKED, 2026-09-13)

> **Status: parked by Johan.** This is a captured idea, not a build in
> progress and not an approved design. Nothing described here is built.
> No spec-first sign-off has happened. Do not start work from this file
> without a fresh, explicit go-ahead from Johan — treat everything below
> as "recorded so it isn't lost," not as a backlog item ready to pick up.

## Why this file, not `.ai/specs/rental-applications.md`

This idea is about a **Property's own rental-listing configuration and
advertising output** — the fields an agent fills in once about a rental
listing (deposit, admin fee, utilities, etc.) and how those feed the
portal advert's description text. It has nothing to do with processing a
tenant's *application* (the subject of `rental-applications.md`, already
a very large file). Pillar-wise this idea sits on **Property** (the
listing and its advertised description), with **Agent** as the one
filling it in — it is not part of the Deal/tenant-application flow at
all. Keeping it in its own file makes it findable later by someone
picking up "the portal-ad text block idea" without wading through an
unrelated module's spec.

## The idea, in Johan's own words (2026-09-13)

> Theres a couple of bits of info from the rental screen that I want to
> specifically use to build the portal ads but we can look at that later
> as we essentially going to use some of the info from rental screen to
> include in the ads - things like - deposit / lets assist / admin fee /
> utilities (included / excluded), etc. the idea Im playing with is if
> the agent completes these on the rental tab we build ... this is the
> information agents add manually to their ads. so if we hold all of
> this on the rental tab then we can have a tick on each on the rental
> tab that says - include in advertising. Now its as simple as building
> a text block at the end of the description field on properties where
> this information is displayed neat and tidy - and the massive plus
> side is that the agent dont have to capture it manually on advertising
> and cant forget the information that needs to be included

He explicitly parked it as later work — this file exists so the detail
he gave while it was fresh survives until he picks it back up, not so
anyone starts building it.

## The worked example (real HFC advert, captured exactly)

Source: <https://hfcoastal.co.za/property/stunning-little-shop-to-rent-in-port-edward-6135>

> Upfront costs: R4710 Deposit R4710 One Month's rental R1500 Once off
> admin fee R1500 Utility deposit. Exclude utilities which will be
> billed separately from the rental per month.

This is the shape of output Johan wants a generated block to produce —
several cost lines followed by a plain-language utilities note. Today an
agent types this by hand into the property description, in whatever
wording and order they choose. Note this example doesn't literally spell
out "lets assist" as its own line — check with Johan whether that's
because this particular listing has none, or because it's expressed
differently (e.g. folded into the deposit figure) before assuming the
fields below are exhaustive.

## The fields it implies

Read directly out of the worked example and Johan's own list, on the
Property's rental tab:

- **Deposit** — a Rand amount (the example shows deposit == one month's
  rental exactly; confirm with Johan whether that's a coincidence for
  this listing or an implied default/relationship between the two
  fields).
- **One month's rental** — a Rand amount (likely already exists
  elsewhere as the listing's own advertised rental price — confirm
  whether this is a new field or a reference to an existing one before
  assuming it needs its own column).
- **Once-off admin fee** — a Rand amount.
- **Utility deposit** — a Rand amount.
- **Let's assist** — named by Johan in his list of things to pull onto
  the rental tab; not present in the one worked example captured here,
  so its own display wording/format is not yet known from a real
  example.
- **Utilities included or excluded** — a state (included / excluded),
  with the excluded case carrying explanatory prose in the worked
  example ("which will be billed separately from the rental per
  month") — worth checking with Johan whether that sentence is
  boilerplate that's the same every time, or something an agent would
  need to vary per listing.

## The per-field "include in advertising" flag

Johan's own mechanism: **each field above gets its own tickbox** on the
rental tab, "include in advertising." Only ticked fields contribute a
line to the generated block — an agent who, say, doesn't want the admin
fee advertised (or has none for this listing) simply doesn't tick it,
and it's absent from the block, not shown as R0 or blank.

## The generated block

The ticked fields, "displayed neat and tidy," get assembled into a text
block appended to the end of the property's own description field —
matching the shape of the worked example (cost lines, then a plain-
language utilities line if excluded).

## The two benefits Johan named — this is the actual case for building it

1. **The agent doesn't capture it manually.** Today this is retyped by
   hand into every advert's description, from information the agent
   already has (or should have) on file. Holding it once, structured,
   removes that duplicate manual step.
2. **The agent can't forget information that needs to be included.**
   Manually-typed adverts are exactly the kind of thing that varies
   listing to listing depending on what the agent remembers to type —
   a structured, ticked field can't be silently omitted the way a
   free-text line can.

## Open questions — named, not answered (Johan's call, not ours)

### 1. Generated at render time, or written into the description field?

**Written-in**: the block is composed once and saved as literal text
inside the property's `description` field. An agent can then edit the
wording afterward (fix a typo, add a listing-specific caveat) — but the
moment the source fields (deposit, admin fee, etc.) change, the written-
in text no longer reflects them and nobody is told it's stale. It has
drifted from its source and nothing detects that.

**Generated at render time**: the block is computed fresh from the
current field values every time the description is displayed/exported,
so it is always accurate — but an agent has no way to tweak its exact
wording for one specific listing; whatever the template produces is what
ships, verbatim, every time.

This is a real trade-off between "always correct" and "editable," not a
technical detail — it's Johan's call.

### 2. What happens to adverts that already have this typed in by hand?

This is exactly what agents do today, for every current rental listing —
so there will be many existing property descriptions that already
contain hand-typed versions of this same information, in whatever
wording each agent chose. If this feature ships and a generated (or
written-in) block gets added on top of an existing hand-typed block,
the live portal advert would show the same cost information twice, in
two different wordings, at once. **Duplicated text on a live portal
advert is worse than no feature at all.** Whether/how existing
descriptions get identified, cleaned up, or migrated before this ships
is unanswered here on purpose.

### 3. Do portal feeds take the description field verbatim?

Checked directly, not assumed: `Property24ListingMapper.php:51` sends
`'description' => $property->description ?? ''` — the P24 feed reads the
raw `description` field's current value straight through, with no
separate hook for a computed/generated block.

If the eventual design generates the block at render time only (Open
Question 1's second option) rather than writing it into the stored
`description` field, and a portal feed reads the raw stored field the
way P24's mapper does today, **the generated block would never reach
the actual portal advert** — it would only ever show on CoreX's own
property page, which defeats the entire stated purpose (this is
specifically meant to appear on the portal ad, per Johan's own worked
example being a real live HFC portal listing). Whether every portal
integration reads the same raw field the same way, and what that implies
for the answer to Open Question 1, is not resolved here — named as the
risk it is, not designed around.
