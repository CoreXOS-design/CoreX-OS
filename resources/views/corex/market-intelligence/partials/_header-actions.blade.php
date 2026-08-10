{{-- MIC Work / Analyse header actions — filter TICKS + Setup link (white-on-navy).

     BUGFIX (Johan): these were <input type="checkbox" onchange="…navigate…"> — the
     tick relied on inline JS to apply the filter. In the browser the onchange did
     not navigate, so clicking a tick did NOTHING (no param entered the URL, the
     count never changed) and paging showed them unchecked. The filter-RAIL filters
     work because they are plain <a href> links. So these ticks are now plain
     <a href> toggle links too — zero JS dependency — carrying the FULL current
     query string (minus page, so a filter change returns to page 1). Same params
     the controller already reads: include_mandated / include_in_stock / address_filter.
     UI_DESIGN_SYSTEM.md §2.4. --}}
@php
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;

    // Current query minus page — toggling a filter returns to page 1.
    $baseQ   = request()->except('page');
    $mandOn  = request()->boolean('include_mandated');
    $stockOn = request()->boolean('include_in_stock');
    $addrOn  = request('address_filter') === 'with_address';

    $toggleUrl = function (string $key, $onValue, bool $isOn) use ($baseQ) {
        $q = $baseQ;
        if ($isOn) {
            unset($q[$key]);          // currently ON  → link turns it OFF
        } else {
            $q[$key] = $onValue;      // currently OFF → link turns it ON
        }
        return route('market-intelligence.work', $q);
    };

    $tickBase = 'display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:0.75rem; color:rgba(255,255,255,0.85); cursor:pointer; white-space:nowrap;';
    $boxOff = 'width:14px; height:14px; border-radius:3px; border:1px solid rgba(255,255,255,0.5); display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; color:transparent;';
    $boxOn  = 'width:14px; height:14px; border-radius:3px; border:1px solid #fff; background:#fff; color:var(--brand-default,#0b2a4a); display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; font-weight:900;';
@endphp

{{-- Sole/exclusive mandates — every agent. Excluded from the pool by default. --}}
<a href="{{ $toggleUrl('include_mandated', '1', $mandOn) }}"
   style="{{ $tickBase }}"
   title="Sole/exclusive-mandated listings are excluded by default — another agency already holds the mandate. Toggle to include them.">
    <span style="{{ $mandOn ? $boxOn : $boxOff }}">✓</span>
    Show sole/exclusive mandates
</a>

{{-- With-address (pull-all): restrict to listings that have a street address. --}}
<a href="{{ $toggleUrl('address_filter', 'with_address', $addrOn) }}"
   style="{{ $tickBase }}"
   title="Some captured listings have no street address yet. Toggle to show only listings that have an address.">
    <span style="{{ $addrOn ? $boxOn : $boxOff }}">✓</span>
    With address only
</a>

@if($isManager)
    {{-- In-stock (our own portal listings, exact ref match) — manager-only audit. --}}
    <a href="{{ $toggleUrl('include_in_stock', '1', $stockOn) }}"
       style="{{ $tickBase }}"
       title="Audit-only: include our own listings (portal reference matches our stock), which are hidden from the canvass pool by default.">
        <span style="{{ $stockOn ? $boxOn : $boxOff }}">✓</span>
        Show in-stock too
    </a>

    <a href="{{ route('settings.prospecting.index') }}"
       class="corex-btn-outline text-sm"
       style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);"
       title="Configure prospecting segments and suggested-action thresholds">
        Setup
    </a>
@endif
