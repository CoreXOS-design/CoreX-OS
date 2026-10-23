{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $cards = [
        'sign_each' => [
            'title' => 'Sign every place',
            'text' => 'The signer opens the document and signs or initials each place on it, one by one, exactly as placed on the document.',
            'affects' => 'Nothing changes. This is how e-sign has always worked.',
        ],
        'adopt_then_stamp' => [
            'title' => 'Adopt once, then click to stamp',
            'text' => 'When the signer opens the document they draw or type their signature and initial once and agree that it is theirs. After that, each signature and initial place is a single click that places it.',
            'affects' => 'Signers no longer draw at every place; each click is still recorded separately, with its time.',
        ],
        'adopt_and_fill_all' => [
            'title' => 'Adopt once and fill everything',
            'text' => 'The signer adopts their signature and initial once, then confirms once, and CoreX places them on every signature and initial place that is theirs. Places that need the person to act one at a time — condition and amendment initials, and disclosure answers — are still done individually.',
            'affects' => 'Fastest for signers. Before anything is placed, they are told how many places will be filled, and their agreement is stored with the signing.',
        ],
    ];
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">How my signers sign</h1>
                <p class="text-xs" style="color: var(--text-muted);">Choose how the people who sign by link — tenants, landlords, sellers, buyers — put their signature on your documents.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('docuperfect.esign.myDocuments') }}" class="corex-btn-outline text-xs">Back to My E-Sign Documents</a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--ds-green, #059669);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);
                    color: var(--ds-crimson, #dc2626);">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="border: 1px solid var(--ds-crimson, #dc2626); color: var(--ds-crimson, #dc2626);">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="rounded-md p-6 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('docuperfect.esign.settings.signing-mode.update') }}" class="space-y-5">
            @csrf

            @foreach($modes as $mode)
                <label for="mode_{{ $mode }}" class="flex items-start gap-3 rounded-md p-4 cursor-pointer"
                       style="border: 1px solid var(--border);">
                    <input type="radio" id="mode_{{ $mode }}" name="signing_mode" value="{{ $mode }}"
                           {{ old('signing_mode', $current) === $mode ? 'checked' : '' }}
                           class="mt-1 h-4 w-4" style="accent-color: var(--brand-icon);">
                    <div>
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $cards[$mode]['title'] }}
                            @if($mode === 'sign_each')<span class="text-[11px] font-normal" style="color: var(--text-muted);">(default)</span>@endif
                        </span>
                        <p class="text-xs mt-1" style="color: var(--text-secondary); max-width: 42rem;">{{ $cards[$mode]['text'] }}</p>
                        <p class="text-xs mt-1" style="color: var(--text-secondary); max-width: 42rem;"><strong>What this changes:</strong> {{ $cards[$mode]['affects'] }}</p>
                    </div>
                </label>
            @endforeach

            <p class="text-xs" style="color: var(--text-muted); max-width: 42rem;">
                The choice applies to documents you send from now on. A document already out for signing finishes the way it was sent.
                Agents are not affected — they keep their saved signature and "apply to all".
            </p>

            <div class="pt-1">
                <button type="submit" class="corex-btn-primary text-sm">Save</button>
            </div>
        </form>
    </div>
</div>
@endsection
