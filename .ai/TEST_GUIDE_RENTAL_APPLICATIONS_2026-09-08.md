# Rental Applications — What To Test This Morning

For Johan. Everything below is on the test site (QA1) only — nothing has gone
to the live site.

---

## 1. What you can test

### The new Rentals section in the menu
Log in. On the left-hand menu you'll now see **Rentals** as its own
section. Click it — it opens to **Rental Applications** and **Returned
Applications**.

### The list screen — search, sort, own/branch/agency, filter, paging, archive
- Click **Rental Applications**.
- **Search** — type into the search box (an applicant's name, email, ID
  number, or part of an address) and click Filter.
- **Sort** — click any column heading (Applicant, Status, Agent, Created,
  Last updated). Click it again to flip the order.
- **Own / Branch / Agency** — near the top there are three buttons letting
  you switch between your own applications, your branch's, or the whole
  agency's. You'll only see the buttons for the levels you're actually
  allowed to see.
- **Status** — use the Status box to show only one status at a time.
- **Rows per page** — use the "Per page" box to show more or fewer rows.
- **Archive / restore** — click **Archive** on any row to take it off the
  list. Nothing is ever deleted for good — click **Show Archived** to see
  it again, and **Restore** to bring it back.

### The applicant's own form
- Open **Rental Applications**, click **New Rental Application**, fill in
  a contact, and save it — **don't** click Send yet.
- Check the status shown on the application, both here and back on the
  list — it should say **Draft**, not Sent. A brand new application
  should never show as sent before you've actually sent it.
- Open the "Online link" shown on that application in a new tab. It should
  say the link isn't ready yet and show no form — this is deliberate, so
  a link can't be filled in before you've actually sent it.
- Go back and click **Send**. Open the same link again — it should now be
  the real, fillable form.
- Type a rand amount into a money field (e.g. current rent) using a comma,
  like `8,500` — it should be accepted.
- Fill in a few fields, attach a document, and confirm everything you
  typed is still there afterwards (nothing should clear).
- The applicant fills in the form, attaches their supporting documents,
  and only then sees the Submit Application button — it sits below the
  documents on purpose, so nothing gets submitted before the documents
  are attached.
- **Contact email.** If the contact has no email on record, the email
  the agent types on the application is saved onto the contact. If the
  contact already has a different email, the contact is left alone — the
  application keeps the new address, but the contact's own record is not
  overwritten.
  > **A question for you:** at the moment an agent can't accidentally
  > overwrite a contact's real email address by typing a different one
  > on an application — the contact only gets filled in if it was blank.
  > If you'd rather the contact always update to whatever the agent
  > types, say so and we'll change it.

### The agent review screen
- From **Returned Applications**, click **Review** on any row.
- **Highlighting** — open a document and mark it up directly on the page
  with a highlighter (drag like a real highlighter pen, not a box).
  Several colours are available; yellow is the default.
- **Notes** — pin a written note to a spot on the document.
- **Two panels scroll separately** — the document on the left and the
  income/assessment panel on the right each scroll on their own, so you
  can work on one without losing your place on the other.
- **Saving** — the assessment panel saves itself as you go, and shows
  "Saved at [time]" so you can see it actually happened. Your highlights
  and notes have their own Save button, which now sits in a fixed strip
  at the top of the screen so it's always visible, however far down you've
  scrolled.
- **New:** if you try to close a document while a highlight or note hasn't
  been saved yet, it now stops you and asks first, instead of quietly
  losing it.
- **New:** opening a large document used to take about nine seconds before
  anything appeared. It now shows the first page in well under a second,
  at full quality, and loads the rest behind it.
- **New:** while building the speed fix above, we found and closed a real
  way a save could have quietly wiped highlights on pages that hadn't
  finished loading yet — nothing was actually lost in practice, but it's
  now impossible rather than just unlikely.
- **New:** if a document was added to an application after it was
  submitted, the review screen now shows a badge marking it as added
  late — right on that document's own row, not on documents that were
  part of the original submission.

### Submitting to the authoriser
- On the review screen, click **Submit to authoriser** (it says
  "Re-submit to authoriser" if it's already been sent once before).

### The authoriser screens
- On the left-hand menu, click **Rentals**, then **Rental Application
  Authorisation**. (You'll only see this if you've been set up as an
  authoriser — see the settings note below.)
- **Approve** — enter the rand amount the tenant is approved for and
  confirm. A comma in the amount (e.g. `8,500`) is now accepted, same as
  the money fields on the application itself.
- **Decline** — decline the application, with an optional reason.
- **Request more information** — sends it back to the agent, not the
  applicant, with your reason attached in a real email to the agent.
- **If a decision has already been made** on an application, only a
  designated override-level person can change it, and they must give a
  reason.
- **What an ordinary agent sees if they try to reach these screens or
  actions directly:** they're blocked outright with a plain "not allowed"
  message — it isn't just a hidden button, the system itself refuses it.

### Setting a status from Returned Applications
On an application that's already come back, an agent can set it to
**Under Assessment** or **Withdrawn** by hand from a dropdown. (Approve
and Decline are authoriser-only now, done from the authoriser screens
above, not from this dropdown.)

### The decline email wording
Go to **Settings → Rental Applications**. The subject and body of the
email sent when an application is declined can be rewritten there, per
agency. The same screen is also where you choose who your authorisers
are — set up the same way as your compliance officers already are, with
a tick-box list so you can put more than one person at each level (both
the reviewer level and the override level).

---

## 2. What is not done yet

Nothing outstanding on the rental application side as at this morning.

---

## 3. Found, but not fixed — reporting only, as instructed

- The same raw technical error message that was showing on rental
  application screens earlier was also found on the viewing pack redaction
  screen.
- Mailbox failure alerts are under-reporting because several failures
  share one cooldown timer and only the first one notifies.
- The sales document sending screens have the same false "Sent" status
  problem that rental applications had — it can say "Sent" when nothing
  actually went out.

---

**If anything above doesn't behave the way it's described, please just
note what you clicked and what happened, and send it back to us.** It
goes straight to whoever built that part — you don't need to explain it
twice or track anyone down yourself.
