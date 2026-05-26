# 01 — System Setup (First-Time Agency Configuration)

**Who is this for:** System Owner (the person setting up CoreX for a new agency)
**What you'll learn:** How to create an agency, configure branding, create the first admin user, and switch between agencies
**Prerequisites:** You must have a System Owner login. This is the highest level of access in CoreX.

---

## 1. Logging In for the First Time

1. Open your browser and go to your CoreX URL (e.g., `corex.hfcoastal.co.za`).
2. Enter your email and password.
3. Click **"Log In"**.
4. You'll land on the **Today** page — your daily command centre.

[SCREENSHOT: The login page]

> **If you forgot your password:** Click **"Forgot your password?"** on the login page. Enter your email and follow the reset link sent to your inbox.

---

## 2. The System Developer Menu

As a System Owner, you have a special section in the left sidebar called **System Developer**. This is where you manage agencies, import data, and access developer tools.

To find it, scroll to the bottom of the sidebar. You'll see:

- **Agency Management** — create and manage agencies
- **PP Agents** — manage Private Property agent registrations
- **Duplicate Cleanup** — find and merge duplicate records
- **API** — view all system endpoints (technical)
- **Client App Activity** — monitor mobile app usage
- **Feedback Reports** — view staff feedback
- **Importer** — bulk import from Property24
- **Fault Reports** — system issue tracking
- **Sales Documents** — document sending system
- **Evaluation** — property/suburb/town reports

[SCREENSHOT: The System Developer section of the sidebar]

---

## 3. Creating a New Agency

1. In the sidebar, click **System Developer > Agency Management**.
2. You'll see a list of all existing agencies with their branch count and user count.
3. Click the **"Create Agency"** button.

[SCREENSHOT: The Agency Management index page with the Create Agency button]

4. Fill in the form:

### Agency Details
| Field | Required? | What to enter |
|-------|-----------|--------------|
| **Agency Name** | Yes | The official registered name of the agency |
| **Slug** | Auto-filled | A URL-friendly version of the name (generated automatically) |
| **Trading Name** | No | The name the agency trades under, if different |
| **Tagline** | No | A short marketing tagline |
| **Address** | No | Physical office address |
| **Phone** | No | Main office number |
| **Phone Secondary** | No | Alternative number |
| **Fax** | No | Fax number (if applicable) |
| **Email** | No | Main agency email |
| **Reg No** | No | Company registration number |
| **VAT No** | No | VAT registration number |
| **FFC No** | No | Agency Fidelity Fund Certificate number |
| **FIC No** | No | Financial Intelligence Centre number |
| **P24 Agency ID** | No | Property24 agency identifier (for syndication) |
| **P24 Agency Label** | No | How the agency appears on Property24 |
| **Is Active** | Yes | Leave ticked — untick only to temporarily disable |
| **Is Demo** | No | Only tick if this is a test/training agency |

### Brand Colours
Pick the four colours that define the agency's look and feel:
- **Sidebar colour** — the background of the left sidebar
- **Icon colour** — accent colour for icons
- **Default colour** — primary text/heading colour
- **Button colour** — colour for action buttons

The defaults are blue/navy and work well for most agencies.

[SCREENSHOT: The brand colour pickers in the agency form]

### Logo
Click the upload area to select a logo file. Accepted formats: JPG, PNG, or WebP. Maximum size: 2 MB.

### First Administrator
Unless you're creating a demo agency, you must create the first admin user:

| Field | Required? | What to enter |
|-------|-----------|--------------|
| **Admin Name** | Yes | Full name of the agency administrator |
| **Admin Email** | Yes | Must be unique across all of CoreX |
| **Admin Password** | Yes | At least 8 characters |
| **Admin Cell** | No | Mobile number |

5. Click **"Create Agency"** (or the submit button at the bottom).

**What happens:** CoreX creates the agency and the admin user in one step. No branches are created automatically — you'll add those next.

> **Success:** You'll see the agency appear in the list. The admin user can now log in with the email and password you provided.

---

## 4. Switching Between Agencies

If you manage multiple agencies, you can switch between them without logging out.

1. Look at the top of the sidebar — you'll see a dropdown showing your current agency.
2. Click the dropdown to see all available agencies.
3. Select the agency you want to work in.
4. The page will refresh and you'll now see that agency's data.

To go back to seeing all agencies, select **"All Agencies"** from the dropdown.

[SCREENSHOT: The Agency Switcher dropdown in the sidebar]

> **Note:** Some agencies may require access authorisation. If an agency has this turned on, you'll need to request access and wait for approval before switching in.

---

## 5. What to Do After Creating an Agency

Now that the agency exists, the administrator should:

1. **Create branches** — See guide 02, section on branch management
2. **Configure company settings** — Agency details, VAT rate, logo for documents
3. **Set up document types and contact types** — Via the Settings page
4. **Invite agents** — Via the Onboarding pipeline
5. **Upload the RMCP** — The compliance manual that all staff must acknowledge
6. **Set up training courses** — Required courses that agents must complete

These tasks are covered in **Guide 02: Agency Admin Daily**.

---

## 6. Other System Developer Actions

### Deactivating an Agency
On the Agency Management page, click the toggle switch next to an agency to deactivate it. Deactivated agencies can still be reactivated later.

### Editing an Agency
Click the edit icon next to an agency to update its details, logo, or brand colours.

### Deleting an Agency (Permanent)
This is a destructive action that removes all of the agency's data permanently. It requires typing a confirmation password. Only use this for test agencies that are no longer needed.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Email already taken" when creating admin | That email is already registered. Use a different email or find the existing account. |
| Logo doesn't appear after upload | Check the file is JPG, PNG, or WebP and under 2 MB. Try a different file. |
| Can't see System Developer menu | Your account may not have System Owner access. Contact the platform administrator. |
| Agency switcher shows no agencies | Refresh the page. If still empty, check that agencies exist and are active. |

---

**Next step:** Hand over to the agency administrator and have them follow **Guide 02: Agency Admin Daily**.
