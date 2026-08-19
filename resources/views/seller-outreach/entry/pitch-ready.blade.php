{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-2xl mx-auto space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Property created · {{ count($sellers) }} {{ Str::plural('contact', count($sellers)) }} — pitch to sellers</h1>
        <p class="text-sm text-white/70 mt-1">
            {{ $property->address ?: $property->title ?: ('Property #' . $property->id) }}{{ !empty($property->suburb) ? ', ' . $property->suburb : '' }}
        </p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, #10b981 10%, transparent); border:1px solid color-mix(in srgb, #10b981 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-md p-2" style="background: var(--surface); border:1px solid var(--border);">
        @forelse($sellers as $seller)
            @php
                $name = trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: 'Seller';
                $numbers = collect($seller['phones'] ?? [])->pluck('value')
                    ->merge(collect($seller['emails'] ?? [])->pluck('value'))->filter()->take(3)->implode(' · ');
            @endphp
            <a href="{{ route('seller-outreach.composer.show', ['contact' => $seller['contact_id'], 'property_id' => $property->id]) }}"
               class="flex items-center justify-between gap-3 rounded-md px-4 py-3 no-underline"
               style="border-bottom:1px solid var(--border);">
                <div class="min-w-0">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">
                        {{ $name }}
                        @if(!empty($seller['is_primary']))
                            <span class="text-[10px] uppercase tracking-wider font-bold ml-1 px-1.5 py-0.5 rounded" style="background:#10b981; color:#fff;">Primary</span>
                        @endif
                        @if(!empty($seller['dead_end']))
                            <span class="text-[10px] uppercase tracking-wider font-semibold ml-1 px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · {{ $seller['dead_end']['label'] }}</span>
                        @endif
                    </div>
                    <div class="text-xs mt-0.5 truncate font-mono" style="color: var(--text-muted);">
                        {{ $numbers ?: 'No contact number' }}
                    </div>
                </div>
                <span class="shrink-0 text-xs font-semibold" style="color: var(--brand-button, #0ea5e9);">Pitch this seller →</span>
            </a>
        @empty
            <div class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">No sellers linked.</div>
        @endforelse
    </div>

    <div class="flex items-center gap-3">
        <a href="{{ route('corex.properties.show', ['property' => $property->id]) }}" class="text-sm" style="color: var(--text-muted);">Open the property</a>
        <span style="color: var(--text-muted);">·</span>
        <a href="{{ route('market-intelligence.work') }}" class="text-sm" style="color: var(--text-muted);">Back to Market Intelligence</a>
    </div>
</div>
@endsection
