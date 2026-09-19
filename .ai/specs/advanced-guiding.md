# Spec: Advanced Guiding + Spot Help (Ellie-driven, hands-on guides)

**Status:** DRAFT — awaiting approval · Lane: QA2 · Drafted 2026-09-19
**Related:** `.ai/specs/ellie-tour-knowledge.md`, `.ai/specs/ellie-navigation-atlas.md`,
`.ai/specs/ellie.md`, `app/Support/Tours/TourRegistry.php`

---

## 1. What this feature does and why

Today CoreX has 90 guided tours (389 steps). A tour **explains** a page: it highlights a
box, says what it is for, and the agent presses Next. Ellie can read those tours and
repeat the steps back as text. Neither of them actually walks the agent through the
work.

This feature adds two new ways to get help, both built on the same tours:

| Mode | What the agent experiences |
|------|---------------------------|
| **Guided Tour** (exists, unchanged) | Click-through explanation of the page. Next → Next → Done. |
| **Advanced Guide** (new) | Hands-on. "Step 1 — type the headline here." The guide waits. When the agent has filled it in correctly, it moves to Step 2 **by itself**. It carries on until the job is actually done. |
| **Spot Help** (new) | Same as Advanced Guide, but runs **only the one part** the agent is stuck on, e.g. just "Adding spaces" on a property. |

**Ellie ties it together.** When an agent asks Ellie "how do I add spaces to a
property?", Ellie answers in a sentence and shows buttons under her reply, e.g.
**[Guide me through it]** or **[Spot Help: Adding spaces]**. One click takes the agent to
the right page and starts the guide there.

**Why:** CoreX Operating Principle #4, "built for agents, not for screens." An agent who
is stuck should be *taken through* the task on the real screen instead of reading about it.
Principle #5 also applies: the guide never types, picks or saves anything for the agent.
The agent always does the work, and the guide only points and waits.

## 2. Pillars

Cross-cutting help surface. Reads the tour catalogue and the **Agent** pillar (the
user's permissions, via `TourRegistry::visibleTo`). It writes only the agent's own
help progress, which already exists (`user_tour_progress`). It creates no pillar data
and changes none. The guide only watches the agent use the real screens.

No cross-pillar reactivity, so no domain events (Non-negotiable #9 not triggered).

## 3. How the agent reaches it

### 3.1 The "?" icon (every page that has a guide)
The existing "?" icon in the page header stops starting the tour straight away. It now
opens a small menu:

```
  ?  ─┬─ Guided Tour         (explain this page)
      ├─ Advanced Guide      (walk me through doing it)
      └─ Spot Help        ▸  Adding spaces
                             Features
                             AI photo scan
                             …
```
- An option appears only if this page's guide supports it. A page with no hands-on
  steps written yet shows only "Guided Tour", so no option is ever empty.
- Spot Help lists this page's sections by plain name.

### 3.2 Ellie
- When Ellie looks up a how-to (her existing `find_how_to` lookup), the guides she
  found come back with her reply as **buttons**. The buttons are built from the real
  guide catalogue, not from Ellie's wording, so Ellie can never offer a guide that does
  not exist or one for a page the agent is not allowed to open.
- The buttons are **Guide me through it** (Advanced Guide) and, when the question
  is about one part, **Spot Help: <section>**.
- Clicking a button:
  - **Already on the right page:** the guide starts right there.
  - **Different page, but a page you can open directly** (e.g. New Property): CoreX
    opens that page and the guide starts when it loads.
  - **Page that needs a specific record** (e.g. Spaces lives on one property's page):
    CoreX takes the agent to the list (My Listings) with a short note: "Open the
    property you want to work on and I'll pick up from there." The guide starts as
    soon as they open a property. If they wander off for more than 30 minutes, the
    pending guide lapses quietly.
- Ellie's panel stays open beside the guide. While a guide is running, the step card
  shows next to the highlighted box and never covers it.

## 4. How a hands-on step behaves

Each step highlights one thing and gives a short instruction. Every step in a guide is
one of these three kinds:

| Step kind | Moves on when… | Example |
|-----------|----------------|---------|
| **Fill in** | the box has a value that the page itself accepts (same rules as the page's own validation, e.g. a suburb must be picked from the list, not typed freely) | "Type a headline buyers will see" |
| **Choose / click** | the agent makes the choice or clicks the highlighted button | "Pick For Sale or For Rental", "Click Add to add a space type" |
| **Read** | the agent presses **Got it** (explanation-only steps) | "These four steps are Basics, Photos, Details, Review" |

Rules for every hands-on step:
1. **Moves on by itself** once the step is done. A short "✓ Done" flash shows first, so
   the agent sees it register.
2. **Back, Skip this step and Stop** are always visible. Stop ends the guide and leaves
   everything the agent typed exactly as it is.
3. **Never a dead end.** If a step's box isn't on screen (hidden by the agent's role, or
   a section that doesn't apply, e.g. rental fields on a sale), that step is skipped.
4. **Follows the page.** When the screen changes under the guide (the property
   wizard moves from Basics to Photos, or Save opens the new property's page), the
   guide continues on the new screen if its next step is there.
5. **The guide never does the work.** It never types, picks, uploads or saves for
   the agent. The one exception is opening a collapsed panel so the highlighted box can
   be seen. Existing tours already do this.
6. **Asked for means shown.** A Guided Tour hides steps the agent has already seen (so
   it doesn't nag). An Advanced Guide or Spot Help that the agent **asked for** always
   runs every step of the job.
7. Advanced Guide and Spot Help **never start on their own**. Only the agent starts
   them, from the "?" menu or from Ellie. The existing auto-start of Guided Tours on a
   first visit is unchanged.

## 5. What gets built in this release

### 5.1 The engine (works for every page)
One guide definition powers all three modes. Existing tours gain optional extras:
a **section** name per step (for Spot Help) and a **"moves on when"** rule per step (for
Advanced Guide). A tour without these extras keeps working exactly as it does today.

### 5.2 Hands-on guides written in this release
| Page | Advanced Guide | Spot Help sections |
|------|----------------|--------------------|
| New Property wizard (all 4 steps: Basics → Photos → Details → Review) | ✓ | Basics · Location · Photos · Details · Review & publish |
| Property page — **Spaces & Features** (new guide; this page has none today) | ✓ | Adding spaces · Room features · Property features · AI photo scan |
| Capture a contact | ✓ | Required details · Contact type · Finding contacts |

The property page also gets a normal **Guided Tour** built from the same steps, so its
"?" icon appears. Like every tour, it auto-starts once per agent on their first visit to
a property page.

The other 87 tours keep working as Guided Tours. They gain Advanced Guide and Spot Help
one module at a time as their hands-on steps are written. Each one is a content-only
change (§8) that adds nothing to the engine.

## 6. Data model / migrations

**None.** The guides are code-defined, like today's tours.
- Progress uses the existing `user_tour_progress` table (seen / dismissed / per-step).
- A guide that is waiting for its page, or that follows the agent across a screen change,
  is kept in the agent's own browser tab only, with a 30-minute limit. Nothing about it
  is stored on the server.

**CRUD floor (CLAUDE.md rule 8):** not applicable. Guides are not agency records. Agents
and admins do not create, edit or archive them, and they are written in code alongside
the screens they describe, like the existing tours.

## 7. Permissions and scoping

- **No new permission keys.** Help for a page is exactly as visible as the page itself.
  Every guide inherits its page's permission through the existing
  `TourRegistry::visibleTo` (owner bypass → explicit tour `permission` → the route's
  permission). Ellie's buttons are filtered through the same check, and so are the "?"
  menu and the Guided Tours directory.
- The whole feature sits behind the existing `guided-tours` feature switch, so an agency
  that has guided tours off gets neither the new menu options nor Ellie's guide buttons.
- **Setup Wizard (Non-negotiable #10a):** not triggered, because no new setting is
  added.
- **API (Non-negotiable #7):** no new endpoint. Ellie's existing reply gains a `guides`
  list. The existing `/api/v1/tours/*` progress endpoints are reused.

## 8. Operator playbook: adding a hands-on guide to another page

1. Add `data-tour="…"` anchors to the boxes on that screen (existing convention).
2. In that page's tour definition, give each step a `section` and a "moves on when"
   rule.
3. That page's "?" menu then shows Advanced Guide and Spot Help, and Ellie can offer
   them. No engine or Ellie change is needed.

## 9. Files to create / modify (QA2 lane)

| File | Change |
|------|--------|
| `app/Support/Tours/TourRegistry.php` | Document + support the optional `section` / advance-rule step fields; helpers to list a tour's sections and whether it supports Advanced/Spot Help; property-capture + contact-capture gain hands-on metadata |
| `app/Support/Tours/defs/property-spaces.php` (new) | Property page Spaces & Features guide |
| `resources/views/layouts/partials/tour-engine.blade.php` | "?" menu; Advanced / Spot Help run modes; wait-for-action; follow screen changes; pending-guide pickup |
| `resources/views/layouts/partials/tour-header-launcher.blade.php` | Menu host (only if needed) |
| `resources/views/corex/properties/show.blade.php` | `data-tour` anchors on Spaces & Features + header "?" slot |
| `resources/views/corex/properties/wizard.blade.php` | `data-tour` anchors for Photos / Details / Review steps |
| `resources/views/corex/contacts/index.blade.php` | Only if a missing anchor is needed |
| `app/Services/AI/TourKnowledgeService.php` | Return guide key + matching section with each how-to hit |
| `app/Services/AI/Ellie/EllieToolkit.php` | `find_how_to` returns the guide buttons (permission-filtered) |
| `app/Http/Controllers/EllieController.php` (+ Ellie answer service) | Pass `guides` through with the reply |
| `resources/views/layouts/partials/ellie-widget.blade.php` | Render guide buttons under Ellie's reply; hand off to the engine |
| `tests/Feature/AI/TourKnowledgeServiceTest.php` | Guide key / section returned; invisible guides excluded |
| `tests/Feature/Tours/AdvancedGuidingTest.php` (new) | Definition shape; every hands-on anchor exists in its Blade view; sections listed; permission filtering of Ellie buttons |
| `.ai/specs/ellie-tour-knowledge.md`, `.ai/CHAT_STARTER.md`, `.ai/CODEBASE_MAP.md` | Docs |

## 10. Acceptance criteria

- [ ] "?" on the New Property wizard opens a menu: Guided Tour · Advanced Guide · Spot Help.
- [ ] Advanced Guide on the wizard: highlighting "Headline", typing one moves to the next
      step by itself; picking a suburb from the list advances, while free text that the page
      rejects does not.
- [ ] The guide follows the wizard from Basics → Photos → Details → Review without restarting.
- [ ] Back, Skip this step and Stop work on every step; Stop leaves all typed values intact.
- [ ] The guide never types, picks, uploads or saves on the agent's behalf.
- [ ] Property page: "?" → Spot Help → **Adding spaces** runs only the spaces steps, ending
      once a space has been added.
- [ ] Ellie: "how do I add spaces to a property?" gives a short answer + **Spot Help: Adding
      spaces** button. From the dashboard it lands on My Listings with the "open a property"
      note, and the guide starts when a property is opened.
- [ ] Ellie: "how do I capture a property?" → **Guide me through it** opens the wizard with
      the Advanced Guide running.
- [ ] An agent without access to a page gets no button for it from Ellie and no menu option.
- [ ] With the `guided-tours` feature off: no menu options, no Ellie guide buttons.
- [ ] All 87 untouched tours still run exactly as before (Guided Tour only in their menu).
- [ ] Works in light and dark theme; the step card never covers the box it points at; usable
      at phone width.
- [ ] Verified by hand on QA2 in the browser; `AdvancedGuidingTest` + `TourKnowledgeServiceTest` green.

## 11. Deliberately NOT in this release

- Hands-on steps for the other 87 tours (engine-ready; content added module by module per §8).
- Manager reporting on who used which guide.
- The guide filling anything in for the agent (by design, per Principle #5).
