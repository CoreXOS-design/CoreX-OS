# Proposal: PROSPECTING status — separate ingested stock from agent-drafted stock

**For Johan — 2026-08-20. Capture only. Nothing built. Written to record the
design so it isn't lost before there's time to act on it.**

---

## 1. The complaint that prompted this

Elize: everything ingested from Deeds and MIC currently lands in `DRAFT`.
To her, "draft" means stock about to go live — a listing an agent is
actively working on and about to publish. Ingested prospecting stock
dilutes that meaning inside the same status, and it gets worse every day
as ingestion volume grows. Today `draft` is one of `Property`'s
`INACTIVE_STATUSES` (`app/Models/Property.php`) — it is a real, load-bearing
status other code already reasons about, not a cosmetic label.

## 2. Johan's design, verbatim

> "the flow - agent creates property and the status is draft.
> we import from deeds it goes to prospecting. the change from prospecting
> to draft happens manually by an agent whey the won the mandate etc. they
> will change it out of prospecting to drafts.
> Not now, but when we have a second to breath - I would even go as far as
> adding a button for the agents - and that might require a 2nd status -
> not on market / not won / something down that lines - contacted owner,
> not on market, dont want to sell etc and click the button and it changes
> the status from prospecting to ? lost / not getting."

## 3. The flow, summarised

| Event | Status | Trigger |
|---|---|---|
| Agent creates a property manually | `draft` | unchanged from today |
| Deeds / MIC ingestion | `prospecting` **(new)** | automatic, on ingest |
| Mandate won | `draft` | **manual**, by an agent, at a real business moment |
| Owner contacted, not selling / not on market / lost | dead-end status, name TBD **(new, phase 2)** | button click, by an agent |

- `prospecting` is a new status, surfaced as a new tile at the top of the
  Properties screen — a visible pool, not a hidden state.
- `prospecting → draft` is never automatic. It happens when an agent wins
  the mandate — the same moment that already exists in the current
  workflow, just now also flipping the status.
- The dead-end status (phase 2, not yet designed — name candidates:
  `lost`, `not_getting`) closes out prospecting stock that will never
  convert, via a one-click button. Not built now; recorded so the shape
  is agreed before it's needed.

## 4. Open risks — these matter more than the happy path

**1. The existing pile.** Deeds/MIC properties already sitting in `draft`
today need reclassifying to `prospecting`. They must be identified by
**origin** (how the record was created — deeds import / MIC ingestion),
not by guesswork or heuristics on the data itself. The resulting list gets
reviewed before anything moves — no bulk reclassification runs unseen.

**2. What else reads "draft".** Every count, report, export, and — above
all — **portal syndication** currently treats `draft` as one undifferentiated
bucket. A `prospecting` property must **never** be able to reach a portal;
it is not the agency's stock, it doesn't have a mandate. This is the one
failure mode that could embarrass Johan publicly, and it must be tested
explicitly, not assumed safe because "prospecting is a new status so old
code won't touch it" — anywhere existing code treats non-`draft` /
non-terminal statuses as syndicatable-by-default needs an explicit audit.

**3. The transition has to stay easy, or the fix just moves the problem.**
If `prospecting → draft` is not explicit and low-friction for an agent to
do at the moment they win a mandate, prospecting stock piles up instead of
converting or dying, and the dilution problem Elize raised has just moved
from `draft` to `prospecting`. This is why Johan raised the dead-end button
in the same breath as the happy path — it's what keeps the prospecting
pool clearable, not just a nice-to-have for later.

## 5. Explicitly not decided yet

- The dead-end status's real name and exact trigger UX.
- Whether `prospecting → draft` needs a confirmation step/reason, or is a
  bare status flip.
- Exact mechanism for reclassifying the existing pile (manual list review
  vs. a guided migration screen).
