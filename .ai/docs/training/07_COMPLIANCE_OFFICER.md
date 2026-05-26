# 07 — Compliance Officer Daily Guide

**Who is this for:** Compliance Officer (CO), or any admin who handles compliance approvals
**What you'll learn:** Reviewing FICA, managing the RMCP, verifying documents, handling staff screenings, and reviewing compliance complaints
**Prerequisites:** You must have compliance permissions assigned to your role.

---

## 1. Your FICA Queue

**Where:** Compliance > FICA

This is your primary daily task. Agents send FICA requests to clients, clients submit their documents, agents verify at their level, and then the submissions come to you for final approval.

1. Go to **Compliance > FICA** in the sidebar.
2. At the top, you'll see a pipeline with four stages and counts: **Awaiting Client** > **Awaiting Agent Review** > **Awaiting CO Approval** > **Complete**.
3. Click the **"My CO Queue"** tab to see only the submissions waiting for your review.

[SCREENSHOT: FICA index page showing the pipeline stages and the My CO Queue tab]

---

## 2. Reviewing a FICA Submission

1. Click **"Review & Approve"** on a submission in the queue.
2. You'll see a three-column layout:

| Left Column | Middle Column | Right Column |
|-------------|---------------|--------------|
| The client's submitted form data and uploaded documents | The agent's verification (read-only): risk rating, verification method, checklist answers | Your compliance review form |

[SCREENSHOT: The three-column FICA review page]

### Your Compliance Checklist (7 items):
Answer Yes or No for each:
1. Identity document(s) proving IDENTITY provided?
2. Document(s) proving ADDRESS provided (less than 2 months old)?
3. Document proving AUTHORITY provided?
4. Document proving DELEGATING AUTHORITY provided?
5. Is the client a VIP/PEP?
6. Anything suspicious or unusual?
7. Transaction consistent with knowledge of client?

### TFS Screening
Complete the Targeted Financial Sanctions screening check.

### Final Approval Form
- **TFS Screening Completed?** — Yes or No (required)
- **Risk Rating** — Low, Medium, or High (pre-filled from agent's rating, you can override)
- **Notes** — optional comments
- **Signature** — draw your signature on the signature pad using your mouse or touchscreen (required)

### Your Three Options:
- **"Approve & Finalise"** — The FICA is approved for 24 months. The client's contact record is automatically updated with their verified details. Their documents are filed to their contact Drive.
- **"Return to Agent"** — Sends it back with a note explaining what needs to be fixed. Enter your reason in the text box (required).
- **"Reject"** — Permanently rejects the submission. Enter a reason (required). A confirmation dialog appears.

> **Success after approval:** The submission status changes to "Approved" with a 24-month expiry. A PDF becomes available for download.

---

## 3. Reopening a Rejected FICA (New Feature)

If a FICA was rejected but should be given another chance:

1. Open the rejected submission.
2. Click **"Reopen for Corrections"**.
3. Enter a reason (minimum 10 characters) explaining what the agent/client should fix.
4. Click confirm.
5. The status changes to "Corrections Needed" and the agent can resubmit.

> **Who can do this:** Only CO, admin, or system owner roles.

---

## 4. RMCP Management

**Where:** Compliance > RMCP

The RMCP (Risk Management Compliance Programme) is your agency's formal compliance manual.

### Viewing Versions
1. Go to **Compliance > RMCP** in the sidebar.
2. You'll see all RMCP versions, sorted by version number.

### Creating a New Version
1. Click to create a new version. The system clones the previous version's sections.
2. Edit each section's text as needed.
3. Use the Variables sidebar to insert dynamic values (agency name, CO name, dates).

### Approving an RMCP
1. Open the draft version.
2. Click the approve action.
3. Fill in: Approver title, upload the board approval document (PDF, required), effective date, next review date, approval notes.
4. Click approve. The version becomes active and replaces any previous active version.

### RMCP Dashboard
Go to **Compliance > RMCP Dashboard** to see:
- Active version info and next review date
- Staff acknowledgement progress: acknowledged, in progress, not started, expiring
- A completion percentage bar
- Per-staff table with status and last acknowledgement date
- **"Send Reminder"** button for staff who haven't acknowledged yet

[SCREENSHOT: RMCP Dashboard showing completion bar and staff table]

---

## 5. Verification Queue

**Where:** Compliance > Verification Queue

This is where you verify or reject documents uploaded by staff (FFC certificates, ID copies, qualifications, etc.).

1. Go to **Compliance > Verification Queue** in the sidebar. A badge shows the number of pending documents.
2. You'll see summary cards: Pending Verification (amber), Verified (last 7 days), Rejected (last 7 days).
3. Click **"Review"** on a pending document.

### Reviewing a Document
- **Left panel (large):** Document preview — PDFs open in a viewer, images display inline.
- **Right panel:** Agent info (name, branch, designation, FFC number), document details (type, file name, size, upload date, expiry), and your action buttons.

### Your Three Options:
- **"Verify Document"** — marks it as verified. If it's an FFC certificate with an expiry date, the system updates the agent's FFC expiry automatically.
- **"Reject Document"** — opens a text box for your reason. The agent is notified and prompted to re-upload.
- **"Mark as Expired"** — only appears if the document's expiry date has passed. Prompts the agent to upload a current version.

[SCREENSHOT: Verification Queue review page showing document preview and action buttons]

---

## 6. Staff Screenings

**Where:** Compliance > Staff Screening

### Screening Dashboard
Go to **Compliance > Staff Screening** to see metric cards: Active Staff, Clear, Flagged, Overdue, Pending, Never Screened.

### Creating a New Screening
1. Click **"New Screening"** (or **"Screen"** next to a staff member).
2. Select the user, screening type (pre-employment, periodic, TFS list update, triggered), and risk tier (high, medium, low).
3. Submit — creates the screening with a set of checks based on the type.

### Completing a Screening
1. Open the screening detail page.
2. For each check: update the result (clear, concerns, fail, not applicable), add reference numbers and notes, upload supporting documents.
3. When all checks are done, set the overall result and click **"Complete"**.

> **Flagging:** If there are concerns, click **"Flag"** to set the staff member's screening status to "Concerns Flagged".

---

## 7. Reviewing Compliance Complaints (Whistleblower)

**Where:** Compliance > Compliance Reporting

For the full guide on this new feature, see **Guide 09: Compliance Reporting**. In summary:

1. Agents file complaints about non-compliant practitioners.
2. You see them in the queue with a badge count.
3. Open a complaint to review: tier, property, subjects, evidence, seller statement.
4. Choose: **"Approve & Send to PPRA"**, **"Request Changes"**, or **"Reject"**.
5. On approval, the PPRA is emailed automatically and seller information packs are sent.

---

## 8. Communications Log

**Where:** Compliance > Communications Log

Every email sent by the compliance system is logged here — FICA emails, PPRA complaints, seller info packs, reminders. Use this to verify that communications were sent successfully.

---

## 9. Lawyer Review Pack

**Where:** Compliance > Compliance Reporting > Lawyer Review Pack

If your agency needs a legal review of all active complaints:
1. Go to the Compliance Reporting page.
2. Click **"Lawyer Review Pack"** to generate a bundled export of all active complaints.

> **Access:** This requires the compliance configuration permission.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| FICA submission doesn't appear in my queue | Check the tab — it may be under "All" rather than "My CO Queue". Also verify you have CO permissions. |
| Signature pad isn't working | Try using a mouse instead of trackpad. On mobile, use your finger. Clear the pad and try again. |
| Can't approve RMCP — button greyed out | Only users with the RMCP approval permission can approve. Check with your admin. |
| Verification queue badge count seems wrong | The count caches for 60 seconds. Refresh the page. |
| Staff screening shows "N checks still pending" | All checks must have a result (even "not applicable") before you can complete the screening. |

---

**Next step:** For the new compliance reporting module, see **Guide 09: Compliance Reporting**.
