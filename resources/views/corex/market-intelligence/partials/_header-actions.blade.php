{{-- MIC Work / Analyse header actions — filter TICKS + Setup link (white-on-navy).

     Ticks are plain <a href> toggle links (NOT JS-onchange checkboxes — that was
     the bug: the onchange never navigated, so clicking did nothing). Each link
     carries the full current query minus page (a filter change returns to page 1)
     and toggles its own param: include_mandated / include_in_stock / address_filter.

     UX (Johan): the reload is slow, so users re-click thinking nothing happened.
     A small additive script gives INSTANT optimistic feedback — flip the tick glyph
     and show "updating…" the moment you click — then lets the real <a> navigation
     proceed. It is additive only: if JS is off/blocked the link still works.
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

    $tickBase = 'display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:0.75rem; color:rgba(255,255,255,0.85); cursor:pointer; white-space:nowrap; transition:opacity 120ms;';
    $boxOff = 'width:14px; height:14px; border-radius:3px; border:1px solid rgba(255,255,255,0.5); display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; color:transparent; transition:background 100ms,border-color 100ms,color 100ms;';
    $boxOn  = 'width:14px; height:14px; border-radius:3px; border:1px solid #fff; background:#fff; color:var(--brand-default,#0b2a4a); display:inline-flex; align-items:center; justify-content:center; font-size:10px; line-height:1; font-weight:900; transition:background 100ms,border-color 100ms,color 100ms;';
    $spin   = 'display:none; font-size:0.625rem; color:rgba(255,255,255,0.7); font-style:italic;';
@endphp

{{-- Sole/exclusive mandates — every agent. Excluded from the pool by default. --}}
<a href="{{ $toggleUrl('include_mandated', '1', $mandOn) }}"
   class="mic-tick" data-active="{{ $mandOn ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Sole/exclusive-mandated listings are excluded by default — another agency already holds the mandate. Toggle to include them.">
    <span class="mic-tick-box" style="{{ $mandOn ? $boxOn : $boxOff }}">✓</span>
    Show sole/exclusive mandates
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>

{{-- With-address (pull-all): restrict to listings that have a street address. --}}
<a href="{{ $toggleUrl('address_filter', 'with_address', $addrOn) }}"
   class="mic-tick" data-active="{{ $addrOn ? '1' : '0' }}"
   style="{{ $tickBase }}"
   title="Some captured listings have no street address yet. Toggle to show only listings that have an address.">
    <span class="mic-tick-box" style="{{ $addrOn ? $boxOn : $boxOff }}">✓</span>
    With address only
    <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
</a>

@if($isManager)
    {{-- In-stock (our own portal listings, exact ref match) — manager-only audit. --}}
    <a href="{{ $toggleUrl('include_in_stock', '1', $stockOn) }}"
       class="mic-tick" data-active="{{ $stockOn ? '1' : '0' }}"
       style="{{ $tickBase }}"
       title="Audit-only: include our own listings (portal reference matches our stock), which are hidden from the canvass pool by default.">
        <span class="mic-tick-box" style="{{ $stockOn ? $boxOn : $boxOff }}">✓</span>
        Show in-stock too
        <span class="mic-tick-spin" style="{{ $spin }}">updating…</span>
    </a>

    <a href="{{ route('settings.prospecting.index') }}"
       class="corex-btn-outline text-sm"
       style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);"
       title="Configure prospecting segments and suggested-action thresholds">
        Setup
    </a>
@endif

{{-- Instant optimistic feedback. Additive only — navigation is the <a href>; this
     just flips the glyph + shows "updating…" so the slow reload doesn't read as a
     dead click. addEventListener (not an inline handler), attached once. --}}
<script>
(function () {
    var BOX_ON  = { background: '#fff', borderColor: '#fff', color: 'var(--brand-default,#0b2a4a)' };
    var BOX_OFF = { background: 'transparent', borderColor: 'rgba(255,255,255,0.5)', color: 'transparent' };
    document.querySelectorAll('a.mic-tick').forEach(function (a) {
        if (a.dataset.fbBound) return;      // idempotent
        a.dataset.fbBound = '1';
        a.addEventListener('click', function () {
            var on  = a.getAttribute('data-active') === '1';
            var box = a.querySelector('.mic-tick-box');
            if (box) {
                var s = on ? BOX_OFF : BOX_ON;         // optimistic flip to the NEW state
                box.style.background  = s.background;
                box.style.borderColor = s.borderColor;
                box.style.color       = s.color;
            }
            var spin = a.querySelector('.mic-tick-spin');
            if (spin) spin.style.display = 'inline';
            a.style.opacity = '0.65';
            a.style.pointerEvents = 'none';            // stop the re-click spam while it reloads
            // No preventDefault — the real <a href> navigation proceeds.
        });
    });
})();
</script>
