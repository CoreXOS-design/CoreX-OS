# DR2 Deal Pipeline — Composable Suspensive-Condition Model
Deal status (small): Pending (deal signed) → Granted (all active suspensive conditions satisfied / finance secured) → Declined (a condition fails) → Registered (transfer at deeds office). Status is pipeline-driven, not hand-set (except Declined).

Suspensive conditions = the core. A deal is a SET of peer conditions, each pending → met/failed/waived:
- Cash — "how many payments?" (default 1); each payment is its OWN step with its own Due/Actual. Model as: "Proof of funds" (the condition → grants the deal, early) + one or more "Payment received" financial steps (can sit late, e.g. deeds office).
- Bond — the bond track; optional "Deposit" tick injects a deposit step.
- Sale of another property — contingent subject-to-sale milestone IN this deal (does not link to a second deal).
Combos: bond=1 condition; subject-to=bond+sale; cash-2-payments=1 condition/2 steps.

Deal Structure tab (right panel): empty-state → "Complete the deal structure to build your pipeline"; right opens on Deal Structure → pick conditions+options → save → pipeline assembles on the left. Same tab re-opened later by the Restructure button.

Pipeline assembly = common/base steps (from pipeline setup) + steps from each active condition. Steps carry: order, follows(predecessor), offset, is_milestone, and a special GRANTED marker step.

Dates — two per step WITH COLUMN HEADINGS (missing now): Due + Actual. Cascade: step.Due = (predecessor.Actual if captured, else predecessor.Due) + step.offset. Anchor = the deal date captured on the Deal Register, which auto-completes the first step (Deal Signed) — no re-capture. Completing a step early/late re-baselines all downstream Dues via the cascade.

"Follows" selector: every step declares which step it follows. Standard chain defined once in pipeline SETUP ("std"); a per-deal dropdown repoints a step's predecessor for oddballs (e.g. "Cash Payment follows → Lodged at Deeds Office"); dates re-cascade from wherever pointed.

"Granted" step: Granted is its OWN step; its follows-position determines when the deal flips Pending→Granted, per deal (default position in setup, movable per deal). Replaces a "marks granted" tick.

Milestones: formalise grant/decline points (Bond Granted, Property Sold, Cash Proof-of-funds, Cash Payment Received, Granted, Registered/Transfer), highlighted (bold + ◆). Milestones carry the triggers.

Restructure ("Restructure deal" button, NOT freeform editing): re-opens Deal Structure → change active conditions with a MANDATORY reason + addendum reference → pipeline recomposes: completed steps stay; a removed condition's steps become "Waived (addendum)" — greyed but still visible for audit, never deleted; new steps drop in; dates re-cascade.

Triggers hang off condition-outcomes + milestones, NEVER a hard-coded step. "Advance the deal" fires on "finance secured" = all active conditions met/waived (whichever the deal currently has), so restructure never orphans a trigger.

Overdue flagging on DR2 screen + My Deals (RAG on pipeline = nice-to-have; calendar + reports later, same date model).

Related quick win (independent): Email Parties inline email-capture MODAL — on a "no email on file" party row, "Add email" opens a modal → saves straight to the contact → closes → row flips to "Send to <party>". No navigation.

Pattern: sensible defaults from pipeline setup + three surgical per-deal overrides — restructure conditions · repoint a step's follows · move the Granted marker.

Locked decisions: reason+addendum on waive/fail = yes; removed steps greyed-but-visible = yes; statuses Pending→Granted/Declined→Registered = yes; two dates Due/Actual with the follows cascade = yes; anchor=Deal Register date = yes; cash = proof-of-funds(grants)+payment(s)(can be late) = proposed default.
