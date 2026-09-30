# Training (LMS) — Spec

> Status: Live. Written 2026-09-30 retroactively for the existing module (built
> 2026-03-27, `.ai/specs/training.md` did not previously exist — CLAUDE.md
> spec-first rule requires one going forward) plus the 2026-09-30 completion
> report and learner-attachment-rendering fixes.

## What this is and why

A per-agency Learning Management System: agencies author courses made of
lessons (text, video, document, or external link), agents work through them,
and mark each lesson read/understood. A course completes when every lesson
does, at which point the agent explicitly acknowledges it ("I confirm I have
read and understood the material") — recorded with a timestamp and a 12-month
expiry, the compliance record an agency can point to for FICA/RMCP/PPRA
training obligations as well as ordinary sales/systems training.

## Pillars

- **Agent** (`User`) — the learner. Every `TrainingProgress` /
  `TrainingCompletion` row is keyed to a `User`.
- Does not read/write Property, Contact, or Deal directly. A future
  `is_required_for_activation` gate (already a column on `training_courses`,
  not yet enforced anywhere) is the intended future connection to agent
  activation/onboarding — out of scope for this spec revision.

## Data model

```
training_courses            (agency-scoped, soft-deletes)
  id, agency_id, title, description, category
    [compliance|onboarding|sales|systems|general],
  is_required, is_required_for_activation, sort_order, is_published,
  created_by, timestamps, deleted_at

training_lessons            (belongs to a course; no agency_id of its own —
                              inherits agency scope through course_id, see
                              TrainingController::visibleLesson())
  id, course_id, title, content, content_type [text|video_url|document|link],
  video_url, document_path, external_link, duration_minutes, sort_order,
  is_published, timestamps

training_progress           (per learner, per lesson)
  id, user_id, course_id, lesson_id, started_at, completed_at,
  time_spent_seconds, timestamps
  unique(user_id, lesson_id)

training_completions        (per learner, per course — the acknowledgement)
  id, user_id, course_id, completed_at, acknowledged_at,
  acknowledgement_signature, certificate_path, expires_at, timestamps
  unique(user_id, course_id)
```

`content_type` selects a lesson's PRIMARY content, but does not gate which of
`content` / `video_url` / `document_path` / `external_link` may be populated —
the author form always allows all four together (e.g. a video lesson with an
introductory text paragraph, or a text lesson with an attached PDF). **The
learner view renders every populated field, independently of
`content_type`** (fixed 2026-09-30 — see Known limitations / history below;
previously it rendered them as a mutually-exclusive chain and silently
dropped anything not matching the primary type).

No hard deletes anywhere in this module. `training_courses` soft-deletes;
`training_lessons`/`training_progress`/`training_completions` cascade-delete
with their parent course (acceptable: a deleted course's lesson/progress
history has no standalone value once the course itself is gone — the
compliance record of record is `training_completions.acknowledgement_signature`
+ `expires_at`, and archiving the course, never hard-deleting it, is what
preserves that).

## Permissions

| Key | Type | Purpose |
|---|---|---|
| `access_training` | access | See the Training nav link / reach `/corex/training` |
| `training.view` | action, has scope (own/branch/all) | Who a manager can see in the completion report (below) |
| `training.manage` | action | Author courses/lessons, view the completion report |
| `manage_courses`, `assign_training` | access | Reserved (not yet wired to any route/controller check — legacy config rows, unchanged by this revision) |

`training.manage` gates every method in `TrainingController`'s Admin section
(`manage()`, `createCourse()`, `storeCourse()`, `editCourse()`,
`updateCourse()`, `createLesson()`, `storeLesson()`, `editLesson()`,
`updateLesson()`, and the new `completions()`) via
`abort_unless($user?->hasPermission('training.manage'), 403)`. Fixed
2026-09-30 from a hardcoded `isOwnerRole() || effectiveRole() === 'super_admin'`
check — see Known limitations / history.

## Nav

- `resources/views/layouts/corex-sidebar.blade.php`, Agents section:
  - **Training** — `@feature('training')` + `@permission('access_training')`
    → `/corex/training` (learner view)
  - **Manage Training** — `@feature('training')` + `@permission('training.manage')`
    → `/corex/training/manage` (author view)
- From `/corex/training/manage`, each course row links to **Completions**
  (`/corex/training/manage/{course}/completions`).

## User flow

**Learner:** `/corex/training` lists published courses for their agency →
open a course (`/corex/training/{course}`) → expand a lesson → Start Lesson →
read/watch/download/open whatever is attached → Mark as Complete (per
lesson, timestamped via `training_progress.completed_at`) → once every
published lesson is complete, an "Acknowledge & Complete Course" panel
appears → explicit acknowledgement creates/updates `training_completions`
(`completed_at`, `acknowledged_at` set together, `expires_at` = +12 months).

**Author (training.manage):** `/corex/training/manage` → + New Course /
Edit → + Lesson / Edit lesson (`lesson-form.blade.php` — title, content
type, content, video URL, external link, document upload, duration, sort
order, published toggle, all independently settable regardless of content
type) → **Completions** to see who has acknowledged the course.

## Completion report (`training.completions`, added 2026-09-30)

`GET /corex/training/manage/{course}/completions` — gated by
`training.manage` (same as authoring; a future split into a narrower
"view reports without authoring" permission is a legitimate ask but was not
requested and is not built here).

**Search** — learner name or email (`users.name`, `users.email`).

**Sort** — `name` (`users.name`), `status`/`completed_at` (both sort on
`training_completions.completed_at`; NULL sorts first in MySQL ascending
order, so the default `status` ascending sort surfaces outstanding learners
first — the actionable list). Default: `sort=status&direction=asc`, tie-broken
by name ascending.

**Filter** — `status` (`completed` / `outstanding`, minimum per
BUILD_STANDARD §1b) and a `date_from`/`date_to` range on
`training_completions.completed_at`.

**Pagination** — 10/25/50/100 per page (`?per_page=`), default 25.

**Empty state** — distinct copy for "no learners in scope yet" vs. "no
learners match these filters."

**Scoping (own/branch/agency, BUILD_STANDARD §1c)** — the roster of
learners shown (not just whether the page is reachable) is narrowed by
`PermissionService::getDataScope($user, 'training')`, which reads the scope
grant already stored against the pre-existing `training.view` permission key
(any `{module}.view` action key automatically gets the own/branch/all
selector in Role Manager — no new config or migration needed for this):

- `own` — only the viewing manager's own row.
- `branch` — learners whose `users.branch_id` matches the viewer's
  `effectiveBranchId()`.
- `all` — every learner in the viewer's agency (the outer `agency_id`
  boundary via `User`'s `AgencyScope`/`BelongsToAgency` is never
  bypassed — this only narrows WITHIN that boundary).

Direct-URL access to another agency's course 404s (`TrainingCourse::findOrFail`
runs under the model's own `AgencyScope`); the scope narrowing above is
enforced in the controller query, not hidden via a missing UI link.

## Known limitations / history

- **2026-09-30 — learner view was silently dropping attachments.** The
  learner view (`training/show.blade.php`) rendered content/video/document/
  link as a mutually-exclusive `@if/@elseif` chain keyed on `content_type`,
  while the author form always allowed all four fields to be populated
  together. A document, external link, or video attached to a (default)
  `text` lesson saved correctly but never rendered for the learner. Fixed:
  each populated field now renders independently. A YouTube watch/youtu.be/
  shorts URL is converted to a privacy-enhanced (`youtube-nocookie.com`)
  embed (`TrainingLesson::youtubeEmbedUrl()`); any other `video_url` falls
  back to a plain "Watch Video" link rather than being iframed blind (a
  non-YouTube URL in an unconditional iframe is also a mild security
  surface — an author-controlled but unvalidated iframe src).
- **2026-09-30 — Training Management (`/corex/training/manage`) 403'd for a
  Role-Manager-granted admin.** All nine admin methods hardcoded
  `isOwnerRole() || effectiveRole() === 'super_admin'`, never consulting the
  `training.manage` permission Role Manager already exposed and had granted.
  Fixed to `hasPermission('training.manage')` (a strict widening — owners/
  super_admins still pass via `PermissionService`'s own break-glass bypass).
- **2026-09-30 — Training nav link was hidden inside the System Developer →
  Hidden panel**, gated only by the broad `access_settings` permission
  instead of `access_training` + the `training` feature flag. Moved into
  the Agents section with the correct gates.
- **Quiz / knowledge check — NOT built, spec only (2026-09-30).** Johan
  asked for a real quiz (multiple choice, agency-configurable pass mark
  default 80%, attempts recorded) if feasible in the same pass; scoped out
  as too large alongside the two bug-fix batches and the completion report
  above delivered same-day. See "Quiz — future spec" below. Today's
  knowledge-check content (e.g. Course 4, "Other Agency Stock") is
  represented as a plain text lesson listing questions with their answers
  inline — a real quiz model does not exist yet.
- **Per-lesson completion IS already "I have read and understood" with a
  timestamp** — this was not new work in the 2026-09-30 pass, it already
  existed (`training.complete-lesson` → `TrainingProgress.completed_at`)
  and satisfies that half of Johan's "completion acknowledgement" ask
  as-is; the genuinely new gap was the manager-facing report, above.

## Quiz — future spec (not built)

If/when prioritised, a real quiz needs (net-new, not present today):

- `training_quiz_questions` (course_id or lesson_id, question text, type
  = multiple_choice, `sort_order`) and `training_quiz_choices`
  (question_id, choice text, `is_correct`), both agency-scoped through
  their parent course, soft-deletes on the question (not the choice —
  choices belong to a soft-deletable question, cascade is fine there).
- `training_quiz_attempts` (user_id, course_id, answers taken, score,
  `passed` bool, attempted_at) — every attempt recorded, not just the
  latest, per Johan's "attempts recorded."
- A new agency setting, **pass mark, default 80%** — this needs a
  `agencies.training_quiz_pass_mark` column (or a row in whatever the
  agency-settings table already is by the time this is built) AND, per
  CLAUDE.md non-negotiable #10a, a corresponding control in
  `config/agency-onboarding-copy.php`'s Setup Wizard in the SAME prompt
  that adds the column — not a follow-up.
- Author UI: a quiz-builder screen (question + choices + correct-choice
  picker) reachable from the course editor, mirroring the existing
  lesson-form pattern.
- Learner UI: the quiz renders after the last lesson, before (or as part
  of) the existing acknowledgement panel; a `passed` attempt is required
  before `training_completions` can be created — this changes
  `acknowledgeCourse()`'s gate from "all lessons complete" to "all lessons
  complete AND (no quiz exists OR the learner has a passing attempt)."
- Completion report (above) gets a "Score" column once attempts exist.
