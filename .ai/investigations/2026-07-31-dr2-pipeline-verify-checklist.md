# DR2 Pipeline — verify checklist for Johan (QA1, all lanes, one pass)

**Date:** 2026-07-31
**Env:** QA1 (`/corex-qa1`) · agency 1 (Home Finders Coastal)
**Purpose:** verify in ONE pass everything shipped to QA1 across lanes — each item has the exact
deal to open, where to click, and what "correct" looks like. Documentation only; no code changed
to produce this. Item owners noted so a miss routes to the right lane.

---

## How to open a deal's pipeline
- Register: **DR2 Register** (`/deals-dr2`) → click a deal → **Pipeline**, OR
- Direct: `…/deals-dr2/{ID}/pipeline/list` (List) · `…/deals-dr2/{ID}/pipeline/timeline` (Timeline).
- Every deal below opens on both **List** and **Timeline** — the top-right **Timeline | List** toggle
  switches between them (your choice is remembered per browser).

## QA1 test decks used here (real state at time of writing)
| Deal | No.  | Status | Model | Conditions | Steps | Done | Notes |
|------|------|--------|-------|------------|-------|------|-------|
| **190** | 1820 | **Granted (G)** | composable | bond | 14 | 8 | grant marker COMPLETED — granted lifecycle + Due/Done |
| **185** | 1817 | Pending (P) | composable | bond | 14 | 2 | grant not yet reached |
| **184** | 1816 | Pending (P) | composable | bond | 14 | 1 | |
| **183** | 1815 | Pending (P) | composable | bond + cash | 18 | 5 | multi-condition |
| **168** | 1811 | Pending (P) | composable | bond + cash + sale-of-another | 19 | 1 | three conditions |
| **152** | 1801 | Pending (P) | **old-model (template)** | — | 12 | 0 | template-built deal |
| **207** | 1824 | **Declined (D)** | composable | bond **+ deposit → Bond Grant** | 16 | 5 | declined state + deposit anchored to bond grant |
| **156** | 1805 | **Granted (G)** | old-model | — | 19 | 11 | granted, template |

> **Status codes** (the badge the register reads): **P** = provisional/pending · **G** = granted
> (unconditional) · **R** = registered · **D** = declined.

---

## 1 · Add a custom step — TIMELINE  *(lane: cc5)*
- **Deal:** 185 (`/deals-dr2/185/pipeline/timeline`).
- **Click:** below the timeline canvas, click **“+ Add custom step”**. In the form: enter a **Step
  name**; optionally **Link to step** (what it follows) → then choose **“+N days after the linked
  step completes”** (relative) or **“On a fixed date”**; click **Add step**.
- **Correct:** page reloads and the new step appears in the pipeline — if linked+relative, dated N
  days after its link; if fixed, on the date you set; if standalone, at the end. (Same control/route
  as the List — parity restored after the rebuild.)
- Cross-check on **List** (`/pipeline/list`): the same **“+ Add custom step”** sits at the foot of
  the phased column and behaves identically.

## 2 · Decline a deal — PIPELINE HEADER  *(lane: cc6)*
- **Deal:** 185 (a live Pending deal — do this last, or on a throwaway, since it locks the pipeline).
- **List** click-path: the **“Decline deal”** button sits in the **header**, next to the
  **Timeline | List** toggle / **← DR2 Register**. Click it → confirm the prompt.
- **Timeline** click-path: expand **Deal panels**, and **“Decline deal”** is at the top-right of that
  panel (above the Structure/Work-Orders/… tabs, alongside where Removed-steps sits).
- **Correct:** deal drops to **Declined**; the whole pipeline goes **read-only** (actions withdrawn);
  the header/panel now shows **“● Declined”** (List) / **“● Deal Declined — re-grant from the
  register”** (Timeline). It stays **re-grantable from the register**.
- **Already-declined example:** open **207** — you should see the Declined indicator and a locked,
  greyed pipeline (no decline button, since it's already D).

## 3 · Restore a removed step + Hide completed — LIST **and** TIMELINE  *(lane: cc6)*
### 3a · Remove → Restore (no more one-way data loss)
- **Deal:** 184 (`/pipeline/list`).
- **Remove:** on any not-terminal step tile, open its actions and click **Remove** (confirm) — the
  step is **archived, not deleted**.
- **Restore (List):** at the **foot of the phased step column**, a **“Removed steps (N)”** chip
  appears → click to expand → click **Restore** next to the step.
- **Restore (Timeline):** same deal → `/pipeline/timeline` → expand **Deal panels** → the
  **“Removed steps (N)”** chip sits just under the Decline control → expand → **Restore**.
- **Correct:** the step **reappears in its original position** in the pipeline, and the
  “Removed steps” chip disappears once nothing is archived. (Verified: restore returns the step LIVE
  at its exact prior position.)

### 3b · Hide completed  *(List only — the Timeline canvas is cc5's and out of scope)*
- **Deal:** 190 (8 completed steps) → `/pipeline/list`.
- **Click:** in the **Sort** bar (top of the step column), tick **“Hide completed (8)”**.
- **Correct:** completed steps collapse out of every phase group; untick to show them again. The
  choice is remembered per browser. The toggle only appears when the deal HAS completed steps (open
  a fresh deal with 0 done, e.g. 152 — the toggle is absent).

## 4 · Deposit anchor — Deal Signed +N / fixed date / Bond Grant +N (stays suspensive)  *(lane: cc6/cc-structure)*
- **See the picker:** the deposit-anchor control lives in the **Deal Structure** empty-state — it
  shows only on a deal whose pipeline is **not yet built**. Capture a fresh test deal (or use one
  with no pipeline) → **Deal Structure** tab (right panel on List / “Deal panels” on Timeline) →
  tick **Bond** → tick **“Include a deposit step”** → the **“Deposit due”** selector offers:
  - **“Deal Signed +”** N days,
  - **“Bond Approved (bond grant) +”** N days,
  - **“a fixed date”**.
  Below it: *“Still a suspensive condition — if this lands after Bond Approved it becomes the deal's
  Granted date.”*
- **Correct (setting):** picking **Bond Approved + N** dates the Deposit step off the **bond-grant**
  date (not the signed date); **fixed** pins an exact date; **Deal Signed + N** offsets from signing.
  In every case the deposit **remains a suspensive condition** (it feeds the grant gate).
- **See a built example:** open **207** → **Deal Structure** tab — its built conditions show
  **Bond · with deposit**, and the deposit was anchored to **Bond Grant** (its Deposit step is dated
  off the bond-grant date in the pipeline).

## 5 · Granted → Registered status lifecycle  *(lane: cc-deal-model)*
The pipeline drives the deal's status (forward-only, never downgrades): completing a step configured
`granted` → **G**, `registered`/`completed` → **R**, decline → **D**.
- **Pending → Granted:** open **183** (P, bond+cash) → complete the **suspensive conditions** (the
  bond-grant + cash steps up to the gate). When the last condition is met, the **Granted gate bar**
  ("Deal becomes unconditional once every condition is met") completes and the deal status flips to
  **Granted (G)** — the register badge updates and `granted_at` is stamped.
- **Already-granted example:** **190** (G) — the Granted gate is completed and the deal reads
  **Granted**; downstream Stage-2 (transfer/registration) steps are now active.
- **Granted → Registered:** on a granted deal, complete the **registration** step (Stage 2, the one
  carrying the `registered` trigger) → deal flips to **Registered (R)**, `registration_date` stamped.
- **Correct:** status only ever advances P→G→R; a late-completed earlier step never downgrades a
  Registered deal. Declining from the header (item 2) sets **D** regardless.

## 6 · “Due X · Done Y” actual-completion tiles  *(lanes: cc5 timeline / cc6 list)*
- **Deal:** 190 (8 done) — best sample of completed vs open tiles.
- **Timeline** (`/pipeline/timeline`): each tile's subtitle reads **“Due {due date} · Done {actual
  date}”** — the projected due AND the real completion date side by side. Not-yet-done tiles show the
  due only; the legend swatch marks **Done**.
- **List** (`/pipeline/list`): a completed step shows a green **✓ {actual completion date}** in place
  of its due date, plus a **Completed** badge and a light strikethrough on the name; open steps show
  their **due date** + status badge.
- **Correct:** the date shown for a done step is its **actual** completion date (back-datable at
  Complete time), not the original due — and the downstream dates re-cascade off that actual date.

---

### One-pass suggested order (least destructive first)
1. **190** — items 3b (hide completed), 5 (granted state), 6 (Due·Done tiles).
2. **183** — item 5 (drive Pending→Granted by completing conditions).
3. **184** — item 3a (remove → restore on List, then Timeline).
4. **185** — item 1 (add custom step, Timeline + List), then item 2 (decline — locks it, do last).
5. **207** — item 2 (declined view), item 4 (built deposit-anchor = Bond Grant).
6. **fresh capture** — item 4 (the deposit-anchor picker in the empty Deal Structure).

*(If any item doesn't match "correct", note the deal number + view + what you saw — the owning lane
is tagged on each item.)*
