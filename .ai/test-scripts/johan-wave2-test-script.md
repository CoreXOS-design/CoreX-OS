# Johan — Wave 2 walk-through (qa1, 16:00)

**Env:** qatesting1 · log in as yourself (super_admin). Deals you capture here land in **agency 1 (HFC)** — confirmed. All refs below are **live qa1 records**.

**Test property (clean — no existing deals):** **#5945 — 9 Evans Avenue, Trafalgar** (active, R1,290,000).
*If you re-run and it's stuck under-offer, just capture against another clean one: #5949 (2 Cunningham Ave), #5946 (8 Beatty Dr), #5945.*

---

## 0. Flip the switches (once)
Open **Settings → Deal → Property Sync**
`https://qatesting1.corexos.co.za/admin/settings/deal-property-sync`
- **Flag property Under-Offer on deal** → **ON**
- **Revert property on deal declined** → **ON** (leave as-is; it's the default)
- **Sold milestone** → leave **OFF/blank** for this walk (so a granted property stays *Under-Offer* and you can watch the cascade + revert; set it to *Granted* later if you want to see it flip to *Sold*).
Save.

---

## 1. Four offers = four pending deals
Go to **Deal Register (DR2) → Add Deal** (`/deals-dr2/create`). Capture **4 deals**, each linked to **9 Evans Avenue (#5945)** via the property search. Give each a different buyer (search or add-new), any commission, **Deal Status = Pending (P)**. Save each.
→ Open **9 Evans Avenue** in Properties (new tab): its status is now **Under-Offer** (the first capture flipped it; it remembers "active" underneath).

## 2. Grant ONE → three auto-decline
On **Deal Register**, find your 4 deals on 9 Evans Ave. On **deal #1**, set the status dropdown to **Granted (G)** (or open it, Deal Status → G, save).
→ Refresh the register: **deals #2, #3, #4 are now Declined (D)** — automatically.
→ Open any of them → **Log/History**: *"Auto-declined: deal #<granted-no> was granted on this property."*
→ 9 Evans Avenue is still **Under-Offer** (one granted deal keeps it live).

## 3. Granted deal falls through → re-grant another
On the **granted** deal (#1), set status → **Declined (D)**.
→ 9 Evans Avenue **reverts to Active** (no active deal left).
Now take **deal #2** (auto-declined earlier) and set it → **Granted (G)**.
→ It grants cleanly (declined → granted is allowed), and 9 Evans Avenue goes **Under-Offer** again.

## 4. Try to grant a SECOND while one is granted → block modal
With **#2 still Granted**, take **#3** and try to set it → **Granted (G)**.
→ **Blocked.** A modal: *"This deal may only be set to Declined — deal #<2's no> already carries a Granted status on this property."*
→ The deal number is a **link → opens deal #2 in a new tab** (resolve it there — e.g. decline it).
→ Your current screen **keeps everything you entered**. Decline #2 in the other tab, come back, and #3 will grant.

## 5. Resale / duplicate-address search guard
New deal → **Add Deal** → in the property search type **`Kinderstrand Road`**.
→ By default you see the **live** record: **#5860 — 1 Kinderstrand Road, Glenmore (Under-Offer)** with a green status badge + listed date. The **sold/withdrawn twin is hidden**.
Tick **"Show sold/archived too"** under the search box.
→ Now the twin appears: **#5062 — 1 Kinderstrand Road (Withdrawn)** with a red off-market badge.
Click the **withdrawn #5062**.
→ **Hard warn:** *"this property record is withdrawn — deals on it will not update statuses; did you mean the active listing at this address?"* Cancel keeps you searching; OK links it anyway.
*(For a "sold on <date>" warn specifically, toggle Show-all on a Shelly Beach search and pick a Sold record.)*

---

**What "green" looks like:** under-offer on first capture · 3 auto-declines with audit on grant · revert on fall-through · clean re-grant · block modal (clickable, data kept) on the 2nd grant · sold/withdrawn twins hidden by default + warned on select.

**Stop-and-fix seat is live** — call any miss and I take it straight to qa1.
