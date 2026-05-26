# 08 — DocuPerfect: Documents & E-Signatures

**Who is this for:** Anyone who creates, signs, or manages documents in CoreX
**What you'll learn:** The full e-sign wizard, wet-ink signing, document packs, clause library, and monitoring your documents
**Prerequisites:** Active CoreX account with document creation permissions.

---

## 1. Where to Find Documents

In the sidebar, click **Documents** to expand the group. You'll see:

| Menu Item | What it does |
|-----------|-------------|
| **Create Document** | Generate a document without e-sign (PDF only) |
| **E-Sign Document** | Create a document AND send it for electronic signature |
| **My E-Sign Documents** | Track all your e-sign documents and their status |
| **Authorise Documents** | Review documents from candidate practitioners (only visible to supervisors) |
| **My Documents** | Browse all your created documents |
| **Packs** | Bundles of multiple document templates |
| **Web Packs** | Bundles of web-based templates |
| **Clause Library** | Pre-written clauses you can insert into documents |
| **Template Management** | Create and edit document templates (admin only) |
| **Field Groups** | Manage reusable field bundles (admin only) |
| **Import Document** | Convert Word documents into CoreX templates (admin only) |

[SCREENSHOT: The Documents section of the sidebar expanded]

---

## 2. The E-Sign Wizard — Overview

The E-Sign Wizard is a 6-step process. The screen is split in two: your form is on the left, and a live preview of the document is on the right.

---

## 3. Step 1 — Choose Your Template

1. Go to **Documents > E-Sign Document**.
2. You'll see three sections:
   - **Continue where you left off** — any draft documents you started but didn't finish. Click **"Continue"** to pick up where you left off, or **"Delete Draft"** to discard.
   - **Templates** — all available templates, grouped by document type.
   - **Packs** — bundles of documents (Web Packs and PDF Packs).

3. Use the filter buttons (**All**, **Sales**, **Rentals**) to narrow down templates.
4. Use the search box to find a specific template by name.
5. Each template card shows: name, number of pages, number of fields, and a badge for Web or PDF format.
6. Click on a template to select it, then click **"Next"**.

> **Important:** Sale agreements and OTPs are blocked from e-sign. The system will show: "Sale agreements must be signed with wet ink per the Alienation of Land Act." Use the wet-ink option for these.

[SCREENSHOT: Step 1 showing template cards with category filters]

---

## 4. Step 2 — Select the Property

1. Type the property address into the search box.
2. Results appear showing: address, property type, bedrooms, price, and the linked owner/landlord name.
3. Click on a property to select it. A green checkmark appears with "Selected: [address]".
4. To clear the selection, click the X button on the badge.

**If the property isn't in CoreX yet:** Use the manual entry fields: Address, Suburb, Unit/ERF Number, Complex Name, Property Type.

> **What happens:** Selecting a property automatically loads its linked contacts into the next step (Recipients).

5. Click **"Next"**.

[SCREENSHOT: Step 2 with property search results and a selected property]

---

## 5. Step 3 — Add Recipients (Signers)

The agent (you) is automatically the first signer and shown as a locked card at the top.

For each additional signer:
1. Choose their **Role** from the dropdown (Seller, Buyer, Landlord, Tenant, Witness).
2. Search for an existing contact by name, email, or ID number. If they're already linked to the property, they'll appear with a **"Linked"** badge.
3. The system fills in their details: Full Name, ID Number, Email, Phone, Physical Address.
4. Verify all details are correct.

**To add a co-owner:** Click **"+ Add Second [Owner] (Co-owner)"**.
**To add more parties:** Click **"+ Add Recipient"**.

> **Role mismatch warning:** If a recipient's role doesn't match what the template expects, you'll see a warning. This usually means the template requires a specific party type.

> **New contacts:** If the person isn't in CoreX, fill in their details manually. They'll be saved as a new contact automatically when you proceed.

5. Click **"Next"**.

[SCREENSHOT: Step 3 showing recipient cards with role dropdowns and the "+ Add Recipient" button]

---

## 6. Step 4 — Document Details

This step shows different fields depending on whether it's a sales or rental document.

### For Sales Documents:
| Field | Default | Notes |
|-------|---------|-------|
| Asking Price (R) | Auto-fills from property | |
| Commission (%) | 7.5% | |
| Mandate Start Date | Today | |
| Mandate Expiry Date | — | Quick-fill buttons: **1 Mo**, **3 Mo**, **6 Mo**, **9 Mo** |

### For Rental Documents:
| Field | Default | Notes |
|-------|---------|-------|
| Monthly Rental (R) | Auto-fills from property | |
| Deposit (R) | Auto-fills from property | |
| Lease Start Date | — | |
| Lease Duration | — | Buttons: **6 months**, **12 months**, **24 months**, **Custom** |
| Lease End Date | Auto-calculated | Based on start + duration |
| Commission (%) | 10% | |
| Marketing Fee (R) | Auto-fills | |

Click **"Next"**.

[SCREENSHOT: Step 4 showing sales fields with the mandate expiry quick-fill buttons]

---

## 7. Step 5 — Fill & Review

Every field in the document is listed in order. Each field shows:
- The field name with a badge indicating which party is responsible
- A dropdown to reassign the field to a different party if needed
- The appropriate input type (text, date, dropdown, yes/no, etc.)

**Auto-filled fields** come from the property and contact data you've already entered. They have a green border.

### Adding Clauses
At the bottom, you'll find the **"Other Conditions / Additional Clauses"** section:
1. Type conditions directly in the text box, OR
2. Click **"+ Insert Clause"** to open the clause library.
3. Select one or more pre-written clauses.
4. They're inserted into the text box automatically.

> **Auto-save:** The system saves your progress as you type. If you need to step away, your work is preserved.

Click **"Next"**.

[SCREENSHOT: Step 5 showing document fields with green borders on auto-filled items and the clause insertion button]

---

## 8. Step 6 — Signing Setup

Choose how the document will be signed:

### E-Sign (Electronic Signature)
- Each signer receives an email with a secure link.
- You sign first (always position 1, locked).
- Reorder other signers using **"Move Up"** / **"Move Down"** buttons.
- Per signer, you can set:
  - **"Send after previous"** — default, waits for the previous person to sign
  - **"Sign later (deferred)"** — skip them for now
  - **"Exclude from email"** — for people who will sign in person
  - **"FICA verification required before signing"** — blocks signing until FICA is approved
- A document summary shows field counts per party.

### Wet Ink
- A PDF is generated for printing.
- Each party prints, signs with a pen, scans, and uploads.
- You review the uploaded scans before proceeding.

### Download Only
- A PDF is generated. No signing flow is created.
- Good for internal reference copies.

Click **"Prepare & Sign"**.

[SCREENSHOT: Step 6 showing the three delivery mode options and signing order cards]

---

## 9. After Clicking "Prepare & Sign"

### For E-Sign:
1. You're taken to the signing interface. Sign the document.
2. After signing, you see: **"Document Signed!"** with the next recipient's name and email.
3. The next signer gets an email with their signing link.
4. Go to **Documents > My E-Sign Documents** to monitor progress.

### What the External Signer Sees:
They receive an email with a button to open the signing page. It's a clean, standalone page (not the full CoreX interface). They:
1. Review the document.
2. Give consent.
3. Sign in each required zone (draw signature, type name, or upload a scan).
4. Click complete.

After all parties sign, a signed PDF is generated with a full audit trail.

---

## 10. My E-Sign Documents

**Where:** Documents > My E-Sign Documents

This page shows all your e-sign documents, grouped by status:

| Status | What it means |
|--------|--------------|
| **Draft** | You started the wizard but haven't finished. Click **"Continue Setup"**. |
| **Ready to Sign** | The document is generated and waiting for you to sign first. Click **"Sign Document"**. |
| **Awaiting Signatures** | You've signed and it's now with other parties. Per-signer icons show who's signed (checkmark), who's been sent (envelope), and who's waiting (lock). |
| **Completed** | All parties have signed. Click **"Audit"** or **"Download"**. |
| **Cancelled** | The document was voided. |

### Actions:
- **"Send Reminder"** — resend the signing email to an overdue signer
- **"Cancel Document"** — voids the document (requires typing a reason). All pending signatures are cancelled and waiting parties are notified.

[SCREENSHOT: My E-Sign Documents page showing status tiles and document list]

---

## 11. Cancelling a Document

1. Find the document on **My E-Sign Documents**.
2. Click **"Cancel"** or **"Cancel Document"**.
3. A dialog appears: "All pending signatures will be voided and waiting parties will be notified. This action cannot be undone."
4. Type the reason for cancellation (required).
5. Click **"Cancel Document"** to confirm.

---

## 12. Candidate Practitioner Supervision

If you're a candidate practitioner (not yet fully qualified), your documents go through an extra step:

1. After you prepare the document, it enters **"Awaiting Supervisor"** status instead of going directly to signing.
2. Your supervisor sees it on their **Authorise Documents** page.
3. They review and either authorise it (allowing signing to proceed) or send it back.

Supervisors: go to **Documents > Authorise Documents** in the sidebar to see documents needing your review. Click **"Review & Authorise"** on each one.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Template I need isn't listed | Check the category filter (All/Sales/Rentals). If it's still missing, your admin may need to create or publish it. |
| "Sale agreements must be signed with wet ink" | This is a legal requirement. Choose **"Wet Ink"** in Step 6 instead of E-Sign. |
| Recipient has a role mismatch warning | The template expects a specific party type. Change the recipient's role to match (e.g., change "Buyer" to "Seller" if it's a mandate). |
| Signer says they didn't get the email | Go to My E-Sign Documents and click **"Send Reminder"**. Also ask them to check spam. |
| Fields didn't auto-fill | Make sure the property and contact were selected (not entered manually). Auto-fill works from linked records. |
| Can't find my draft | Go to My E-Sign Documents and look under the "Draft" section. Or in Step 1, check "Continue where you left off". |

---

**Next step:** For compliance-specific document workflows, see **Guide 09: Compliance Reporting**.
