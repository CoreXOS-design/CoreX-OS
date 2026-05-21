@extends('layouts.corex')

@section('corex-content')
<div class="p-6 max-w-5xl mx-auto">
    <div class="mb-4 p-4 rounded-md" style="background: var(--brand-default); color: #fff;">
        <nav class="text-xs mb-1" style="color: rgba(255,255,255,0.75);">
            <a href="{{ route('corex.tracked-properties.show', $tp) }}" class="no-underline" style="color: rgba(255,255,255,0.85);">
                ← Back to tracked property
            </a>
        </nav>
        <h1 class="text-xl font-semibold leading-tight">Merge duplicate — preview</h1>
        <div class="text-sm mt-1" style="color: rgba(255,255,255,0.75);">
            Suburb: {{ $tp->suburb ?? '—' }} · this TP: <code class="text-[11px]" style="background: rgba(255,255,255,0.10); padding: 1px 4px; border-radius: 2px;">{{ $tp->external_id }}</code>
        </div>
    </div>

    <div class="mb-6 p-4 rounded-md"
         style="background: color-mix(in srgb, var(--brand-button) 8%, transparent); border: 1px solid var(--brand-button); color: var(--text-primary);">
        <p class="text-sm">
            <strong>Merge feature coming in next phase.</strong> The list below shows the candidate duplicates surfaced by the coarse same-suburb filter. The full side-by-side merge UI — with field-by-field winner selection, source_chain preservation, and external-ref reattribution — lands in Phase D.
        </p>
    </div>

    <section class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">
            Same-suburb candidates ({{ $candidates->count() }})
        </h2>
        @if($candidates->isEmpty())
            <p class="text-sm italic" style="color: var(--text-muted);">No same-suburb candidates found.</p>
        @else
            <div class="space-y-2">
                @foreach($candidates as $c)
                    <div class="p-2.5 rounded-md flex items-start gap-3"
                         style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="flex-1 text-sm" style="color: var(--text-primary);">
                            <div>
                                {{ trim(($c->street_number ? $c->street_number . ' ' : '') . ($c->street_name ?? '')) ?: '(no street)' }}
                                @if($c->suburb) · {{ $c->suburb }} @endif
                            </div>
                            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                                @if($c->erf_number) Erf {{ $c->erf_number }} · @endif
                                Last enriched {{ optional($c->last_enriched_at)->diffForHumans() ?? 'unknown' }}
                            </div>
                        </div>
                        <a href="{{ route('corex.tracked-properties.show', $c->id) }}"
                           class="text-[11px] no-underline"
                           style="color: var(--brand-button);">Open ↗</a>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
