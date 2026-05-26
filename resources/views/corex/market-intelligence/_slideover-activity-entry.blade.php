{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — A single activity timeline entry. Used by the Overview tab's
    "Latest activity" list, the Activity tab timeline, AND the inline
    Add-note success path (server returns this rendered for prepending).

    Input: $entry — ['kind','at','actor','summary','outcome' (optional)]
--}}
@php
    $kindIcon = match($entry['kind'] ?? '') {
        'pitch'      => '💬',
        'claim_note' => '✎',
        'first_seen' => '👁',
        'call'       => '📞',
        default      => '·',
    };
    $when = $entry['at'] instanceof \Carbon\Carbon ? $entry['at'] : (is_string($entry['at'] ?? null) ? \Carbon\Carbon::parse($entry['at']) : null);
@endphp

<div class="mi-activity-entry" style="display: grid; grid-template-columns: 28px 1fr; gap: 8px; padding: 8px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
    <div style="width: 24px; height: 24px; border-radius: 4px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
        {{ $kindIcon }}
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
