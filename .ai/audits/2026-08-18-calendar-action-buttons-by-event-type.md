# Calendar Event-Detail Panel — Action Button Audit (per appointment class)

> **Status:** READ-ONLY AUDIT. No code was changed. Branch `qa1-deal-external-guard`
> (QA1 SERVING checkout — `/corex-qa1`), verified against real `corex_qa1` data.
> Author: Claude, 2026-08-18. Commissioned by Johan to establish ground truth after
> cherry-picking `2ee1159ad`, `072dad319`, `61d6a8d53` onto this branch.
>
> **Methodology note:** the blade file contains TWO copies of the event-detail
> panel markup. Lines 1156–1430 are a **dead stub** — explicitly wrapped in
> `@if(false)` (line 1161) with the comment *"original location stub… kept
> disabled so the rest of the file's div/template counts stay balanced during
> the move"* (`resources/views/command-center/calendar/index.blade.php:1156-1160`).
> The **live panel** — the one actually rendered (`<aside x-show="panelOpen" …>`
> at `index.blade.php:2093`) — is the block at **lines 2093–2405**. All findings
> below are read from the live block. The dead stub is noted separately in §9
> (Risks) because it is a maintenance trap, not because it renders.

---

## 0. Real `calendar_event_class_settings` global rows (agency_id IS NULL), verified via `php artisan tinker` read-only query against `corex_qa1`

Query script: `/tmp/claude-0/-corex-qa1/39b7c221-5904-47e3-9bdc-a5c1e376743d/scratchpad/query_class_settings.php` (SELECT-only, no writes).

| class | is_active | event_nature | actor_role | completion_behaviour | feedback_mode | occupies_time |
|---|---|---|---|---|---|---|
| viewing | true | **actionable** | buyer_action | **require_feedback** | per_contact | true |
| property_evaluation | true | **actionable** | seller_action | **require_feedback** | per_contact | true |
| listing_presentation | true | **actionable** | seller_action | **require_feedback** | per_contact | true |
| meeting | true | **informational** | both | **freeform** | per_contact | true |
| other | true | **informational** | both | **freeform** | per_contact | true |
| private | true | **informational** | both | **freeform** | per_contact | true |
| task | true | **actionable** ⚠ | neither | **freeform** | per_contact | **false** ⚠ |

⚠ **Two facts about `task` do not match the naive "informational = meeting/other/private/task" assumption embedded in commit `072dad319`'s own reasoning and in spec §15.1's table:**
- `task.event_nature = actionable`, not informational (seeder `database/seeders/CalendarEventClassSeeder.php:103` sets `actor_role`/`completion_behaviour` for task but does **not** set `event_nature` in that map at all — the row's actual `actionable` value comes from elsewhere, either an explicit `#43 task` seed block or the column default. `CalendarEventClassSetting`'s migrations default `event_nature` to `actionable` — task simply never got flipped to informational).
- `task.occupies_time = false` — contradicts spec §15.1's "APPOINTMENT species" table, which lists `task` alongside viewing/meeting/other as `occupies_time = true`. On real qa1 data it is `false` (a marker, not a conflictable appointment).

Practical consequence: because `task.event_nature = actionable`, `task` was **already** getting `is_actionable = true` before commits `072dad319`/`61d6a8d53` ever ran — meaning it already had the plain "Complete" button (§1) and also gets **Dismiss** (§1, gate is `is_actionable` alone) and the top-of-panel "Capture feedback" CTA block if it ever has past+contacts. `supports_plain_complete` including `task` is therefore redundant for the Complete button specifically, but is NOT what gives task any other behaviour — see §3.

`feedback_mode = per_contact` for **all 7** classes on qa1 (the migration `2026_05_11_094044_add_feedback_mode_and_visibility_columns.php:20-23` only explicitly set `viewing => per_contact` and `listing_presentation => per_property`; qa1's actual row shows `listing_presentation.feedback_mode = per_contact`, meaning either the migration's explicit UPDATE never ran on this DB or something else overwrote it afterward — **flagging as unverified**, out of scope to chase further here). This matters because `CalendarController::showFeedback()` branches its ENTIRE response shape on `feedback_mode`, and on qa1 every class (including listing_presentation) currently takes the **per_contact** branch, not per_property. See §5.

---

## 1. AS-BUILT MATRIX — event-detail panel, live block (`index.blade.php:2093-2405`)

All conditions read `panelData.*`, which is the JSON returned by `CalendarController::show()` (`app/Http/Controllers/CommandCenter/CalendarController.php:672-959`).

| Button | Blade condition (file:line) | panelData field | Controller source (file:line) |
|---|---|---|---|
| **Edit** | `index.blade.php:2290` `panelData.is_editable` | `is_editable` | `CalendarController.php:780` — `$isManual` = `source_type` in `{manual, manual:demo}` |
| **Delete** | `index.blade.php:2396` `panelData.is_editable` | `is_editable` | same as Edit |
| **Feedback CTA (top-of-panel)** | `index.blade.php:2277` `panelData.is_actionable && panelData.is_past && panelData.has_contacts` | `is_actionable`, `is_past`, `has_contacts` | `is_actionable` = `!isInformational()` (`CalendarController.php:797`); `is_past` = `event_date->isPast()` (`:778`); `has_contacts` = `linkedContacts()->exists()` (`:779`) |
| **Download viewing pack** (+ Buyer Pack / Agent Sheet) | `index.blade.php:2307` `panelData.linked_viewing_pack && panelData.supports_viewing_pack` | `linked_viewing_pack`, `supports_viewing_pack` | `linked_viewing_pack` built at `CalendarController.php:833-848` via `resolveEventViewingPack()`; `supports_viewing_pack` = `in_array(category, ['viewing','viewings'])` (`:821`) |
| **Create viewing pack** | `index.blade.php:2333` `panelData.is_editable && panelData.supports_viewing_pack` | `is_editable`, `supports_viewing_pack` | as above (no `!linked_viewing_pack` exclusivity clause — removed by `61d6a8d53`) |
| **Capture Feedback to Complete** | `index.blade.php:2345` `panelData.is_actionable && panelData.completion_behaviour === 'require_feedback'` | `is_actionable`, `completion_behaviour` | `completion_behaviour` = `$cfg?->completion_behaviour ?? 'freeform'` (`:813`) |
| **Complete with Reason** (require_reason classes only — none of the 7 target classes) | `index.blade.php:2353` `panelData.is_actionable && panelData.completion_behaviour === 'require_reason'` | same | same |
| **Complete (plain)** | `index.blade.php:2367` `(panelData.is_actionable \|\| panelData.supports_plain_complete) && (!panelData.completion_behaviour \|\| panelData.completion_behaviour === 'freeform')` | `is_actionable`, `supports_plain_complete`, `completion_behaviour` | `supports_plain_complete` = `in_array(category, ['meeting','other','private','task'])` (`CalendarController.php:829`) |
| **Dismiss** | `index.blade.php:2385` `panelData.is_actionable` | `is_actionable` | as above |

### Per-class resolution (target's 7 classes, `panelData` values from real DB + code)

| Class | Edit | Delete | Feedback CTA / Capture Feedback btn | Viewing pack (Download / Create) | Complete (plain) | Dismiss |
|---|---|---|---|---|---|---|
| **viewing** | YES (is_editable) | YES | YES (`completion_behaviour=require_feedback`) | YES / YES (`supports_viewing_pack=true`) | **no** (require_feedback branch consumed it) | YES (is_actionable) |
| **property_evaluation** | YES | YES | YES (require_feedback) | no / no (`supports_viewing_pack=false`) | no | YES |
| **listing_presentation** | YES | YES | YES (require_feedback) | no / no | no | YES |
| **meeting** | YES | YES | **no** (is_actionable=false → no top CTA, no feedback btn) | no / no | **YES** (via `supports_plain_complete`) | **no** (is_actionable=false) |
| **other** | YES | YES | no | no / no | **YES** (supports_plain_complete) | no |
| **private** | YES* | YES* | no | no / no | **YES*** (supports_plain_complete) | no |
| **task** | YES | YES | no (is_actionable=true but require_feedback is false, so falls to plain-Complete branch AND already is_actionable) | no / no | **YES** (via `is_actionable` already true — `supports_plain_complete` redundant here) | **YES** (is_actionable=true) |

`*` — private events: Edit/Delete/Complete only render for the panel data the CREATOR receives. A non-creator gets the redacted `show()` response (`CalendarController.php:718-751`) where `is_editable`, `is_actionable` are hard-set `false` and `supports_plain_complete` is **absent from the payload entirely** (not one of the keys returned in the redacted branch) — so all three buttons are invisible to a non-creator. See §8 for the more important finding: this is a **client-side-only** guarantee for Complete/Dismiss.

**Key observation not in the target matrix:** `Dismiss` currently renders for viewing / property_evaluation / listing_presentation / task (all `is_actionable=true`), but NOT for meeting/other/private (`is_actionable=false`, and `supports_plain_complete` does not gate Dismiss — only the plain-Complete template reads it). The target matrix (given as Johan's stated intent) does not mention Dismiss for any class. **Flagging as ambiguous** — unclear whether Johan wants Dismiss removed from viewing/property_evaluation/listing_presentation/task, added to meeting/other/private, or left exactly as-is (simply not mentioned because it wasn't the focus of this exercise). Not decided here.

---

## 2. GAP LIST — as-built vs target

Target (Johan's stated intent):
- listing_presentation: Edit, Delete, Capture feedback
- property_evaluation: Edit, Delete, Capture feedback
- viewing: Edit, Delete, viewing-pack button(s), Capture feedback
- meeting / other / private / task: Edit, Delete, Mark complete

**Result: as-built matches the target exactly for all 7 classes**, given the two cherry-picked commits (`072dad319`, `61d6a8d53`) are in the working tree (confirmed — see below). Zero gaps found on the button-presence axis.

| Class | Target | As-built | Gap? |
|---|---|---|---|
| listing_presentation | Edit·Delete·Feedback | Edit·Delete·Feedback | none |
| property_evaluation | Edit·Delete·Feedback | Edit·Delete·Feedback | none |
| viewing | Edit·Delete·Pack·Feedback | Edit·Delete·Pack(Download+Create)·Feedback | none |
| meeting | Edit·Delete·Complete | Edit·Delete·Complete | none |
| other | Edit·Delete·Complete | Edit·Delete·Complete | none |
| private | Edit·Delete·Complete | Edit·Delete·Complete (creator-only) | none on presence; see §8 for an authorization gap on the same button |
| task | Edit·Delete·Complete | Edit·Delete·Complete | none |

**Extra buttons beyond the target list (not gaps against the stated matrix, but worth naming since the task asked for every button that renders):**
- **Dismiss** on viewing/property_evaluation/listing_presentation/task (not mentioned in target at all — see §1's ambiguity flag).
- **Capture Feedback to Complete** vs a separate top-of-panel **"Capture feedback →"** CTA — these are TWO different renderings of essentially the same action (`openFeedbackModal`), one in the sticky footer (`index.blade.php:2345`, gated on `completion_behaviour==='require_feedback'`) and one inline in the scrollable body (`index.blade.php:2277`, gated on `is_actionable && is_past && has_contacts`). Both can show simultaneously on a past viewing/property_evaluation/listing_presentation event that has contacts — this is not a "gap" against the target (target just says "Capture feedback" once) but is a duplicate-affordance UI quirk worth flagging.

**Confirming the cherry-picks are actually live in the checkout** (not just in git log): `grep -n "supports_viewing_pack\|supports_plain_complete" app/Http/Controllers/CommandCenter/CalendarController.php` returns lines 821 and 829 in the current working tree, and `git diff HEAD` is empty — the three commits' changes are present in `/corex-qa1`'s working files despite each commit message's own footer claiming "Worktree-only; not applied to /corex-qa1 (serving)". **That footer text is stale/incorrect relative to the actual state of this checkout as of this audit** — flagging as a discrepancy worth Johan's attention (not fixed here, per scope).

---

## 3. DERIVED vs HARDCODED

| Button | Driven by | Quote |
|---|---|---|
| Edit / Delete | DB-derived (`source_type`) | `'is_editable' => $isManual` where `$isManual = in_array($calendarEvent->source_type, ['manual', 'manual:demo'])` (`CalendarController.php:757,780`) |
| Feedback CTA / Capture-Feedback-to-Complete | DB-derived (`event_nature` via `effectiveEventNature()`, `completion_behaviour`) | `'is_actionable' => !$calendarEvent->isInformational()` (`:797`); `completion_behaviour` read straight off `CalendarEventClassSetting` (`:813`) |
| Viewing pack (both templates) | **Hardcoded class-slug list** | `'supports_viewing_pack' => in_array($calendarEvent->category, ['viewing', 'viewings'], true)` (`CalendarController.php:821`) |
| Complete (plain) | **Additive OR of DB-derived (`is_actionable`) and a hardcoded class-slug list** | `'supports_plain_complete' => in_array($calendarEvent->category, ['meeting', 'other', 'private', 'task'], true)` (`:829`) |
| Dismiss | DB-derived (`is_actionable`) | `index.blade.php:2385` `panelData.is_actionable` |

**Column-derived equivalent, if `supports_viewing_pack`/`supports_plain_complete` were replaced with pure column reads:**

- `supports_viewing_pack` has **no existing column to derive from at all** — there is no "supports a viewing pack" flag anywhere in `calendar_event_class_settings`. The nearest candidate is `actor_role = 'buyer_action'`, which today is true ONLY for `viewing` (confirmed by seeder + tinker query, §0) — so `actor_role === 'buyer_action'` would currently reproduce the same result as the hardcoded list, with **zero classes disagreeing** among the 7 target classes. It is a coincidence of the current seed data, not a structural guarantee — nothing stops a future `buyer_action` class being added that has nothing to do with viewing packs (or `viewing`'s `actor_role` being repurposed away from `buyer_action` for an unrelated reason), silently gaining/losing pack buttons. **No class in the 7-class target disagrees with this substitution today.**

- `supports_plain_complete` — the natural column-derived read is `completion_behaviour === 'freeform'` (i.e. NOT `!is_actionable`, since that was already tried and rejected precisely because it breaks the RAG/overdue semantics — see the commit message reasoning). Checking `completion_behaviour === 'freeform'` against the real qa1 data (§0): meeting=freeform ✓, other=freeform ✓, private=freeform ✓, task=freeform ✓ — **and also matches for every OTHER freeform class not in the 7** (e.g. any source-driven freeform marker class), which is the whole point of the `is_actionable` side of the OR already being freeform-based. So `completion_behaviour === 'freeform'` alone would functionally subsume `is_actionable`'s freeform branch too, making `supports_plain_complete` as a separate hardcoded list **redundant with a column that already exists** for these 4 classes on qa1's current data. **No class among the 7 disagrees** — flipping to `completion_behaviour === 'freeform'` (dropping the hardcoded list) would reproduce the same result for meeting/other/private/task/viewing/property_evaluation/listing_presentation, because none of those 7 have `completion_behaviour = freeform` values that contradict the target matrix. This is a genuine "could be derived, isn't" finding — reported per the task's ask, not fixed.

**Why the commit author chose the hardcoded list anyway** (from the commit message, `072dad319`): the stated reason was to avoid touching `is_actionable`/`event_nature` itself (a broader semantic used for RAG/overdue/digest), which is legitimate — but it does not explain choosing a **class-slug list** over a **narrower column read** (`completion_behaviour`). This is a design choice worth Johan's review, not a correctness bug — flagged, not changed.

---

## 4. VIEWING PACK

**Current condition (post `61d6a8d53`):**
- **Download viewing pack**: `index.blade.php:2307` — `panelData.linked_viewing_pack && panelData.supports_viewing_pack` (unchanged by `61d6a8d53` — it never had an exclusivity clause to begin with, per that commit's own message).
- **Create viewing pack**: `index.blade.php:2333` — `panelData.is_editable && panelData.supports_viewing_pack`. The diff that changed this (`61d6a8d53`) dropped `!panelData.linked_viewing_pack &&` from the condition — i.e. before this commit, Create only showed when NO pack existed yet (mutually exclusive with Download); after, Create shows unconditionally on every editable viewing event regardless of whether a pack already exists, so both buttons can render together.

**How an existing pack is resolved (`CalendarController::resolveEventViewingPack()`, `:1014-1027`):**
```php
$linked = $calendarEvent->viewingPack()->first();      // direct FK: viewing_packs.calendar_event_id
if ($linked) { return $linked; }
$buyerId = $this->eventBuyerContactId($calendarEvent);  // contact_id, else calendar_event_links buyer/attendee row
if (! $buyerId) { return null; }
return \App\Models\ViewingPack::where('contact_id', $buyerId)->orderByDesc('id')->first();  // buyer's MOST RECENT pack, regardless of properties
```
Two-strategy resolution: **direct FK first** (`ViewingPack.calendar_event_id`), **falling back to a buyer-based lookup** (most-recent `ViewingPack` for the event's buyer contact) when no direct link exists. This is confirmed structural (not conjecture) at `app/Models/CommandCenter/CalendarEvent.php:163-165`: `viewingPack(): hasOne(ViewingPack::class, 'calendar_event_id')->latestOfMany()`.

**Can a single viewing event have more than one candidate pack?** Yes, architecturally — `hasOne(...)->latestOfMany()` on `viewingPack()` proves the schema permits multiple `ViewingPack` rows sharing one `calendar_event_id` (it silently resolves to the newest by id). On real qa1 data, **zero** events currently have >1 directly-linked pack (verified: `ViewingPack::whereNotNull('calendar_event_id')->groupBy('calendar_event_id')->having('cnt','>',1)` → 0 rows), but the `61d6a8d53` change is explicitly designed to let an agent **create an additional pack** on top of an existing one ("start an ADDITIONAL pack… rather than being locked out"), so multiplicity is an intended near-future state, not just a theoretical one.

**Staleness — verified against real qa1 data** (script: `/tmp/claude-0/-corex-qa1/39b7c221-5904-47e3-9bdc-a5c1e376743d/scratchpad/query_packs.php`, comparing each viewing event's currently-linked properties — `calendar_event_links` role=Property + `property_id` — against the resolved pack's `viewing_pack_properties`):

- 61 total `viewing`-category events on qa1.
- Of the first 500 (all 61) scanned: **1 event has a direct FK link** (event #7047 → pack #33), **15 resolve via the buyer fallback**, **45 have no resolvable pack at all**.
- **7 of the 16 resolvable pairs have a property-set mismatch** — the pack's properties do not equal the event's currently-linked properties:

  | Event | Event's linked properties | Resolved pack | Pack's properties | Resolution path |
  |---|---|---|---|---|
  | #6495 | [1894, 3394, 3927, 5794] | #2 | [3394, 3927, 5874] | buyer fallback |
  | #6507 | [1311, 1505, 2850, 3560] | #39 | [1311, 3335] | buyer fallback |
  | #6670 | [5747] | #14 | [1377] | buyer fallback |
  | #6754 | [1311] | #28 | [1311, 3587] | buyer fallback |
  | #6755 | [3587] | #28 | [1311, 3587] | buyer fallback |
  | **#7047** | [1311, 1519] | **#33 (direct FK)** | **[] (empty)** | direct link |
  | #7188 | [3335, 3522] | #39 | [1311, 3335] | buyer fallback |

  Event #7047 is the SAME event `072dad319`'s and `61d6a8d53`'s own commit messages cite as their verification case ("Viewing #7047 (pack #33 directly linked): Download=YES, Create=YES") — and on real data, that "linked" pack has **zero properties attached**, while the event itself links two different properties. This is a real, present-day instance of exactly the staleness the audit asked about, on the very event used to sign off the two commits.

**Answer: yes**, there is a code path (the buyer-fallback branch, and even the direct-FK branch as #7047 shows) where a resolved pack's property set does not match the event's current property set. Nothing in `resolveEventViewingPack()` or the panel compares the two sets or surfaces a staleness warning — the UI just shows whatever pack resolves, with a bare property_count badge.

---

## 5. FEEDBACK FLAVOURS

Two distinct **UI surfaces**, both writing through the **same controller endpoints and the same table**:

| Surface | Blade / component | Route | Controller action | Table written |
|---|---|---|---|---|
| Calendar inline feedback modal | `index.blade.php` (`feedbackData`/`openFeedbackModal`, `:3811` onward; per-property fields `:1502-1520`, per-contact fields `:1597`) | `command-center.calendar.feedback.show` / `.feedback.store` | `CalendarController::showFeedback` (`:1044`) / `::storeFeedback` (`:1197`) | `calendar_event_feedback` |
| AT-114 "feedback from anywhere" reusable modal | `resources/views/command-center/calendar/_event-feedback-modal.blade.php`, included from `_linked-events.blade.php:217` | **same two routes** (`_event-feedback-modal.blade.php:180-181`: `SHOW_URL`/`STORE_URL` = `route('command-center.calendar.feedback.show'/'feedback.store', …)`) | **same** `showFeedback`/`storeFeedback` | **same** `calendar_event_feedback` |

**Is the seller-side capture (property_evaluation / listing_presentation) the SAME modal/table as the buyer-side (viewing)?** Yes and no, depending on class:
- **listing_presentation** on qa1's own data takes the **per_contact** branch too (§0 — `feedback_mode = per_contact` for every class, including listing_presentation, on this DB), so today it goes through the identical `viewing`-shaped code path (`feedback_kind = 'viewing'`, `CalendarController.php:1142-1195`, writes `outcome_option_id`/`concern_option_ids`/`seller_visible_notes` per contact).
- **property_evaluation** — same, `feedback_mode` defaults to `per_contact` (no explicit override anywhere in migrations/seeders — confirmed by grep, only `viewing` and `listing_presentation` are ever explicitly set, and qa1's actual listing_presentation row is also `per_contact`) — so it too takes the per-contact branch.
- **If** an agency's `listing_presentation.feedback_mode` were `per_property` (which is what the migration originally intended and what live is reported to actually have, per `2ee1159ad`'s commit message), it takes a **structurally different branch** of the **same table/controller**: `feedback_kind = 'listing_presentation'`, keyed by `property_id` not `contact_id`, storing `kind_specific_data` (JSON) instead of `outcome_option_id`/`concern_option_ids` (`CalendarController.php:1092-1140` read side, `:1237-1259` write side).

So: **one table (`calendar_event_feedback`), one pair of routes/controller actions, two UI entry-point components, and two internal storage SHAPES** (per-contact outcome/concern columns vs per-property `kind_specific_data` JSON) selected by `feedback_mode`, not by class identity per se.

**What `2ee1159ad` actually switches — wording/labels only, or also fields/table?**

Quoted from the code (`CalendarController.php:1073-1074`):
```php
$isSellerFacing = ($cfg?->actor_role ?? null) === 'seller_action';
$outcomeCategory = $isSellerFacing ? 'lp_outcome' : 'outcome';
```
This changes **which row-set is queried** from `agency_feedback_options` (`:1078-1090` — `AgencyFeedbackOption::where('category', $outcomeCategory)`), i.e. it changes the actual **list of selectable outcome options** returned to the client (different `id`s, different labels — "Mandate signed / Considering / Lost…" vs "Interested / Not interested / Made offer…"), not merely a label string. It does **not** change:
- which table is written (`calendar_event_feedback`, unchanged),
- which columns are written (`storeFeedback()` is explicitly untouched per the commit message — "storeFeedback() (write path) needed NO change"),
- the grouping/shape decision (`feedback_mode`, completely independent, unchanged).

So it is **field-selection** (which option rows populate the dropdown → which `outcome_option_id` FK value ultimately gets written), not a table or write-path change, and not purely cosmetic wording either — a buyer-facing viewing captured post-fix writes an `outcome_option_id` FK from the `outcome` category; a seller-facing property_evaluation/listing_presentation captured post-fix writes one from `lp_outcome`. Pre-fix, per_property-mode classes always got `lp_outcome` and per_contact-mode classes always got `outcome`, regardless of who the appointment was actually for — the exact "backwards" bug the commit describes.

**Cross-check bug found while reading (out of scope, reported not fixed):** `app/Http/Controllers/CoreX/ContactController.php:218` (buyer-perspective) and `:284` (seller-perspective) both hardcode `->where('category', 'outcome')` when building the label lookup for the Contact page's viewings/feedback tab — **neither reads `lp_outcome`**. Since `2ee1159ad` only touched `CalendarController::showFeedback()`, a seller-facing (property_evaluation, and listing_presentation-if-per_contact) feedback row's `outcome_option_id` now references an `lp_outcome`-category option id, but `ContactController.php:284`'s `$sOutcomes` lookup map only contains `outcome`-category id→label pairs — so that `outcome_option_id` will not resolve to any label there, and the Contact page's seller-perspective feedback block will show a **blank/missing outcome badge** for exactly the classes this fix was meant to correct. See §7 for the fuller picture.

---

## 6. PRESENTATION FEEDBACK

**Yes**, a separate mechanism exists: `presentation_outcomes` (migration `database/migrations/2026_06_01_080001_create_presentation_outcomes_table.php`). One row per `Presentation` (`UNIQUE(presentation_id)`), enum `outcome` (`won_mandate`, `won_sale`, `lost_to_competitor`, …), `cancellation_reason`, `resulted_in_deal_id` → `deals.id`. This is the "Modal C" both `072dad319` and `2ee1159ad`'s commit messages explicitly call "out of scope, untouched."

**Is a `listing_presentation` calendar event linked to a `Presentation` record today?** **No — nothing at all.** Checked:
- `presentations` table columns (`2026_02_20_200000_create_presentations_table.php`): `branch_id`, `created_by_user_id`, `listing_id` (nullable, → a listing, not a calendar event), `title`, `status`, `currency`. No calendar-event reference.
- `calendar_events` table / `CalendarEvent` model: no `presentation_id` column, and `source_type`/`source_id` (the generic polymorphic pair used for deal-step-linked events etc.) is never set to a `Presentation` anywhere in the codebase — grepped every `CalendarEvent::create`/`new CalendarEvent` call site (`AutoEventService`, `CalendarEventCreator`, `CalendarEventService`, `CalendarEventObserver`, `LeaveCalendarService`, `ProvisionalPointService`, `CommandCentreService`, `CommandCenterApiController`, `DashboardController`) — none reference `Presentation`.
- `presentation_outcomes` itself only FKs to `presentation_id`, `agency_id`, `resulted_in_deal_id` — no calendar-event column.

**Plainly: wiring calendar-event feedback into presentation feedback is NOT possible today without new schema.** It would require at minimum a new nullable column (either `calendar_events.presentation_id` or `presentation_outcomes.calendar_event_id`) or a pivot table — there is no existing FK, pivot, or morph reference of any kind connecting the two today.

---

## 7. WHERE FEEDBACK LANDS

**Yes**, captured event feedback (from §5's surfaces) DOES surface on the Contact record — but only reliably for one of the two vocabularies.

**Location:** `resources/views/corex/contacts/show.blade.php:1604-1644` ("Viewings & Feedback" section — buyer perspective at `:1604-1635`, seller perspective further down, both fed the same `$sv['feedback']` shape). Data assembled in `app/Http/Controllers/CoreX/ContactController.php` `show()`:
- **Buyer perspective** (`:186-240`): every `calendar_event_links` row where this Contact appears, joined to `calendar_event_feedback` **by `contact_id`** (`:213-215`), label resolved from `agency_feedback_options` category `'outcome'` (`:218`).
- **Seller perspective** (`:242-312`): every property this Contact owns (`contact_property` pivot), joined to `calendar_event_feedback` **by `calendar_event_id`** for events where a `subject_property` link matches an owned property (`:276-281`), label also resolved from category `'outcome'` only (`:284`).

**Table:** `calendar_event_feedback` (same table as §5, read directly via `DB::table(...)`, not the Eloquent model).

**The gap (confirmed in §5, restated here as the "where it lands" consequence):** because both the buyer-perspective (`:218`) and seller-perspective (`:284`) label lookups on the Contact page hardcode `category = 'outcome'`, and `2ee1159ad` made seller-facing captures (property_evaluation, and any per_contact-mode listing_presentation) write an `outcome_option_id` from the `lp_outcome` category instead, **seller-facing feedback captured after `2ee1159ad` will show no outcome label on the Contact page** (the FK won't resolve against the `'outcome'`-only lookup map) — only the free-text notes fields (`seller_visible_notes`, `internal_notes`) would still render, since those are stored inline on the row, not via a category lookup. Buyer-facing (viewing) feedback is unaffected and surfaces correctly.

Separately: **per-property (`feedback_kind = 'listing_presentation'`) rows never populate `outcome_option_id` at all** (`CalendarController.php:1239-1256` writes `kind_specific_data` instead) — so if any agency's `listing_presentation.feedback_mode` is actually `per_property` (as `2ee1159ad`'s commit message says live's config is), those rows would show **no outcome badge on the Contact page under either category**, only whatever the seller-perspective query's other fields happen to carry (`seller_notes`/`captured_at` are read from the row's own columns, which per-property rows don't populate either — `internal_notes`/`next_action_notes` are the only fields per-property rows actually fill). This is a genuine, unresolved read-side gap between the calendar's own feedback modal (fixed by `2ee1159ad`) and the Contact page's separate read query (not touched by that commit) — reported per rule 2, not fixed.

---

## 8. PRIVATE + INFORMATIONAL

**Verified `event_nature` from real DB (§0):** `meeting`, `other`, `private` = `informational`. `task` = `actionable` (not informational — see the §0 callout). This matches the target's expectation for meeting/other/private but the audit could not confirm task was ever intended to be informational — the seeder's `#43 task` block and its `actor_role`/`completion_behaviour` map (`CalendarEventClassSeeder.php:103`) never set `event_nature`, and the live row shows `actionable`.

**ProcessReminders overdue gate** (`app/Console/Commands/CommandCenter/ProcessReminders.php:37-54`):
```php
$informationalClasses = CalendarEventClassSetting::withoutGlobalScopes()
    ->where('event_nature', 'informational')->pluck('event_class')->all();
$overdueCount = CalendarEvent::where('status','pending')
    ->where(fn($q) => $q->where('metadata->event_nature','actionable')
        ->orWhere(fn($q2) => $q2->when(!empty($informationalClasses), fn($q3)=>$q3->whereNotIn('category',$informationalClasses))))
    ->update(['status' => 'overdue']);
```
So `meeting`/`other`/`private` never go overdue (correct, consistent with the target's implicit assumption that these are "informational time-blocks"). `task`, being `actionable`, **does** go overdue via this sweep — a behaviour difference from the other 3 "plain-complete" classes that the target matrix's grouping (meeting/other/private/task all get "Mark complete") does not distinguish. This is not a bug in the button-rendering logic audited here, but it is a real behavioural inconsistency across the 4 classes the target treats as one group — flagged for Johan's awareness.

**Does `markCompleted()`/`markDismissed()` treat informational events differently in a way that breaks the button?** No — both are trivial status writes (`CalendarEvent.php:293-301`, just `$this->update(['status' => 'completed'|'dismissed'])`), nature-agnostic. No inconsistency found in the completion flow itself.

**Private class — are Edit/Delete/Complete gated to the creator only, or visible to any agent who can see the event?**

- **UI rendering**: creator-only, effectively — a non-creator's `show()` response is the redacted branch (`CalendarController.php:718-751`) where `is_editable=false`, `is_actionable=false`, and `supports_plain_complete` is not even a key in that JSON — so none of Edit/Delete/Complete/Dismiss render for a non-creator in the panel.
- **Server-side enforcement is NOT uniform across the four mutating endpoints:**

  | Endpoint | Creator-only guard? | Evidence |
  |---|---|---|
  | `show()` | YES | `isPrivateHiddenFrom($user)` at `:718` |
  | `update()` | YES | `:1635` |
  | `destroy()` | YES | `:1772` |
  | `reschedule()` | YES | `:1588` |
  | **`complete()`** | **NO** | no `isPrivateHiddenFrom` call anywhere in the method (`:1845-1897`) |
  | **`dismiss()`** | **NO** | no `isPrivateHiddenFrom` call anywhere in the method (`:1899-1919`) |

  `complete()`/`dismiss()` only check `visibilityResolver->canSee()` — and `canSee()` (`app/Services/CommandCenter/Calendar/CalendarVisibilityResolver.php:27-58`) returns `true` for an admin/owner in the same agency, or an invited attendee, **regardless of the private flag**. So a same-agency admin/owner (or an attendee of the event) who can "see" someone else's private event as a busy block **can complete or dismiss it via a direct POST to `/calendar/{id}/complete`/`/dismiss`**, even though the UI never shows them the button. This is a real authorization gap on the same "Mark complete" button the target matrix asks for on `private` events — reported, not fixed (see §9).

---

## 9. RISKS

1. **Private-event Complete/Dismiss authorization gap (§8).** `CalendarController::complete()` (`:1845`) and `::dismiss()` (`:1899`) lack the `isPrivateHiddenFrom()` guard that `show()`/`update()`/`destroy()`/`reschedule()` all have. This predates the three cherry-picked commits (it is not something `072dad319`/`61d6a8d53` introduced — `supports_plain_complete` only affects rendering, not the endpoint), but implementing the target matrix's "private: … Mark complete" literally, by adding more visibility to the Complete button, makes this pre-existing gap more likely to be exercised (more legitimate reasons for a non-owner to see/click near a private event). Worth closing alongside, per Johan's direction — flagged, not fixed here.

2. **Dead stub block (`index.blade.php:1156-1430`, wrapped `@if(false)`) does not carry the same gates as the live block.** It still uses `panelData.is_actionable` alone for the freeform-Complete button (no `supports_plain_complete` OR) and still has the pre-`61d6a8d53` viewing-pack shape (a self-contained `panelData.viewing_pack.linked` object, ungated by `supports_viewing_pack`, though `buildEventViewingPack()`'s own server logic already restricts `launch_url` to `$isViewing` at `:996`). It is currently inert (`@if(false)`), so it renders nothing — but it is dead code that will silently diverge further from the live block on every future calendar change, and its own "kept… so div/template counts stay balanced" framing suggests it exists only to avoid a larger refactor. Not a rendering risk today; a maintenance/confusion risk (a future edit "fixing a bug" in the wrong copy) and a genuine code-cleanliness debt. Reported per rule 2 (found outside the exact task, not touched).

3. **Cherry-pick commit messages claim "Worktree-only; not applied to /corex-qa1 (serving)" but the changes ARE present in this checkout** (§2). Either the commit messages are stale relative to what actually landed, or this checkout is not the branch state the commit author believed they were leaving it in. Flagging as a provenance discrepancy Johan should be aware of before trusting any other "not applied to X" claim in these three commits' messages (e.g. the LIVE APPLY steps sections, which describe live's state as of whenever those commits were authored and may also be stale).

4. **`supports_viewing_pack`/`supports_plain_complete` as hardcoded class-slug lists (§3) vs. deriving from `actor_role`/`completion_behaviour`.** Both substitutions were checked and, on today's real qa1 data, reproduce the target matrix identically for all 7 classes — but nothing prevents future class-config drift (e.g. someone adds a new `buyer_action` class unrelated to viewing packs, or flips `task.completion_behaviour` away from `freeform` for an unrelated reason) from silently breaking the hardcoded list without updating it, per non-negotiable #10 spirit ("class flags by actor_role, never a hardcoded class list" — `.ai/specs/calendar-interactive.md:204`, §13.6). **This directly contradicts the calendar spec's own stated invariant** ("Class flags are by `actor_role`, never a hardcoded class list, in every seeder/backfill" — §13.6) — though that invariant is scoped to "every seeder/backfill," and `supports_viewing_pack`/`supports_plain_complete` are computed at request-time in the controller, not in a seeder/backfill, so it is a stretch rather than a direct violation. Flagged as a spec-tension worth Johan's explicit call, not treated as a hard violation.

5. **`task.event_nature = actionable` (§0, §8) contradicts spec §15.1's species table**, which lists `task` under APPOINTMENT species with `occupies_time = true` — real data shows `occupies_time = false`. This is a pre-existing spec/data mismatch unrelated to the three cherry-picked commits; not caused or touched by this work, but surfaced by this audit's data verification. Reported per rule 2.

6. **Contact-page outcome-label lookup gap (§5, §7)** — `ContactController.php:218,284` hardcode `category='outcome'`, unpatched by `2ee1159ad`. Implementing the target matrix does not touch this file, so it is not a regression risk from THIS change, but it means "Capture feedback" on property_evaluation/listing_presentation (as the target matrix asks for) will keep landing on a Contact page that silently drops the outcome label for those captures. Reported, not fixed.

None of the three cherry-picked commits appear to undo anything documented in `calendar-interactive.md` — the two AT-111 viewing-pack behaviours (§4 there is silent on pack multiplicity, so no explicit spec was violated), the AT-114 feedback-from-anywhere modal (§5, unaffected — same routes/table), and `event_nature`/`is_actionable`'s broader RAG/overdue/digest semantics (§6 of the spec) were explicitly preserved by design (`072dad319`'s stated reasoning). No contradiction with the spec found for the 3 commits themselves — items 1, 2, 4, 5, 6 above are all **pre-existing conditions surfaced by, not caused by,** the 3 commits.

---

## Questions NOT fully answerable from code/data alone

1. **Whether `Dismiss` should appear on meeting/other/private, or be removed from viewing/property_evaluation/listing_presentation/task** — the target matrix is silent on Dismiss entirely; no code artifact resolves the intent either way. Needs Johan's explicit call.
2. **Whether `listing_presentation.feedback_mode` "should" be `per_property` on qa1** — the migration explicitly set it to `per_property`, `2ee1159ad`'s commit message asserts live has it as `per_property`, but qa1's actual row is `per_contact`. Could not determine from code alone whether this is a qa1 seed-drift bug, a deliberate qa1-specific override, or evidence the migration's explicit UPDATE never executed on this DB — would need DB migration-run history (`migrations` table timestamps) cross-referenced against a fresh install, which is beyond a read-only code/data audit of button gating.
3. **Whether `task.event_nature` was deliberately left `actionable`** (letting it go overdue and get Dismiss, unlike meeting/other/private) or is an oversight in the seeder's `#43 task` block never setting it — no comment or spec text states an intent either way.
4. **Whether the private-event Complete/Dismiss authorization gap (§8) is already known/accepted or is a genuine unnoticed hole** — could not find any prior audit, test, or spec text acknowledging it; `tests/Feature/` was not searched exhaustively for a private-event complete/dismiss authorization test (running the suite was out of scope per instructions), so it's possible a test already covers this and the gap is smaller than it looks from static reading alone.
