# DR2 Pipeline — authoritative view spec

Two views only: TIMELINE and LIST. They MUST be visually distinct. The failure to avoid: making both look like vertical lists (that happened and Johan rejected it).

## TIMELINE view = a real time/date-based timeline ("the timeline we had, fixed")
- Horizontal: a date axis along the top; each step is a TILE positioned by start date, width = its duration (work starts and ends at set points).
- Overlapping steps AUTO-STACK into separate rows so tiles NEVER overlap and labels never collide; the canvas scrolls.
- Behind the tiles: phase bands (Suspensive Conditions / Transfer & Registration); milestone gate diamonds; a red TODAY line.
- Each tile keeps the full action set (Complete/Reopen, Edit dates, Sequence, N/A, Remove, Comments).
- Reference mockup: .ai/mockups/dr2_timeline_horizontal.html — FIX the overlap; do NOT replace it with a vertical list.

## LIST view = vertical sectioned cards grouped by stage (the phased layout — correct FOR THE LIST)
- Top-to-bottom: Deal Signed anchor -> Stage 1 "Suspensive Conditions" (grouped tracks: Bond/Cash/Sale/FICA) -> GRANTED gate -> Stage 2 "Transfer & Registration".
- Each step = full-width card: dot, name, star, dates, status, "Waiting on..." note, full action grid; grab-to-reorder (display only).
- Stage 2 dimmed/locked until the deal is granted.
- Reference mockup: .ai/mockups/dr2_list_phased.html

## Shared by both views
- Deal-context tabs on TOP (Structure, Work Orders, Documents, Parties, Proforma): collapsible, default collapsed. Each expanded panel bounded to ~min(48vh,460px) and scrolls INTERNALLY — never pushes content off-screen (Johan's rule: "define a set area, inside it it scrolls").
- Comments footer that posts without error (the $days 500 is fixed; keep it fixed).
- No 500s. Prove BOTH views with real-browser screenshots compared to the two reference mockups before calling anything done. Real DR2 data (dr1_deal_id). QA1 only.

## What went wrong before (do not repeat)
The Timeline was rebuilt as the vertical sectioned layout, so Timeline and List both looked like lists. Timeline = horizontal date-based; List = vertical sectioned cards.
