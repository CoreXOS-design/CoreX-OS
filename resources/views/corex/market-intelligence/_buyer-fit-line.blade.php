{{-- mic-work-tab.md 13.18: "Alex wants  🛏 3+ · 🛁 2+ · 🚗 – · Erf – · Floor 150+" - the mirror of the facts line, same order, one colour per state (ok green, miss red, unknown amber with "?", na grey).
     Input: $fit = the row's buyer_fit. Only what the engine gave. --}}
@php
    $B = \App\Services\Prospecting\MicWork\BuyerFitLabel::class;
    $icon = 'width:15px; height:15px; vertical-align:-3px;';
    $paths = [
        'beds' => 'M3 18v-7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7M3 14h18M3 18v2M21 18v2M7 9V6h10v3',
        'baths' => 'M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3zM6 12V6a2 2 0 0 1 4 0M7 19l-1 2M17 19l1 2',
        'garages' => 'M5 17h14M6 17l1.5-6h9L18 17M7 17v2M17 17v2',
    ];
    $bits = [];
    foreach (['beds', 'baths', 'garages', 'parking', 'erf', 'floor'] as $key) {
        $f = $B::field($fit, $key);
        if (!$f) { continue; }
        $st = $B::state($f['state'] ?? null);
        $wants = trim((string) ($f['wants'] ?? '')) !== '' ? trim($f['wants']) : '–';
        $bits[] = ['key' => $key, 'label' => $f['label'] ?? ucfirst($key), 'wants' => $wants . ($st === 'unknown' ? ' ?' : ''), 'color' => $B::color($st), 'title' => $B::fieldTitle($fit, $f)];
    }
@endphp
@if($bits)
    <div data-qa="fit-line" style="display:flex; align-items:center; gap:2px 14px; font-size:.875rem; line-height:1.3; color: var(--text-primary); flex-wrap:wrap;">
        <span style="font-weight:600;">{{ $fit['first_name'] ?? 'Buyer' }} wants</span>
        @foreach($bits as $b)
            <span data-qa="fit-{{ $b['key'] }}" title="{{ $b['title'] }}" style="display:inline-flex; align-items:center; gap:4px; font-weight:600; color: {{ $b['color'] }};">
                @if(isset($paths[$b['key']]))<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="{{ $icon }}"><path d="{{ $paths[$b['key']] }}"/></svg>@else{{ ['erf' => 'Erf', 'floor' => 'Floor', 'parking' => 'P'][$b['key']] }}@endif
                {{ $b['wants'] }}
            </span>
        @endforeach
    </div>
@endif
