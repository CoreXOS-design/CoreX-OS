# 09 — Compliance Reporting & Seller Info Packs (New Feature)

**Who is this for:** Agents (filing reports) and Compliance Officers (reviewing reports)
**What you'll learn:** How to report non-compliant practitioners using the three-tier system, how to send seller information packs, and what happens when a report is approved
**Prerequisites:** You need the Compliance Reporting permission. All agents and branch managers have this by default.

> **This is a brand-new feature** built in May 2026. It replaces the old ad-hoc complaint process with a structured, auditable system that automatically routes complaints to the PPRA.

---

## 1. When to Use This

Use Compliance Reporting when you encounter another practitioner (from any agency) who is operating without proper paperwork. There are three tiers:

| Tier | Situation | Example |
|------|-----------|---------|
| **Tier 1 — Paperwork Breach** | The seller confirms that the agent didn't get them to sign a mandate, didn't do FICA, or didn't provide a Mandatory Disclosure Form | A seller tells you "No, they never asked me to sign anything" |
| **Tier 2 — No FFC Displayed** | The agent's advert on Property24 or Private Property doesn't show a valid FFC number | You see a listing where the FFC field is blank or shows an old number |
| **Tier 3 — Unregistered Practitioner** | The person is not registered with the PPRA at all — this is a criminal offence | You search the PPRA register and can't find them |

---

## 2. Filing a Report

**Where:** Compliance > Compliance Reporting

1. Go to **Compliance > Compliance Reporting** in the sidebar.
2. Click **"File New Report"**.

[SCREENSHOT: The Compliance Reporting index page with the "File New Report" button]

3. **Choose the Complaint Type** (required — select one):
   - **Tier 1 — Paperwork breach (seller confirmed)**
   - **Tier 2 — No FFC displayed**
   - **Tier 3 — Unregistered practitioner**

4. **Link a Property** — search for the property in the system, or type the address if it's not in CoreX.

5. **Add the Subject(s)** — who you're reporting. For each subject:
   - **Agency Name** (required) — the agency the practitioner works for
   - **Practitioner Name** (optional) — the agent's name if known
   - **Portal URL** (required) — the link to the listing on Property24 or Private Property
   - **Portal Source** — select Property24, Private Property, or Other

   You can report up to 10 subjects in a single complaint. Click **"Add another subject"** for more.

6. **Seller Information** (Tier 1 only) — enter the seller's written statement confirming the paperwork breach. This text appears word-for-word in the complaint PDF sent to the PPRA.

7. **Agent Notes** — internal notes for the Compliance Officer reviewing your report.

8. **Upload Evidence:**
   - **Tier 1:** The seller statement is the primary evidence. Screenshots are optional but recommended.
   - **Tier 2:** You **must** upload a screenshot showing the advert with the missing FFC number.
   - **Tier 3:** You **must** upload a screenshot of the advert AND a screenshot of the PPRA register search showing no result.
   - Up to 5 files, maximum 10 MB each. Accepted: images and PDFs.

9. Click **"Submit Report"**.

> **Success:** You'll see a confirmation with a reference number (e.g., HFC-WB-42). The report goes to your Compliance Officer for review. A badge with the pending count appears on their sidebar.

[SCREENSHOT: The complaint form showing Tier selection, subjects section, and evidence upload]

---

## 3. What Happens After You Submit

1. Your report enters **"Pending Approval"** status.
2. The Compliance Officer is notified (sidebar badge + notification).
3. They review the report and choose one of three actions:
   - **"Approve & Send to PPRA"** — the complaint is sent to the PPRA with a formal PDF
   - **"Request Changes"** — sent back to you with notes on what to fix
   - **"Reject"** — the complaint is dismissed with a reason

If changes are requested, you'll see the complaint in your list with status **"Changes Requested"**. Update the report and resubmit.

---

## 4. What Happens When the CO Approves

When a complaint is approved, several things happen automatically:

1. **A formal PDF complaint is generated** in the format the PPRA expects, including all evidence and the seller statement.
2. **The PPRA receives an email** with the PDF attached. The email goes to the correct tier-specific recipient at the PPRA.
3. **Sellers linked to the property automatically receive a Seller Information Pack** — an email explaining the compliance issue and their rights. A WhatsApp-shareable link is also generated.
4. **Everything is logged** in the Communications Log.

> **Note:** The system is currently in **demo mode** — complaints are sent to an internal test email, not the real PPRA. This will be switched to live mode after training.

---

## 5. Reviewing Reports as Compliance Officer

**Where:** Compliance > Compliance Reporting (badge shows pending count)

1. Click on a pending complaint to open it.
2. You'll see: reference number, tier badge, property, all subjects (with portal links), the reporter's name, seller statement (Tier 1), uploaded evidence, agent notes, and an audit timeline.
3. At the bottom, you have three buttons:

| Button | What it does |
|--------|-------------|
| **"Approve & Send to PPRA"** | Generates PDF, emails PPRA, auto-sends seller info packs. A confirmation dialog asks: "Send this complaint to PPRA now?" |
| **"Request Changes"** | Opens a text box. Enter what needs to be fixed. The reporter is notified. |
| **"Reject"** | Opens a text box for your reason. The complaint is closed. |

[SCREENSHOT: The complaint detail page showing the evidence section and the three action buttons at the bottom]

---

## 6. The Communications Log

**Where:** Compliance > Communications Log

Every email sent by the compliance system is recorded here: FICA emails, PPRA complaints, seller information packs, reminders. Check this to verify that communications went through successfully.

[SCREENSHOT: Communications Log showing sent emails with timestamps and status]

---

## 7. Sending a Seller Information Pack (Standalone)

You can send a seller info pack without filing a full compliance report. This is useful when you want to educate a seller about their rights.

**Where:** Compliance > Send Standalone Info Pack (at the bottom of the Compliance submenu, in smaller text)

1. Go to **Compliance > Send Standalone Info Pack**.

2. **Choose the issue type:**
   - No mandate / FICA / MDF signed
   - Agent has no FFC displayed
   - Agent appears unregistered

3. **Link a property** (optional) — if you select a property, the system automatically loads all linked sellers as recipients with their name and email pre-filled.

4. **Add recipients** — the auto-loaded sellers have checkboxes to include/exclude. Click **"Add Recipient"** to add someone manually (enter name and email).

5. **Preview the email** — click **"Preview Email"** to see exactly what will be sent.

6. **Send** — click **"Send to [N] recipient(s)"**. Each recipient gets an individual email.

7. **WhatsApp sharing** — click the green **"Copy WhatsApp Link"** button to copy a shareable link (valid for 90 days) that you can paste into a WhatsApp message.

[SCREENSHOT: The Seller Info Pack send page showing the issue type selection and recipients list]

> **Success:** The page confirms how many emails were sent. If any failed, it shows the count.

---

## 8. Where to Find Your Reports

Go to **Compliance > Compliance Reporting** to see all your reports. You can filter by:
- **Status:** Draft, Pending Approval, Changes Requested, Rejected, Approved, Sent, Acknowledged by PPRA, Closed
- **Tier:** Tier 1, Tier 2, or Tier 3

Each row shows: reference number, tier badge, subject agency, property, reporter, status, and days open.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Can't upload evidence | Check file size (max 10 MB per file) and format (images or PDF). |
| "Add at least one recipient" when sending info pack | You need at least one recipient enabled. Check the checkboxes or add one manually. |
| Report stuck in "Pending Approval" | The CO hasn't reviewed it yet. Check with your Compliance Officer. |
| PPRA email not sent | The system may be in demo mode. Check with your admin. The Communications Log shows the actual send status. |
| Seller Info Pack link expired | Links are valid for 90 days. Generate a new one by re-sending the pack. |

---

**For Compliance Officers:** Also see **Guide 07** for your full daily compliance workflow including FICA, RMCP, and staff screenings.
