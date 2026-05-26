# 02 — Agency Admin Daily Operations

**Who is this for:** Agency Administrator
**What you'll learn:** Day-to-day management of users, branches, settings, onboarding, reports, and system configuration
**Prerequisites:** Your agency must already be created (see Guide 01). You must have an Administrator login.

---

## 1. Your Dashboard — The "Today" Page

When you log in, you land on **Today**. As an admin, you see both agent cards and management cards.

**Your key cards:**
- **Today's Appointments** — always visible, shows your schedule
- **Overdue Items** — tasks and events that are past their due date
- **E-Sign Activity** — documents waiting for your action
- **FICA CO Review Queue** — FICA submissions waiting for compliance review
- **Leave Awaiting Approval** — staff leave applications
- **Unread Notifications** — system alerts

Click any card to jump straight to that section.

[SCREENSHOT: Admin Today page showing management cards]

---

## 2. User Management

### Viewing All Users
1. Go to **Admin > User Management** (accessible from the sidebar or via direct URL).
2. You'll see a list of all users in your agency with their name, email, role, branch, and status (active/inactive).

### Creating a New User (Quick Method)
1. Click **"Create User"**.
2. Fill in: Name, Email, Role (from dropdown), Branch, Designation.
3. Set a temporary password.
4. Click **"Save"**.
5. The user receives an invitation email with their login details.

> **Recommended:** Use the Onboarding Pipeline (section 4 below) for new agents. The quick method is better for office staff or admin accounts.

### Deactivating a User
1. Find the user in the list.
2. Click the toggle icon to deactivate them.
3. Deactivated users cannot log in but their data is preserved.

### Resending an Invitation
If a user lost their invitation email:
1. Find them in the user list.
2. Click **"Resend Invite"**.

### Switching to View as Another User
You can see CoreX through another user's eyes:
1. At the bottom of the sidebar, click the three-dot menu next to your profile.
2. Click **"Switch User"**.
3. Select a user from the list.
4. A yellow banner appears: **"Viewing as [Name]"** with a **"Switch back"** button.
5. Click **"Switch back"** when done.

> **Note:** You cannot switch to other admin or system owner accounts. This is a security restriction.

[SCREENSHOT: The impersonation banner at the bottom of the sidebar]

---

## 3. Branch Management

### Adding a Branch
1. Navigate to the branch assignments page (via User Management area).
2. Fill in the **Create Branch** form: **Name** and **Code** (both required).
3. Click submit.
4. After creation, configure the branch details: Trading Name, Address, Phone, Email, registration numbers, logo.

### Configuring Branch Settings
Each branch can have its own:
- Trading name and tagline
- Physical address and contact details
- Registration numbers (Reg No, VAT, FFC)
- Property24 agency ID (for per-branch syndication)
- Logo (separate from the agency logo)

### Deleting a Branch
1. You can only delete a branch if all users have been reassigned to another branch.
2. The system will ask you to map each user to their new branch before proceeding.
3. Deleted branches can be restored later if needed.

[SCREENSHOT: Branch assignment page showing users and branches]

---

## 4. Onboarding a New Agent

This is the formal pipeline for bringing a new agent into the system.

### Starting the Application
1. Go to **Admin > Onboarding** in the sidebar. You'll see a Kanban board with columns for each stage.
2. Click **"Create Application"**.
3. Fill in the form:
   - **First Name**, **Last Name** (required)
   - **Email** (required)
   - **Phone**, **ID Number**
   - **Designation** (required): Property Practitioner, Candidate Practitioner, or Intern
   - **FFC Number**, **FFC Expiry**, **PPRA Status**
   - **Years Experience**, **Current Agency**
   - **Motivation** (free text)
   - **Referral Source**, **Referred By**
4. Click submit. A checklist is automatically created for this application.

[SCREENSHOT: The Onboarding Kanban board]

### Reviewing the Application
1. Click on the application card to open its detail page.
2. Upload required documents: ID Copy, FFC Certificate, Qualifications, PI Insurance, Tax Clearance, Proof of Address, CV.
3. Verify each document by clicking the verify button next to it.
4. Toggle checklist items as requirements are met.
5. Move the application through stages: Applied > Screening > Interview > Documentation > Training.

### Activating the Agent
1. Once all checklist items are complete, click **"Activate"**.
2. The system creates their user account with a temporary password.
3. The password is displayed in a success message — note it down and share it securely.
4. The agent receives an invitation email with a link to set their own password.

> **Success:** The agent now appears in your user list and can log in to CoreX.

---

## 5. Settings Overview

The Settings page has 12 sections. Go to **Admin > Settings** in the sidebar.

### Agency Settings
Company details, VAT rate, logo, and a live preview of how your document header looks.

### User Settings
Manage designations (job titles), social media accounts, and generate API tokens.

### Notifications
Master on/off switches for notification channels (in-app, email, push). Configure which events trigger notifications and how far in advance.

### Feature: Contacts
Manage **Contact Types** (Seller, Buyer, Landlord, etc.), **Contact Sources** (Referral, Walk-in, Website, etc.), and **Contact Tags** (custom labels for categorising contacts).

### Feature: Properties
Manage property categories, types, statuses, and mandate types. Toggle marketing features on/off. Enable/disable syndication portals (Website, Private Property, Property24).

### Feature: Matches
Turn Core Matches on or off. Set whether matches appear on property pages. Choose visibility scope (agent only, branch, or full agency). Customise the WhatsApp message template for sharing matches.

### Feature: Dashboard
Choose between individual user settings or agency-wide dashboard settings. Configure idle property alerts, document reminders, lease expiry alerts, FICA/FFC reminders, and notification channels.

### Whistleblow Settings
Configure compliance reporting approvers, the Compliance Officer email, and per-tier PPRA recipient email addresses.

[SCREENSHOT: The Settings page showing the section tabs]

---

## 6. Setting Up Event Classes

Event classes control how calendar events look and behave.

1. Go to **Dashboard > Event Classes** (in the Dashboard submenu — only visible to admins).
2. You'll see a list of event types: Viewing, Property Evaluation, Listing Presentation, Meeting, Task, Other.
3. For each class, you can configure:
   - Visibility thresholds (when events turn from green to amber to red)
   - Colours
   - Feedback modes (how feedback is captured after the event)
   - Completion behaviour

---

## 7. Setting Up Commission and Finance

### Commission Settings
Go to **Settings > Agency Settings** to set the default VAT rate and listing-to-sale ratio.

### Finance Engine
Go to **Admin > Finance Engine** in the sidebar to:
- View finance definitions (the metrics tracked per period)
- Run finance audits to verify calculation integrity
- Recalculate computed values if something looks wrong

### Performance Settings
Set company-wide targets, monthly goals, and activity definitions that feed into the worksheet and daily activity capture.

---

## 8. Knowledge Base

A central library for company documents, policies, and procedures.

1. Go to **Admin > Knowledge Base** in the sidebar.
2. Create categories (e.g., "Company Policies", "Templates", "Training Materials").
3. Upload documents into categories.
4. Toggle documents as **Active** (visible to staff) or inactive.
5. Toggle **Ellie Integration** to make a document searchable by the AI assistant.

[SCREENSHOT: Knowledge Base with categories and uploaded documents]

---

## 9. Reading Reports

### Agent Performance
Go to **Agency Tracker > Admin > Performance** to see company-wide performance for any period. View per-branch breakdowns, deal status summaries, activity points, and listing stock stats.

### Branch Performance
Go to **Agency Tracker > Branch > Branch Performance** for branch-level data with per-agent breakdowns.

### Dashboard Reports
Go to **Dashboard > Agency Report** for the full agency dashboard with metrics, conversion funnel, and branch comparison.

### Daily Activity Summaries
Go to **Agency Tracker > Admin > Daily Activity Summary** to see company-wide activity with drill-down to branch and then to individual agent.

---

## 10. Training Management

### Creating a Course
1. Go to **Admin > Training Mgmt** in the sidebar.
2. Click to create a new course.
3. Fill in: Title, Description, Category, whether it's required, and whether it must be completed before an agent can be activated.
4. Add lessons to the course with content (text, video URL, document upload, or external link).
5. Publish the course when ready.

Staff will see required courses in their **Training** sidebar item and on their **My Portal** page.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Can't see Admin section in sidebar | Your role may not have the admin sidebar permission. Check with System Owner. |
| User says they never got the invitation email | Use **"Resend Invite"** on their user record. Check spam folders. |
| Onboarding "Activate" button is greyed out | Not all required checklist items are complete. Review the checklist on the application detail page. |
| Settings changes don't seem to save | Make sure you click the save/submit button for each section. Some sections save individually. |
| Reports show zero data | Check the date range filter. Also verify that the correct branch/agency is selected. |

---

**Next step:** For compliance responsibilities, continue to **Guide 07: Compliance Officer**.
