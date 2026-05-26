# E-Sign Reset Investigation — Five Live Breaks vs 49 Green Tests
**Date:** 2026-05-27 (filename) / dated to current session 2026-05-26
**Branch:** HFC2402
**Mode:** READ-ONLY forensic. One audit report written; no source edits, no debug traces remaining.
**Operating principle:** Best, not merely working. Tests that pass while runtime breaks are tests of the wrong shape.

---

## EXECUTIVE SUMMARY

Five live breaks coexist with 49/49 passing tests because every test in `tests/Feature/RecipientLoop/*` exercises **synthetic HTML fixtures and isolated service methods** — none exercise the live pipeline that runs in production: `Template (DB) → CdsDraft → blade view file → WebTemplateDataService → SignatureSurfaceNormalizer → LetterheadRefresher → InsertableBlockRenderer → RoleBlockExpansionService → SigningController::show → sign.blade.php (rendered)`. The unit-shape tests confirm each service does its single job on hand-crafted HTML. The integration shape of template 111 — multi-cluster role detection, draft vs Template divergence, agent-permission spillover into recipient view — defeats every assumption the tests encode. Add to that an **uncommitted local edit** to `template-111.blade.php` (collapsing 4 hardcoded seller blocks to 1) which the service detector reads as "2 disjoint clusters of role=seller" and refuses to duplicate, and you have a system whose tests are green and whose user is staring at a broken document.

---

## Q1 — Template revert: deletes Seller 2/3/4 blocks, saves, refreshes → 4 blocks back

### Ground-truth Tinker dump (template 111)
- `field_mappings` count: **26 (14 of which carry "Seller" in label)**.
- `cds_json` keys: `title, version, sections, extracted_at, original_text` (the IMPORTER-time structured doc — never updated by builder edits).
- `editor_state` keys: `tags, mappings, tagged_html` (the live builder state mirror).
- `editor_state.tagged_html` length: 16,960 chars.
- `blade_view`: `docuperfect.web-templates.cds.template-111`.
- `cds_drafts` rows for template 111: **5 rows** (1 in `status=draft`, 4 in `status=saved`). Latest draft id **35** updated 2026-05-26 12:18:53 — still has 4 Seller-Address fields in `mappings` and 4 in `tagged_html`.

### Architecture of the save/load cycle

| Layer | File | Lines | Reads | Writes |
|-------|------|-------|-------|--------|
| Edit entry | `app/Http/Controllers/Docuperfect/TemplateController.php` | 117–158 | `Template.cds_json + editor_state` | `CdsDraft` row |
| Draft load | `TemplateController.php` | 394–502 | `CdsDraft.{cds_json, tags, mappings, tagged_html, settings}` | view payload |
| Save Mappings | `TemplateController.php` | 505–517 | request JSON | `CdsDraft.{tags, mappings, tagged_html}` |
| Save Draft | `TemplateController.php` | 519–546 | request JSON | `CdsDraft.{template_name, tags, mappings, tagged_html, settings}` |
| Generate (final) | `TemplateController.php` | 548–608 | `CdsDraft` | `Template.{cds_json, field_mappings, fields_json, editor_state, blade_view (file)}` |
| Builder init | `resources/views/docuperfect/templates/cds-builder.blade.php` | 1205–1257 | `savedTaggedHtml, savedTags, savedMappings, savedSettings` (Blade vars from `cdsBuilder()` view) | rehydrates `docContainer.innerHTML + this.tags + this.mappings` |
| User delete | `cds-builder.blade.php` | 2035–2045 (`removeTag`) | `tagId` | mutates DOM, `this.tags`, `this.mappings`; calls `_persistMappings` (`POST /templates/cds/mappings`) |

### Root cause (why blocks come back)

There are **three distinct revert paths**, ordered by likelihood:

1. **The user edited the visible block text (via `contenteditable=true`) without removing the tag IDs.** `cds-builder.blade.php:137` declares the doc container `contenteditable="true"`. Block markup edits send `tagged_html: docContainer.innerHTML` via `_doSaveDraft` (`cds-builder.blade.php:1147`) and `_doSaveMappings` (`cds-builder.blade.php:2109`). BUT the `init()` rehydrate path at `cds-builder.blade.php:1208–1222` replays the user's last-saved `tagged_html` AND re-injects every entry in `this.tags` whose `data-tag-id` still exists in the DOM. **`this.tags` is never auto-pruned when a tag's element disappears from the DOM** — orphans persist. So if a partial delete (e.g. range select + Delete) removed the tag span's surrounding text but not the span itself, or if a manual code-style delete left the IDs intact, re-render at next visit appears unchanged. This is the "blocks back" experience.

2. **The user clicked "Generate Template" (which calls `cdsGenerate` at `TemplateController.php:548–608`) which always writes a fresh `blade_view` file from `$draft->tagged_html` — but `cds_json` is COPIED THROUGH UNCHANGED** (`TemplateController.php:562`). Re-opening any future template view through `Template::edit()` (`TemplateController.php:117–158`) creates a NEW draft seeded from `$template->cds_json` (the unchanged original) only if no current `status=draft` row exists; if multiple sessions overlap or the draft was marked `saved`, the next edit starts fresh from the pre-edit structure. That is the source of the four `saved` rows + one `draft` row in DB for template 111.

3. **The blade file regeneration path is asymmetric.** `cdsGenerate` calls `generateCdsBladeView` (`TemplateController.php:705`) which prefers `tagged_html` (line 715) and falls back to rendering `cds_json` via `CdsRendererService` (line 718). If `tagged_html` is empty (e.g. when reaching this path from a fresh draft never edited), the original 4-seller CDS JSON is what hits the blade file. There is no enforced "you must save tagged_html before generating" gate.

### Smoking gun for the revert symptom
Working copy `resources/views/docuperfect/web-templates/cds/template-111.blade.php` currently differs from HEAD: the unified diff (`git diff`) shows 4 seller blocks collapsed to 1 (lines 27–28, `seller_address / seller_phone / seller_email`). This is Johan's local edit AFTER a `cdsGenerate`. But:
- `Template.field_mappings` (DB) still holds 14 seller mappings.
- `CdsDraft.id=35.mappings` still holds 14 seller mappings.

So the **blade file** has 1 seller block, but **field_mappings + draft state** still describe 4 sellers. The CDS builder's UI is rebuilt from `tagged_html + mappings` (DB) on open — which is the 4-block world. The recipient signing view renders the **blade file** (1-block world) and then `RoleBlockExpansionService` reads `field_mappings` (4-block world) for its `editable_by` lookup. The two layers no longer agree.

**The system has no single source of truth for "what fields does this template have."** `cds_json`, `editor_state.tags`, `editor_state.mappings`, `editor_state.tagged_html`, `field_mappings`, `fields_json`, the generated blade file, and the live `CdsDraft` all carry overlapping definitions of the same answer. Six places to be inconsistent.

---

## Q2 — Body shows only agent signature, no per-seller blocks for a 3-seller signing

### Live signing session probed
- `SignatureTemplate` id **349**, document **399**, document.template_id **111**, latest `updated_at` 2026-05-26 12:00:54.
- Recipients (`signature_requests`):
  - id… `seller` / role_index=1 / **James Van Der Merwe** / contact_id=5
  - id… `seller` / role_index=2 / **Steve Jobs** / contact_id=2
  - id… `seller` / role_index=3 / **Johannes Kerkorrel** / contact_id=11
  - id… `agent` / role_index=1 / **Johan Reichel**
- `web_template_data.merged_html` length: 315,640 chars (already rendered at signing-create time, stored on `document.web_template_data`).
- In the stored `merged_html`: **12 `data-field` elements**, **0 `data-recipient-identity`**, **0 `data-viewer-editable`**, **2 stray `~~~~` pairs** (the Q3 bug).

### Running `expandWithLooping` live against this exact merged_html + recipients

(Reproduction via Tinker against MySQL, with no source modification.)

- Output: 4 `data-recipient-identity` stamps — **all `seller_1`**. 0 `seller_2`. 0 `seller_3`.
- `data-viewer-editable` stamps when running as `seller_2`: **0**.
- Unique data-field values found: `seller_name_surname_id, property_*, seller_address, seller_phone, seller_email, price, price_in_words, mandate_expiry` — all 12 names singular (no `__r2`/`__r3` suffixes).

### Per-boundary `detectBlockBoundaries` result (no-modification reproduction)

```
boundaries count: 2
  role=seller max_idx=1 total_clusters_for_role=2 cluster_ordinal=0
    block_xpath=/html[1]/body[1]/div[1]/div[1]/div[1]/div[3]/span[1]
    idx=1 field_count=1  →  seller_name_surname_id
  role=seller max_idx=1 total_clusters_for_role=2 cluster_ordinal=1
    block_xpath=/html[1]/body[1]/div[1]/div[1]/div[1]
    idx=1 field_count=3  →  seller_address, seller_phone, seller_email
```

### Root cause (file:line)

`app/Services/Docuperfect/RoleBlockExpansionService.php:524–542` — Case A logic. When the detector reports `total_clusters > 1` for a role (here: 2), the expansion service **deliberately skips duplication** to "preserve structure" and stamps the existing fields as `role_1` only. From the source:

```
// Case A or C — but only safe to auto-loop when this is the
// ROLE's only cluster. If the role has multiple disjoint
// clusters in the document, duplicating just this one risks
// breaking the page structure ...
if ($totalClusters > 1) {
    foreach ($liveInstanceGroups[1] ?? [] as $f) {
        $this->stampFieldNode($f['node'], $role, 1, isOrphan: false);
    }
    ...
    return;
}
```

The trigger: template 111 has a "Seller Name Surname ID" placeholder in the OPENING paragraph (`<span data-field="seller_name_surname_id">`), and the seller-address/phone/email block lower down. The detector correctly sees two disjoint clusters of role=`seller`. Case A bails. Seller 2 and Seller 3 never get a block.

Consequence for viewer = `seller_2`:
- 0 fields carry `data-recipient-identity="seller_2"`.
- `stampViewerEditability` (`RoleBlockExpansionService.php:389–439`) walks every `data-field`, compares `data-recipient-identity` against `viewer.role_identity = "seller_2"`, finds no matches, **stamps zero `data-viewer-editable`**.
- The signing-view JS (`resources/views/docuperfect/signatures/external/sign.blade.php:1939–1970`) attribute-based gate finds 0 editable spans and renders nothing as input.

### What about the "agent signature only" appearance?

The page also shows signature-line `@include` calls (`template-111.blade.php:39–40, 44–45, 50–51, 71–72`). Each `signature-line` partial renders one signature surface per `party` parameter. With the new collapsed-blade in working copy, the Seller header is still rendered, but only ONE signature-line per Seller (party = 'seller', singular). Because `expandWithLooping` doesn't duplicate, only one seller signature line appears. The agent signature line is always emitted via `signature-block` (`TemplateController.php:861–862`). Net effect: agent signature visible, "awaiting seller 2/3" placeholder text shows because the marker/zone system creates signature markers per signer (3 sellers) but the rendered surface only has one slot — markers 2 and 3 fall back to inline "awaiting" text in `sign.blade.php`'s marker loop.

### Answer to "are seller blocks (a) absent, (b) hidden, (c) static text?"

**(a) ABSENT from the rendered HTML for seller_2 and seller_3.** The HTML has exactly one set of seller fields, stamped `seller_1`. Seller 2 sees nothing because nothing was generated for him.

---

## Q3 — `~~~~OTHER_CONDITIONS~~~~` rendering as literal in clause 2.8

### Renderer location
- `app/Services/Docuperfect/InsertableBlockRenderer.php:30` — `final class InsertableBlockRenderer`.
- Invoked at `app/Http/Controllers/Docuperfect/SigningController.php:305–313` (recipient signing path) with `CONTEXT_RECIPIENT_SIGNING`.
- Marker regex: `/~{4,}([A-Z_]+(?::[^~]+)?)~{4,}/` (`InsertableBlockRenderer.php:459`).
- The unbound-marker fallback (`renderUnboundMarkers`, lines 451–475) catches any `~~~~TOKEN~~~~` not registered in `template.insertable_blocks`.

### Live state
- `template.insertable_blocks` for template 111: **empty array**.
- `signature_template.insertable_blocks` for template 349: **empty array**.
- Literal `~~~~` count in `web_template_data.merged_html` for document 399: **2 (one pair)**.
- Literal substring `OTHER_CONDITIONS` count in `merged_html`: **0**.

### What the merged_html actually contains at clause 2.8

Tinker snippet (verbatim):
```
<span class="corex-clause-number">2.8</span>&nbsp;~~~~<span class="corex-clause-text">Other Contitions~~~~</span>
```

Two failures:
1. **The token between the tildes is `Other Contitions` (misspelled "Contitions", with space and mixed case).** The renderer regex requires `[A-Z_]+` between tildes → does NOT match.
2. **An HTML `<span class="corex-clause-text">` tag sits BETWEEN the opening and closing `~~~~`.** Even if the token had matched, the regex is single-line/anchored and won't span an HTML tag boundary.

### Where the literal originated

Not in the current `template-111.blade.php` (it has `<span data-field="other_conditions">{{ $other_conditions ?? '' }}</span>` at line 54). The merged_html was rendered when template 111's blade file was in a PREVIOUS state — confirmed by timestamps: `document.created_at = 2026-05-26 11:57:55`, `template.updated_at = 2026-05-26 12:11:12`. The blade was last regenerated 14 minutes AFTER the document's `merged_html` was rendered. So the stored `merged_html` is a stale snapshot from a blade version that had typed tildes inline as authoring-time markers. The user (or AI importer) typed `~~~~Other Contitions~~~~` directly into the CDS builder's `contenteditable=true` body; the doc-tag-injection step then wrapped half of it in a `<span>` tag, splitting the marker.

### Why the renderer didn't catch it
- The token is not `[A-Z_]+` → regex skips.
- Even if regex allowed lowercase, the `<span>` boundary defeats single-character-class matching.
- `LegacyOtherConditionsBridge` (referenced at `app/Services/Docuperfect/LegacyOtherConditionsBridge.php:146`) is a separate pre-processor; not invoked on this stored merged_html re-render path.

### Cumulative bug shape
Three layers conspire: (a) the CDS builder allows free-text typing without distinguishing literal text from marker syntax; (b) the stored `merged_html` snapshot is treated as authoritative and re-served at signing time without re-rendering from the (updated) blade; (c) the renderer expects strict `~~~~UPPERCASE_TOKEN~~~~` form and the typo broke it silently.

---

## Q4 — Apply-to-All-Pages on recipient sign view (legal loophole)

### Trigger locations (sign.blade.php external)
- Modal element: `resources/views/docuperfect/signatures/external/sign.blade.php:990` (`x-show="showInitialApplyAll"`).
- Heading text: line 994 — "Apply to All Pages?".
- State init: line 1419 — `showInitialApplyAll: false`.
- Trigger A (markers, signature/initial type): lines 2467–2484 — gated `if (this.isAgent && !this.firstSignatureDone && ...)`.
- Trigger B (web-template initials path): lines 3243–3251 — gated `if (this.isAgent && unsignedInitials.length > 0 && !this.webInitialSigData) ...`.
- Apply handler: `applyInitialToAll()` at line 3258.

### Where `isAgent` is set
- Blade var: `resources/views/docuperfect/signatures/external/sign.blade.php:1375` — `isAgent: @json($isAgent ?? false)`.
- Controller: `app/Http/Controllers/Docuperfect/SigningController.php:411–414`:

```
$authUser = $request->user();
$isAgent = ($authUser !== null && method_exists($authUser, 'hasPermission')
        && $authUser->hasPermission('manage_documents'))
    || $signingRequest->party_role === 'agent';
```

### The legal loophole

The `isAgent` flag conflates two distinct concepts:
1. The signature request belongs to an `agent` party (`party_role === 'agent'`).
2. The HTTP request's authenticated user has `manage_documents` permission.

Result: when Johan, logged in as the dispatching agent, opens a recipient's `/sign/{token}` URL in his own browser **for testing** or **for screen sharing during signing**, the second arm of the `||` fires. The recipient's signing surface receives `isAgent = true`. The modal triggers. The applied initial blanket-applies to every page break.

This is two failures wearing one mask:
- **Identity confusion.** The `isAgent` flag does not mean "the signer of this token is an agent". It means "the viewer's session has manage permission". They are different facts. The view consumes a name that suggests the first and provides the second.
- **No defence-in-depth at the modal.** A correct gate would be `signingRequest.party_role === 'agent'` only — that fact is immutable for the token. Permission spillover from the viewer's session has no business shaping the recipient experience.

The B1B.9 build notes acknowledged this risk; the comment at sign.blade.php:2473–2475 reads:
> Phase 1B.9 (FIX 2) — only the agent gets the apply-to-all prompt. Recipients must initial each page individually for legal informed-consent reasons.

The intent is correct. The implementation is wrong because `isAgent` is the wrong predicate.

In production with a real (unauthenticated) recipient on a phone clicking an email link, `$authUser === null`, so the first arm fails, the second arm fires only when the token truly is the agent's, and the gate works. The bug surfaces in agent-side QA — exactly when the human discovering the bug believes they are simulating a recipient.

---

## Q5 — Tests-of-services vs tests-of-reality

### Five test files reviewed
- `tests/Feature/RecipientLoop/EditableScopeTest.php` — 5 cases
- `tests/Feature/RecipientLoop/BlockRendererTest.php` — 18 cases
- `tests/Feature/RecipientLoop/CloneLabelRewriteTest.php` — 3 cases
- `tests/Feature/RecipientLoop/IndexedIdentityTest.php` — accessor + helper tests
- `tests/Feature/RecipientLoop/InfoPanelTest.php` — 4 cases (HTTP-touching)

### Fixture vs reality analysis

**`BlockRendererTest.php`** — uses hand-rolled HTML like `'<div class="seller-section"><span data-field="seller_first_name">P</span></div>'` (line 178+, 204, 220, 269, 293, 336–340). Every fixture is a **single clean cluster per role**. None reproduces template 111's actual shape: an opening-paragraph `seller_name_surname_id` field PLUS a separate seller block later, which produces `total_clusters_for_role = 2` and trips the multi-cluster bailout at `RoleBlockExpansionService.php:532`. The tests never construct the multi-cluster case A and never assert on its behaviour.

**`CloneLabelRewriteTest.php`** — uses 2-block hardcoded fixtures (`twoBlockTemplate()`); exercises Case D.2 only. Same fixture-shape mismatch.

**`EditableScopeTest.php`** — calls the actual HTTP endpoint (`postJson('/sign/{token}/save-web-fields', ...)`) — this is the closest thing to a tests-of-reality. But the seeded template (`seedTwoSellerTemplate`, lines 141–182) sets `field_mappings` with a single tag and assigns `signing_parties = ['owner_party', 'owner_party', 'agent']`. The fixture skips the rendering pipeline entirely: it never produces `merged_html`, never calls `expandWithLooping`, never goes through the blade file → renderer chain. The test exercises the SAVE endpoint with hand-constructed field payloads that already carry `identity` and `original_field` keys. **Real client payloads come from a JS DOM walk that may emit any field name.** The test doesn't prove the round-trip works — it proves the endpoint validates payloads that have already been shaped correctly.

**`IndexedIdentityTest.php`** — accessor + helper tests on POPO models. No HTTP. No render. Cannot catch any integration bug.

**`InfoPanelTest.php`** — does call `$this->get('/sign/{token}')` (line 28). This IS an end-to-end test. But the `seedTwoSellerTemplate` helper (similar shape to EditableScope) constructs the SignatureTemplate without ever generating a real `merged_html` — assertions check for substrings like "How to sign", "Seller 2", "Steve Jobs" which come from the **info panel partial** (a sidebar), not from the rendered document body. The body of the document is never asserted on. So the seller-block-missing bug from Q2 lives in the part of the response the test doesn't examine.

### Pattern of the gap

Every test either:
- **Tests a service in isolation with hand-crafted HTML** (BlockRenderer, CloneLabelRewrite) — green when service does its small job on its small fixture; silent on integration.
- **Tests an HTTP endpoint with hand-crafted payloads** (EditableScope) — green when endpoint validates shape; silent on whether the page that the user opens HAS that shape.
- **Tests an HTTP page for sidebar substrings** (InfoPanel) — green when sidebar renders; silent on the document body.

None asserts: "I issued GET `/sign/{token}` for a seller_2 token against the real `template-111.blade.php` blade file with the real field_mappings of template 111, and the returned `$response->getContent()` contains `data-recipient-identity="seller_2"` and `data-viewer-editable="1"` on at least one editable seller field."

### Proposed end-to-end test (file path + shape)

`tests/Feature/Docuperfect/SigningView/RealTemplate111EndToEndTest.php`

```
public function test_seller_2_sees_their_own_editable_seller_block_on_template_111(): void
{
    // 1. SEED a real-shape template from the FIXTURE blade file that ships
    //    with the repo. Use copy-of-111 so the test is hermetic against
    //    user edits to the production file. Place fixture at
    //    tests/Fixtures/templates/template-111-canonical.blade.php and
    //    register a view path override in setUp().
    $template = DocuperfectTemplate::create([
        'name'           => 'EATS Real-Shape',
        'render_type'    => 'web',
        'template_type'  => 'cds',
        'category'       => 'sales',
        'blade_view'     => 'tests.fixtures.templates.template-111-canonical',
        'signing_parties'=> ['owner_party', 'agent'],
        'field_mappings' => $this->canonical111Mappings(), // exact shape from prod
        'owner_id'       => $this->seedUserId(),
    ]);

    // 2. Build a SignatureTemplate via the SAME path the wizard uses
    //    (SignatureService::createFromTemplate or whatever exists),
    //    seeding 3 seller recipients + 1 agent via the wizard's actual
    //    contact-loop. Do NOT hand-construct rows — call the service.

    // 3. GET the recipient signing URL for seller_2:
    $response = $this->get('/sign/' . $seller2Token);
    $response->assertOk();

    // 4. Parse the response BODY (not just sidebar):
    $body = $response->getContent();
    $crawler = new Symfony\Component\DomCrawler\Crawler($body);

    // 5. Hard assertions on the rendered document surface:
    $this->assertCount(1, $crawler->filter('[data-recipient-identity="seller_2"]'),
        'Seller 2 must have at least one stamped field in their own block.');
    $this->assertGreaterThan(0,
        $crawler->filter('[data-recipient-identity="seller_2"][data-viewer-editable="1"]')->count(),
        'Seller 2 must have at least one editable field (address/phone/email).');
    $this->assertStringNotContainsString('~~~~', $body,
        'No literal marker tildes should ever reach the recipient body.');
    $this->assertStringNotContainsString('OTHER_CONDITIONS', $body);

    // 6. Apply-to-all gate: assert absent when isAgent path = false.
    $this->assertStringNotContainsString('Apply to All Pages?', $body);
}
```

The fixture blade lives in-repo so tests own their canonical truth; the production blade can drift from the test's reality and the test will catch it on the next CI run.

This single test would have failed on the current branch — and would have prevented the B3 ship.

---

## Q6 — Systemic discipline gap (one paragraph)

The discipline gap is shape, not effort. The B1–B3 builds wrote thorough unit tests but no end-to-end tests because the team treats "test passes" as the contract — and every test passes by exercising its service in isolation against hand-crafted HTML that bears no resemblance to template 111's real-world shape. The integration moat (the `Template → CdsDraft → blade file → WebTemplateData → SurfaceNormalizer → LetterheadRefresher → InsertableBlockRenderer → RoleBlockExpansionService → SigningController → sign.blade.php` chain) has no test at all. To close the gap, the rule must change from "every service has unit tests" to "every user-visible signing experience has at least one HTTP test that asserts on the rendered document body for a real fixture template and a real recipient role". Concretely: (1) ship a `tests/Fixtures/templates/` directory of canonical CDS blade files (template-111, template-126, template-127 minimum) that the tests own and migrate when shape changes; (2) ship a `Tests\Concerns\BuildsSigningSession` trait that calls the real wizard/service to produce a signing session — never hand-rolls `SignatureTemplate::create` + `Document::create` shortcuts; (3) write a baseline `*EndToEndTest` per multi-recipient template with `data-recipient-identity` assertions on the rendered body — not on service return values, not on sidebar substrings; (4) require any change to `RoleBlockExpansionService`, `InsertableBlockRenderer`, `SigningController::show`, or the cds-builder save path to update or add at least one such end-to-end test in the same commit (CI gate). Without those four changes the next "feat(esign): B4" commit will green-light another phantom feature.

---

## Proposed Fix Sequence

Ordered so each fix unblocks the next without rework.

1. **Stop the bleed: gate Apply-to-All on the request token's party_role, not the viewer's permission.**
   - Scope: `app/Http/Controllers/Docuperfect/SigningController.php:411–414` + `resources/views/docuperfect/signatures/external/sign.blade.php:1375`.
   - Change: replace `isAgent` with two distinct flags — `signerIsAgent` (from `$signingRequest->party_role === 'agent'` only) and `viewerHasManagePermission` (from auth check, for telemetry/UI breadcrumb only, never gating consent surfaces).
   - Why first: zero-risk surgical edit; closes a legal exposure; doesn't depend on any other fix.
   - Add: `tests/Feature/Docuperfect/SigningView/ApplyToAllConsentGateTest.php` with two cases — recipient token + manage permission (asserts modal absent), agent token (asserts modal present).

2. **End-to-end test scaffold + canonical fixture for template 111.**
   - Scope: `tests/Fixtures/templates/template-111-canonical.blade.php` (new file copying the current blade), `tests/Concerns/BuildsSigningSession.php` (new trait that drives the real wizard to create a session), `tests/Feature/Docuperfect/SigningView/RealTemplate111EndToEndTest.php` (new).
   - Why second: must exist BEFORE fixing Q2 so the fix has a green test to land against. Currently the fix-target has no observability in CI.

3. **Fix Q2 multi-cluster role detection: replace the "skip when total_clusters > 1" bailout with a strategy that picks the LARGEST cluster as the per-instance block and stamps singleton fields with `role_1` in place.**
   - Scope: `app/Services/Docuperfect/RoleBlockExpansionService.php:524–574` (Case A logic). The detector at `RoleBlockDetectionService` may also need a `is_repeatable` flag per cluster (singleton "Seller Name Surname ID" in an opening paragraph = not repeatable; a "Seller / Physical address / Tel: / Email" block lower = repeatable).
   - Why third: the canonical test from step 2 will fail until this lands. The fix is non-trivial because we need to distinguish "the seller's name appears once in an opening narrative paragraph" from "the seller block is the multi-recipient anchor". Either annotate clusters in the detector with a `repeatable: bool` heuristic (largest cluster wins; small singleton ignored) OR introduce an explicit `<div data-role-block="seller">` wrapper convention and migrate template 111 to use it.
   - Add: BlockRendererTest cases for multi-cluster shape (two `seller_*` clusters with field counts 1 and 3 + 3 recipients → asserts 3 stamped instances).

4. **Fix Q3 OTHER_CONDITIONS literal:**
   - 4a. Reject `~~~~...~~~~` patterns in `cds-builder.blade.php` save path that contain HTML tags inside; surface a builder-side validation error.
   - 4b. Re-render `merged_html` from the current blade on every signing-view show when `signature_template.status === 'draft'` — never serve a stale snapshot for an unsent doc. Add a `MergedHtmlFreshnessGuard` that compares `template.updated_at` to the document's stored render timestamp and forces re-render.
   - 4c. Extend the `InsertableBlockRenderer` regex to be HTML-tag-tolerant: `/~{4,}\s*(?:<[^>]+>\s*)*([A-Za-z][A-Za-z0-9 _:]+?)(?:\s*<[^>]+>)*\s*~{4,}/` with a normalisation step (uppercase, replace non-`[A-Z_]` with `_`).
   - Scope: `app/Services/Docuperfect/InsertableBlockRenderer.php:459` + new `app/Services/Docuperfect/MergedHtmlFreshnessGuard.php` + `app/Http/Controllers/Docuperfect/SigningController.php:254–272`.
   - Why fourth: depends on the freshness guard infrastructure which is independent of Q2's fix but useful for Q1.

5. **Fix Q1 template revert: collapse the field-truth sources to one.**
   - Scope: `app/Models/Docuperfect/Template.php`, `app/Models/Docuperfect/CdsDraft.php`, `app/Http/Controllers/Docuperfect/TemplateController.php:117–608`.
   - Change: introduce `Template::canonicalFieldMappings()` that pulls from a single field — preferably `field_mappings` — and DEPRECATE the parallel storage in `editor_state.mappings`, `editor_state.tags`, `fields_json`, `cds_json.sections.*.content[*].field_placeholder`. Migration backfills the canonical field; observers prevent direct writes to the others. Builder reads from canonical only; save writes to canonical only.
   - Auto-prune `this.tags` in the builder when its DOM element disappears (`removeTag` should be the ONLY removal path; runtime scan on save catches orphans).
   - Why fifth: largest blast radius (touches CDS builder, AI importer, signing render, wizard, document signing surface). Must land after the safety net (steps 1, 2) and after the in-flight bugs (3, 4) so reviewers can read its diff against a stable system.

6. **Test gate: CI must fail a PR that touches the signing render pipeline without adding/updating an end-to-end test.**
   - Scope: `scripts/dev-check.ps1` or a new `scripts/check-esign-test-coverage.ps1` that greps the diff for changes to the eight pipeline files and counts test diff lines in `tests/Feature/Docuperfect/SigningView/`.
   - Why last: the rule has teeth only after steps 1–5 have established the test-shape pattern. Locking it in earlier blocks fixes themselves.

---

## File: line citations used in this report

- `app/Http/Controllers/Docuperfect/TemplateController.php:117` — `edit()` entry, draft creation
- `app/Http/Controllers/Docuperfect/TemplateController.php:394–502` — `cdsBuilder()` view payload
- `app/Http/Controllers/Docuperfect/TemplateController.php:505–517` — `cdsSaveMappings()`
- `app/Http/Controllers/Docuperfect/TemplateController.php:519–546` — `cdsSaveDraft()`
- `app/Http/Controllers/Docuperfect/TemplateController.php:548–608` — `cdsGenerate()` (writes Template + blade file)
- `app/Http/Controllers/Docuperfect/TemplateController.php:705` — `generateCdsBladeView()`
- `app/Http/Controllers/Docuperfect/SigningController.php:41` — `show(Request, $token)`
- `app/Http/Controllers/Docuperfect/SigningController.php:254–272` — merged_html load + render
- `app/Http/Controllers/Docuperfect/SigningController.php:305–313` — InsertableBlockRenderer invocation
- `app/Http/Controllers/Docuperfect/SigningController.php:321–332` — RoleBlockExpansionService invocation
- `app/Http/Controllers/Docuperfect/SigningController.php:411–414` — `isAgent` definition (Q4 root cause)
- `app/Http/Controllers/Docuperfect/SigningController.php:428` — `isAgent` passed to view
- `app/Http/Controllers/Docuperfect/SigningController.php:1364–1402` — `getEditableFieldsFromMappings`
- `app/Services/Docuperfect/RoleBlockExpansionService.php:262` — `expandWithLooping`
- `app/Services/Docuperfect/RoleBlockExpansionService.php:481–633` — `applyBoundary`
- `app/Services/Docuperfect/RoleBlockExpansionService.php:524–542` — Case A multi-cluster bailout (Q2 root cause)
- `app/Services/Docuperfect/RoleBlockExpansionService.php:389–439` — `stampViewerEditability`
- `app/Services/Docuperfect/InsertableBlockRenderer.php:30` — class
- `app/Services/Docuperfect/InsertableBlockRenderer.php:459` — marker regex (Q3 root cause)
- `app/Services/Docuperfect/InsertableBlockRenderer.php:451–475` — `renderUnboundMarkers`
- `resources/views/docuperfect/templates/cds-builder.blade.php:137` — `docContainer contenteditable=true`
- `resources/views/docuperfect/templates/cds-builder.blade.php:1132–1181` — `_doSaveDraft`
- `resources/views/docuperfect/templates/cds-builder.blade.php:1192–1196` — auto-save (60s interval)
- `resources/views/docuperfect/templates/cds-builder.blade.php:1205–1257` — `init()` (restore path)
- `resources/views/docuperfect/templates/cds-builder.blade.php:2035–2045` — `removeTag`
- `resources/views/docuperfect/templates/cds-builder.blade.php:2095–2122` — `_doSaveMappings`
- `resources/views/docuperfect/signatures/external/sign.blade.php:990–998` — Apply-to-All modal
- `resources/views/docuperfect/signatures/external/sign.blade.php:1375` — `isAgent` Alpine init
- `resources/views/docuperfect/signatures/external/sign.blade.php:1419` — `showInitialApplyAll: false`
- `resources/views/docuperfect/signatures/external/sign.blade.php:1939–1970` — field-to-input span replacement (B3 gate)
- `resources/views/docuperfect/signatures/external/sign.blade.php:2467–2484` — Apply-to-all signature trigger
- `resources/views/docuperfect/signatures/external/sign.blade.php:3243–3251` — Apply-to-all initial trigger
- `resources/views/docuperfect/web-templates/cds/template-111.blade.php:27–28` — collapsed seller block (uncommitted local edit)
- `resources/views/docuperfect/web-templates/cds/template-111.blade.php:54` — clause 2.8 `other_conditions` field
- `tests/Feature/RecipientLoop/BlockRendererTest.php:178–198` — Case A fixture (single clean cluster)
- `tests/Feature/RecipientLoop/EditableScopeTest.php:141–182` — `seedTwoSellerTemplate` (no render)
- `tests/Feature/RecipientLoop/InfoPanelTest.php:24–32` — `test_info_panel_renders_for_recipient_signing_view`

---

End of investigation. Audit report only — no source edits, no debug traces remaining (verified via `git status`).
