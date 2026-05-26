# 06 — Agent Buyer Workflow

**Who is this for:** Agents working with buyers — from first enquiry through to deal closure
**What you'll learn:** Capturing buyers, FICA, buyer matching, viewings, pipeline, creating deals, and settlement
**Prerequisites:** Active agent account with Day One setup complete (Guide 04).

---

## 1. Capturing a Buyer

**Where:** Real Estate > Contacts

1. Go to **Real Estate > Contacts** in the sidebar.
2. Click **"Add Contact"**.
3. Fill in: First Name, Last Name, Phone, Email, ID Number.
4. Set **Contact Type** to **"Buyer"**.
5. Click **"Save"**.

> **Success:** The contact profile page opens. The buyer is now in the system and visible in the Buyer Pipeline.

[SCREENSHOT: Contact form with Buyer selected as type]

---

## 2. Doing FICA on the Buyer

The process is the same as for sellers (see Guide 05, section 2), but applied to the buyer contact.

1. Go to **Compliance > FICA**.
2. Click **"Send Online FICA"**.
3. Select the buyer contact.
4. Click **"Send FICA Request"**.
5. The buyer completes the form via email link.
6. You review and approve as agent, then the CO does the final approval.

> **Before creating an offer:** The buyer's FICA must be approved. This isn't enforced by the system for deal creation, but it is a legal requirement.

---

## 3. Creating Buyer Match Criteria (Core Matches)

**Where:** Contact page > Core Matches tab, or Real Estate > Core Matches

This tells CoreX what the buyer is looking for, so the system can automatically match them with properties.

1. Go to the buyer's contact page.
2. Click the **Core Matches** tab.
3. Fill in the match criteria:
   - **Listing Type** (required) — Sale or Rental
   - **Property Type** — House, Townhouse, Apartment, etc.
   - **Price Range** — Minimum and Maximum budget
   - **Bedrooms** (minimum), Bathrooms (minimum), Garages (minimum)
   - **Suburbs** — one or more target areas
   - **Floor Size / ERF Size** ranges
   - **Must-Have Features** — non-negotiable requirements
   - **Nice-to-Have Features** — preferred but not essential
   - **Pool / Furnished / Pets** — Yes, No, or Any
   - **Notes** — anything else relevant
4. Click **"Save"**.

> **Success:** CoreX immediately runs the matching engine and shows results — a list of properties that match the criteria, each with a match score.

[SCREENSHOT: Core Matches criteria form on a buyer contact page]

---

## 4. Reviewing Matched Properties

1. After saving the match, you're taken to the **Results** page.
2. Properties are listed with their match score (higher = better match).
3. For each property you can:
   - **View** — open the full property page
   - **Hide** — remove it from this buyer's results (if not relevant)
   - **Convert to Deal** — when the buyer is ready to make an offer

You can also share matches with the buyer via WhatsApp.

[SCREENSHOT: Match results page showing scored properties with action buttons]

---

## 5. Setting Up Viewings

**Where:** Dashboard > Calendar

1. Go to **Dashboard > Calendar**.
2. Click on a time slot or click **"New Event"**.
3. Set **Event Class** to **"Viewing"**.
4. Link the **Property** you're showing.
5. Add the **Buyer** as an attendee (search by name).
6. Set date, time, and reminders.
7. Click **"Save"**.

The buyer and property owner receive calendar invitations (if they have email addresses).

---

## 6. The Buyer Pipeline

**Where:** Dashboard > Buyer Pipeline

The Buyer Pipeline gives you a visual board of all your buyers and where they are in the process.

1. Go to **Dashboard > Buyer Pipeline** in the sidebar.
2. You'll see a Kanban board with columns: **New**, **Warm**, **Cold**, **Lost**.
3. Drag buyer cards between columns as their status changes.
4. Click on a buyer card to see their detail page with:
   - Their search criteria
   - Matched properties
   - Viewing history and feedback
   - Playbook actions (next steps to take)
   - Preferences

[SCREENSHOT: Buyer Pipeline Kanban board showing buyers in different stages]

---

## 7. Capturing Buyer Feedback

After every viewing, capture the buyer's feedback:

1. Go to the viewing event on your **Calendar** (past events).
2. Click the event.
3. Click **"Capture Feedback"**.
4. Record: outcome, interest level, concerns, notes.
5. Submit.

This feedback is visible on:
- The buyer's contact page
- The property's history
- The Buyer Pipeline detail page

> **If you forget:** The system creates a follow-up task to remind you. You'll see it on your Today page.

---

## 8. Converting a Match to a Deal

When a buyer wants to make an offer:

1. Go to the buyer's **Core Matches** results.
2. Find the property they want.
3. Click **"Convert to Deal"**.
4. This creates a new deal with the buyer pre-filled.
5. You'll be redirected to the Deal creation wizard to complete the details.

---

## 9. Creating the Offer — Deal V2 (5-Step Wizard)

**Where:** Admin > Deals > New Deal

1. **Step 1 — Property:** Search for the property by address. Select it. Click **"Next"**.

2. **Step 2 — Contacts:** Add all parties:
   - **Sellers** — the property owner(s). If linked on the property, they auto-load.
   - **Buyers** — your buyer contact. Add co-buyers if applicable.
   - **Other Parties** — conveyancer, bond originator (add later if not known yet).
   Click **"Next"**.

3. **Step 3 — Details:**
   - **Deal Type** — Bond, Cash, or Sale of Second Property
   - **Purchase Price** (required)
   - **Commission** — enter as percentage or total amount (including 15% VAT)
   - **Offer Date** (required)
   - **Listing Split / Selling Split** — how commission is divided between listing and selling sides (must total 100%)
   - For each side: select agent(s), mark if external, set split percentages
   Click **"Next"**.

4. **Step 4 — Pipeline:** Select the appropriate deal pipeline template (templates are grouped by deal type). Click **"Next"**.

5. **Step 5 — Confirm:** Review everything. Click **"Submit"**.

> **Success:** A deal reference number is generated and you're taken to the deal page showing the pipeline tracker.

[SCREENSHOT: Deal creation Step 3 showing commission fields]

---

## 10. Pipeline Progression and Step Approval

On the deal page, you'll see a **Pipeline Tracker** — a vertical list of steps the deal must go through.

Each step shows:
- A colour-coded border: **green** (on track), **amber** (approaching deadline), **red** (overdue)
- Due date and days remaining
- Status: pending, in progress, awaiting approval, completed, or skipped

**To complete a step:**
1. Click on the step to expand it.
2. Upload any required documents.
3. Click **"Complete"**.
4. If the step requires Branch Manager approval, it moves to **"Awaiting BM Approval"** and the BM is notified.

**To skip a step:** Click **"Skip"** (only available on non-mandatory steps).

---

## 11. Settlement and Payment

Once the deal reaches completion:

1. Click **"Settlement"** on the deal page.
2. The system calculates per-agent commission based on the deal's split structure.
3. Review: listing side share, selling side share, agent cuts, PAYE deductions.
4. When the commission is paid, click **"Mark as Paid"**.

> **Important:** "Mark as Paid" is permanent — it locks all financial fields. Make sure everything is correct before clicking.

[SCREENSHOT: Settlement page showing commission breakdown per agent]

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Core Matches shows no results | Broaden the criteria — reduce minimum beds, increase price range, add more suburbs. |
| Can't convert match to deal | Check that you have the **"Convert to Deal"** permission. Ask your admin if it's missing. |
| Deal creation fails at Step 3 | Make sure Purchase Price and Offer Date are filled in, and Listing + Selling splits total exactly 100%. |
| Pipeline step is overdue (red) | Complete it as soon as possible, or contact your BM if it's waiting on approval. |
| Buyer appears in wrong pipeline column | Drag them to the correct column, or click their card and update the state. |

---

**Next step:** For document creation details, see **Guide 08: DocuPerfect Guide**.
