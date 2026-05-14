{{--
    F.6 — Opportunity pockets card. List of (suburb, bedrooms) buckets
    where strong-tier buyer demand outstrips canvass-pool supply.
    Click → Work mode pre-filtered. Spec: §9.3.
--}}
@php
    $list = $pockets ?? [];
@endphp

<div class="mi-card">
    <div class="mi-card-title">Opportunity pockets</div>
    <div class="mi-card-subtitle">buyer demand ≥ 2× supply, sorted by ratio</div>

    @if(empty($list))
        <div style="padding: 16px; text-align: center; color: var(--text-muted); font-size: 0.8125rem;">
            No opportunity pockets right now — try widening agency wishlists or capturing more listings.
        </div>
    @else
    <div style="display: flex; flex-direction: column; gap: 6px;">
        @foreach($list as $p)
        @php
            $url = route('market-intelligence.index', [
                'suburb'         => $p['suburb'],
                'bedrooms_exact' => $p['bedrooms'],
                'action_preset'  => 'pitch_now_high',
                'mode'           => 'work',
            ]);
            $ratioText = $p['ratio'] !== null ? round($p['ratio'], 1) . '×' : '∞';
        @endphp
        <a href="{{ $url }}"
           style="display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 8px 10px;
                  background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px;
                  text-decoration: none; color: inherit;"
           title="{{ $p['demand'] }} buyers · {{ $p['supply'] }} listings · click to canvass">
            <div style="min-width: 0;">
                <div style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $p['suburb'] }} · {{ $p['bedrooms'] }} bed
                </div>
                <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                    {{ $p['demand'] }} buyers · {{ $p['supply'] }} listings · {{ $p['price_band'] }}
                </div>
            </div>
            <span style="align-self: center; display: inline-flex; align-items: center; padding: 2px 8px; font-size: 0.75rem; font-weight: 700; border-radius: 999px;
                         background: color-mix(in srgb, var(--ds-green, #10b981) 22%, transparent);
                         color: var(--ds-green, #10b981);">
                {{ $ratioText }}
            </span>
        </a>
        @endforeach
    </div>
    @endif
</div>
