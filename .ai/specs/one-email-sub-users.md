# One Email — Sub-Users Signing In With a Username — Build Spec

> Spec file: `.ai/specs/one-email-sub-users.md`
> Ticket: AT-423 (branch `AT-423-New-user-method-for-using-under-one-email-with-sub-users`, Andre's lane → QA2)
> Status: **SIGNED — Andre, 2026-09-21 (all §12 rulings closed). BUILT on AT-423, uncommitted,
> awaiting Andre's look on localhost before commit → QA2.**
> Open for Johan: §13 wizard omission (CLAUDE.md #10a). To verify on Staging: §7 portal note.
> Author: Andre (business requirement) + Claude (solution design), 2026-09-21
> Related: `.ai/specs/corex-feature-registry.md`, `.ai/specs/agency-onboarding-setup.md` §6.1,
> `.ai/specs/login-audit-trail.md`, AT-79/AT-80 (`users.display_email` / `outward_email`)

---

## 1. Purpose (business requirement)

Some agencies run **one shared mailbox for every agent** (e.g. `agents@hfcoastal.co.za`)
instead of giving each agent their own address. Today CoreX cannot support that: every
user needs their own unique email address to exist and to sign in.

Andre, 2026-09-21 (paraphrased from the request):

> An agency can switch on **One email**. They can then create **sub-users** under a
> user / one email address. A sub-user signs in with a **username** such as
> `andre@hfcoastal` plus their own password. Creating a sub-user produces a **link**
> that can be sent out; on it the new sub-user enters their username and is prompted to
> create a password. After that, **a sub-user behaves exactly like a normal user.** The
> only differences are how they are created and how they sign in.

### Explicit non-goals

- No change for agencies that leave One email off. Every existing user, login, reset and
  invite behaves byte-for-byte as today.
- No new roles, permissions or data visibility. A sub-user's role, branch, own/branch/agency
  scope, commission setup, portal profile, etc. are set exactly as for any user.
- No shared login. Every sub-user has their own password, their own session, their own
  login history, their own audit trail. Nobody signs in "as agents@".
- No per-agent inbox inside CoreX. Mail for a sub-user is delivered to the shared mailbox;
  CoreX does not split or filter that mailbox.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Agent** (`User`) | the main account (shared mailbox owner), agency feature state | new sub-user rows; `users.is_sub_user`, `users.must_change_password`; `agencies.one_email_user_id`; password + `email_verified_at` on link redemption |
| Property / Contact / Deal | — | nothing new. Sub-users own, list and transact exactly like any agent. |

No new cross-pillar reactivity → no new domain events. Existing user-creation / login paths
keep emitting what they already emit (e.g. the `Login` listener → `login_histories`).

---

## 3. The design decision (why it is built this way)

The codebase treats `users.email` as **the one unique sign-in identity** everywhere: web
login, mobile-app login, password reset (token table is keyed by it), invite links, the
e-sign "which signing slot is mine" lookup (`SignatureController.php:1116`, `:3354`),
importers, and ~20 direct `Mail::to($user->email)` sends. If several users shared one
value in that column, every one of those lookups would silently pick "the first one" —
a password reset or mobile login could land on **the wrong person**. That is not an
acceptable failure mode.

**So the rule is: the sign-in identity stays unique; only mail delivery is shared.**

1. A sub-user's **username** (`andre@hfcoastal`) is stored in the existing unique sign-in
   column (`users.email`). Every existing lookup keeps finding exactly one person. The
   unique index, the soft-delete "seat lock", the e-sign lookup, the login audit trail and
   the reset-token table all keep working unchanged.
2. A username can **never be a deliverable address**: it has no dot after the `@`, and the
   format rule enforces that. So a username can never collide with a real email of
   another user or agency.
3. The agency names ONE **main account** — an existing normal user whose real email is the
   shared mailbox (e.g. `agents@hfcoastal.co.za`) — in a new column
   `agencies.one_email_user_id`. A sub-user carries only a flag (`users.is_sub_user`); their
   mail goes to whatever the agency's main account is. Changing the main account, or its
   email, moves every sub-user's mail with it (one source of truth, one inbox per agency —
   Andre, 2026-09-21).
4. Mail that is **for the sub-user** (invite link, password reset, digests, reminders,
   notifications) is delivered to the main account's address.
5. Everywhere CoreX shows an agent's email **to the outside world** (portals, e-sign
   documents, website API, client portal, seller pages) it uses the existing
   `outward_email` (AT-79). For a sub-user that becomes the shared mailbox, never the
   username. An explicit `display_email` on the sub-user still wins, as today.
6. A **safety net** on outgoing mail: if any message is ever addressed to a sub-user's
   username (a code path we missed, or future code), it is re-addressed to that
   sub-user's shared mailbox before sending and a warning is logged. Nothing is ever sent
   to an undeliverable username.

This matches the AT-80 direction already recorded on `User::outwardEmail()`: the login
identity and the outward-facing address are separate concepts.

---

## 4. Data model

### 4.1 Migration (one)

Migration `2026_09_21_000000_add_one_email_sub_user_columns`:

- `agencies.one_email_enabled` — boolean, NOT NULL, default `false`. The agency switch (§4.2).
- `agencies.one_email_user_id` — `unsignedBigInteger`, nullable, FK → `users.id`
  (users are never hard-deleted, so no `ON DELETE`). The agency's ONE main account
  (shared inbox). Chosen when the switch is turned on.
- `users.is_sub_user` — boolean, NOT NULL, default `false`. Everyone today = `false`.
  Deliberately NOT fillable — set only by the admin Users / Assistants screens.
- `users.must_change_password` — boolean, NOT NULL, default `false` (§6.5).

No change to the `email` unique index. `display_email` unchanged.
Schema snapshot refreshed per CLAUDE.md §12a (from the **test** DB, DEFINER-stripped).

### 4.2 Agency switch — "Team Inbox", its own Settings section (Andre, 2026-09-21)

**Not** a Settings → Features row. Andre's ruling during the build: *"put the switch
somewhere custom and not on the features page since it's a big change to the agency."*

Andre's second ruling: *"move the button to /corex/settings and give it a fancy name"* — it is
called **Team Inbox**, tagline *"One inbox · every agent their own key"*.

Stored in `agencies.one_email_enabled` + `agencies.one_email_user_id`, saved by its own
controller (`Admin\OneEmailSettingsController`, route `corex.settings.team-inbox`,
`PUT /corex/settings/agency/team-inbox`, `permission:manage_performance_settings`),
rendered as the **Team Inbox** section of **Settings → Agency**
(`/corex/settings?s=team-inbox`), beside Remote Access; the menu entry shows only to people
with `manage_performance_settings`, and Settings search finds it by "one email", "shared
inbox", "sub-user", "username". It acts on the agency the admin is working in; no agency
selected → refused, never guessed (STANDARDS Rule 17). See §6.1 for the flow. The saver's boolean write
is `$request->has()`-guarded (agency-onboarding-setup.md §6.1).

`OneEmailService::enabled()` = `one_email_enabled` AND a shared inbox is recorded.

### 4.3 Model additions

`App\Models\User`:
- `isSubUser(): bool` — `is_sub_user`.
- `mailboxUser(): ?User` — the agency's `one_email_user_id` user, loaded `withTrashed()`
  and without global scopes (queued mail has no auth user; an archived main account's
  address is still the inbox; see §8). Uses the memoised `Agency::find()`.
- `deliveryEmail(): ?string` — sub-user → main account's `email`; else own `email`.
  Null only if a sub-user's agency has no main account (logged; mail is then not sent).
- `routeNotificationForMail()` → `deliveryEmail()` (every `$user->notify()` mail).
- `outwardEmail` accessor → `display_email` if filled, else (sub-user ? shared inbox : email).

`App\Models\Agency`: `oneEmailUser()` relation.

`App\Services\Users\OneEmailService` — the single home of the rules: `enabled()`,
`mainAccount()`, `candidates()`, `stemFor()`, `isRealEmail()`, `normaliseName()`,
`isValidName()`, `buildUsername()`, `usernameMessages()`, `formData()`, `setupUrl()`,
`usernameMatches()`, `subUserCount()`. Used by both the Users and Assistants screens.

---

## 5. Username rules (input space)

Format: `<name>@<agency stem>`. The **stem is fixed by CoreX** (Andre, 2026-09-21), taken
from the main account's email domain up to the first dot (`agents@hfcoastal.co.za` →
`@hfcoastal`). The admin types only the name part; the stem is shown beside the box and
added by CoreX.

| Input | Handling |
|---|---|
| Empty name | Reject: "Enter a username for this person." |
| Leading/trailing spaces, uppercase | Trim + lower-case before validating and storing (`Andre ` → `andre@hfcoastal`) |
| Characters other than letters, digits, `.`, `-`, `_` | Reject: "Usernames can only use letters, numbers, dots, dashes and underscores." |
| Admin types the full `andre@hfcoastal` | Accept; the duplicated stem is stripped |
| Name already taken (anywhere in CoreX, incl. archived) | Reject: "That username is already taken — try andre.r or andre2." Archived holder → same message as today's seat-lock ("belongs to an archived user — restore them instead") |
| Stem cannot be derived (main account email odd) | Prevented: only users with a valid real email can be chosen as a main account |

Sign-in fields (web login, mobile-app login, forgot password, reset password) accept
**either** a valid email **or** a valid username. Anything else → the existing "These
credentials do not match our records" (no hint about which part was wrong).

---

## 6. UI placement & user flows

### 6.1 Switching it on — Settings → Agency → Team Inbox
1. Admin ticks **"Let agents share one inbox, each with their own sign-in"**.
2. A **Shared inbox** picker appears: this agency's active people who sign in with a real
   email (never a sub-user, never an assistant). The card warns "This is a big change for
   your agency. You will be asked to confirm when you save."
3. **Save** → a confirm dialog ("Switch on Team Inbox? People you add as sub-users will sign
   in with a username, and all of their CoreX emails will go to the shared inbox you chose.").
4. Saved → back on the Team Inbox section with "Team Inbox is ON…"; the section shows
   *Currently: ON — emails go to …* and how many people sign in with a username.

**Prevented in the screen:** while ticked ON with no shared inbox chosen, Save is disabled and
"Choose the shared inbox first" shows. The server still refuses it too ("Choose whose inbox
is shared before turning Team Inbox on.").

**Save outcome travels in the URL** (`?s=team-inbox&saved=on|off|inbox|saved`) and the section
words the message from it — not a one-time session flash. Found in the 2026-09-21 browser
test: the Settings page's background pollers (portal-leads poll, reminders) can land between
the save and the redirected page and use the flash up, so the admin saw no confirmation. Choosing someone outside the candidates is refused. The same section is
where the shared inbox is later changed (§6.8a).

### 6.2 Creating a sub-user (Admin → Users → Add User)
Only when the switch is on, the form gains **"How will this person sign in?"**:
- **Their own email address** (default — today's form, unchanged), or
- **A username, sharing an inbox** → the Email box is replaced by
  **Username** — `[ andre ] @hfcoastal`, with the note that their emails go to the shared inbox.

With the switch off the form is byte-for-byte today's layout (no choice shown).

Everything else on the form (name, role, branch, designation, commission, etc.) is the
normal Add User form, unchanged. On save:
- the user is created exactly as today (pending invite, random unusable password,
  `email_verified_at = null`, inactive until first sign-in);
- the invite email goes to the **shared inbox**. It says who it is for ("This invitation is
  for Andre Roets… please pass it on") but **deliberately does not print the username** —
  otherwise anyone reading the shared inbox would hold both halves and the §6.3 username
  check would prove nothing. The admin gives the person their username directly;
- the admin lands on the new person's **Edit User** page, which shows a **Set-up link for
  Andre Roets** panel with the username and a **Copy link** button (to send by WhatsApp/SMS).
  That panel shows on the Edit User page (and the Assistant page) **every time** while the
  person has not yet set their password, with a fresh 7-day link — it never depends on a
  one-time session flash, which the page's background pollers can use up (seen in the
  2026-09-21 browser test on the Assistant page). It disappears once they have set up.

Assistants (Admin → Assistants → Add) get the same choice, same rules.

### 6.3 The set-up link (the sub-user)
Reuses the existing signed invite link (`account.setup`, 7 days). For a sub-user:
1. Page asks: **"Enter your username"** (the username is NOT pre-shown — the step proves
   the link reached the right person).
2. Wrong username → "That username doesn't match this invitation." 5 wrong attempts →
   the link is locked for 15 minutes (throttled per link + IP).
3. Right username → **Create password** + **Confirm password** (same rules as today's
   set-up page) → signed in → normal first-login flow.
Expired / already used link → the existing friendly "this link has expired — ask your
admin for a new one" page. Admin → Users → **Resend invite** issues a fresh link (email to
shared inbox + Copy link).

### 6.4 Signing in
Login page label becomes **"Email or username"** (input no longer `type=email`). Sub-user
types `andre@hfcoastal` + password. Remember-me, rate limiting (5 tries), login history,
agency/branch session setup, `is_active` gate — all unchanged. Same for the mobile app
login (`POST /api/v1/login`).

### 6.5 Forgotten password — admin only (Andre, 2026-09-21)
Sub-users cannot reset their own password. "Forgot password" with a username sends
nothing and shows: "Sub-user passwords are reset by your agency admin — please ask them."
An admin resets it from Admin → Users → Edit the sub-user → **Reset password**:
1. Admin types a **temporary password** (min 8 characters, confirmed) and saves.
2. `users.must_change_password = true`. The old password stops working at once; the
   "remember me" token is rotated so an old remembered session cannot come back.
3. Admin tells the agent the temporary password (outside CoreX).
4. The agent signs in with username + temporary password → is sent straight to
   **Choose a new password** (new password + confirm; must differ from the temporary one).
   Every other page redirects there until done; Log out is still allowed.
5. On save: `must_change_password = false`, then their normal first page.

Mobile-app login while `must_change_password` is set → refused with
"Please sign in on the website first to choose a new password." (the app has no
change-password screen). A sub-user who never set a password (invite pending) gets
**Resend invite**, not Reset password.

### 6.6 After sign-in
A sub-user uses CoreX exactly like any user. On My Portal → Profile (and the older
/profile form) the Email field becomes **Username** — read-only — with "You sign in with this
username. Your CoreX emails go to agents@hfcoastal.co.za. Only your admin can change it."
Server-side, `ProfileUpdateRequest` pins the posted `email` to their current username, so a
crafted request cannot change it either.

### 6.7 Editing / converting (Admin → Users → Edit)
- Change the username (same rules as §5).
- **Give them their own email** — switch sign-in type to "own email", enter the address;
  `is_sub_user` is cleared. Password is kept; they sign in with the email from then on.
- Convert a normal user into a sub-user — the reverse, only while `one-email` is on.

### 6.8a Changing the shared inbox
Same Team Inbox section (§6.1). While on, the picker shows the current shared inbox; choosing
another person moves every sub-user's mail at once ("Shared inbox changed…"). Existing
usernames keep their original ending (usernames never change on their own). The card never
offers "none" — the shared inbox can be replaced, never cleared.

Turning the switch **off** keeps the shared inbox recorded, so existing sub-users keep
signing in and receiving mail; only adding new ones stops. The card then says
"N people sign in with a username and still can."

### 6.8 Users list
The existing Users list already searches (name or email) and filters client-side on the
sign-in column, so usernames are searchable with no change. Additions: sub-users show a
**"Sub-user · agents@hfcoastal.co.za"** tag beside their username, and — once the agency has
any sub-user — a **Sign-in type: All / Own email / Username (sub-user)** filter. Scoping is
unchanged (AgencyScope + `manage_users`). Edit User's header shows
"andre@hfcoastal (username · emails go to …)". The Pending Invite card (Resend) shows for a
sub-user regardless of `is_active`, because a pending sub-user is inactive until first
sign-in and Resend is their only way to a fresh link.

The Archive (delete) dialog for the shared-inbox account shows a warning naming how many
sub-users use it and that their mail keeps going there (§8).

---

## 7. Where mail and addresses change (as built)

| Area | Today | Change |
|---|---|---|
| `$user->notify()` (39 sites, 19 notifications) | `users.email` | `User::routeNotificationForMail()` → shared inbox. No call-site edits. |
| Direct `Mail::to($user->email)` to staff (~20 sites: digests, reminders, oversight, first-login, e-sign) | login column | **Class fix, not per-site edits (BUILD_STANDARD §6):** `App\Support\SubUserMailRouter`, called first inside the one `MessageSending` listener in `OutboundMailGuardServiceProvider`. Any To/Cc/Bcc/Reply-To that is a sub-user's username is re-addressed to their shared inbox before the guard and before the real send; two sub-users on one message give the inbox ONE copy; a username with nowhere to go cancels the send (logged). Only an address with no dot after the `@` triggers a lookup, so normal mail costs nothing. This covers every current site AND any written later. |
| Invites (Users + Assistants create / resend) | `Mail::to($user->email)` | explicitly `deliveryEmail()` (shared inbox) + the copyable link |
| Password reset mail | stock notification | never sent for a sub-user (§6.5) |
| Outward raw `->email`: P24 agent push (`Property24SyndicationService.php:1014`, `:1142`), Private Property agent push (`PrivatePropertySyndicationService.php:1022`), website API (`AgentResource.php:25`), client portal (`ClientPortalController.php:329`, `:721`), seller insights (`ClientSellerInsightsController.php:226`), mobile property (`MobilePropertyController.php:944`) | raw login email | → `deliveryEmail()`: **identical to today for every normal user** (their own email — `display_email` behaviour on these feeds is unchanged), the shared inbox for a sub-user. Narrower than the draft's `outward_email` switch, so no existing user's portal address changes. |
| Private Property agent de-duplication (`ensureNoDuplicateBeforeUpdateAgent`, matches PP profiles by email) | login column | **deliberately unchanged**: it keeps matching on the unique username. Matching on the shared inbox would make one sub-user's push remap another sub-user's PP profile onto itself. |
| E-sign "my signing slot" lookup, login audit, importers, seat lock | login column | **unchanged** — username is unique, so these keep working |

**To verify on Staging (cannot be proven locally):** whether Property24 and Private Property
accept several agent profiles that share one email address. CoreX now sends each sub-user
with the shared inbox; if a portal rejects duplicate agent emails, that shows as a sync
error on the second sub-user.

---

## 8. Prevent-or-absorb decisions

| Situation | Decision |
|---|---|
| Admin turns One email **off** while sub-users exist | **Absorb**: existing sub-users keep signing in and receiving mail (the shared inbox stays recorded); only *creating* new sub-users is hidden. The card shows "N people sign in with a username and still can." (Turning it off must never lock anyone out.) |
| Main account is **archived** | **Absorb**: sub-users keep working; mail still goes to that address (it is a real inbox). The archive dialog warns "…is the shared inbox for N sub-users. They will keep signing in, and their CoreX emails will still go to that address…" |
| Main account's email is **changed** | **Absorb**: all its sub-users' mail follows automatically. The username stem does **not** change (existing usernames stay valid). |
| Main account is itself a sub-user | **Prevent**: the picker only lists users with a real email. |
| Main account turned into a sub-user, or clearing the main account while sub-users exist | **Prevent**: "This account is the shared inbox for N sub-users — choose a different shared inbox first." |
| Main account missing at send time (defensive) | **Absorb**: mail is not sent to the username; logged as a warning with the user id. |
| Sub-user uses "Forgot password" | **Prevent**: no mail; "ask your agency admin" message. |
| Sub-user edits their own email on Profile / Agent portal | **Prevent**: field is read-only for sub-users (validation ignores it). |
| Same username requested twice at once | **Prevent**: DB unique index is the final guard; the loser gets the friendly "taken" message, not a 500. |
| Username that was archived then re-used | Same as today's email seat-lock: "restore them instead". |
| Sub-user's invite link opened after conversion to own email | Link still valid; page behaves as the normal (email) set-up page. |

---

## 9. Permissions

No new permission keys. Creating/editing sub-users is part of adding/editing users →
existing `manage_users` (Users routes) and `assistants.create` (Assistants). The Team Inbox
section (menu entry and section) and its saver use `manage_performance_settings`; the page
itself needs `access_settings`. The saver acts on the admin's effective agency.

---

## 10. Files (as built)

Created:
- `database/migrations/2026_09_21_000000_add_one_email_sub_user_columns.php` (§4.1)
- `app/Services/Users/OneEmailService.php` (§4.3)
- `app/Http/Controllers/Admin/OneEmailSettingsController.php` (§4.2 / §6.1)
- `app/Http/Controllers/Auth/PasswordChangeRequiredController.php` + `resources/views/auth/password-change-required.blade.php` (§6.5)
- `app/Http/Middleware/EnsurePasswordChanged.php` (web group; inert for everyone without the flag; never traps an admin who is impersonating)
- `app/Support/SubUserMailRouter.php` (§7 safety net)
- `resources/views/admin/users/_invite-link.blade.php` (copy-link panel; Users list, Edit User, Assistant page)
- `resources/views/admin/assistants/_sign-in-fields.blade.php`
- tests (§11)

Modified:
- Models: `User.php` (§4.3), `Agency.php` (relation + cast)
- Settings: `CoreX/SettingsController.php` (section data + `team-inbox` section key), `corex/settings.blade.php` (menu entry + section), `routes/web.php` (one route). Company Settings is untouched.
- Sign-in: `LoginRequest.php` (trim), `auth/login.blade.php` + `auth/forgot-password.blade.php` (label "Email or username"), `PasswordResetLinkController.php` (sub-user refusal), `routes/api.php` (mobile refusal while a new password is owed), `routes/auth.php` (two routes), `bootstrap/app.php` (middleware)
- Set-up link: `AccountSetupController.php` + `auth/account-setup.blade.php`
- Invite email: `Mail/UserInviteMail.php` + `emails/user-invite.blade.php`
- Users: `UserManagementController.php` + `admin/users/create-edit.blade.php`, `index.blade.php`, `_delete-modal.blade.php`
- Assistants: `AssistantController.php` + `admin/assistants/create.blade.php`, `edit.blade.php`, `show.blade.php`
- Profile: `ProfileUpdateRequest.php`, `agent/portal.blade.php`, `profile/partials/update-profile-information-form.blade.php`
- Mail: `OutboundMailGuardServiceProvider.php` (calls the router first)
- Outward addresses (§7): `Property24SyndicationService.php`, `PrivatePropertySyndicationService.php`, `WebsiteApi/AgentResource.php`, `Api/V1/ClientPortalController.php`, `Api/V1/ClientSellerInsightsController.php`, `Api/MobilePropertyController.php`
- `database/schema/mysql-schema.sql`

Not needed after all (the draft listed them): new validation Rule classes — Laravel's `email`
rule already accepts `andre@hfcoastal`, so every sign-in field works unchanged and the
username rules live in `OneEmailService`; `NewPasswordController` — a sub-user never gets a
reset token; `ProfileController` / `AgentPortalController` — the shared request covers both;
the individual `Mail::to($user->email)` staff sites — covered by the safety net (§7).

Not touched: `AgencySetupGateController` (first agency admin is always an email user),
`DemoOwnerLoginController`, importers (agents imported from P24 are always email users).

---

## 11. Acceptance criteria & test matrix

`tests/Feature/Users/OneEmailSubUserTest.php` (35 tests),
`tests/Feature/Assistants/OneEmailSubUserAssistantTest.php` (4),
`tests/Feature/Syndication/OneEmailAgentAddressTest.php` (2). Proven paths:

- Switch: turning on without a shared inbox refused; on + inbox saved; a sub-user or
  another agency's user refused as the inbox; the card renders.
- Switch off → Add User form has no choice; a POST with sub-user fields is rejected.
- Create sub-user: happy path (`"  Andre "` → `andre@hfcoastal`); full `Andre@HFCoastal`
  typed; blank; bad characters; a foreign ending (`andre@gmail`); taken username;
  archived-username seat lock (create → archive → re-create, §5a data-state axis).
- Invite mail goes to the shared inbox, never the username; link + username handed back.
- Set-up link: username not shown; password before username refused; wrong username
  refused; right username (mixed case + spaces) → password step → set; 6th try locked.
- Web sign-in with username (caps + spaces); two sub-users on one inbox each reach only
  their own account; wrong password refused.
- Mobile `POST /api/v1/login` with username; refused while a new password is owed.
- Switch turned off → existing sub-user still signs in and still gets mail.
- Forgot password with a username → nothing sent, "ask your admin".
- Admin temporary password: must be typed twice the same; old password dies; temp password
  reaches only "Choose a New Password"; every other page redirects there; re-using the
  temp password refused; new password works and clears the flag.
- `notify()` routes to the shared inbox; normal users unchanged.
- Safety net: username in To/Cc re-addressed and de-duplicated; normal and unknown
  addresses left alone; nowhere-to-go cancelled; a real `Mail::raw` send is recorded by the
  outbound guard as going to the shared inbox.
- Shared inbox email changed → mail follows, usernames unchanged. Shared inbox archived →
  mail still delivered; the archive preview carries the warning.
- Outward: `outward_email` + website API show the shared inbox; explicit `display_email` wins;
  P24 profile payload sends the shared inbox for a sub-user and is unchanged for a normal agent.
- Convert sub-user → own email (a username-shaped "email" refused; real email accepted; then
  signs in with it). The shared-inbox account cannot become a sub-user.
- Profile PATCH cannot change a sub-user's username or flip them back to "pending invite".
- Users list / Edit User / Add User render with the new controls; Assistants add (username +
  shared-inbox invite), bad username (no assistant created), edit + temporary reset, and an
  email assistant unchanged.
- Regression guards run: `AgentActivationGateTest` (7/7 — invite + first sign-in unchanged).

Real-browser walk (headless Chrome, localhost, 2026-09-21): switch on (confirm dialog) →
add sub-user → copy-link panel → open link → wrong then right username → set password →
sign in with username → My Portal shows the read-only username → admin temporary reset →
forced "Choose a New Password" (other pages stay closed) → forgot-password refusal. Zero
console errors on every new/changed screen except pre-existing ones listed in the report.

---

## 12. Business rulings (Andre, 2026-09-21)

1. The shared inbox is an **existing user** (e.g. the account that signs in with
   `agents@hfcoastal.co.za`). ✅
2. **One shared inbox per agency.** ✅
3. The username ending is **fixed by CoreX.** ✅
4. **Only an admin resets a sub-user's password**, and at the next sign-in the sub-user is
   made to choose a new one. ✅ Mechanism: **Option A — the admin sets a temporary password**
   (Andre, 2026-09-21). Option B (typing the username alone opens "create new password")
   was rejected: usernames are guessable, so anyone could take over a reset account.

## 13. Deliberately NOT in the wizard

- The **One email switch and its shared-inbox picker** are NOT in the Agency Onboarding
  Setup Wizard. Andre moved the switch off the Settings → Features page (which is what
  would have fed the wizard automatically) to its own Settings → Team Inbox section because it is
  "a big change to the agency": turning it on needs a deliberate choice of shared inbox and
  a confirmation, which a wizard toggle cannot give. Per CLAUDE.md #10a leaving a setting
  out of the wizard is **Johan's call** — flagged for his confirmation. If he wants it in,
  the natural form is an explanatory line in the wizard pointing to Settings → Team Inbox, not a bare toggle.
