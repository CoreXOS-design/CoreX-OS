@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('corex.properties.show', $property) }}"
           class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">
            ← Back to property
        </a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Which seller is this pitch for?</h1>
        <p class="text-sm text-white/60">
            This property has {{ $sellers->count() }} linked sellers. Pick one to compose the pitch.
        </p>
    </div>

    <div class="space-y-2">
        @foreach($sellers as $seller)
            <a href="{{ route('seller-outreach.composer.show', ['contact' => $seller->id, 'property_id' => $property->id]) }}"
               class="block rounded-md p-4 no-underline transition"
               style="background: var(--surface); border: 1px solid var(--border);"
               onmouseover="this.style.borderColor='#00d4aa'" onmouseout="this.style.borderColor='var(--border)'">
                <div class="font-semibold" style="color: var(--text-primary);">
                    {{ trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) ?: '(unnamed)' }}
                    <span class="text-xs ml-1" style="color: var(--text-muted);">
                        @if($seller->pivot && $seller->pivot->role)· {{ $seller->pivot->role }}@endif
                    </span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    @if($seller->phone) 📞 {{ $seller->phone }} @endif
                    @if($seller->email) · ✉️ {{ $seller->email }} @endif
                    @if($seller->messaging_opt_out_at)
                        · <span style="color: var(--ds-crimson);">⚠ opted out</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection
