{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Source tab: Tracked Property chain + external refs.
--}}
@php
    $tp = $source['tracked_property'] ?? null;
    $chain = $source['chain'] ?? [];
    $externalRefs = $source['external_refs'] ?? collect();

    // Portal-source brand colours. These are EXTERNAL brand colours (Property24
    // blue, Private Property green, etc.) not CoreX brand colours, so the
    // design system doesn't define tokens for them. Using the var(--token, #fallback)
    // pattern from UI_DESIGN_SYSTEM.md §5.10 — when --portal-p24 etc. tokens
    // are approved and defined, this picks them up automatically. Legacy precedent:
    // resources/views/prospecting/index_legacy_body.blade.php:603 uses the same hex.
    $badgeStyle = fn (string $type) => match(strtolower($type)) {
        'p24'            => 'background: var(--portal-p24, #1e40af); color: #fff;',
        'pp'             => 'background: var(--portal-pp, #059669); color: #fff;',
        'cmainfo','cma'  => 'background: var(--portal-cma, #7c3aed); color: #fff;',
        'chrome_capture' => 'background: var(--portal-chrome, #d97706); color: #fff;',
        default          => 'background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);',
    };

    // F.8 — plain-English tooltips for each source-type badge.
    $badgeTooltip = fn (string $type) => match(strtolower($type)) {
        'p24'            => 'Property24 portal listing — captured from property24.com',
        'pp'             => 'Private Property portal listing — captured from privateproperty.co.za',
        'cmainfo','cma'  => 'CMA presentation built in CoreX — comparable market analysis evidence',
        'chrome_capture' => 'Captured via the CoreX browser extension on an agent\'s machine',
        default          => 'Other ingestion source — see ref for details',
    };
@endphp

<div style="padding: 16px;">
    @if(!$tp)
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
            This listing isn't linked to a Tracked Property yet. The next ingestion will create one.
        </div>
    @else
    <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
        <div>
            <a href="{{ route('corex.tracked-properties.show', $tp->id) }}"
               style="font-size: 0.875rem; font-weight: 600; color: var(--brand-icon); text-decoration: none;">
                Tracked Property #{{ $tp->id }} →
            </a>
            <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                {{ count($chain) }} source contribution{{ count($chain) === 1 ? '' : 's' }} · {{ $externalRefs->count() }} external ref{{ $externalRefs->count() === 1 ? '' : 's' }}
            </div>
        </div>
    </div>

    <div style="font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 8px;">Source chain</div>
    @if(empty($chain))
        <div style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 14px;">No chain entries on record.</div>
    @else
    <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px;">
        @foreach(collect($chain)->sortByDesc(fn ($c) => $c['date'] ?? '') as $c)
        @php
            $type = $c['type'] ?? 'other';
            $date = !empty($c['date']) ? \Carbon\Carbon::parse($c['date'])->format('j M Y H:i') : '—';
            $fields = $c['fields_contributed'] ?? [];
        @endphp
        <div style="display: grid; grid-template-columns: 80px 1fr; gap: 10px; align-items: start; padding: 8px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px;">
            <span style="display: inline-flex; align-items: center; justify-content: center; padding: 2px 6px; font-size: 0.625rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border-radius: 4px; {{ $badgeStyle($type) }} align-self: start;"
                  title="{{ $badgeTooltip($type) }}">
                {{ strtoupper($type) }}
            </span>
            <div style="min-width: 0;">
                <div style="font-size: 0.8125rem; color: var(--text-primary);">
                    {{ $c['ref'] ?? 'unknown ref' }}
                </div>
                <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                    {{ $date }}
                    @if(!empty($fields))
                        · contributed: {{ implode(', ', array_slice($fields, 0, 6)) }}{{ count($fields) > 6 ? ' +' . (count($fields) - 6) . ' more' : '' }}
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <div style="font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 8px;">External refs</div>
    @if($externalRefs->isEmpty())
        <div style="font-size: 0.8125rem; color: var(--text-muted);">No external refs linked.</div>
    @else
    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
        @foreach($externalRefs as $ref)
        <span style="display: inline-flex; align-items: center; padding: 3px 8px; font-size: 0.6875rem; font-weight: 600; border-radius: 4px; {{ $badgeStyle($ref->source_type) }}"
              title="{{ $badgeTooltip($ref->source_type) }} · First seen {{ \Carbon\Carbon::parse($ref->first_seen_at)->format('j M Y') }}">
            {{ $ref->source_ref }}
        </span>
        @endforeach
    </div>
    @endif
    @endif
</div>
