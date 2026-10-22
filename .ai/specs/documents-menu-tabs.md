# Documents menu — 20 links become 6 items

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre: "now the documents section"). Not committed.**
> Johan must see this before it leaves QA2.
> Owner: Andre · Same mechanism and rules as `.ai/specs/real-estate-menu-tabs.md` (§2 there applies word for word).

## 1. The new Documents menu

| # | Menu item | Tabs (in order) — each is today's page on today's route |
|---|---|---|
| 1 | Create Document | *(unchanged plain link — it starts something)* |
| 2 | E-Sign Document | *(unchanged plain link — it starts something)* |
| 3 | **My Documents** | My Documents · My E-Sign Documents · Authorise Documents · Agency Documents · Shared Drive |
| 4 | **Library** | Packs · Web Packs · Clause Library · Compile Studio |
| 5 | **Templates** | Template Management · Field Groups · Named Fields · Document Types · Import Document · Template Packages |
| 6 | **E-Sign Settings** | Recipient Presets · Recipient Templates · Finalisation Settings |

Every label is unchanged. "Authorise Documents" is the My E-Sign Documents screen with `?filter=authorisation` (as its old menu
item was): the two tabs share a route and the filter decides which is lit.

### 1.1 Gates per tab (copied verbatim — not changed). All tabs sit inside the panel's `@feature('docuperfect')`.

| Hub | Tab | Route | Permission(s) / condition |
|---|---|---|---|
| My Documents | My Documents | `docuperfect.dashboard` | `access_docuperfect` |
| My Documents | My E-Sign Documents | `docuperfect.esign.myDocuments` | `create_docuperfect_docs` AND `access_docuperfect` |
| My Documents | Authorise Documents | `docuperfect.esign.myDocuments?filter=authorisation` | same AND `CandidatePractitionerService::canAuthorise()` |
| My Documents | Agency Documents | `my-portal.agency-documents` | `view_agency_documents` |
| My Documents | Shared Drive | `documents.shared-drive.index` | feature `shared-drive` (as well as `docuperfect`) AND `access_shared_drive` |
| Library | Packs / Web Packs | `docuperfect.packs.index` / `docuperfect.web-packs.index` | `access_docuperfect_packs` AND `access_docuperfect` |
| Library | Clause Library | `docuperfect.clauses.index` | `access_clause_library` AND `access_docuperfect` |
| Library | Compile Studio | `docuperfect.compiler.index` | `esign.compiler.view` |
| Templates | Template Management · Field Groups · Named Fields · Document Types · Import Document | `docuperfect.templates.index` · `.field-groups.index` · `.settings.namedFields` · `.settings.types` · `.import.index` | `manage_templates` AND `access_docuperfect` |
| Templates | Template Packages | `docuperfect.template-transfer.index` | System Owner only AND `templates.transfer` |
| E-Sign Settings | Recipient Presets · Recipient Templates · Finalisation Settings | `docuperfect.esign.recipient-presets.index` · `docuperfect.recipient-templates.index` · `docuperfect.esign.settings.finalization` | `esign.settings` |

## 2. No data, settings or new list screens

Navigation only — no pillar data, migration, permission/feature key or agency setting (nothing for the Setup Wizard); each tab keeps
its own search/sort/filter/scoping. Labels are neutral.

## 3. As built / proof

- `App\Support\Navigation\Hubs\{MyDocuments,DocumentLibrary,DocumentTemplates,ESignSettings}Tabs`, registered in `TabHubs`; landing
  routes `corex.hubs.my-documents`, `corex.hubs.doc-library`, `corex.hubs.doc-templates`, `corex.hubs.esign-settings` (resolve to the
  Documents panel).
- `tests/Feature/Navigation/DocumentsMenuTabsTest.php` — equivalence (5 user types × 18 tabs), the six-item menu, landing, and every
  tab the admin sees opens with its bar and its own tab marked. `TabHubSwitchesTest` covers these hubs too (Template Packages is
  skipped there — it is System-Owner-only, not a switch). The `docuperfect.esign.myDocuments` allow-list entry came off: the two-items-
  one-route double match it explained is gone (one tab is lit, by the filter).
- Guided tours: dp-dashboard, dp-esign-my-docs, portal-agency-docs, docs-shared-drive, dp-packs, dp-templates — all 33 targets checked
  on the rendered pages before and after; none lost (8 missing both before and after: rows the local data does not have).
- Note: HR › Documents (PPRA FFC Letter) is a separate nested panel with the same title — untouched.
