# CoreX — Build Standard (Robustness Charter)

> **MANDATORY. Read alongside CLAUDE.md, STANDARDS.md, CODEBASE_MAP.md.**
> This file defines what "done" means. Code that only passes its own
> happy-path test is NOT done. This is the senior-engineer baseline.
> Every prompt references this file. No feature is complete until it
> satisfies every section below that applies to it.

---

## 0. The governing principle

**We do complicated so the user does simple — and the user is never
perfect.** Real users submit half-filled forms, paste messy data, click
the wrong order, skip optional fields, and do the lazy-but-valid
shortcut. Code that assumes clean input is broken code, no matter how
many happy-path tests pass. The job is a system that takes anything
thrown at it and either handles it gracefully or refuses it clearly —
never a 500, never a silent data-loss, never an error message that means
nothing to a user.

---

## 1. Full CRUD is the default, never a request

Every entity that can be created can be read, updated, and archived
(soft-deleted). If a prompt says "add the ability to create X", the
build INCLUDES list, view, edit, and archive for X unless the prompt
explicitly scopes it down. Never ship a create with no edit. Never ship
an edit with no archive. Asking for "full CRUD" should never be a
thought — it is the floor.

---

## 2. The input-space rule (this is the one that keeps biting us)

For EVERY field a user can touch, the build must handle the entire
input space, not the example in the spec:

- **Required-but-empty** → reject at validation with a message a
  non-technical user understands. Never let it reach the DB and 500.
- **Optional-and-empty** → accept gracefully. Empty optional field must
  NEVER cause an error. (The `array_filter` class of bug: an optional
  filter that strips NOT-NULL columns. BANNED. NOT-NULL columns always
  get a value — '' or a sensible default — they are never filtered out.)
- **Optional-and-filled-but-malformed** → validate format, reject with a
  clear message, do not crash.
- **The lazy-but-valid shortcut** → e.g. "first name + phone, hit send."
  If it's legal per the rules, it MUST work end to end. This is how
  users actually behave. It is a first-class path, not an edge case.
- **Whitespace** → trim before validation. Leading/trailing spaces on
  email/phone/name never cause a reject or a duplicate.
- **Wrong order** → if a user can reach step 3 before step 2, either
  prevent it in the UI or handle it server-side. Never assume sequence.

**Schema is the contract.** Before writing any create/update, read the
migration. Every NOT-NULL column without a DB default MUST be supplied a
value by the code, every time, for every input combination. Prove it.

---

## 3. Guard rails: prevent OR absorb, never break

For any input that could break the system, exactly one of two things
must be true, by design:

1. **Prevent** — the UI/validation does not allow the breaking entry
   (disabled submit, required field, format mask, confirm dialog), OR
2. **Absorb** — the system accepts the non-entry/odd-entry and continues
   without breaking (sensible default, graceful skip, null-safe path).

There is no third option. "It errors if the user does X" is a defect,
not a known limitation. Decide prevent-or-absorb for every breaking
input AT SPEC TIME, before code is written.

---

## 4. Errors are for users, not stack traces

- No raw 500 / SQLSTATE / exception page ever reaches a user. Catch,
  log the technical detail, show the user a plain-language message that
  tells them what to do next.
- A failed action must leave the system in a clean state — transactions
  roll back fully, no half-created records, no orphaned rows.
- "Not found" is a 404 with a friendly page, never a 500.
- Deleted-related-record (link to a deleted contact/property/deal)
  renders gracefully with denormalised data or a clear note — never a
  crash. (We have hit this repeatedly. It is now a standing requirement.)

---

## 5. Tests must mirror reality, not the spec example

A test that only passes `last_name => 'Tester'` is theatre. Every
build's tests MUST include:

- The happy path (all fields).
- **Each optional field omitted, individually** (the empty paths).
- The lazy-but-valid shortcut (minimum legal input).
- One malformed-but-submitted input per validated field.
- The deleted-related-record path where relationships exist.
- Idempotency where the action can be repeated.

Test DATA must look like real CoreX data — real SA addresses, real
phone formats, the messy stuff agents type — NOT "Test / Test /
0000000000". If the demo/seed data is clean-world, the tests built on it
are lying. Seed data mirrors live-world messiness on purpose.

When VS Code reports "tests pass," the report must state WHICH input
paths were tested. "12 tests pass" means nothing. "Tests pass for:
all-fields, no-last-name, email-only, malformed-phone-rejected,
deleted-contact-renders" means something.

---

## 6. Fix the class, not the instance

When a bug is found, grep the codebase for every sibling occurrence of
the same pattern and fix them all in one pass. One `array_filter`
NOT-NULL bug means every `Model::create(array_filter(...))` in the
codebase is suspect. Find them all. A senior engineer kills the class of
defect, not the one instance the user happened to hit.

---

## 7. Navigation & access are part of the feature

Every new page/feature includes its navigation entry (sidebar, menu, or
button) AND its permission gate in the same build. A page a user cannot
reach, or can reach without permission, is not done.

---

## 8. Definition of Done (the checklist every build is held to)

A feature is DONE only when ALL apply:

- [ ] Full CRUD present (or explicitly scoped out in the prompt)
- [ ] Every NOT-NULL column supplied a value for every input combination
- [ ] Every optional-empty path accepted gracefully (no 500)
- [ ] Every required-empty path rejected with a user-clear message
- [ ] The lazy-but-valid shortcut works end to end
- [ ] Prevent-or-absorb decided and implemented for every breaking input
- [ ] No raw error reaches the user; transactions roll back cleanly
- [ ] Deleted-related-record paths render gracefully
- [ ] Tests cover happy + each-empty + shortcut + malformed + deleted-rel
- [ ] Test/seed data mirrors real-world messiness
- [ ] Sibling occurrences of any fixed bug-class also fixed
- [ ] Navigation entry + permission gate present
- [ ] Verification report states WHICH input paths were proven

If any box is unchecked, the feature is not done — regardless of how
many tests pass.

---

## 9. How this changes the prompt lifecycle

1. **Spec** — robustness is specced UP FRONT. The spec lists the input
   space, the prevent-or-absorb decision per breaking input, and the
   test matrix. Edge cases are decided BEFORE code, never discovered
   after.
2. **Investigate** — read the migration (NOT-NULL contract), read
   sibling code paths (bug-class scan), read the existing tests (are
   they happy-path theatre?).
3. **Build** — to this standard, not to the happy path.
4. **Verify** — against the input matrix in section 8, with real data.
   Report which paths were proven.
5. **Review (Claude/Johan)** — the report is checked against section 8.
   "Tests pass" is rejected; "these input paths proven" is required.
