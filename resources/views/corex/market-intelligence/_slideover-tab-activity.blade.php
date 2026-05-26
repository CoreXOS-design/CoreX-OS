{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Activity tab: merged chronological timeline of pitches + claim notes.
--}}
@php
    // Merge pitches + claim notes into one ordered list.
    $entries = collect();
    foreach ($pitches as $p) {
        $entries->push([
            'kind'    => 'pitch',
            'at'      => $p->sent_at ? \Carbon\Carbon::parse($p->sent_at) : null,
            'actor'   => $p->agent_name ?? 'agent',
            'summary' => 'Sent ' . ($p->channel ?? 'message') . ' pitch'
                        . ($p->template_name ? ' (template: ' . $p->template_name . ')' : ''),
            'outcome' => $p->outcome,
        ]);
    }
    foreach ($claimNotes as $note) {
        $entries->push([
            'kind'    => 'claim_note',
            'at'      => $note['at'],
            'actor'   => $note['actor'] ?? 'agent',
            'summary' => $note['text'],
        ]);
    }
    $entries = $entries->filter(fn ($e) => $e['at'] !== null)->sortByDesc(fn ($e) => $e['at'])->values();
@endphp

<div style="padding: 16px;">
    @if($entries->isEmpty())
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
            No activity yet — claim this listing and pitch the seller to start the timeline.
        </div>
    @else
        <div class="mi-activity-timeline" style="display: flex; flex-direction: column; gap: 8px;">
            @foreach($entries as $entry)
                @include('corex.market-intelligence._slideover-activity-entry', ['entry' => $entry])
            @endforeach
        </div>
    @endif
</div>
