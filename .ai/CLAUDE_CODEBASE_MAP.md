# CoreX OS — Codebase Map

> VS Code Claude: Read this BEFORE writing any code. This tells you exactly
> where things are, what patterns work, and what data structures look like.
> DO NOT GUESS. If something isn't documented here, INVESTIGATE FIRST.

## Golden Rule for Building New Features

**INVESTIGATE → COPY → ADAPT.** Never build from scratch when a working
pattern exists. Find the existing implementation, understand how it works,
then copy the pattern for the new feature.

## Server & Environment

| Item | Value |
|------|-------|
| Production server | Ubuntu at 91.99.130.85 |
| Codebase path | /corex |
| Domain | corex.hfcoastal.co.za |
| GitHub repo | johan7610/hfc-dash |
| Branches | main (production), HFC2402 (Johan dev), andre (Andre dev) |
| DB production | MySQL on 127.0.0.1:3306, database: nexus_os |
| DB local | MySQL via Laragon on 127.0.0.1:3306, database: nexus_os |
| PHP version | 8.x |
| Node/Vite | Vite 7.3.x, npm run build for production, npm run dev for local |
| Python AI | /opt/hf-ai/app.py on port 3100, managed by hf-ai.service |
| Session driver | database (sessions table) |

## Deploy Commands

```bash
# Standard deploy to server
cd /corex && git fetch origin main && git reset --hard origin/main && npm run build && php artisan migrate --force && php artisan view:clear && php artisan cache:clear && php artisan route:clear && php -r "opcache_reset();"

# Deploy HFC2402 directly (skip main)
cd /corex && git fetch origin HFC2402 && git reset --hard origin/HFC2402 && npm run build && php artisan migrate --force && php artisan view:clear && php artisan cache:clear && php artisan route:clear && php -r "opcache_reset();"

# Clear sessions (force all users to re-login)
php -r "require '/corex/vendor/autoload.php'; \$app = require_once '/corex/bootstrap/app.php'; \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap(); DB::table('sessions')->truncate(); echo 'Sessions cleared';"

# Python AI service
systemctl restart hf-ai
```

## Key Architecture Patterns

### Permissions (DO NOT use role name checks)
```php
// CHECK: app/Services/PermissionService.php
// hasPermission($key) — checks if user has a specific permission
// getDataScope($user, $module) — returns 'own', 'branch', or 'all'

// In controllers:
abort_unless(auth()->user()->hasPermission('module.create'), 403);

// In blade views:
@permission('module.create')
    <button>Add New</button>
@endpermission

// Data scoping on models:
$query->scopeVisibleTo(auth()->user());

// NEVER DO THIS:
// if ($user->role === 'admin')  ← WRONG
// if ($user->isAdmin())         ← WRONG
// Only exception: $user->isOwnerRole() for the system owner bypass
```

### Soft Deletes (NEVER hard delete)
```php
// All 31 models use SoftDeletes trait
// "Delete" buttons call ->delete() which sets deleted_at
// Use ->withTrashed() for admin recovery views
// ProfileController::destroy() is the ONLY hard delete (GDPR self-delete)
```

### Blade Components
```blade
{{-- Sticky page header (use on every page) --}}
<div class="-m-4 lg:-m-6">
    <x-page-header title="Page Title" :flush="true">
        <x-slot:actions>
            <button>Save</button>
        </x-slot:actions>
    </x-page-header>
    <div class="p-4 lg:p-6">
        {{-- content --}}
    </div>
</div>

{{-- List page with search/filter/sort --}}
<x-list-header title="Items" :count="$items->count()" :total="$items->total()"
    search-placeholder="Search..." form-action="/items" :paginator="$items">
    <x-slot:filters>
        <select name="status">...</select>
    </x-slot:filters>
    <x-slot:actions>
        <a href="/items/create">Add New</a>
    </x-slot:actions>
</x-list-header>

{{-- Sortable column header --}}
<x-sort-header column="name" :sort="$sort" :direction="$direction">Name</x-sort-header>
```

### @json() Rule
```blade
{{-- NEVER DO THIS — closures break @json() --}}
@json($items->map(function($i) { return ...; }))

{{-- ALWAYS transform in controller first --}}
{{-- Controller: $items = $collection->map(fn($i) => [...]);  --}}
{{-- Blade: --}}
@json($items)
```

---

## DocuPerfect — Document System

### File Locations
| Purpose | Path |
|---------|------|
| Template editor JS | public/js/docuperfect-editor.js |
| Template editor CSS | public/css/docuperfect-editor.css |
| Template edit view | resources/views/docuperfect/templates/edit.blade.php |
| Document edit view | resources/views/docuperfect/documents/edit.blade.php |
| Document create view | resources/views/docuperfect/create.blade.php |
| My Documents list | resources/views/docuperfect/documents/index.blade.php |
| Template model | app/Models/Docuperfect/Template.php |
| Document model | app/Models/Docuperfect/Document.php |
| Template controller | app/Http/Controllers/Docuperfect/TemplateController.php |
| Document controller | app/Http/Controllers/Docuperfect/DocumentController.php |
| Signature controller | app/Http/Controllers/Docuperfect/SignatureController.php |
| Signing controller | app/Http/Controllers/Docuperfect/SigningController.php |
| Agent signing view | resources/views/docuperfect/signatures/sign.blade.php |
| External signing view | resources/views/docuperfect/signatures/external/sign.blade.php |
| Signature setup view | resources/views/docuperfect/signatures/setup.blade.php |
| Document flattener | app/Services/Docuperfect/DocumentFlattener.php |
| Signature service | app/Services/Docuperfect/SignatureService.php |
| E-Sign wizard controller | app/Http/Controllers/Docuperfect/ESignWizardController.php |
| E-Sign wizard view | resources/views/docuperfect/esign/wizard.blade.php |
| Flow model | app/Models/Flow.php |

### Template Page Images
Templates have page images stored on disk. The existing editor loads them via:
```
Route: GET /docuperfect/signatures/{templateId}/flattened-page/{page}
Controller: SignatureController@flattenedPage
Storage: storage/app/docuperfect/templates/{id}/pages/page-{n}.jpg
```

**IMPORTANT:** When building anything that renders template pages, use this
EXACT URL pattern. Do not invent a new one.

### fields_json Structure
Each template/document has a `fields_json` column containing an array of field objects:

```json
{
    "id": "f_abc123",
    "type": "placeholder",
    "pageIndex": 0,
    "position": { "x": 25.5, "y": 12.3 },
    "size": { "width": 20.0, "height": 3.5 },
    "value": "",
    "named_field_id": 42,
    "named_field_name": "property_address",
    "label": "Property Address",
    "assignedTo": "creator",
    "required": false,
    "solidBg": false
}
```

Field types: `placeholder` (text), `strikethrough`, `diagonal`, `selection`, `tick`, `initial`, `date`, `condition` (clause), `sign`

Position values are PERCENTAGES (0-100) relative to the page image.
Size values are PERCENTAGES of page width/height.

### Field Rendering Pattern (COPY THIS)
The existing editor positions fields using percentage-based absolute positioning:

```javascript
// From docuperfect-editor.js createFieldElement()
el.style.position = 'absolute';
el.style.left = field.position.x + '%';
el.style.top = field.position.y + '%';
el.style.width = field.size.width + '%';
el.style.height = field.size.height + '%';
```

Each page is rendered as a `position:relative` container with the page image
as the background, and fields as `position:absolute` children.

### Toolbar Fixed Positioning
The template editor toolbar uses JS-driven `position:fixed` (not CSS sticky):
```javascript
// setupFixedBars() in docuperfect-editor.js
// Finds #dp-page-header, #appScroll, and <aside> (sidebar)
// Applies position:fixed with left = sidebar width, right = 0
// Uses ResizeObserver on sidebar for collapse/expand
```

### Signing Flow
```
Template → Document (copies fields_json) → Signature Template →
Signature Requests (per signer) → Signing View → Flattening → Complete
```

Party roles in signature_requests.party_role: `agent`, `landlord`, `tenant`
Field assignedTo values: `creator`, `agent`, `lessor`, `lessee`, `buyer`, `seller`

**CRITICAL:** These don't match! Use the alias mapping:
```php
$roleAliases = ['lessor' => 'landlord', 'lessee' => 'tenant',
                'landlord' => 'landlord', 'tenant' => 'tenant'];
```

### signature_templates.status ENUM
`draft`, `ready`, `signing`, `awaiting_tenant`, `awaiting_landlord`,
`pending_agent_approval`, `completed`, `expired`, `declined`, `rejected`

---

## Agency Tracker — Deal Register & Performance

### File Locations
| Purpose | Path |
|---------|------|
| Deal model | app/Models/Deal.php |
| Deal controller | app/Http/Controllers/Admin/DealController.php |
| Finance definitions | app/Models/FinanceDefinition.php |
| Finance computed values | app/Models/FinanceComputedValue.php |
| Commission math | See CLAUDE_AGENCYTRACKER.md section 9 |

### MySQL vs SQLite (ELIMINATED)
Local dev now uses MySQL via Laragon. NEVER use:
- `GLOB` — use `REGEXP`
- `julianday()` — use `DATEDIFF()`
- `CAST(... AS INTEGER)` — use `CAST(... AS UNSIGNED)`

---

## Presentation System

### File Locations
| Purpose | Path |
|---------|------|
| Presentation controller | app/Http/Controllers/Presentation/PresentationController.php |
| Portal capture controller | app/Http/Controllers/Presentation/PortalCaptureController.php |
| Show view | resources/views/presentations/show.blade.php |
| Parsers | app/Services/Presentations/Evidence/Parsers/ |
| Chrome extension | chrome-extension/portal-capture/ |

---

## E-Sign Wizard (NEW — In Development)

### File Locations
| Purpose | Path |
|---------|------|
| Controller | app/Http/Controllers/Docuperfect/ESignWizardController.php |
| Wizard view | resources/views/docuperfect/esign/wizard.blade.php |
| Flow model | app/Models/Flow.php |
| Flows migration | database/migrations/*_create_flows_table.php |

### Current State (Phase 1 — broken, needs rewrite)
The wizard shell exists but:
- Template pages don't render (wrong URL pattern — must use flattenedPage route)
- Field positions wrong (not using the percentage positioning from editor)
- Save Draft doesn't work (AJAX endpoint not returning correctly)
- Recipients hardcoded (should be flexible unlimited signers)

### How to Fix
1. Build a standalone test page that renders a template with field overlays
2. Use the EXACT same page image URL and field positioning as docuperfect-editor.js
3. Once test page renders correctly, wrap it in the wizard shell
4. DO NOT guess at URLs or positioning — investigate the working editor first

---

## Ellie AI Assistant

### File Locations
| Purpose | Path |
|---------|------|
| Laravel controller | app/Http/Controllers/EllieController.php |
| Python brain | /opt/hf-ai/app.py (NOT in git) |
| Knowledge search | app/Services/KnowledgeSearchService.php |
| Embedding service | app/Services/EmbeddingService.php |
| Service config | /etc/hf-ai/openai.env |
| Systemd service | hf-ai.service |

---

## Layout System

### Main Layout Files
| File | Purpose |
|------|---------|
| resources/views/layouts/corex-app.blade.php | App wrapper (Andre's layout) |
| resources/views/layouts/corex-sidebar.blade.php | Sidebar navigation |
| resources/views/layouts/navigation.blade.php | Top nav bar |

### Sidebar Rules
- Fully @permission() gated
- Single Alpine.js pattern for open/close
- Scroll position persisted via sessionStorage
- View As feature uses effectiveRole() — but isOwnerRole() bypass uses REAL role

---

## Common Gotchas

1. **Nested HTML forms** — HTML doesn't support them. Use `form=""` attribute
   on submit buttons + `id` on the form. Inner forms must be siblings not children.

2. **@json() with closures** — NEVER. Transform in controller first.

3. **Sticky headers need correct scroll context** — use the full-bleed wrapper
   pattern (`-m-4 lg:-m-6` parent, `sticky top-0` child).

4. **Fixed positioning in editor** — toolbar uses JS-driven position:fixed,
   not CSS sticky. Recalculates on sidebar collapse/expand.

5. **signature_templates status enum** — if adding a new status, ALTER TABLE
   to add to the enum first.

6. **Session driver is database** — clear with DB::table('sessions')->truncate(),
   not file deletion.

7. **npm run dev for local** — required for Vite to serve CSS/JS. Production
   uses npm run build. If site loads with no styles, run `npm run dev`.
