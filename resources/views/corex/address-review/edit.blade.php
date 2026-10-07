{{-- Structured address matching — fix one unreadable address. Spec: .ai/specs/structured-address-matching.md §10. No Alpine. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('corex.address-review.index') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Address Review</a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Fix address — {{ $kind === 'property' ? 'Property' : 'Captured property' }} #{{ $model->id }}</h1>
        <p class="text-sm text-white/60">Correct what CoreX holds. It is read again straight away and marked as checked by you.</p>
    </div>

    @if(session('error'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ $errors->first() }}</div>
    @endif

    <div class="rounded-md p-4 text-sm space-y-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
        <div><span class="font-semibold" style="color:var(--text-primary);">Why it is on the list:</span> {{ $model->address_parse_note ?: 'It could not be read with confidence.' }}</div>
        <div><span class="font-semibold" style="color:var(--text-primary);">As CoreX reads it now:</span>
            {{ trim(($structure['street_number'] ?? '') . ' ' . ($structure['street_name_clean'] ?? '')) ?: 'no street found' }}{{ $structure['unit_number'] ? ', unit ' . $structure['unit_number'] : '' }}{{ $structure['complex_name'] ? ', ' . $structure['complex_name'] : '' }}
            · suburb {{ $structure['suburb_resolved'] ? 'recognised' : 'not recognised' }}
        </div>
        <div><a href="{{ $recordUrl }}" target="_blank" rel="noopener" class="no-underline" style="color:var(--brand-icon,#2563eb);">Open the record →</a></div>
    </div>

    <form method="POST" action="{{ route('corex.address-review.fix', ['kind' => $kind, 'id' => $model->id]) }}"
          class="rounded-md p-6 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        @csrf
        @foreach([
            ['street_number', 'Street number', 'For example 19, 12A, 1/3'],
            ['street_name', 'Street name', 'The street, with or without its type (Grindewald Drive)'],
            ['unit_number', 'Unit / section number', 'Flats and townhouses'],
            ['complex_name', 'Complex / scheme name', ''],
            ['suburb', 'Suburb', 'Use the Property24 suburb name (for example Uvongo Beach)'],
            ['erf_number', 'Erf / stand number', ''],
        ] as [$name, $label, $help])
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">{{ $label }}</label>
                <input type="text" name="{{ $name }}" value="{{ old($name, $model->{$name}) }}"
                       class="w-full px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                @if($help)<p class="text-xs mt-1" style="color:var(--text-muted);">{{ $help }}</p>@endif
            </div>
        @endforeach
        <div class="flex items-center gap-3">
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save and mark as checked</button>
            <a href="{{ route('corex.address-review.index') }}" class="text-sm no-underline" style="color:var(--text-secondary);">Cancel</a>
        </div>
    </form>
</div>
@endsection
