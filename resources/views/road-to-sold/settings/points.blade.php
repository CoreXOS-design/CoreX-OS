@extends('layouts.corex')

{{--
    Road to Sold v4 belt points (road-to-sold.md 23.16) - Settings > Points: what the agency pays for each belt credit.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Needs $tabs, $amounts (slug => points), $credits (slug => [label, default, group, raised-by, live]), $rb (route base).
    Screen-text rule (Johan): every line is data or a control.
--}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $groups = collect($credits)->groupBy(fn ($c) => $c[2], true);
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - points</h1>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" data-qa="points-saved"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route($rb . 'points.save') }}" data-qa="points-form" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @csrf @method('PUT')
        @foreach($groups as $group => $rows)
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:var(--surface-2, var(--surface));"><h3 class="text-sm font-bold" style="color:var(--text-primary);">{{ $group }}</h3></div>
            <div class="p-5 grid grid-cols-1 lg:grid-cols-2 gap-4" style="border-bottom:1px solid var(--border);">
                @foreach($rows as $slug => $c)
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="points-{{ $slug }}">{{ $c[0] }}</label>
                        <input id="points-{{ $slug }}" type="number" name="points[{{ str_replace('.', '_', $slug) }}]" min="0" max="10000" value="{{ old('points.' . $slug, $amounts[$slug] ?? $c[1]) }}"
                               data-qa="points-{{ $slug }}" class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                        <span class="text-xs ml-2" style="color:var(--text-muted);">points (standard: {{ $c[1] }}, 0 = off)</span>
                        <p class="text-xs mt-1" style="color:var(--text-muted);">{{ $c[3] }}@unless($c[4]) - starts paying when the screen that raises it lands @endunless</p>
                    </div>
                @endforeach
            </div>
        @endforeach
        <div class="p-5"><button type="submit" class="inline-flex items-center justify-center h-10 px-5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9); border:1px solid var(--brand-icon, #0ea5e9);">Save points</button></div>
    </form>
</div>
@endsection
