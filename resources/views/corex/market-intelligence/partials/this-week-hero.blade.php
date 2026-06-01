{{--
    Phase D2 — "This Week" hero block on the Work tab.

    Variables:
      $tiles            — Collection<TileDTO> (may be empty)
      $tilesGeneratedAt — Carbon|null (when the tile collection was last computed)
      $agent            — User (current request user; for the greeting)

    A zero-tile state still renders the heading + "no urgent actions" copy,
    so the user gets feedback that the system looked but found nothing.

    Spec: .ai/specs/mic-complete-spec.md §6.
--}}
@php
    $firstName = trim(strtok((string) ($agent->name ?? ''), ' ')) ?: 'there';
@endphp
<section class="mic-this-week"
         style="padding: 20px 24px;
                margin-bottom: 20px;
                background: linear-gradient(135deg,
                    color-mix(in srgb, var(--brand-button) 4%, var(--surface)) 0%,
                    var(--surface) 60%);
                border: 1px solid var(--border);
                border-radius: 6px;">
    <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 6px;">
        <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Hi {{ $firstName }}. Here's your week.
        </h2>
        <span style="font-size: 0.6875rem; color: var(--text-muted);">
            Generated {{ ($tilesGeneratedAt ?? null)?->diffForHumans() ?? 'just now' }}
        </span>
    </div>

    @if($tiles->isEmpty())
        <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0 0 12px 0;">
            No urgent actions right now — keep prospecting and the system will surface what matters.
        </p>
    @else
        <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0 0 12px 0;">
            {{ $tiles->count() === 1 ? '1 thing' : $tiles->count() . ' things' }} need your attention, ranked by urgency.
        </p>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            @foreach($tiles as $tile)
                @include('corex.market-intelligence.partials.this-week-tile', ['tile' => $tile])
            @endforeach
        </div>
    @endif

    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border);
                display: flex; align-items: center; justify-content: space-between;
                font-size: 0.6875rem; color: var(--text-muted);">
        <span>
            Data behind this · refreshed every 6 hours
        </span>
        <a href="{{ route('market-intelligence.analyse') }}"
           style="text-decoration: none; color: var(--text-secondary);">
            Show me the data →
        </a>
    </div>
</section>
