{{--
    F.6 — Ellie strategic brief hero. Templated narrative for F.6 (the
    real EllieService is post-Wednesday). Action buttons route back to
    Work mode pre-filtered.

    Spec: §9.1.
--}}
@php
    $b = $brief ?? ['narrative_html' => '', 'generated_at' => null, 'actions' => []];
    $generatedAt = $b['generated_at'] ? \Carbon\Carbon::parse($b['generated_at']) : null;
@endphp

<div class="mi-card"
     style="background: linear-gradient(135deg, color-mix(in srgb, var(--brand-icon, #0ea5e9) 7%, var(--surface)) 0%, var(--surface) 70%);
            border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, var(--border));">
    <div style="display: flex; align-items: flex-start; gap: 12px;">
        {{-- Ellie avatar --}}
        <div style="width: 36px; height: 36px; border-radius: 50%;
                    background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, transparent);
                    color: var(--brand-icon, #0ea5e9);
                    display: flex; align-items: center; justify-content: center;
                    font-weight: 700; flex-shrink: 0; font-size: 0.875rem;">
            E
        </div>

        <div style="flex: 1; min-width: 0;">
            <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                <span style="font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand-icon, #0ea5e9);">
                    Weekly market brief
                </span>
                @if($generatedAt)
                <span style="font-size: 0.625rem; color: var(--text-muted);">
                    · generated {{ $generatedAt->diffForHumans() }}
                </span>
                @endif
            </div>

            <div style="font-size: 0.875rem; color: var(--text-primary); line-height: 1.55; margin-bottom: 12px;">
                {!! $b['narrative_html'] !!}
            </div>

            @if(!empty($b['actions']))
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                @foreach($b['actions'] as $i => $action)
                    @php
                        $isPrimary = (bool) ($action['is_primary'] ?? ($i === 0));
                        $btnStyle = $isPrimary
                            ? 'background: var(--brand-icon, #0ea5e9); color: #fff; border: 1px solid var(--brand-icon, #0ea5e9);'
                            : 'background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);';
                    @endphp
                    <a href="{{ $action['preset_url'] }}"
                       style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 11px; font-size: 0.75rem; font-weight: 600; border-radius: 4px; text-decoration: none; {{ $btnStyle }}">
                        {{ $action['label'] }} →
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
