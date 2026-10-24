---
name: sidebar-feature-toggle
description: Every sidebar item must ship with a Settings > Features on/off toggle (config/corex-features.php, @feature wrap, route enforcement, hidden links elsewhere). Use when adding, moving or editing any sidebar/hub menu item or a link to a feature.
---
# Every sidebar item ships with a Features on/off toggle (Andre + Johan, 2026-10-07)

A navigation entry with no switch is incomplete, exactly like a page with no navigation entry. In the SAME commit:
1. **Row in `config/corex-features.php`:** label, plain-English `explain`, concrete `affects`, `default => true` (nothing may disappear for an agency already using it), `depends_on` its parent module where it cannot work without it, a `category` matching the sidebar group, `route_names` (globs of the signed-in web routes it owns) and `view_dirs`. One row feeds the Settings screen AND the Setup Wizard.
2. **Wrap the sidebar link in `@feature('<key>')`.** A group whose children are all off hides itself (compute group visibility from its children, as HR/Reports/Rentals do).
3. **Routes are refused when off:** `route_names` is enforced by `App\Http\Middleware\EnforceFeatureRoutes` (404). A route matching several features' globs needs all on. The toggle beats the Role Manager permission.
4. **Every other screen linking to it hides the link:** wrap in `@feature('<key>')`; inside a sentence keep the text and drop the link (`@feature('k')<a>text</a>@else text @endfeature`). No dead buttons.
5. **No duplicate switch:** if a switch already exists (e.g. `agencies.assistants_enabled`) register it as a switchboard-origin key (`AgencyFeatureService::SWITCHBOARD_STORES`) - one switch, one home.
Only two kinds of link may have no toggle, both listed in `App\Support\Navigation\SidebarFeatureCoverage`: a core pillar page (`'core' => true`) and a System-Owner-only link. Never add anything else there to pass a test - add the toggle.
**Enforced:** `FeatureNavGuardCoverageTest` fails on an unwrapped link, an unknown feature key (silently resolves OFF), a `route_names` glob matching no route, or an outside screen linking without `@feature`. `php scripts/sidebar-nav-audit.php` lists offenders. Spec `.ai/specs/corex-feature-registry.md` section 8.2. Also: nav entry same day, permission key + Role Manager entry (CLAUDE.md #2, #5).
