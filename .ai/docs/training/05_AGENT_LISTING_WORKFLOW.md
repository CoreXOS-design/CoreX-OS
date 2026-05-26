# 05 — Agent Listing Workflow (Seller Side)

**Who is this for:** Agents working with sellers — from first contact through to published listing
**What you'll learn:** The complete seller-side workflow: contact, FICA, property, mandate, marketing readiness, syndication
**Prerequisites:** You must have an active agent account and have completed your Day One setup (Guide 04).

---

## 1. Adding a Seller Contact

**Where:** Real Estate > Contacts

1. Click **Real Estate** in the sidebar, then **Contacts**.
2. Click the **"Add Contact"** button.
3. The system checks for duplicates as you type — if a match is found, you'll be asked whether to use the existing contact or create a new one.
4. Fill in the form:
   - **First Name** (required)
   - **Last Name** (required)
   - **Phone** (required)
   - **Email**
   - **ID Number**
   - **Contact Type** — select **"Seller"** from the dropdown
   - **Contact Source** — how you met them (Referral, Walk-in, etc.)
   - **Notes** — any initial notes
5. Click **"Save"**.

> **Success:** You'll see the new contact's profile page with tabs for Info, Properties, Notes, Documents, and more.

[SCREENSHOT: The contact creation form with Seller selected as contact type]

---

## 2. Doing FICA on the Seller

**Where:** Compliance > FICA

Before you can take a mandate or market a property, the seller must have approved FICA on file.

### Sending an Online FICA Request
1. Go to **Compliance > FICA** in the sidebar.
2. Click **"Send Online FICA"**.
3. Search for and select the seller contact (they must have an email address).
4. Click **"Send FICA Request"**.
5. The seller receives an email with a secure link to complete their FICA form.
6. They fill in their details and upload: ID document, proof of address, source of funds.
7. Once submitted, the FICA appears in your queue as **"Awaiting Agent Review"**.

### Reviewing as the Agent
1. Click **"Verify"** on the submission.
2. Check the uploaded documents against the form data.
3. Complete the **Verification Checklist** (6 yes/no questions).
4. Select a **Risk Rating** (Low, Medium, or High).
5. Choose at least one **Verification Method** (WhatsApp video call, physically met, video call with ID, or certified copies).
6. Click **"Approve (Send to Compliance Officer)"**.

The Compliance Officer will then do a final review. Once they approve, the seller's FICA status changes to **Complete** with a 24-month validity.

> **If something's wrong:** Click **"Request Corrections"** to send the form back to the seller with a note about what to fix.

[SCREENSHOT: FICA pipeline showing the four stages]

---

## 3. Adding the Property

**Where:** Real Estate > Properties

1. Go to **Real Estate > Properties** in the sidebar.
2. Click **"Add Property"**.
3. The property page opens with these tabs: Overview, Info, Gallery, Contacts, Notes, History, Drive, Intelligence, Core Matches.
4. Start on the **Info** tab and fill in:

### Required Fields
- **Title** — the listing headline (e.g., "Spacious 3 Bed in Uvongo")
- **Price** — the asking price in rands
- **Suburb** — where the property is located
- **Bedrooms**, **Bathrooms**, **Garages**

### Address Fields
- Street Number, Street Name, City, Complex Name, Unit Number (if applicable)

### Property Details
- **Listing Type** — Sale or Rental
- **Property Type** — House, Townhouse, Apartment, etc. (from your agency's configured types)
- **Mandate Type** — Sole, Open, or Dual
- **Status** — For Sale, Under Offer, Sold, etc.
- Size (m2), ERF size, features

5. Click **"Save Changes"** on the sidebar.

> **Success:** The property appears in your listings. The **Readiness Panel** on the sidebar shows your progress toward marketing readiness.

[SCREENSHOT: Property Info tab with key fields filled in, Readiness Panel visible on sidebar]

---

## 4. Linking the Seller to the Property

**Where:** Property page > Contacts tab

1. On the property page, click the **Contacts** tab.
2. Click **"Link Contact"**.
3. Search for the seller by name, phone, or email.
4. Select the contact from the results.
5. In the **Role** field, type **"Seller"** (or Owner, Landlord — as appropriate).
6. Click **"Link"**.

Alternatively, you can click **"Create & Link"** to create a new contact and link them in one step.

> **Why this matters:** The system uses the seller link to check FICA status for marketing readiness. If the seller isn't linked with the correct role, the marketing gate will block you.

[SCREENSHOT: Contacts tab on property page showing linked seller with role badge]

---

## 5. Uploading Photos

**Where:** Property page > Gallery tab

1. Click the **Gallery** tab.
2. You'll see upload sections for Dawn, Noon, Dusk, and general Gallery photos.
3. Drag and drop images, or click to browse.
4. After uploading, drag images to reorder them (the first image becomes the main listing photo).
5. You need **at least 4 photos** to pass the marketing readiness check.

[SCREENSHOT: Gallery tab with photos uploaded and the reorder interface]

---

## 6. Creating the Mandate via E-Sign

**Where:** Documents > E-Sign Document

This creates the legal mandate document and sends it for electronic signature.

1. Go to **Documents > E-Sign Document** in the sidebar.
2. **Step 1 — Choose Template:** Search for "Mandate" and select the appropriate template (e.g., "Mandate to Sell", "Sole Mandate"). Click **"Next"**.
3. **Step 2 — Select Property:** Type the property address in the search box. Select it from the results. Click **"Next"**.
4. **Step 3 — Add Recipients:** The agent (you) is automatically added as the first signer. The seller should auto-load from the property's linked contacts. Verify their details (name, ID, email, phone). Click **"Next"**.
5. **Step 4 — Document Details:** Fill in the commission rate (defaults to 7.5%), mandate start and expiry dates (use the quick-fill buttons: 1 Mo, 3 Mo, 6 Mo, 9 Mo). Click **"Next"**.
6. **Step 5 — Fill & Review:** Check all auto-filled fields. Add any special conditions using the **"+ Insert Clause"** button. Click **"Next"**.
7. **Step 6 — Signing Setup:** Choose **"E-Sign"** as the delivery mode. Verify the signing order (you sign first, then the seller). Click **"Prepare & Sign"**.

> **Important:** Sale agreements (OTPs) cannot use e-sign — the law requires wet-ink signatures. The system will block this automatically.

8. You'll be taken to the signing interface. Sign the document.
9. After you sign, the seller receives an email with a secure link to sign.

> **Success:** Monitor progress on **Documents > My E-Sign Documents**. You'll see the document move from "Ready to Sign" to "Awaiting Signatures" to "Completed".

[SCREENSHOT: Step 1 of the E-Sign wizard showing mandate templates]

For the full E-Sign guide with all options, see **Guide 08: DocuPerfect Guide**.

---

## 7. Marketing Readiness — The Four Gates

Before you can publish the property on portals, four conditions must be met:

| Gate | What's needed | Where to check |
|------|--------------|----------------|
| **1. Authority to Market** | A signed mandate document in the property's Drive | Property > Drive tab |
| **2. Sellers FICA Approved** | Every linked seller/owner must have FICA = Complete | Compliance > FICA |
| **3. At Least 4 Photos** | Upload at least 4 photos in any category | Property > Gallery tab |
| **4. Details Complete** | Title, price, suburb, and other key fields filled in | Property > Info tab |

The **Readiness Panel** on the property's sidebar shows which gates are passing and which are blocking.

[SCREENSHOT: Readiness Panel showing 3 of 4 gates passing with one blocker highlighted]

### Going Live
Once all four gates pass:
1. Click **"Go Live"** on the property sidebar.
2. This creates a compliance snapshot — a frozen record of who the sellers are and that their FICA was approved at this point in time.
3. The property is now eligible for syndication.

---

## 8. Publishing to Property24 and Private Property

**Where:** Property page > sidebar > Syndication button

1. On the property page, click **"Syndication"** in the sidebar.
2. A panel opens showing three portals: HFC Premium (website), Property24, Private Property.
3. Toggle on the portal(s) you want.
4. Click **"Submit"** for each portal.
5. The status will show: Pending > Active (or Error if something's wrong).
6. Check the last-synced timestamp to confirm.

> **If you see an error:** Check the readiness panel for missing fields. Each portal has slightly different requirements.

[SCREENSHOT: Syndication panel showing PP and P24 with status indicators]

---

## 9. Creating a Viewing

**Where:** Dashboard > Calendar

1. Go to **Dashboard > Calendar** in the sidebar.
2. Click on a time slot or click **"New Event"**.
3. Set the **Event Class** to **"Viewing"**.
4. Enter the title, date, and time.
5. Link to the **Property** using the search picker.
6. Add **Attendees** (the buyer, yourself, anyone else).
7. Click **"Save"**.

Attendees receive calendar invitations and can accept or decline.

---

## 10. Capturing Feedback After a Viewing

1. After the viewing time has passed, go to the event on your **Calendar**.
2. Click the event to open it.
3. Click **"Capture Feedback"**.
4. Record the outcome, buyer's interest level, any concerns, and notes.
5. Submit.

> **Why this matters:** Feedback flows into the **Buyer Pipeline** and is visible on the property's history. If you forget, the system will create a follow-up task to remind you.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Authority to Market not found" | The mandate hasn't been signed yet or isn't saved in the property Drive. Complete step 6 first. |
| "FICA incomplete for [name]" | The seller's FICA hasn't been approved by the CO yet. Check Compliance > FICA for the status. |
| Syndication shows "Error" | Check the readiness panel for the specific portal's requirements. Common issues: missing suburb, no photos, agent not registered with the portal. |
| Can't find the property in e-sign wizard | Make sure the property has been saved (not just a draft). Search by the suburb or address. |
| Seller says they didn't receive the signing email | Go to My E-Sign Documents, find the document, and click **"Send Reminder"**. |

---

**Next step:** For working with buyers, see **Guide 06: Agent Buyer Workflow**.
