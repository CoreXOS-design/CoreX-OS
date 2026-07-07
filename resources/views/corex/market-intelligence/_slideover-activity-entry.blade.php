{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — A single activity timeline entry. Used by the Overview tab's
    "Latest activity" list, the Activity tab timeline, AND the inline
    Add-note success path (server returns this rendered for prepending).

    Input: $entry — ['kind','at','actor','summary','outcome' (optional)]
--}}
@php
    // Activity-kind icon — inline SVG (stroke: currentColor) replaces the legacy
    // emoji markers. Consistent 13px line icons per UI_DESIGN_SYSTEM.md.
    $svgAttrs = 'width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    $kindIcon = match($entry['kind'] ?? '') {
        'pitch'      => '<svg xmlns="http://www.w3.org/2000/svg" ' . $svgAttrs . '><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'claim_note' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $svgAttrs . '><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        'first_seen' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $svgAttrs . '><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>',
        'call'       => '<svg xmlns="http://www.w3.org/2000/svg" ' . $svgAttrs . '><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        default      => '<span style="width:5px;height:5px;border-radius:50%;background:var(--text-muted);display:inline-block;"></span>',
    };
    $when = $entry['at'] instanceof \Carbon\Carbon ? $entry['at'] : (is_string($entry['at'] ?? null) ? \Carbon\Carbon::parse($entry['at']) : null);
@endphp

<div class="mi-activity-entry" style="display: grid; grid-template-columns: 28px 1fr; gap: 8px; padding: 8px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
    <div style="width: 24px; height: 24px; border-radius: 4px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; color: var(--text-secondary);">
        {!! $kindIcon !!}
    </div>
    <div style="min-width: 0;">
        <div style="font-size: 0.8125rem; color: var(--text-primary); line-height: 1.4;">
            {{ $entry['summary'] ?? '' }}
        </div>
        <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
            {{ $entry['actor'] ?? 'system' }}
            @if($when) · {{ $when->diffForHumans() }} · {{ $when->format('j M Y H:i') }} @endif
            @if(!empty($entry['outcome']) && $entry['outcome'] !== 'sent')
                · outcome: {{ str_replace('_', ' ', $entry['outcome']) }}
            @endif
        </div>
    </div>
</div>
