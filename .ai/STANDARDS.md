# CoreX OS — Prime Directive

CoreX OS will become the best and biggest real estate operating system in South Africa.

**Technology Choices:** When multiple options exist, always choose the best one. If there is a superior library, API, approach or architecture — use it. Never choose mediocre when world class is available. Cost is a consideration but never a reason to choose inferior technology when better options exist at the same or similar cost.

**Quality Standard:** Every feature built must work seamlessly. A feature that half-works is not acceptable. Debug it until it works properly or do not ship it.

**Vision:** Johan Reichel brings deep real estate industry knowledge spanning operations, compliance, accounting, and agency management. Claude's role is to convert that knowledge into a flawless operating system — one that sets the industry standard.

---

# CoreX OS — Standards

These are the non-negotiable rules for building CoreX. Every developer, every prompt, every feature must comply.

---

## UX Rules

### Navigation — No Orphaned Pages
Every new page or feature must include a navigation path to reach it. A sidebar link, a button, a contextual action — something. If a user cannot navigate to a page without knowing the URL, the feature is incomplete.

### Soft Deletes — No Hard Deletes
CoreX has a no-hard-deletes policy across the entire platform.
- Show a "Delete" button to users
- The underlying action is always archive/soft-delete (`deleted_at` timestamp)
- Admin can recover any archived record
- Andre is implementing `SoftDeletes` across all models — check before adding new ones

### Confirmations Before Destructive Actions
Any action that archives, removes, or irreversibly changes data must show a confirmation dialog. No silent destructive actions.

### Status Always Visible
Every record that has a status (listing, deal, document, compliance item) must display that status clearly on its card/row. Users should never have to open a record to find out where it stands.

### Loading States
Every async operation must show a loading indicator. No blank screens, no silent waits.

### Mobile Awareness
CoreX is used in the field. Agents use phones. Every new page must be usable on a mobile screen — not necessarily pixel-perfect, but functional.

---

## Execution Rules

### Listen To The User — Non-Negotiable
- When the user describes a specific behaviour they want, build exactly that. Do not build an approximation or a "better" alternative.
- When the user says something is not working, believe them. Do not suggest it might be a different problem.
- When the user asks for shift-all-down, build shift-all-down. Not insert. Not swap. Not popover.
- Read the user's request twice before writing any code. If unclear, ask ONE question. Then build.
- Do not tell the user to test something that has not been verified to address their exact request.

### Document Importer — Lessons Learned
- Blank positions in the HTML are FIXED. They cannot be inserted or removed. Only assignments shift.
- When AI misses one blank, all subsequent fields shift wrong. The fix is shift-assignments, not insert-blank.
- Always send BOTH context_before AND context_after to AI — SA lease documents have blanks BEFORE their labels.
- Claude API errors: always run php artisan config:clear before assuming the key is wrong.
- Right tool for right job: Mammoth for HTML, Claude/OpenAI for field detection only.

### Investigation Before Prompt
Before writing any implementation prompt for Andre, always investigate:
- Exact file paths involved
- Exact method names and line numbers
- Exact model relationships
- Exact migration state

Never guess at structure. Check first.

### Fix Root Causes, Not Symptoms
If something is broken, find why it's broken — not the shortest path to making the error disappear. A symptom fix today becomes a compound rebuild in three months.

### No Quick Patches
Over-engineer for correctness. A solution that solves the problem cleanly once is always better than a workaround that needs revisiting.

### Every Spec Approved Before Build Begins
No module gets built without an approved spec in `/.ai/specs/`. Both Johan and Andre must be aligned on the spec before any code is written. The spec is the contract.

### Settings First
Before building any new module, identify every dropdown, status, type, or category it will use. Ensure those values live in settings tables before the feature is built. Never retrofit settings later.

---

## Architectural Laws

### One Source of Truth Per Data Point
If a piece of data exists in the system, it exists in one place. It is never duplicated across tables unless explicitly denormalized for performance with a documented sync strategy.

### Pillar Linkage is Mandatory
Every record created in any module must link to at least one pillar (Property, Contact, Deal, Agent). A document with no linked property and no linked contact is an orphan. Orphans are forbidden.

### Document Fidelity is Non-Negotiable
A web document rendered to PDF must be character-for-character identical to the intended legal document. No autocorrection. No smart quotes. No rewording. No reformatting. If a word changes, the document is legally compromised.

### Flows Carry Data Forward
When a flow moves from one stage to the next, all relevant data from previous stages is carried forward and pre-filled. Agents never re-enter data the system already knows.

### API Keys and Credentials Live in .env Only
Never in code. Never in the database unless encrypted. Never in comments. `.env` only.

### Database — No SQLite in Repo
`database.sqlite` must be in `.gitignore`. It causes constant merge conflicts and has no place in a MySQL-driven production system.

---

## Code Style Expectations

### Laravel Conventions
- Models in `app/Models/`
- Services in `app/Services/`
- Controllers thin — business logic in services
- Use Eloquent relationships — never raw joins in controllers
- Migrations for every schema change — no manual DB edits on server

### Blade + Alpine.js
- Use Alpine.js for interactivity — no jQuery
- Use corex layout files: `corex-app.blade.php` + `corex-sidebar.blade.php`
- No inline styles — use Tailwind classes
- Component-level CSS in the component, not in global stylesheets unless truly global

### Naming
- Models: PascalCase singular (`Property`, `Contact`, `Deal`)
- Tables: snake_case plural (`properties`, `contacts`, `deals`)
- Routes: kebab-case (`/deals/create`, `/listings/edit`)
- Blade files: kebab-case (`listing-card.blade.php`)

---

## Prompt Execution Rules

### Rule 13: Full CRUD is Non-Negotiable
Every created entity must have create, read, update, and delete paths. No orphan records.

### Rule 14: Every Action Must Be Reversible
Undo, soft-delete, or archive. Never hard delete.

### Rule 15: Read Specs Before Coding
Before any code changes, read CLAUDE.md, STANDARDS.md, and the relevant spec from .ai/specs/. Design decisions in the spec override assumptions.

### Rule 16: Functional Verification Required
php -l and dev-check are necessary but not sufficient. Every feature must be verified via Tinker or equivalent to confirm it actually works end-to-end, not just compiles.

---

## Known Limitations

### View-As vs Switch User (Impersonation)

CoreX has TWO user-perspective features. They are NOT the same:

| Feature | Trigger | What it does | Visibility scopes work? |
|---------|---------|--------------|------------------------|
| **View As** (role dropdown) | Owner header dropdown → "View As [role]" | Swaps `role` + `branch_id` in session ONLY. Auth::user() unchanged. | **NO** — scopes still see original user |
| **Switch User** (impersonation) | Sidebar user menu → "Switch User" → pick user | Full `Auth::login($target)`. Auth::user() fully swapped. | **YES** — all scopes behave correctly |

**Rule: To test visibility-scoped features (ContactScope, CalendarVisibilityResolver, future scopes), use "Switch User" — NOT "View As".**

The "View As" role dropdown is useful ONLY for testing permission/UI gating (what menu items appear, what buttons show). It does NOT affect data visibility scopes because `Auth::user()` remains the original super_admin.

**Impersonation system details:**
- Controller: `App\Http\Controllers\Admin\ImpersonateController`
- Routes: `POST /admin/impersonate/{user}` (start), `POST /admin/impersonate/stop` (exit)
- Permission required: `impersonate_users` or owner role
- Audit log: `impersonation_logs` table (admin_user_id, target_user_id, action, ip, user_agent)
- Banner shown during impersonation (amber "Viewing as [name]" with exit button)
- Session marker: `impersonator_id` stores original admin's id for restoration

**Diagnostic pattern:** If a visibility-scoped feature shows wrong results, check which feature was used. If "View As" → switch to "Switch User" instead. If "Switch User" → the scope has a genuine bug.
