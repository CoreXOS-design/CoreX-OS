@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full max-w-5xl mx-auto space-y-4">

    {{-- Header --}}
    <div>
        <a href="{{ route('corex.admin.deal-link-review.index') }}"
           class="text-xs no-underline" style="color: var(--text-muted);">← Back to queue</a>
        <h1 class="text-xl font-bold leading-tight mt-1.5" style="color: var(--text-primary);">
            Review match for: {{ $deal?->property_address ?: '—' }}
        </h1>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Deal summary --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="ds-section-header" style="margin: 0 0 8px 0;">Deal details</h2>
        <div class="grid gap-3 text-sm" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Deal #</div>
                <span style="color: var(--text-primary);">{{ $deal?->deal_no ?? '—' }}</span>
            </div>
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Deal date</div>
                <span style="color: var(--text-primary);">{{ $deal?->deal_date?->format('j M Y') ?: '—' }}</span>
            </div>
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Registration</div>
                <span style="color: var(--text-primary);">{{ $deal?->registration_date?->format('j M Y') ?: '—' }}</span>
            </div>
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Sale price</div>
                <span style="color: var(--text-primary);">
                    @if($deal?->sale_price)
                        R {{ number_format((int) $deal->sale_price) }}
                    @elseif($deal?->property_value)
                        R {{ number_format((float) $deal->property_value, 0) }}
                    @else
                        —
                    @endif
                </span>
            </div>
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Seller</div>
                <span style="color: var(--text-primary);">{{ $deal?->seller_name ?: '—' }}</span>
            </div>
            <div>
                <div class="text-[0.625rem] uppercase tracking-wider mb-0.5" style="color: var(--text-muted);">Buyer</div>
                <span style="color: var(--text-primary);">{{ $deal?->buyer_name ?: '—' }}</span>
            </div>
        </div>
    </div>

    {{-- Candidate properties --}}
    <div>
        <h2 class="ds-section-header" style="margin: 0 0 8px 0;">Candidate properties ({{ number_format($candidates->count()) }})</h2>
        @if($candidates->isEmpty())
            <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted);">
                No candidates were found. Use the manual search below to pick a property anyway.
            </div>
        @else
            <div class="flex flex-col gap-2.5">
                @foreach($candidates as $cand)
                    @php
                        $prop = $properties->get($cand['property_id'] ?? null);
                        $confidence = $cand['confidence'] ?? null;
                        $confClass = match ($confidence) {
                            'exact'  => 'ds-badge-success',
                            'high'   => 'ds-badge-info',
                            'medium' => 'ds-badge-warning',
                            default  => 'ds-badge-default',
                        };
                    @endphp
                    <div class="rounded-md p-3.5 grid items-center gap-3.5" style="background: var(--surface); border: 1px solid var(--border); grid-template-columns: 1fr auto;">
                        <div>
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">
                                {{ $cand['address'] ?? ($prop?->address ?? 'unknown') }}
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">
                                @if($cand['suburb'] ?? null){{ $cand['suburb'] }} · @endif
                                Property #{{ $cand['property_id'] }}
                                @if($prop)
                                    · Status: {{ $prop->status }}
                                    @if($prop->price) · Listed at R {{ number_format((float) $prop->price, 0) }}@endif
                                    @if($prop->last_activity_at) · Active {{ \Carbon\Carbon::parse($prop->last_activity_at)->diffForHumans() }}@endif
                                @endif
                            </div>
                            <div class="flex items-center gap-2 flex-wrap mt-1.5 text-[0.6875rem]" style="color: var(--text-muted);">
                                <span>Match score: <strong style="color: var(--text-secondary);">{{ number_format((int) ($cand['score'] ?? 0)) }}</strong></span>
                                @if($confidence)
                                    <span class="ds-badge {{ $confClass }}">{{ $confidence }}</span>
                                @endif
                                @if(!empty($cand['date_match']))<span>· date proximity confirmed</span>@endif
                            </div>
                        </div>
                        <div>
                            <form method="POST" action="{{ route('corex.admin.deal-link-review.link', $item->id) }}">
                                @csrf
                                <input type="hidden" name="property_id" value="{{ $cand['property_id'] }}">
                                <button type="submit" class="corex-btn-primary whitespace-nowrap">Link this property →</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Manual search --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="ds-section-header" style="margin: 0 0 8px 0;">Or link manually</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Paste the property ID if you already know it (visible on /corex/properties/N).
        </p>
        <form method="POST" action="{{ route('corex.admin.deal-link-review.link', $item->id) }}"
              class="flex flex-col sm:flex-row gap-2.5 sm:items-end">
            @csrf
            <div class="sm:w-40">
                <label for="property_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property ID</label>
                <input id="property_id" type="number" name="property_id" required min="1" placeholder="e.g. 1234"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="flex-1">
                <label for="review_note" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Note <span style="color: var(--text-muted);">(optional)</span></label>
                <input id="review_note" type="text" name="review_note" maxlength="2000" placeholder="Why you picked this property"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary whitespace-nowrap">Link</button>
        </form>
    </div>

    {{-- Resolve without linking --}}
    <div class="flex flex-wrap gap-2 justify-end">
        <form method="POST" action="{{ route('corex.admin.deal-link-review.skip', $item->id) }}">
            @csrf
            <button type="submit" class="corex-btn-outline whitespace-nowrap">Defer for later</button>
        </form>
        <form method="POST" action="{{ route('corex.admin.deal-link-review.unlink', $item->id) }}">
            @csrf
            <button type="submit" class="corex-btn-outline whitespace-nowrap">None of these — mark unmatched</button>
        </form>
    </div>
</div>
@endsection
