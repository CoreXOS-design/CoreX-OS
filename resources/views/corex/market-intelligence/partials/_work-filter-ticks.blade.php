{{-- MIC Work tab — filter ticks (with-address, in-stock, P24, PP), rendered
     inline with the buyer-match legend row so the controls scroll WITH the
     list they filter instead of living in the page header above it.

     With-address / in-stock: relocated from partials/_header-actions.blade.php
     (Johan 2026-08-19) — same <a href> toggle-link mechanism, same
     query-string params (address_filter / include_in_stock), so their effect
     on the row count is unchanged by the move. The document-scoped click
     feedback script and the work.blade.php delegated AJAX handler both key
     off `a.mic-tick` globally, so relocating the markup needs no JS changes.

     P24 / PP: new source ticks. Canonical field is
     prospecting_listings.portal_source — enum('p24','pp') NOT NULL, directly
     on every row this tab lists. Not the tracked_property_external_refs
     source_type the Opportunities tab uses (different table, more values) —
     do not unify. Both default ON so an untouched page filters nothing.

     No x-data here — plain <a href> links only. UI_DESIGN_SYSTEM.md §2.4. --}}
@php
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;

    $baseQ   = request()->except('page');
    $addrOn  = request('address_filter') === 'with_address';
    $stockOn = request()->boolean('include_in_stock');
    $p24On   = request()->boolean('p24', true);
    $ppOn    = request()->boolean('pp', true);

    // ON→OFF removes/sets the "off" marker per param's own default state.
    $toggleUrl = function (string $key, $onValue, bool $isOn) use ($baseQ) {
        $q = $baseQ;
        if ($isOn) {
            unset($q[$key]);          // currently ON  → link turns it OFF
        } else {
            $q[$key] = $onValue;      // currently OFF → link turns it ON
        }
        return route('market-intelligence.work', $q);
    };
    // Inverted for default-ON ticks: ON = param absent, OFF = param explicitly '0'.
    $toggleUrlDefaultOn = function (string $key, bool $isOn) use ($baseQ) {
        $q = $baseQ;
        if ($isOn) {
            $q[$key] = '0';            // currently ON  → link turns it OFF
        } else {
            unset($q[$key]);           // currently OFF → link back to default (ON)
        }
        return route('market-intelligence.work', $q);
    };

    // Colors adapted from the header's white-on-navy to this row's light --surface.
    $tickBase = 'display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:0.6875rem; color: var(--text-secondary); cursor:pointer; white-space:nowrap; transition:opacity 120ms;';
    $boxOff = 'width:14px; height:14px; border-radius:3px; border:1px solid var(--border); display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; color:transparent; transition:background 100ms,border-color 100ms,color 100ms;';
    $boxOn  = 'width:14px; height:14px; border-radius:3px; border:1px solid var(--brand-icon,#0ea5e9); background:var(--brand-icon,#0ea5e9); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; font-weight:900; transition:background 100ms,border-color 100ms,color 100ms;';
    $spin   = 'display:none; font-size:0.625rem; color: var(--text-muted); font-style:italic;';
@endphp

<a href="{{ $toggleUrl('address_filter', 'with_address', $addrOn) }}"
   class="mic-tick" data-active="{{ $addrOn ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Some captured listings have no street address yet. Toggle to show only listings that have an address.">
    <span class="mic-tick-box" style="{{ $addrOn ? $boxOn : $boxOff }}">@if($addrOn)✓@endif</span>
    With address only
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>

@if($isManager)
<a href="{{ $toggleUrl('include_in_stock', '1', $stockOn) }}"
   class="mic-tick" data-active="{{ $stockOn ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Audit-only: include our own listings (portal reference matches our stock), which are hidden from the canvass pool by default.">
    <span class="mic-tick-box" style="{{ $stockOn ? $boxOn : $boxOff }}">@if($stockOn)✓@endif</span>
    Show in-stock too
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>
@endif

<a href="{{ $toggleUrlDefaultOn('p24', $p24On) }}"
   class="mic-tick" data-active="{{ $p24On ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Property24-sourced listings. Untick to hide them from the worklist.">
    <span class="mic-tick-box" style="{{ $p24On ? $boxOn : $boxOff }}">@if($p24On)✓@endif</span>
    P24
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>

<a href="{{ $toggleUrlDefaultOn('pp', $ppOn) }}"
   class="mic-tick" data-active="{{ $ppOn ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Private Property-sourced listings. Untick to hide them from the worklist.">
    <span class="mic-tick-box" style="{{ $ppOn ? $boxOn : $boxOff }}">@if($ppOn)✓@endif</span>
    PP
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>
