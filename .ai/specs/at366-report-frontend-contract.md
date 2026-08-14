# AT-366 Report — Frontend ↔ Backend contract (cc1 consumer contract for cc6)

**Status:** PROPOSED by cc1 (owns the report views/assets) for cc6 (owns backend data + endpoints). 2026-08-14.
Frontend #7 (sort/filter) + #8 (print) are built and need nothing here. #6 (status toggles) and #9
(drilldown modals) are built against THIS contract; cc6 implements the two data surfaces below to match,
or proposes edits here. Everything is **agency-scoped server-side** — the frontend never widens scope.

---
## A) #6 — per-status deal data (for the status toggles)
The status toggles (All / Pending / Granted / Registered / Declined) recompute deal **QTY** and **VALUE**
live. The frontend needs a per-status breakdown on **every rollup row** returned by
`AgencyPerformanceReportService::build()` — i.e. on `company`, each `branches[*]`, and each `agents[*]`:

```php
'deal_status' => [
    'pending'    => ['qty' => 3,  'value' => 5400000.0],
    'granted'    => ['qty' => 5,  'value' => 9100000.0],
    'registered' => ['qty' => 8,  'value' => 14200000.0],
    'declined'   => ['qty' => 1,  'value' => 1750000.0],
],
```
- Keys are exactly `pending|granted|registered|declined` (the deal statuses). `qty` int, `value` float (ZAR).
- "All" in the UI = the sum of all four. Toggling a status off subtracts its `{qty,value}` from the shown totals.
- If `deal_status` is absent on a row, the frontend hides the toggle bar for that view (graceful degrade) —
  so shipping the toggles does not break if cc6 lands the data later.

## B) #9 — drilldown JSON endpoint (for "click a figure → see the rows")
One generic endpoint backs every drilldown (deals, contacts, properties, FICA, buyers, viewings, any metric):

```
GET /corex/performance/agency-report/drilldown
      ?metric=<metric-key|deals|contacts|properties|fica|buyers|viewings>
      &level=<company|branch|agent>
      &id=<branch-key|user-id>          # omit/empty for company level
      &period=<preset>                  # e.g. this_month
      &start=<YYYY-MM-DD>&end=<YYYY-MM-DD>   # only when period=custom
      &status=<pending|granted|registered|declined>   # optional, deals only
   → middleware: auth + permission:view_performance
```

**Response (application/json):**
```json
{
  "title": "8 registered deals — Thabo Mokoena · This month",
  "total": 8,
  "columns": [
    {"key": "address",    "label": "Property",   "align": "left"},
    {"key": "price",      "label": "Price",      "align": "right", "format": "currency"},
    {"key": "commission", "label": "Commission", "align": "right", "format": "currency"},
    {"key": "status",     "label": "Status",     "align": "left",  "format": "badge"}
  ],
  "rows": [
    {"address": "12 Beach Rd, Uvongo", "price": 1850000, "commission": 92500, "status": "Registered",
     "href": "/corex/deals-v2/1421"}
  ]
}
```
- `columns[].format` ∈ `text | number | currency | date | badge` (default `text`). `align` ∈ `left | right`.
- `rows[].href` optional — if present the frontend deep-links the row's first cell.
- **Scoping:** resolve agency from the session user server-side; `abort(403)` if no agency;
  `abort(404)` if `id` is not in the agency. NEVER return cross-agency rows.
- Empty result → `{"title": "...", "total": 0, "columns": [...], "rows": []}` (200, not 404).
- Entity kinds map to the obvious detail list: `deals`→deal rows (address/price/commission/status),
  `contacts`→contacts created (name/type/created), `properties`→properties created, `fica`→FICA submissions,
  `buyers`→buyers added, `viewings`→viewings. `metric=<any metric key>` may alias to its entity list.

**Frontend behaviour (built, cc1):** clicking any figure (company card, branch/agent metric cell, or a
status total) opens a modal that GETs this endpoint and renders `columns`+`rows`, with loading / error /
empty states. Until cc6 ships the route, the modal shows a friendly "detail coming soon" state and the
click handlers are inert-safe (no JS error).

## Open items for cc6
1. Confirm the `deal_status` key placement (on each rollup row) and the four status keys.
2. Confirm the drilldown URL + query params + JSON envelope, or propose changes inline here.
3. Confirm currency unit (assumed ZAR whole-rand floats).
